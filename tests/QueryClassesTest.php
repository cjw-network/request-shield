<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

use CjwNetwork\RequestShield\Http;

/*
 * Proposal 0048 end to end: the three kinds of query parameters -- in the key
 * (cache-query), ignored (cache-ignore) and unknown (cache-unknown-query
 * hit-only) -- as the application and the HTTP cache get them.
 */

return [
    'RSF04-01 end to end (0048): an ignored parameter is taken out before the cache and the application, the page kept without it; an unknown one is answered from the page without it and never kept; an attack in an ignored parameter is still refused' => function (): void {
        needsPlugins();                 // the HTTP cache is a plugin
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = sys_get_temp_dir() . '/rs-qc-' . getmypid() . '-' . mt_rand();
        mkdir("$dir/docroot", 0777, true);
        file_put_contents("$dir/docroot/index.php", '<?php
            file_put_contents(__DIR__ . "/../runs", "x", FILE_APPEND);
            header("Cache-Control: public, max-age=600");
            echo json_encode(["get" => $_GET, "req" => $_REQUEST, "qs" => $_SERVER["QUERY_STRING"] ?? "", "uri" => $_SERVER["REQUEST_URI"] ?? "",
                "shield" => $_SERVER["REQUEST_SHIELD"] ?? null, "ignored" => $_SERVER["REQUEST_SHIELD_IGNORED"] ?? null,
                "lookup" => $_SERVER["REQUEST_SHIELD_CACHE_LOOKUP"] ?? null, "t" => hrtime(true)]);');
        $port = freePort();
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset http-cache on\nset http-cache-hosts 127.0.0.1:$port\n"
            . "cache-query page\ncache-ignore @tracking pa*\nset cache-unknown-query hit-only\ninclude @attacks\nset debug-header on\n");
        // php -S prepends nothing to its router: the router loads the shield, as auto_prepend_file would.
        file_put_contents("$dir/router.php", '<?php require ' . var_export(rsEntry(), true) . '; require __DIR__ . "/docroot/index.php";');
        $web = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d apc.enable_cli=1 -S 127.0.0.1:%d %s > %s 2>&1',
            escapeshellarg("$dir/site.rules"), serverPhp(), $port, escapeshellarg("$dir/router.php"), escapeshellarg("$dir/web.log")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri, array $headers = []) use ($port): array {
                $r = Http::get("http://127.0.0.1:$port$uri", $headers, 5);
                $page = json_decode((string) ($r['body'] ?? ''), true);
                return [(int) ($r['status'] ?? 0), strtolower((string) ($r['headers']['x-rs-cache'] ?? '')), is_array($page) ? $page : []];
            };
            $runs = static fn (): int => strlen((string) @file_get_contents("$dir/runs"));

            // Ignored: taken out for the application, a copy in REQUEST_SHIELD_IGNORED; kept as the page without it.
            [$status, $cache, $page] = $get('/news?page=2&utm_source=nl&fbclid=abc');
            same(200, $status, 'answered');
            same([['page' => '2'], 'page=2', '/news?page=2', 'allow'], [$page['get'] ?? null, $page['qs'] ?? null, $page['uri'] ?? null, $page['shield'] ?? null],
                'the application sees the address without the ignored parameters, may be kept');
            same(['utm_source' => 'nl', 'fbclid' => 'abc'], json_decode((string) ($page['ignored'] ?? ''), true), 'a copy of them for tracking on the server');
            [, $cache] = $get('/news?page=2&utm_source=other&utm.medium=mail');
            truthy($cache === 'hit' && $runs() === 1, 'another campaign, utm.medium as PHP names it: the same page, from the cache (' . $cache . ')');

            // A name cache-query names stays in the key, though "pa*" matches it: page 3 is not page 2.
            [, $cache, $page] = $get('/news?page=3');
            truthy($cache !== 'hit' && ($page['get'] ?? null) === ['page' => '3'], 'cache-query wins over a cache-ignore glob (' . $cache . ')');
            // A cookie of the same name: $_REQUEST as PHP makes it without the parameter (request_order GP: no cookies).
            [, , $page] = $get('/news?page=4&utm_source=nl', ['Cookie' => 'utm_source=cookie']);
            same(['page' => '4'], $page['req'] ?? null, '$_REQUEST without the ignored parameter, though a cookie has its name');
            // "utm[x" is PHP's utm_x: taken out of $_GET and the query alike.
            [, , $page] = $get('/news?page=5&utm[x=1');
            same([['page' => '5'], 'page=5'], [$page['get'] ?? null, $page['qs'] ?? null], 'a "[" without "]": the name PHP makes, ignored everywhere');

            // Unknown, hit-only: the page without it answers; on a miss the application runs, nothing is kept.
            $before = $runs();
            [, $cache] = $get('/news?page=2&x=7');
            truthy($cache === 'hit' && $runs() === $before, 'an unknown parameter: the kept page without it (' . $cache . ')');
            [$status, $cache, $page] = $get('/about?x=7');
            same([200, ['x' => '7'], 'allow-uncached', '/about'], [$status, $page['get'] ?? null, $page['shield'] ?? null, $page['lookup'] ?? null],
                'a miss: the application sees the parameter, may not keep it, may answer from /about');
            [, $cache] = $get('/about');
            truthy($cache !== 'hit' && $runs() === $before + 2, 'nothing was kept for /about?x=7 (' . $cache . ')');
            [, $cache] = $get('/about?x=9');
            truthy($cache === 'hit' && $runs() === $before + 2, 'now /about is kept: /about?x=9 answered from it (' . $cache . ')');

            // An attack in an ignored parameter: the rules saw the whole query.
            [$status] = $get('/news?utm_source=' . rawurlencode("' UNION SELECT password FROM users--"));
            same(403, $status, 'an attack in utm_source: refused, as without cache-ignore');
        } finally {
            proc_terminate($web);
            proc_close($web);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },

    'RSF02-05 end to end: query drop in an area -- made-up parameters and wrong types leave the query, the kept page answers; strict elsewhere; an attack is refused' => function (): void {
        needsPlugins();                 // the HTTP cache is a plugin
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = sys_get_temp_dir() . '/rs-qd-' . getmypid() . '-' . mt_rand();
        mkdir("$dir/docroot", 0777, true);
        file_put_contents("$dir/docroot/index.php", '<?php
            file_put_contents(__DIR__ . "/../runs", "x", FILE_APPEND);
            header("Cache-Control: public, max-age=600");
            echo json_encode(["get" => $_GET, "qs" => $_SERVER["QUERY_STRING"] ?? "", "uri" => $_SERVER["REQUEST_URI"] ?? "", "ignored" => $_SERVER["REQUEST_SHIELD_IGNORED"] ?? null]);');
        $port = freePort();
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset http-cache on\nset http-cache-hosts 127.0.0.1:$port\n"
            . "query page int\nquery strict\nmatch /magazin/** {\n  query drop\n}\ncache-query page\ninclude @attacks\nset debug-header on\n");
        file_put_contents("$dir/router.php", '<?php require ' . var_export(rsEntry(), true) . '; require __DIR__ . "/docroot/index.php";');
        $web = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d apc.enable_cli=1 -S 127.0.0.1:%d %s > %s 2>&1',
            escapeshellarg("$dir/site.rules"), serverPhp(), $port, escapeshellarg("$dir/router.php"), escapeshellarg("$dir/web.log")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri) use ($port): array {
                $r = Http::get("http://127.0.0.1:$port$uri", [], 5);
                $page = json_decode((string) ($r['body'] ?? ''), true);
                return [(int) ($r['status'] ?? 0), strtolower((string) ($r['headers']['x-rs-cache'] ?? '')), is_array($page) ? $page : []];
            };
            $runs = static fn (): int => strlen((string) @file_get_contents("$dir/runs"));
            [$status, $cache, $page] = $get('/magazin/1?id=xyz&page=2x');
            same([200, [], '', '/magazin/1'], [$status, $page['get'] ?? null, $page['qs'] ?? null, $page['uri'] ?? null], 'the application sees the address without them');
            same(['id' => 'xyz', 'page' => '2x'], json_decode((string) ($page['ignored'] ?? ''), true), 'a copy in REQUEST_SHIELD_IGNORED');
            [$status, $cache] = $get('/magazin/1?cb=' . mt_rand());
            truthy($status === 200 && $cache === 'hit' && $runs() === 1, 'a cache buster: the kept page (' . $cache . ')');
            [, , $page] = $get('/magazin/1?page=2&cb=1');
            same([['page' => '2'], '/magazin/1?page=2'], [$page['get'] ?? null, $page['uri'] ?? null], 'a known parameter of its type stays');
            [$status] = $get('/news?id=xyz');
            same(404, $status, 'outside the area: query strict');
            [$status] = $get('/magazin/1?q=' . rawurlencode("' UNION SELECT password FROM users--"));
            same(403, $status, 'an attack in a dropped parameter: refused');
        } finally {
            proc_terminate($web);
            proc_close($web);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
