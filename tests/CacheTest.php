<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Cache\CachePlugin;
use CjwNetwork\RequestShield\Cache\FileCache;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;

/**
 * The HTTP cache as a plugin (0031 G.2, RSF04-03): public answers of the
 * addresses a cache may keep, answered before the application -- never a
 * page that may be someone's own; nothing at all without set http-cache on.
 */

function cacheDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-cache-' . getmypid() . '-' . mt_rand();
    mkdir($dir);
    return $dir;
}

function cacheSettings(string $dir, string $rules): Settings
{
    file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\n$rules");
    return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
}

function cacheReq(string $uri, array $headers = [], string $method = 'GET'): Request
{
    $server = ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method, 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '198.51.100.7', 'HTTPS' => 'on'];
    foreach ($headers as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }
    return Request::fromServer($server);
}

return [
    'RSF04-03 without set http-cache on there is no cache: no plugin, no handler -- a request pays nothing' => function (): void {
        $dir = cacheDir();
        try {
            $off = cacheSettings($dir, '');
            truthy(!in_array(CachePlugin::class, $off->plugins, true) && ($off->hooks['handler'] ?? []) === [], 'off: nothing to ask');
            $on = cacheSettings($dir, "set http-cache on\n");
            truthy(in_array(CachePlugin::class, $on->plugins, true) && in_array(CachePlugin::class, $on->hooks['handler'] ?? [], true), 'on: the plugin, as a handler');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF04-03 what is kept: 200, 301, 308, public, no cookie set, no Vary but encoding, within its size -- for its own max-age, else the ttl; expired is gone; purge by path' => function (): void {
        $dir = cacheDir();
        try {
            $s = cacheSettings($dir, "set http-cache on\nset http-cache-ttl 2m\nset http-cache-max-object 1K\n");
            $p = new CachePlugin($s);
            $c = new FileCache("$dir/c");
            $ok = ['Content-Type: text/html', 'X-RS: allow', 'Set-Cookie-Not: x'];
            truthy($p->keep($c, 'https://www.example.org/a', 200, $ok, 'A'), 'a plain page');
            foreach ([[404, $ok, 'B', '404'], [200, [...$ok, 'Set-Cookie: s=1'], 'B', 'a cookie set'], [200, [...$ok, 'Cache-Control: private'], 'B', 'private'],
                [200, [...$ok, 'Cache-Control: no-store'], 'B', 'no-store'], [200, [...$ok, 'Vary: Cookie'], 'B', 'Vary: Cookie'], [200, $ok, str_repeat('x', 1025), 'too large'],
                [200, [...$ok, 'Cache-Control: max-age=0'], 'B', 'max-age=0'], [200, [...$ok, 'Vary: Accept-Encoding', 'Vary: Cookie'], 'B', 'a second Vary line'],
                [200, [...$ok, 'Cache-Control: public', 'Cache-Control: private'], 'B', 'a second Cache-Control line'], [200, [...$ok, 'Content-Encoding: gzip'], 'B', 'gzip the application made'],
                [200, [...$ok, 'Pragma: no-cache'], 'B', 'Pragma: no-cache'], [200, [...$ok, 'Expires: Thu, 01 Jan 1970 00:00:00 GMT'], 'B', 'an Expires gone by'],
                [200, [...$ok, "Content-Disposition: attachment; filename=\"\xe4.txt\""], 'B', 'a header that is no UTF-8']] as [$status, $h, $body, $why]) {
                truthy(!$p->keep($c, 'https://www.example.org/b', $status, $h, $body), "not kept: $why");
            }
            truthy($p->keep($c, 'https://www.example.org/v', 200, [...$ok, 'Vary: Accept-Encoding', 'Cache-Control: public, s-maxage=10, max-age=99'], 'V'), 'Vary on encoding only');
            $a = $c->get('https://www.example.org/a', microtime(true));
            same(['A', ['Content-Type: text/html', 'Set-Cookie-Not: x']], [$a['body'] ?? null, $a['headers'] ?? null], 'the headers of one answer dropped (X-RS)');
            same(120, ($a['expires'] ?? 0) - ($a['stored'] ?? 0), 'the ttl when the answer says nothing');
            $v = $c->get('https://www.example.org/v', microtime(true));
            same(10, ($v['expires'] ?? 0) - ($v['stored'] ?? 0), 's-maxage first');
            same(null, $c->get('https://www.example.org/v', microtime(true) + 11), 'expired: gone');
            $c->put('https://www.example.org/news/1', 200, [], 'n', 60, microtime(true));
            $c->put('https://www.example.org/shop/1', 200, [], 's', 60, microtime(true));
            $c->put('https://www.example.org/news%3Fx', 200, [], 'q', 60, microtime(true), '/news%3Fx');
            same(2, $c->purge('/news'), 'purge below a path, by the path kept with the answer');
            same(2, $c->stats()['entries'], 'a and shop/1 are left (v expired above)');
            same(1, $c->purge(null, microtime(true) + 90), 'expired: what has run out (shop/1, 60 s; a has 120 s)');
            same(1, $c->stats()['entries'], 'a is left');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF04-03 the handler answers a kept page before the application, with Age and its ETag -- never a request with a session, a token, a form, or one the rules made uncacheable' => function (): void {
        $dir = cacheDir();
        try {
            $s = cacheSettings($dir, "set http-cache on\nset http-cache-hosts www.example.org\n");
            $p = new CachePlugin($s);
            $c = new FileCache("$dir/store/http-cache");
            $c->put(cacheReq('/a')->cacheKey(), 200, ['Content-Type: text/html', 'ETag: "e1"'], 'kept', 60, microtime(true) - 5);
            $r = $p->handle(cacheReq('/a'), Decision::allow());
            truthy($r !== null && $r->body === 'kept' && in_array('X-RS-Cache: hit', $r->headers, true) && preg_grep('/^Age: [5-9]$/', $r->headers) !== [], 'a hit: ' . json_encode($r));
            same(304, $p->handle(cacheReq('/a', ['If-None-Match' => '"e1"']), Decision::allow())->status ?? 0, 'its ETag: 304');
            same('', $p->handle(cacheReq('/a', [], 'HEAD'), Decision::allow())->body ?? 'x', 'HEAD: no body');
            same(null, $p->handle(cacheReq('/a', ['Cookie' => 'session=1']), Decision::allow()), 'a session cookie: the page may be someone\'s own');
            truthy($p->handle(cacheReq('/a', ['Cookie' => '_ga=GA1.2; rsp=2.x']), Decision::allow()) !== null, 'analytics and the pass: harmless');
            same(null, $p->handle(cacheReq('/a', ['Authorization' => 'Bearer x']), Decision::allow()), 'a token');
            same(null, $p->handle(cacheReq('/a', [], 'POST'), Decision::allow()), 'a form');
            same(null, $p->handle(cacheReq('/a'), Decision::allowUncached('query parameter')), 'the rules said: not for a cache');
            same(null, $p->handle(cacheReq('/a', ['Host' => 'www.example.org:1337']), Decision::allow()), 'a port the list does not name: links built from it must not be kept');
            same(null, $p->handle(cacheReq('/a', ['Host' => 'made.up.example']), Decision::allow()), 'a made-up name');
            same(null, (new CachePlugin(cacheSettings($dir, "set http-cache on\n")))->handle(cacheReq('/a'), Decision::allow()), 'no http-cache-hosts: nothing');
            same(null, $p->handle(cacheReq('/a?x=1&x=2'), Decision::allow()), 'a parameter twice: PHP takes the last, the key sorts');
            same(null, $p->handle(cacheReq('/a%2Fb'), Decision::allow()), 'an encoded / in the path');
            $c->put(cacheReq('/r')->cacheKey(), 301, ['Location: /x', 'ETag: "e2"'], '', 60, microtime(true));
            same(301, $p->handle(cacheReq('/r', ['If-None-Match' => '"e2"']), Decision::allow())->status ?? 0, 'a kept redirect stays a redirect, ETag or not');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF04-03 end to end: the second request is answered from the cache and the site does not run; a made-up parameter, a session and a page that sets a cookie never are; purge empties it' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = cacheDir();
        mkdir("$dir/docroot");
        file_put_contents("$dir/docroot/index.php", '<?php
            file_put_contents(__DIR__ . "/../runs", "x", FILE_APPEND);
            if (strpos($_SERVER["REQUEST_URI"], "/account") !== false) { setcookie("session", "1"); }
            header("Cache-Control: public, max-age=60");
            if (strpos($_SERVER["REQUEST_URI"], "/clean") !== false) { echo "junk"; ob_clean(); echo "real " . hrtime(true); return; }
            if (strpos($_SERVER["REQUEST_URI"], "/cut") !== false) { echo "part "; while (ob_get_level() > 0) { ob_end_flush(); } echo "rest " . hrtime(true); return; }
            if (strpos($_SERVER["REQUEST_URI"], "/big") !== false) { for ($i = 0; $i < 40; $i++) { echo str_repeat("x", 65536); flush(); } echo hrtime(true); return; }
            header("Cache-Control: public, max-age=60");
            echo "page " . $_SERVER["REQUEST_URI"] . " " . hrtime(true);');
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset http-cache on\ncache-query page\n");
        $port = freePort();
        file_put_contents("$dir/site.rules", "set http-cache-hosts 127.0.0.1:$port\n", FILE_APPEND);
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
            escapeshellarg("$dir/site.rules"), serverPhp(), escapeshellarg(rsEntry()), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri, array $headers = []) use ($port): array {
                $h = '';
                foreach ($headers as $k => $v) {
                    $h .= "$k: $v\r\n";
                }
                $out = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['header' => $h, 'ignore_errors' => true, 'timeout' => 10]]));
                return [implode("\n", $http_response_header ?? []), $out];
            };
            $runs = static fn (): int => strlen((string) @file_get_contents("$dir/runs"));
            [$h1, $b1] = $get('/index.php?page=2');
            [$h2, $b2] = $get('/index.php?page=2');
            truthy(strpos($h1, 'X-RS-Cache: miss') !== false && strpos($h2, 'X-RS-Cache: hit') !== false && $b1 === $b2 && $runs() === 1, "the site ran once: $h2 / " . $runs());
            [$h3, $b3] = $get('/index.php?made=up');
            [, $b4] = $get('/index.php?made=up');
            truthy(strpos($h3, 'X-RS-Cache') === false && $b3 !== $b4, 'a made-up parameter: never kept');
            [, $b5] = $get('/index.php?page=2', ['Cookie' => 'session=abc']);
            truthy($b5 !== $b1, 'with a session: the site\'s own answer');
            $get('/index.php/account');
            [$h6] = $get('/index.php/account');
            truthy(strpos($h6, 'X-RS-Cache: hit') === false, 'a page that sets a cookie: never kept');
            [, $c1] = $get('/index.php/clean');
            [$c2h, $c2] = $get('/index.php/clean');
            truthy(strpos($c1, 'junk') === false && strpos($c2h, 'X-RS-Cache: hit') === false && $c1 !== $c2, "what ob_clean() threw away: never kept, nor is the answer ($c2h)");
            $get('/index.php/cut');
            [$k2h, $k2] = $get('/index.php/cut');
            truthy(strpos($k2h, 'X-RS-Cache: hit') === false && strpos($k2, 'part rest') === 0, "an answer ended before the script: never kept ($k2h)");
            $get('/index.php/big');
            [$g2h, $g2] = $get('/index.php/big');
            truthy(strpos($g2h, 'X-RS-Cache: hit') === false && strlen($g2) > 40 * 65536, 'larger than http-cache-max-object: never kept, and sent whole');
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' cache ' . escapeshellarg("$dir/site.rules") . ' purge 2>&1', $out, $code);
            truthy($code === 0 && strpos(implode("\n", $out), 'removed') === 0, implode("\n", $out));
            [$h7] = $get('/index.php?page=2');
            truthy(strpos($h7, 'X-RS-Cache: miss') !== false, 'after purge: asked again');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
