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
    $port = freePort();
    $cmd = sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
        escapeshellarg($dir . '/config.php'), serverPhp(), escapeshellarg(rsEntry()),
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
            $cookies = [];
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                }
                if (stripos($line, 'Retry-After:') === 0) {
                    $retry = (int) trim(substr($line, 12));
                }
                if (preg_match('#^Set-Cookie:\s*([^=]+)=([^;]*)#i', $line, $m)) {
                    $cookies[$m[1]] = $m[2];
                }
            }
            return ['status' => $status, 'body' => (string) $body, 'json' => json_decode((string) $body, true), 'retry' => $retry, 'cookies' => $cookies];
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
            skip('no proc_open');
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
    'protect(): flood -> challenge page -> the script solves it -> pass cookie -> through' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        withServer([
            'challenge' => ['secret' => str_repeat('e2e-secret', 5), 'searchEngines' => false, 'difficulty' => ['min' => 20000, 'max' => 20000]],
            'budgets' => ['requests' => ['limit' => 1000, 'window' => 60, 'challengeAt' => 3]],
            'exempt' => ['ips' => []],
        ], function (callable $get): void {
            $ua = ['User-Agent' => 'Mozilla/5.0 e2e'];
            for ($i = 0; $i < 3; $i++) {
                same(200, $get('GET', '/page', $ua)['status'], 'within the threshold');
            }
            $r = $get('GET', '/page', $ua);
            same(429, $r['status'], 'past the threshold: challenged');
            truthy(preg_match('/var RS=(\{.*?\});\(function/s', $r['body'], $m) === 1, 'the challenge page');
            $rs = json_decode($m[1], true);
            same(20000, $rs['c']['maxnumber']);

            [$payload] = solveInNode($rs['c']);
            truthy(is_string($payload), 'the script found the number');

            $r = $get('GET', '/page', $ua + ['Cookie' => $rs['cookie'] . '=' . $payload]);
            same(200, $r['status'], 'solved: the page itself');
            same('allow-uncached', $r['json']['shield'] ?? null, 'this answer is not cached');
            truthy(($r['cookies']['rsp'] ?? '') !== '', 'pass cookie set');
            same('', $r['cookies']['rss'] ?? null, 'solution cookie removed');

            $pass = $r['cookies']['rsp'];
            for ($i = 0; $i < 5; $i++) {
                $r = $get('GET', '/page', $ua + ['Cookie' => 'rsp=' . $pass]);
            }
            same(200, $r['status'], 'with the pass cookie: through');
            same('allow', $r['json']['shield'] ?? null);
            same(429, $get('GET', '/page', ['User-Agent' => 'another browser', 'Cookie' => 'rsp=' . $pass])['status'], 'the pass is bound to the User-Agent');
            same(429, $get('GET', '/page', $ua + ['Cookie' => $rs['cookie'] . '=' . $payload])['status'], 'the same solution again: a new challenge');
        });
    },
    'protect(): content rules refuse an attack pattern before the application runs' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withServer([
            'contentRules' => [
                ['target' => 'query', 'patterns' => ['#\bunion\s+select\b#i']],
                ['target' => 'header:user-agent', 'patterns' => ['#\bsqlmap\b#i']],
            ],
        ], function (callable $get): void {
            same(403, $get('GET', '/index.php?id=1+union+select+2')['status'], 'an attack in the query');
            same(403, $get('GET', '/index.php', ['User-Agent' => 'sqlmap/1.7'])['status'], 'an attack tool');
            same(403, $get('GET', '/index.php?id=1%20union%2520select%202')['status'], 'double-encoded does not help');
            $r = $get('GET', '/index.php?id=1');
            same(200, $r['status'], 'a clean request reaches the application');
            same('allow', $r['json']['shield'] ?? null);
        });
    },
];
