<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Plugin;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Stats\Stats;
use CjwNetwork\RequestShield\Stats\StatsPlugin;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** Plugins (proposal 0023): the core tells them what it decided and how a request ended. */

/** A plugin that writes down what it was told. */
final class RsRecordingPlugin implements Plugin
{
    /** @var list<string> */
    public static array $heard = [];

    public function __construct(Settings $settings)
    {
        self::$heard[] = 'made';
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
        self::$heard[] = "decided $decision->action " . ($continues ? 'continues' : 'final') . ' ' . $seen->host() . ' ' . $seen->who() . ($rule !== null ? " $rule" : '');
    }

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
        self::$heard[] = "ended $status";
    }
}

/** A plugin that fails. */
final class RsBrokenPlugin implements Plugin
{
    public function __construct(Settings $settings)
    {
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
        throw new RuntimeException('broken on purpose');
    }

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
        throw new RuntimeException('broken on purpose');
    }
}

/** A class that is no plugin. */
final class RsNotAPlugin
{
}

function pluginReq(string $uri, string $ua = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/136.0', string $host = 'WWW.Example.org:8443'): Request
{
    return Request::fromServer(['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => $host, 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => $ua]);
}

return [
    'RSF6.4 naming plugins: in a rule file (plugin <class>) and in PHP settings -- class names only, never inside a match block' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-plugin-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            file_put_contents("$dir/site.rules", "[SITE-METRICS] plugin \\Vendor\\Metrics\\Plugin   # numbers for the monitoring\nplugin RsRecordingPlugin\n");
            $read = RuleFile::read(["$dir/site.rules"]);
            $s = Settings::from($read['config']);
            same(['Vendor\\Metrics\\Plugin', 'RsRecordingPlugin'], $s->plugins, 'both, the leading backslash gone');
            same('SITE-METRICS', $s->origin('plugins', 'Vendor\\Metrics\\Plugin'), 'with its ID');
            foreach (["plugin a/b\n" => 'one class name', "plugin A B\n" => 'one class name', "match /x/** {\n  plugin RsRecordingPlugin\n}\n" => 'does not go inside a match block'] as $text => $says) {
                file_put_contents("$dir/bad.rules", $text);
                try {
                    RuleFile::read(["$dir/bad.rules"]);
                    throw new TestFailure("accepted: $text");
                } catch (RuleFileException $e) {
                    truthy(strpos($e->getMessage(), $says) !== false, $e->getMessage());
                }
            }
            same(['RsRecordingPlugin'], Settings::from(['plugins' => ['RsRecordingPlugin', '\\RsRecordingPlugin']])->plugins, 'PHP settings: once each');
            try {
                Settings::from(['plugins' => ['not a class']]);
                throw new TestFailure('accepted "not a class"');
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'plugins') !== false, $e->getMessage());
            }
            same([], Settings::from([])->plugins, 'none by default');
            file_put_contents("$dir/check.rules", "[SITE-X] plugin RsMissing\\Plugin\n");
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' check ' . escapeshellarg("$dir/check.rules") . ' 2>&1', $out, $code);
            truthy($code === 3 && strpos(implode("\n", $out), 'warning: SITE-X: plugin RsMissing\\Plugin is not there, or is no CjwNetwork\\RequestShield\\Plugin -- it is left out') !== false,
                'check warns about a plugin it cannot find: ' . implode("\n", $out));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF6.4 the plugins a shield makes: those named, the statistics with "set stats on", nothing for a class that is missing or no Plugin' => function (): void {
        $log = sys_get_temp_dir() . '/rs-plugin-log-' . getmypid() . '-' . mt_rand();
        $old = ini_set('error_log', $log);
        try {
            same([], (new Shield(Settings::from([]), new MemoryStore()))->plugins(), 'no plugin: nothing to tell');
            $made = (new Shield(Settings::from(['ext' => ['stats' => ['enabled' => true]], 'storeDir' => sys_get_temp_dir()]), new MemoryStore()))->plugins();
            same([StatsPlugin::class], array_map('get_class', $made), 'the statistics come with set stats on');
            $made = (new Shield(Settings::from(['plugins' => ['RsRecordingPlugin', 'RsMissing\\Plugin', 'RsNotAPlugin']]), new MemoryStore()))->plugins();
            same(['RsRecordingPlugin'], array_map('get_class', $made), 'a missing class and one that is no Plugin are left out');
            truthy(is_file($log) && strpos((string) file_get_contents($log), 'request-shield: plugin RsMissing\\Plugin is missing or no') !== false, 'and noted in PHP\'s error log');
        } finally {
            ini_set('error_log', (string) $old);
            @unlink($log);
        }
    },
    'RSF6.4 record(): the plugins hear the decision -- final, or "continues" with ended() after the site; a failing plugin changes nothing' => function (): void {
        $log = sys_get_temp_dir() . '/rs-plugin-log-' . getmypid() . '-' . mt_rand();
        $old = ini_set('error_log', $log);
        try {
            RsRecordingPlugin::$heard = [];
            $shield = new Shield(Settings::from(['plugins' => ['RsBrokenPlugin', 'RsRecordingPlugin'], 'storeDir' => sys_get_temp_dir()]), new MemoryStore());
            $r = pluginReq('/.env');
            $d = $shield->decide($r, 1000.0);
            $shield->record($r, $d, $shield->explain($d, $r), 1000.0);
            same(['made', 'decided reject final www.example.org people SCAN-HIDDEN'], RsRecordingPlugin::$heard, 'told after a broken plugin failed; the website without port, lower case');
            truthy(strpos((string) @file_get_contents($log), 'plugin RsBrokenPlugin failed: broken on purpose') !== false, 'the failure in PHP\'s error log');
        } finally {
            ini_set('error_log', (string) $old);
            @unlink($log);
        }
    },
    'RSF6.4 Seen: the website, who came, a bot\'s family -- worked out once, on demand' => function (): void {
        $s = Settings::from(['storeDir' => sys_get_temp_dir()]);
        $shield = new Shield($s, new MemoryStore());
        $seen = new Seen(pluginReq('/', 'curl/8.5', 'Shop.Example.ORG.:80'), $shield);
        same(['shop.example.org', null, false, 'curl', 'bots'], [$seen->host(), $seen->crawler(), $seen->verified(), $seen->botFamily(), $seen->who()]);
        $google = new Seen(pluginReq('/', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'), $shield);
        same(['CRAWL-GOOGLE', false, 'search', null, 'bots'], [$google->crawler(), $google->verified(), $google->crawlerKind(), $google->botFamily(), $google->who()], 'a borrowed name from an address that is not Google\'s: a bot');
        same('people', (new Seen(pluginReq('/'), $shield))->who());
        foreach (['', 'python-requests/2.32', 'HeadlessChrome/120', 'Mozilla/5.0 Firefox/136.0', 'MyCrawler/1.0'] as $ua) {
            same(Stats::botFamily($ua), Seen::family($ua), "the same families as before: $ua");
        }
    },
    'RSF6.4 the real path: a plugin in a rule file, ended() with the site\'s status, a broken plugin -- the site answers all the same' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = sys_get_temp_dir() . '/rs-plugin-e2e-' . getmypid() . '-' . mt_rand();
        mkdir("$dir/docroot", 0700, true);
        file_put_contents("$dir/docroot/index.php", '<?php if ($_SERVER["REQUEST_URI"] === "/gone") { http_response_code(410); } echo "ok";');
        file_put_contents("$dir/plugin.php", '<?php
final class RsE2ePlugin implements CjwNetwork\RequestShield\Plugin {
    public function __construct(CjwNetwork\RequestShield\Settings $s) {}
    public function decided(CjwNetwork\RequestShield\Request $r, CjwNetwork\RequestShield\Decision $d, ?string $rule, CjwNetwork\RequestShield\Seen $seen, float $now, bool $continues, ?CjwNetwork\RequestShield\Decision $would = null): void {
        file_put_contents(' . var_export("$dir/heard.log", true) . ', "$r->path decided $d->action " . ($continues ? "continues" : "final") . "\n", FILE_APPEND);
    }
    public function ended(CjwNetwork\RequestShield\Request $r, int $status, array $headers, CjwNetwork\RequestShield\Seen $seen, float $now): void {
        file_put_contents(' . var_export("$dir/heard.log", true) . ', "$r->path ended $status\n", FILE_APPEND);
    }
}
final class RsE2eBroken implements CjwNetwork\RequestShield\Plugin {
    public function __construct(CjwNetwork\RequestShield\Settings $s) {}
    public function decided(CjwNetwork\RequestShield\Request $r, CjwNetwork\RequestShield\Decision $d, ?string $rule, CjwNetwork\RequestShield\Seen $seen, float $now, bool $continues, ?CjwNetwork\RequestShield\Decision $would = null): void { throw new RuntimeException("x"); }
    public function ended(CjwNetwork\RequestShield\Request $r, int $status, array $headers, CjwNetwork\RequestShield\Seen $seen, float $now): void { throw new RuntimeException("x"); }
}
');
        file_put_contents("$dir/prepend.php", '<?php require ' . var_export(rsEntry(), true) . '; require ' . var_export("$dir/plugin.php", true) . '; \CjwNetwork\RequestShield\Shield::protectFile(' . var_export("$dir/site.rules", true) . ');');
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nexempt none\nrestrict **/admin/** to 192.0.2.1\nplugin RsE2eBroken\nplugin RsE2ePlugin\n");
        $port = freePort();
        // REQUEST_SHIELD_CONFIG: the prepend file starts the shield itself; bootstrap.php must not, from a config/ of this checkout.
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=/nonexistent exec %s -d auto_prepend_file=%s -d log_errors=1 -d error_log=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1', serverPhp(),
            escapeshellarg("$dir/prepend.php"), escapeshellarg("$dir/php-errors.log"), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri) use ($port): string {
                return (string) @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10, 'header' => "User-Agent: Mozilla/5.0 Firefox/136.0\r\n"]]));
            };
            same('ok', $get('/'), 'the site answers, a broken plugin or not');
            same('ok', $get('/gone'), 'the site\'s own 410');
            $get('/admin/users');                     // a path without a dot: the built-in server hands it to PHP
            usleep(200000);
            same(['/ decided allow continues', '/ ended 200', '/gone decided allow continues', '/gone ended 410', '/admin/users decided reject final'],
                file("$dir/heard.log", FILE_IGNORE_NEW_LINES), 'decided for every request; ended with the site\'s status, not for one the shield answered');
            truthy(strpos((string) @file_get_contents("$dir/php-errors.log"), 'plugin RsE2eBroken failed') !== false, 'the broken plugin in PHP\'s error log');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
'RSF6.4 the example in docs/features/plugins.md runs: one line in the error log when refusals pile up' => function (): void {
        if (!\CjwNetwork\RequestShield\Store\ApcuStore::usable()) {
            skip('APCu not enabled (php -d apc.enable_cli=1)');
        }
        $doc = (string) file_get_contents(dirname(__DIR__) . '/docs/features/plugins.md');
        truthy(preg_match('/```php\n(<\?php\nnamespace Acme\\\\Shield;.*?)```/s', $doc, $m) === 1, 'the example is in the guide');
        $file = sys_get_temp_dir() . '/rs-plugin-doc-' . getmypid() . '.php';
        $log = sys_get_temp_dir() . '/rs-plugin-doc-' . getmypid() . '.log';
        file_put_contents($file, $m[1]);
        $old = ini_set('error_log', $log);
        try {
            require_once $file;
            $shield = new Shield(Settings::from(['plugins' => ['Acme\\Shield\\RefusalAlert'], 'storeDir' => sys_get_temp_dir()]), new MemoryStore());
            $r = pluginReq('/.env');
            $now = 1790763600.0 + mt_rand(0, 1000) * 60;
            for ($i = 0; $i < 250; $i++) {
                $d = $shield->decide($r, $now);
                $shield->record($r, $d, $shield->explain($d, $r), $now);
            }
            $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($log)), static fn (string $l): bool => strpos($l, 'acme: more than 200 refusals') !== false));
            same(1, count($lines), 'one line, once the 200 are crossed');
            truthy(strpos($lines[0], 'on www.example.org (last rule: SCAN-HIDDEN)') !== false, $lines[0]);
        } finally {
            ini_set('error_log', (string) $old);
            @unlink($file);
            @unlink($log);
        }
    },
];
