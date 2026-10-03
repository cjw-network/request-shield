<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Http;

/**
 * The one HTTPS GET of the updates (feeds, crawler lists): file_get_contents
 * where allow_url_fopen is on, curl where it is off, a clear word where
 * neither can (ADR 0013). A small server of this test's own answers.
 */

/** @param callable(string): void $body gets the server's base URL */
function withHttpServer(callable $body): void
{
    $dir = sys_get_temp_dir() . '/rs-http-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    file_put_contents("$dir/router.php", '<?php
        $p = $_SERVER["REQUEST_URI"];
        if ($p === "/list.txt") { header("ETag: \"v1\""); header("X-Got-Agent: " . ($_SERVER["HTTP_USER_AGENT"] ?? "")); header("X-Got-Match: " . ($_SERVER["HTTP_IF_NONE_MATCH"] ?? "")); echo "203.0.113.0/24\n198.51.100.0/24\n"; return; }
        if ($p === "/moved") { header("Location: /list.txt", true, 302); return; }
        if ($p === "/big") { echo str_repeat("x", 5000); return; }
        http_response_code(500); echo "boom";');
    $port = freePort();
    $proc = proc_open(sprintf('exec %s -S 127.0.0.1:%d %s > /dev/null 2>&1', escapeshellarg(PHP_BINARY), $port, escapeshellarg("$dir/router.php")), [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body("http://127.0.0.1:$port");
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

/** Http::get() in a PHP with the given ini settings; returns what it printed (json). */
function httpIn(string $ini, string $url): mixed
{
    $code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . '; echo json_encode(["get" => \CjwNetwork\RequestShield\Http::get(' . var_export($url, true) . ', ["If-None-Match" => "\"v1\""], 5, 1000, "rs-test"), "offline" => \CjwNetwork\RequestShield\Http::offline()]);';
    exec(escapeshellarg(PHP_BINARY) . " $ini -r " . escapeshellarg($code) . ' 2>&1', $out, $exit);
    return json_decode(implode('', $out), true) ?? ['raw' => implode("\n", $out), 'exit' => $exit];
}

return [
    'RSF1.3 Http::get: status, body, the headers of the last answer, redirects, at most N bytes, the User-Agent' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withHttpServer(function (string $base): void {
            $r = Http::get("$base/list.txt", ['If-None-Match' => '"v1"'], 5, 0, 'rs-test');
            same(200, $r['status'] ?? null);
            same("203.0.113.0/24\n198.51.100.0/24\n", $r['body'] ?? null);
            same(['"v1"', 'rs-test', '"v1"'], [$r['headers']['etag'] ?? null, $r['headers']['x-got-agent'] ?? null, $r['headers']['x-got-match'] ?? null], 'headers out and in');
            $r = Http::get("$base/moved", [], 5);
            same([200, '"v1"'], [$r['status'] ?? null, $r['headers']['etag'] ?? null], 'the redirect followed, the last answer\'s headers');
            same(1000, strlen((string) (Http::get("$base/big", [], 5, 1000)['body'] ?? '')), 'cut at the limit');
            same(500, Http::get("$base/nope", [], 5)['status'] ?? null, 'an error is an answer');
            same(null, Http::get('http://127.0.0.1:1/x', [], 1), 'no server: no answer');
            same(null, Http::offline(), 'this PHP can fetch');
        });
    },
    'RSF1.3 allow_url_fopen off: curl takes over, the same answer; without curl too, a clear word on what to do' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withHttpServer(function (string $base): void {
            $viaCurl = httpIn('-d allow_url_fopen=0', "$base/moved");
            if (function_exists('curl_init')) {
                same([200, "203.0.113.0/24\n198.51.100.0/24\n", '"v1"', true], [$viaCurl['get']['status'] ?? null, $viaCurl['get']['body'] ?? null, $viaCurl['get']['headers']['etag'] ?? null, array_key_exists('offline', $viaCurl) && $viaCurl['offline'] === null],
                    'curl: the redirect, the body, the headers, not offline: ' . json_encode($viaCurl));
                $big = httpIn('-d allow_url_fopen=0', "$base/big");
                same([200, 1000], [$big['get']['status'] ?? null, strlen((string) ($big['get']['body'] ?? ''))], 'curl: cut at the limit, the answer kept -- not an empty 200: ' . substr(json_encode($big) ?: '', 0, 200));
            }
            $neither = httpIn('-d allow_url_fopen=0 -d disable_functions=curl_init', "$base/list.txt");
            truthy(array_key_exists('get', $neither) && $neither['get'] === null, 'nothing fetched: ' . json_encode($neither));
            truthy(is_string($neither['offline'] ?? null) && strpos($neither['offline'], 'allow_url_fopen is off and curl is not loaded') !== false
                && strpos($neither['offline'], 'copy its store-dir/feeds') !== false, 'the word on what to do instead: ' . json_encode($neither));
        });
    },
];
