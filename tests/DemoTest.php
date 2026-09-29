<?php

declare(strict_types=1);

/**
 * examples/demo, run as its README says (PHP's built-in server with its
 * router), so the example cannot quietly stop working.
 */

/**
 * Starts the demo, runs $body with a request function, stops it. $prefix ''
 * runs it as its README says (router.php, at the root); '/examples/demo/index.php'
 * with the repository as document root and no rewrite rules, as in a
 * subdirectory of a web server. The request function adds the prefix itself.
 */
function withDemo(callable $body, string $prefix = ''): void
{
    $var = sys_get_temp_dir() . '/rshield-demo-' . getmypid() . '-' . mt_rand();
    mkdir($var, 0700, true);
    $port = 19000 + mt_rand(0, 999);
    $root = dirname(__DIR__);
    $cmd = sprintf('REQUEST_SHIELD_DEMO_VAR=%s exec %s -S 127.0.0.1:%d %s > /dev/null 2>&1',
        escapeshellarg($var), escapeshellarg(PHP_BINARY), $port,
        $prefix === '' ? escapeshellarg($root . '/examples/demo/router.php') : '-t ' . escapeshellarg($root));
    $proc = proc_open($cmd, [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body(function (string $method, string $uri, array $headers = [], string $content = '') use ($port, $prefix): array {
            $h = '';
            foreach ($headers as $k => $v) {
                $h .= "$k: $v\r\n";
            }
            $opts = ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0];
            if ($content !== '') {
                $opts['content'] = $content;
                $opts['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            }
            $body = @file_get_contents("http://127.0.0.1:$port$prefix$uri", false, stream_context_create(['http' => $opts]));
            $status = 0;
            $shield = null;
            $cookies = [];
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                }
                if (stripos($line, 'X-Request-Shield:') === 0) {
                    $shield = trim(substr($line, 17));
                }
                if (preg_match('#^Set-Cookie:\s*([^=]+)=([^;]*)#i', $line, $m)) {
                    $cookies[$m[1]] = $m[2];
                }
            }
            $location = null;
            foreach ($http_response_header ?? [] as $line) {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
            }
            return ['status' => $status, 'body' => (string) $body, 'shield' => $shield, 'cookies' => $cookies, 'location' => $location];
        });
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($var));
    }
}

$examples = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withDemo(function (callable $get) use ($prefix): void {
            $r = $get('GET', '/');
            same(200, $r['status'], 'front page');
            truthy(strpos($r['body'], 'href="' . $prefix . '/challenge"') !== false, 'links start where the demo lives');
            truthy(strpos($r['body'], 'action="' . $prefix . '/page/form"') !== false, 'so does the form');
            same('allow', $r['shield']);
            truthy(strpos($r['body'], 'request-shield demo') !== false, 'the page itself');
            same('allow', $get('GET', '/page/about')['shield']);
            same('allow-uncached query parameter', $get('GET', '/?utm_source=newsletter')['shield']);
            same('allow-uncached path not cacheable', $get('GET', '/random/abc')['shield']);
            same(404, $get('GET', '/.env')['status'], 'scanner path');
            same(400, $get('GET', '/files/%2e%2e/secret')['status'], 'traversal');
            $r = $get('POST', '/page/form', [], 'message=' . rawurlencode('<b>hi</b>'));
            same(200, $r['status'], 'form');
            truthy(strpos($r['body'], '&lt;b&gt;hi&lt;/b&gt;') !== false, 'the posted text, escaped');
            same('allow-uncached method', $r['shield']);
            $r = $get('GET', '/reset');
            same(303, $r['status'], 'reset redirects');
            same($prefix . '/', $r['location'], 'back to the demo\'s front page');
            // PHP deletes a cookie as "name=deleted" with an expiry in the past.
            truthy(in_array($r['cookies']['rs_pass'] ?? null, ['', 'deleted'], true), 'the pass cookie is deleted');
        }, $prefix);
};

$challenge = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        withDemo(function (callable $get): void {
            $r = $get('GET', '/challenge');
            same(429, $r['status'], 'challenged at once, whatever the budget');
            same('challenge always', $r['shield']);
            truthy(preg_match('/var RS=(\{.*?\});\(function/s', $r['body'], $m) === 1, 'the challenge page');
            $rs = json_decode($m[1], true);
            [$payload] = solveInNode($rs['c']);
            $r = $get('GET', '/challenge', ['Cookie' => $rs['cookie'] . '=' . $payload]);
            same(200, $r['status'], 'solved');
            truthy(strpos($r['body'], 'You passed the browser check') !== false, 'the page behind the check');
            $pass = $r['cookies']['rs_pass'] ?? '';
            truthy($pass !== '', 'pass cookie');
            same(200, $get('GET', '/challenge', ['Cookie' => 'rs_pass=' . $pass])['status'], 'with the pass: straight through');
        }, $prefix);
};

$budget = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withDemo(function (callable $get): void {
            for ($i = 1; $i <= 20; $i++) {
                same(200, $get('GET', '/')['status'], "request $i");
            }
            $r = $get('GET', '/');
            same(429, $r['status'], 'request 21');
            same('challenge requests', $r['shield']);
        }, $prefix);
};

$sub = '/examples/demo/index.php';
return [
    'the demo: every example link does what the page says' => fn () => $examples(''),
    'the demo: /challenge is always checked; solved, it opens' => fn () => $challenge(''),
    'the demo: past 20 requests a minute the check appears on any page' => fn () => $budget(''),
    'the demo in a subdirectory, without rewrite rules: the same' => fn () => $examples($sub),
    'the demo in a subdirectory: /challenge is always checked' => fn () => $challenge($sub),
    'the demo in a subdirectory: the budget' => fn () => $budget($sub),
];
