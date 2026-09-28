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
// Building the shield itself (once per request in protect()).
$t = hrtime(true);
for ($i = 0; $i < 20000; $i++) {
    $s = new Shield($config, $stores['memory']);
}
printf("  %-7s %6.2f µs per request (new Shield with this configuration)\n", 'setup', (hrtime(true) - $t) / 20000 / 1000);
exec('rm -rf ' . escapeshellarg($dir));
