<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\FileStore;

/**
 * Fail safe (ADR 0007): whatever breaks inside the shield, the site stays up.
 * The decision path is made to throw through the $known hook (an adapter's
 * URL index), which CacheableRule calls for every GET -- the one place the
 * application can hand the shield code that fails.
 */

/**
 * A site behind the shield on PHP's built-in server, the shield started by a
 * prepend file of this test's own (bootstrap.php stays quiet: no config).
 *
 * @param callable(callable(string): array{status: int, body: string, headers: list<string>}, string): void $body gets a GET function and the directory
 */
function withFailing(string $rules, string $known, callable $body): void
{
    $dir = sys_get_temp_dir() . '/rs-robust-' . getmypid() . '-' . mt_rand();
    mkdir("$dir/docroot", 0700, true);
    file_put_contents("$dir/docroot/index.php", '<?php echo "ok " . ($_SERVER["REQUEST_SHIELD"] ?? "-");');
    file_put_contents("$dir/site.rules", str_replace('__DIR__', $dir, $rules));
    file_put_contents("$dir/prepend.php", '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";\n"
        . '\CjwNetwork\RequestShield\Shield::protectFile(' . var_export("$dir/site.rules", true) . ', ' . $known . ');');
    $port = freePort();
    $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=/nonexistent exec %s -d auto_prepend_file=%s -d log_errors=1 -d error_log=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
        escapeshellarg(PHP_BINARY), escapeshellarg("$dir/prepend.php"), escapeshellarg("$dir/php-errors.log"), $port, escapeshellarg("$dir/docroot")), [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body(static function (string $uri) use ($port): array {
            $out = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
            $headers = $http_response_header ?? [];
            return ['status' => (int) substr((string) ($headers[0] ?? ''), 9, 3), 'body' => $out, 'headers' => $headers];
        }, $dir);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

return [
    'RSF5.5 an exception in the decision path: the request passes, uncached, as "shield error"' => function (): void {
        $server = $_SERVER;
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/page', 'HTTP_HOST' => 'example.org', 'REMOTE_ADDR' => '198.51.100.7', 'SERVER_PROTOCOL' => 'HTTP/1.1'] + $_SERVER;
        unset($_SERVER['REQUEST_SHIELD']);
        $log = tempnam(sys_get_temp_dir(), 'rs-log-');
        $errorLog = ini_set('error_log', (string) $log);        // the one line goes here, not into the test output
        try {
            $d = Shield::protect(['store' => 'memory'], static function (): ?bool {
                throw new RuntimeException('the adapter\'s index is broken');
            });
            same(Decision::ALLOW_UNCACHED, $d->action, 'let through');
            same('shield error', $d->reason);
            same(false, $d->cacheable(), 'never cached');
            same(Decision::ALLOW_UNCACHED, $_SERVER['REQUEST_SHIELD'] ?? null, 'what the application sees');
            same($d, Shield::current(), 'Shield::current() says the same');
            same(null, Shield::currentRule());
            truthy(strpos((string) file_get_contents((string) $log), 'the shield failed and let the request through: RuntimeException') !== false, 'the error log names the cause');
        } finally {
            $_SERVER = $server;
            ini_set('error_log', (string) $errorLog);
            @unlink((string) $log);
        }
    },
    'RSF5.5 the real path: the site answers although the shield throws; its own refusals still stand; the error log hears it once' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withFailing("set store file\nset store-dir __DIR__/store\nset debug-header on\nblock /secret/**\n", 'static function () { throw new RuntimeException("boom"); }', function (callable $get, string $dir): void {
            for ($i = 0; $i < 3; $i++) {
                $r = $get('/');
                same(200, $r['status'], 'the site answers');
                same('ok allow-uncached', $r['body'], 'uncached, and the application knows');
                truthy(in_array('X-Request-Shield: allow-uncached shield error', $r['headers'], true), 'the debug header names the cause: ' . implode(' | ', $r['headers']));
            }
            $r = $get('/secret/x');
            same(404, $r['status'], 'a blocked path is refused before the failing step runs');
            $log = (string) @file_get_contents("$dir/php-errors.log");
            same(1, preg_match_all('/the shield failed and let the request through: RuntimeException: boom/', $log), "one line for three requests, not three:\n$log");
        });
    },
    'RSF5.5 a store directory nobody can write: nothing is counted, every request passes, no error' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $probe = sys_get_temp_dir() . '/rs-ro-' . getmypid();
        mkdir($probe, 0500);
        $root = is_writable($probe);                 // root writes anywhere: the case cannot be made here
        rmdir($probe);
        if ($root) {
            skip('running as root: a read-only directory is still writable');
        }
        withFailing("set store file\nset store-dir __RO__\nlimit requests 2/min\n", 'null', function (callable $get, string $dir): void {
            // The rules are written before the directory exists; point them at it now.
            mkdir("$dir/ro", 0500);
            file_put_contents("$dir/site.rules", str_replace('__RO__', "$dir/ro", (string) file_get_contents("$dir/site.rules")));
            for ($i = 0; $i < 5; $i++) {
                $r = $get('/');
                same(200, $r['status'], "request $i passes: the limit cannot be counted, so it does not bite");
            }
            same('', trim((string) @file_get_contents("$dir/php-errors.log")), 'no error for the visitor and none in the log');
        });
    },
    'RSF5.5 the file store and the secret on an unwritable directory do not throw' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-ro-unit-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0500);
        try {
            if (is_writable($dir)) {
                skip('running as root: a read-only directory is still writable');
            }
            $store = new FileStore($dir);
            same(0.0, $store->hit('k', 60, microtime(true)), 'a hit counts as nothing');
            $store->mark('m', time() + 60, microtime(true));
            same(0, $store->marked('m', microtime(true)), 'a mark is not kept');
            $secret = Secret::resolve(null, $dir);
            truthy(strlen($secret) >= 32, 'a secret for this request all the same');
        } finally {
            @chmod($dir, 0700);
            @rmdir($dir);
        }
    },
];
