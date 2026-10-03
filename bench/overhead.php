<?php
/**
 * What the shield costs a request that passes: Request::fromServer() plus
 * every check plus one counter, per store.
 *
 *   php -d apc.enable_cli=1 bench/overhead.php [iterations]
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CjwNetwork\RequestShield\Config;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\ApcuStore;
use CjwNetwork\RequestShield\Store\FileStore;
use CjwNetwork\RequestShield\Store\MemoryStore;

$n = (int) ($argv[1] ?? 100000);
$server = [
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/bold_ger/kontakt?page=2', 'HTTP_HOST' => 'www.example.org',
    'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
    'HTTP_ACCEPT' => 'text/html,application/xhtml+xml', 'HTTP_ACCEPT_ENCODING' => 'gzip, br', 'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9',
    'HTTP_COOKIE' => 'PHPSESSID=0123456789abcdef0123456789abcdef; _ga=GA1.2.3.4',
];
$config = [
    'trustedProxies' => ['10.0.0.0/8'],
    'hosts' => ['www.example.org', 'example.org'],
    'blockedPaths' => array_merge(Config::scannerPaths(), Config::wordpressPaths()),
    'cacheable' => ['query' => ['page'], 'paths' => ['#^/[a-z0-9_/-]*$#']],
    'budgets' => ['requests' => ['limit' => 1000000000, 'window' => 60, 'challengeAt' => 1000000000]],
];

$stores = ['memory' => new MemoryStore()];
if (ApcuStore::usable()) {
    $stores['apcu'] = new ApcuStore('rshield-bench:');
}
$dir = sys_get_temp_dir() . '/rshield-bench-' . getmypid();
$stores['file'] = new FileStore($dir, 0.0);

printf("PHP %s, %d iterations, a passing request with 11 headers behind a trusted proxy\n", PHP_VERSION, $n);
foreach ($stores as $name => $store) {
    $shield = new Shield($config, $store);
    $iterations = $name === 'file' ? min($n, 20000) : $n;
    $t = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $d = $shield->decide(Request::fromServer($server, ['10.0.0.0/8']), microtime(true));
    }
    printf("  %-7s %6.2f µs per request (%s)\n", $name, (hrtime(true) - $t) / $iterations / 1000, $d->action);
}
// Building the shield itself, once per request: from an array (every setting
// checked), and from a settings file compiled once (Settings::load(), served
// by OPcache -- run with -d opcache.enable_cli=1 to see what FPM sees).
$t = hrtime(true);
for ($i = 0; $i < 20000; $i++) {
    $s = new Shield($config, $stores['memory']);
}
printf("  %-7s %6.2f µs per request (new Shield from an array: every setting checked)\n", 'setup', (hrtime(true) - $t) / 20000 / 1000);
$cfgFile = $dir . '-config.php';
file_put_contents($cfgFile, '<?php return ' . var_export($config, true) . ';');
CjwNetwork\RequestShield\Settings::load($cfgFile, $dir . '-cache');
$t = hrtime(true);
for ($i = 0; $i < 20000; $i++) {
    clearstatcache();       // each request starts with an empty stat cache
    $s = new Shield(CjwNetwork\RequestShield\Settings::load($cfgFile, $dir . '-cache'), $stores['memory']);
}
printf("  %-7s %6.2f µs per request (new Shield from a compiled settings file%s)\n", 'setup', (hrtime(true) - $t) / 20000 / 1000,
    function_exists('opcache_get_status') && ini_get('opcache.enable_cli') ? ', OPcache on' : ', OPcache OFF: the file is parsed every time');
// The same from rule files: one file (one stat() per request), and a main
// file with an include and five extensions' files (with APCu: checked every
// 10 seconds, no stat() in between).
$rules = $dir . '-rules';
@mkdir("$rules/rules.d", 0700, true);
file_put_contents("$rules/site.rules", "trust 10.0.0.0/8\nhost www.example.org example.org\ncache-query page\nlimit requests 600/min challenge-at 300\nlimit misses 60/min on-demand\nblock /wp-admin/**\nchallenge /login\n");
file_put_contents("$rules/multi.rules", file_get_contents("$rules/site.rules") . "include rules.d/*.rules\n");
for ($x = 1; $x <= 5; $x++) {
    @mkdir("$rules/ext$x", 0700, true);
    file_put_contents("$rules/ext$x/request-shield.rules", "cache-path /ext$x/**\nchallenge /ext$x/checkout\n");
}
file_put_contents("$rules/rules.d/local.rules", "exempt 192.0.2.50\n");
foreach (['one rule file' => ["$rules/site.rules", []], 'rule file + include + 5 extensions' => ["$rules/multi.rules", ["$rules/ext*/request-shield.rules"]]] as $label => [$main, $sources]) {
    CjwNetwork\RequestShield\Settings::load($main, $dir . '-cache', $sources);
    $t = hrtime(true);
    for ($i = 0; $i < 20000; $i++) {
        clearstatcache();
        $s = new Shield(CjwNetwork\RequestShield\Settings::load($main, $dir . '-cache', $sources), $stores['memory']);
    }
    printf("  %-7s %6.2f µs per request (%s%s)\n", 'setup', (hrtime(true) - $t) / 20000 / 1000, $label,
        $sources !== [] ? (function_exists('apcu_enabled') && apcu_enabled() ? ', APCu: rechecked every 10 s' : ', no APCu: main file only') : '');
}
exec('rm -rf ' . escapeshellarg($rules));
exec('rm -rf ' . escapeshellarg($cfgFile) . ' ' . escapeshellarg($dir . '-cache'));
exec('rm -rf ' . escapeshellarg($dir));

// ── The challenge paths (only for clients past a threshold) ──────────────────
$secret = str_repeat('bench-secret', 4);
$pow = new CjwNetwork\RequestShield\Challenge\ProofOfWork($secret);
$pass = new CjwNetwork\RequestShield\Challenge\PassCookie($secret);
$m = 5000;
$t = hrtime(true);
for ($i = 0; $i < $m; $i++) {
    $c = $pow->create('203.0.113.7', 50000, 2000000000);
    $page = CjwNetwork\RequestShield\Challenge\ChallengePage::render($c, 'rss', true);
}
printf("  %-7s %6.2f µs per challenge page (%d bytes)\n", 'page', (hrtime(true) - $t) / $m / 1000, strlen($page));
$c = $pow->create('203.0.113.7', 2000, 2000000000);
for ($n = 0; hash('sha256', $c['salt'] . $n) !== $c['challenge']; $n++);
$payload = rtrim(strtr(base64_encode(json_encode(['algorithm' => 'SHA-256', 'challenge' => $c['challenge'], 'number' => $n, 'salt' => $c['salt'], 'signature' => $c['signature']])), '+/', '-_'), '=');
$t = hrtime(true);
for ($i = 0; $i < $m; $i++) {
    $ok = $pow->verify($payload, '203.0.113.7', 1000.0);
}
printf("  %-7s %6.2f µs per solution check (%s)\n", 'verify', (hrtime(true) - $t) / $m / 1000, $ok ? 'valid' : 'INVALID');
$cookie = $pass->issue('203.0.113.7', 'Mozilla/5.0', 2000000000);
$t = hrtime(true);
for ($i = 0; $i < $n = 20000; $i++) {
    $ok = $pass->valid($cookie, '203.0.113.7', 'Mozilla/5.0', 1000.0);
}
printf("  %-7s %6.2f µs per pass cookie check (%s)\n", 'pass', (hrtime(true) - $t) / 20000 / 1000, $ok ? 'valid' : 'INVALID');
