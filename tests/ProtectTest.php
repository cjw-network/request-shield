<?php

declare(strict_types=1);

/**
 * The real path: PHP's built-in server with auto_prepend_file = bootstrap.php,
 * as on a shared host, and an application that prints what it was told.
 *
 * No router script: the built-in server does not prepend auto_prepend_file to
 * one. And it answers paths with an extension (/.env) itself, as static files,
 * without PHP; a web server with the site's rewrite rules hands them to
 * index.php, which the requests below do explicitly (/index.php/.env).
 */

function withServer(array $config, callable $body): void
{
    $dir = sys_get_temp_dir() . '/rshield-e2e-' . getmypid() . '-' . mt_rand();
    mkdir($dir . '/docroot', 0700, true);
    file_put_contents($dir . '/docroot/index.php', '<?php echo json_encode(["shield" => $_SERVER["REQUEST_SHIELD"] ?? null, "xff" => $_SERVER["HTTP_X_FORWARDED_FOR"] ?? null, "cacheable" => \CjwNetwork\RequestShield\Shield::current()?->cacheable()]);');
    file_put_contents($dir . '/config.php', '<?php return ' . var_export($config + ['store' => 'file', 'storeDir' => $dir . '/store'], true) . ';');
    $port = 18000 + mt_rand(0, 999);
    $cmd = sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
        escapeshellarg($dir . '/config.php'), escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__) . '/bootstrap.php'),
        $port, escapeshellarg($dir . '/docroot'));
    $proc = proc_open($cmd, [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body(function (string $method, string $uri, array $headers = []) use ($port): array {
            $h = '';
            foreach ($headers as $k => $v) {
                $h .= "$k: $v\r\n";
            }
            $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'timeout' => 10]]);
            $body = @file_get_contents("http://127.0.0.1:$port$uri", false, $ctx);
            $status = 0;
            $retry = null;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                }
                if (stripos($line, 'Retry-After:') === 0) {
                    $retry = (int) trim(substr($line, 12));
                }
            }
            return ['status' => $status, 'body' => (string) $body, 'json' => json_decode((string) $body, true), 'retry' => $retry];
        });
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

return [
    'protect(): passes, marks, rejects, strips and throttles' => function (): void {
        if (!function_exists('proc_open')) {
            return;
        }
        withServer([
            'trustedProxies' => ['10.9.9.9'],
            'cacheable' => ['query' => ['page']],
            'budgets' => ['requests' => ['limit' => 20, 'window' => 60]],
            'exempt' => ['ips' => []],
        ], function (callable $get): void {
            $r = $get('GET', '/page');
            same(200, $r['status'], 'a page');
            same('allow', $r['json']['shield'] ?? null);
            same(true, $r['json']['cacheable'] ?? null);

            same('allow-uncached', $get('GET', '/page?utm_source=x')['json']['shield'] ?? null, 'unknown parameter: uncached');
            same(404, $get('GET', '/index.php/.env')['status'], 'scanner path');
            same(405, $get('DELETE', '/page')['status'], 'method');
            same(400, $get('GET', '/index.php/a/%2e%2e/b')['status'], 'traversal');

            $r = $get('GET', '/page', ['X-Forwarded-For' => '6.6.6.6']);
            truthy(is_array($r['json']) && array_key_exists('xff', $r['json']), 'the application answered');
            same(null, $r['json']['xff'], 'forged X-Forwarded-For removed before the application');

            $last = null;
            for ($i = 0; $i < 25; $i++) {
                $last = $get('GET', '/page');
            }
            same(429, $last['status'], 'flood throttled');
            truthy(($last['retry'] ?? 0) >= 1, 'Retry-After sent');
        });
    },
];
