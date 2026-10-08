<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Stats\Report\StatsReport;
use CjwNetwork\RequestShield\Stats\Stats;
use CjwNetwork\RequestShield\Stats\StatsExtension;
use CjwNetwork\RequestShield\Stats\StatsPlugin;
use CjwNetwork\RequestShield\Store\ApcuStore;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** How long the site took, in the statistics (proposal 0046, step 1: stats … times). */

function timesDir(): string
{
    $dir = sys_get_temp_dir() . '/rshield-times-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    return $dir;
}

function timesReq(string $uri, string $ua = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/136.0', string $method = 'GET'): Request
{
    return Request::fromServer(['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method, 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => $ua]);
}

/**
 * One request that goes on to the site, decided and ended as protect() and its shutdown function do --
 * the site having taken $seconds (REQUEST_TIME_FLOAT set back), answering $status with $headers.
 *
 * @param list<string> $headers
 */
function timesRequest(Settings $s, Request $r, float $seconds, int $status, array $headers): void
{
    $shield = new Shield($s, new MemoryStore());
    $plugin = new StatsPlugin($s);
    $seen = new Seen($r, $shield);
    $now = microtime(true);
    $was = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
    $_SERVER['REQUEST_TIME_FLOAT'] = $now - $seconds;
    try {
        $plugin->decided($r, Decision::allow(), null, $seen, $now, true);
        $plugin->ended($r, $status, $headers, $seen, $now);
    } finally {
        $_SERVER['REQUEST_TIME_FLOAT'] = $was;
    }
}

/** @return array<string, int> today's counters */
function timesDay(Settings $s): array
{
    return Stats::of($s)->read(gmdate('Ymd'), gmdate('Ymd'))['days'][gmdate('Ymd')] ?? [];
}

return [
    'RSF06-03 times: twelve fixed bands from 1 ms to over 10 s; the median and the slow end read from them' => function (): void {
        same([0, 0, 1, 1, 7, 8, 10, 10, 11], array_map([Stats::class, 'band'], [0, 1000, 1001, 5000, 300000, 500001, 2600000, 10000000, 10000001]), 'the band of a time');
        same(12, count(Stats::BANDS) + 1, 'twelve bands');
        $bands = array_fill(0, 12, 0);
        $bands[7] = 10;                             // ten requests between 250 and 500 ms
        same(375000, StatsReport::percentile($bands, 0.5), 'the median: within its band, by its share');
        $bands[1] = 90;                             // and ninety between 1 and 5 ms (cache hits)
        same([3222, 375000], [StatsReport::percentile($bands, 0.5), StatsReport::percentile($bands, 0.95)], 'median among the fast ones, the slow end among the slow');
        $over = array_fill(0, 12, 0);
        $over[11] = 3;
        same(10000000, StatsReport::percentile($over, 0.5), 'over 10 s: says 10 s');
        same(null, StatsReport::percentile(array_fill(0, 12, 0), 0.5), 'nothing counted: no median');
        same(['40 µs', '0.4 ms', '12 ms', '2.4 s', '1,5 min'], [StatsReport::duration(40), StatsReport::duration(400), StatsReport::duration(12000), StatsReport::duration(2400000), StatsReport::duration(90000000, 'de')], 'in words');
    },
    'RSF06-03 times: what the HTTP cache did -- hit, miss (kept), nostore (asked the site, not to be kept, and why), past (no cache asked)' => function (): void {
        $html = 'Content-Type: text/html; charset=utf-8';
        same(['hit', null], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: hit']), 'from the cache');
        same(['hit', null], StatsPlugin::cacheKind(304, ['X-RS-Cache: hit']), 'a 304 from the cache');
        same(['miss', null], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss']), 'asked the site: the answer may be kept');
        same(['nostore', 'cookie'], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss', 'Set-Cookie: session=1']), 'a cookie: not kept');
        same(['nostore', 'private'], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss', 'Cache-Control: private, max-age=60']), 'private');
        same(['miss', null], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss; role', 'Cache-Control: private, no-cache']), 'a role\'s page: its private, no-cache is the cache\'s own (G.4)');
        same(['nostore', 'cookie'], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss; role', 'Cache-Control: private, no-cache', 'Set-Cookie: s=1']), 'a role\'s page that sets a cookie: not kept');
        same(['nostore', 'vary'], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss', 'Vary: Accept-Language']), 'Vary on the language: not one page');
        same(['miss', null], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss', 'Vary: X-User-Hash']), 'Vary on the role\'s hash: the shield\'s to follow');
        same(['nostore', 'status'], StatsPlugin::cacheKind(404, [$html, 'X-RS-Cache: miss']), 'a page not found');
        same(['nostore', 'vary'], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss', 'Vary: Accept-Language']), 'varies by language');
        same(['past', null], StatsPlugin::cacheKind(200, [$html]), 'no cache asked');
        $dir = timesDir();
        try {
            file_put_contents("$dir/site.rules", "set store-dir $dir/store\nset http-cache on\nset http-cache-ttl 0\n");
            $zero = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same(['nostore', 'ttl'], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss'], $zero), 'http-cache-ttl 0: without a max-age of its own the page is not kept -- the cache\'s own ttl decides');
            same(['miss', null], StatsPlugin::cacheKind(200, [$html, 'X-RS-Cache: miss', 'Cache-Control: max-age=60'], $zero), 'with a max-age of its own it is');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 times: the rule file -- "stats … times" and stats-slow; "stats on" does not time (off by default); a word it does not know' => function (): void {
        $dir = timesDir();
        try {
            file_put_contents("$dir/a.rules", "set stats requests times\nset stats-slow 3s\n");
            $o = StatsExtension::of(Settings::from(RuleFile::read(["$dir/a.rules"])['config']));
            same([['requests', 'times'], 3], [$o['parts'], $o['slow']], 'times named; the slow log from 3 s');
            file_put_contents("$dir/b.rules", "set stats on\n");
            $o = StatsExtension::of(Settings::from(RuleFile::read(["$dir/b.rules"])['config']));
            same([false, 2], [in_array('times', $o['parts'], true), $o['slow']], '"on" counts the six parts, not times; slow log 2 s by default');
            file_put_contents("$dir/c.rules", "set stats requests speed\n");
            $msg = '';
            try {
                RuleFile::read(["$dir/c.rules"]);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
            }
            truthy(strpos($msg, 'times') !== false && strpos($msg, '"speed"') !== false, 'the words it knows, times among them: ' . $msg);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 times: counted per kind of visitor and cache kind, the sum in microseconds, the shield\'s share; a slow request in the slow log without address or query -- files and APCu' => function (): void {
        foreach (ApcuStore::usable() ? ['file', 'apcu'] : ['file'] as $store) {
            $dir = timesDir();
            try {
                $s = Settings::from(['storeDir' => $dir, 'store' => $store, 'ext' => ['stats' => ['enabled' => true, 'parts' => ['requests', 'pages', 'times'], 'flush' => 0]]]);
                $html = 'Content-Type: text/html';
                timesRequest($s, timesReq('/news/a?id=7'), 0.3, 200, [$html, 'X-RS-Cache: miss']);
                timesRequest($s, timesReq('/news/a?id=7'), 0.002, 200, [$html, 'X-RS-Cache: hit']);
                timesRequest($s, timesReq('/cart'), 0.12, 200, [$html, 'X-RS-Cache: miss', 'Set-Cookie: cart=1']);
                timesRequest($s, timesReq('/export?token=secret'), 2.4, 200, ['Content-Type: text/csv']);
                timesRequest($s, timesReq('/', 'python-requests/2.32'), 0.04, 200, [$html]);
                $day = timesDay($s);
                same([1, 1, 1, 1, 1], [$day['rt:people|miss|7'] ?? 0, $day['rt:people|hit|1'] ?? 0, $day['rt:people|nostore|6'] ?? 0, $day['rt:people|past|9'] ?? 0, $day['rt:bots|past|4'] ?? 0],
                    "$store: each in its band, by who and what the cache did: " . json_encode(array_filter($day, static fn (string $k): bool => strncmp($k, 'r', 1) === 0, ARRAY_FILTER_USE_KEY)));
                truthy(($day['rs:people|miss'] ?? 0) >= 300000 && ($day['rs:people|miss'] ?? 0) < 400000, "$store: the sum in microseconds: " . ($day['rs:people|miss'] ?? 0));
                same(1, $day['rn:cookie'] ?? 0, "$store: why a miss was not kept");
                $slow = Stats::of($s)->slowLines(gmdate('Ymd'), gmdate('Ymd'));
                same(1, count($slow), "$store: one request over 2 s: " . implode(' | ', $slow));
                truthy(preg_match('~^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ GET /export 200 24\d\dms [\d.]+MB past people$~', $slow[0] ?? '') === 1, "$store: time, method, path without its query, status, ms, memory, cache, who: " . ($slow[0] ?? ''));
                truthy(strpos(implode("\n", $slow), '203.0.113') === false && strpos(implode("\n", $slow), 'secret') === false, "$store: no address, no query");
                // The report: what the site took, the cache's share and what it saved.
                $t = StatsReport::build($s, null, 1)['times'];
                truthy($t !== null, "$store: a report");
                same([4, 1], [$t['site']['count'] ?? 0, $t['kinds']['hit']['count'] ?? 0], "$store: the site's four, the cache's one");
                same(0.5, $t['hitShare'] ?? null, "$store: of the two the cache could have answered, it answered one");
                truthy(($t['saved'] ?? 0) > 290000 && ($t['saved'] ?? 0) < 400000, "$store: what the hit saved: about a miss's time: " . ($t['saved'] ?? 0));
                same(['cookie' => 1], $t['reasons'] ?? [], "$store: why");
                truthy(isset($t['who']['bots']) && isset($t['who']['people']), "$store: by who came");
                truthy(is_int($t['shield'] ?? null), "$store: the shield's share a request");
                // The page: the card, in German; a path from the slow log escaped.
                timesRequest($s, timesReq('/x<script>'), 2.2, 200, ['Content-Type: text/html']);
                $page = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['view' => 'all', 'lang' => 'de', 'days' => 1]);
                truthy(strpos($page, 'Wie schnell die Website antwortete') !== false && strpos($page, 'Nicht gespeichert, weil: setzt ein Cookie 1') !== false, "$store: the card on the page");
                truthy(strpos($page, 'Langsame Anfragen') !== false && strpos($page, '/x&lt;script&gt;') !== false && strpos($page, '/x<script>') === false, "$store: the slow log's lines, escaped");
            } finally {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    },
    'RSF06-03 times: off by default -- without "times" no band, no sum, no slow log' => function (): void {
        $dir = timesDir();
        try {
            $s = Settings::from(['storeDir' => $dir, 'store' => 'file', 'ext' => ['stats' => ['enabled' => true, 'parts' => ['requests']]]]);
            timesRequest($s, timesReq('/export'), 3.0, 200, ['X-RS-Cache: miss']);
            $day = timesDay($s);
            same([], array_values(array_filter(array_keys($day), static fn (string $k): bool => strncmp($k, 'r', 1) === 0 && $k[1] !== ':')), 'nothing of times: ' . json_encode($day));
            same([], Stats::of($s)->slowLines(gmdate('Ymd'), gmdate('Ymd')), 'no slow log');
            same(null, StatsReport::build($s, null, 1)['times'], 'the report: no times');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 times, the real path: a page the cache keeps (a miss, then a hit), one with a cookie (not kept), a slow one; bin/request-shield stats says how fast' => function (): void {
        if (!function_exists('proc_open') || !function_exists('exec')) {
            skip('no proc_open');
        }
        $dir = timesDir();
        mkdir("$dir/docroot");
        file_put_contents("$dir/docroot/index.php", '<?php $u = $_SERVER["REQUEST_URI"];
            if (strpos($u, "/slow") !== false) { usleep(2200000); }
            if (strpos($u, "/cart") !== false) { setcookie("cart", "1"); }
            header("Cache-Control: public, max-age=60");
            echo "page " . $u;');
        $port = freePort();
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset stats requests pages times\nset http-cache on\nset http-cache-hosts 127.0.0.1:$port\nexempt none\nset debug-header on\n");
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1', escapeshellarg("$dir/site.rules"),
            serverPhp(), escapeshellarg(rsEntry()), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri) use ($port): string {
                @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) Firefox/136.0\r\n", 'ignore_errors' => true, 'timeout' => 10]]));
                return implode("\n", $http_response_header ?? []);
            };
            $first = $get('/index.php');
            $second = $get('/index.php');
            truthy(strpos($first, 'X-RS-Cache: miss') !== false && strpos($second, 'X-RS-Cache: hit') !== false, "the cache at work: $second");
            preg_match_all('~^Server-Timing: shield;dur=([\d.]+);desc=request-shield$~m', $first, $a);
            preg_match_all('~^Server-Timing:.*$~mi', $second, $b);
            preg_match_all('~^Server-Timing: shield;dur=([\d.]+);desc=request-shield$~m', $second, $c);
            same([1, 1, 1], [count($a[1]), count($b[0]), count($c[1])], 'debug-header on: one Server-Timing on a miss, one on a hit -- the stored one is never replayed (0046 step 3): ' . $second);
            truthy(($a[1][0] ?? '') !== ($c[1][0] ?? ''), 'the hit\'s own time, not the miss\'s');
            $get('/index.php/cart');
            $get('/index.php/slow?who=me');
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli());
            exec("$bin stats " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            $shown = implode("\n", $out);
            same(0, $code, $shown);
            truthy(preg_match('~the site took: median [\d.]+ (ms|s), slow end \(p95\) [\d.]+ s, average [\d.]+ (ms|s) -- 3 requests~', $shown) === 1, 'the site: three answered by it: ' . $shown);
            truthy(preg_match('~HTTP cache: 33 % hits \(hit [\d.]+ ms, miss [\d.]+ ms\), saved [\d.]+ ms; not kept: cookie 1~', $shown) === 1, 'the cache: one hit of three it could answer (the slow page is cacheable too), the cookie page not kept: ' . $shown);
            truthy(preg_match('~the shield: [\d.]+ (µs|ms) a request~u', $shown) === 1, 'the shield\'s share: ' . $shown);
            truthy(preg_match('~Slow requests \(the last\):\n  \S+ GET /index\.php/slow 200 2\d{3}ms [\d.]+MB miss people~', $shown) === 1, 'the slow log: without its query: ' . $shown);
            truthy(strpos($shown, 'who=me') === false && strpos($shown, '127.0.0.1') === false, 'no query, no address');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 times per page (0046 step 2): the slowest pages viewed often enough, each with its median, slow end and share from the cache -- files and APCu' => function (): void {
        foreach (ApcuStore::usable() ? ['file', 'apcu'] : ['file'] as $store) {
            $dir = timesDir();
            try {
                $s = Settings::from(['storeDir' => $dir, 'store' => $store, 'ext' => ['stats' => ['enabled' => true, 'parts' => ['requests', 'pages', 'times'], 'flush' => 0]]]);
                $html = 'Content-Type: text/html';
                for ($i = 0; $i < 12; $i++) {
                    timesRequest($s, timesReq('/shop/search?q=' . $i), 0.4, 200, [$html, 'X-RS-Cache: miss', 'Cache-Control: private']);
                    timesRequest($s, timesReq('/news/'), $i % 2 === 0 ? 0.002 : 0.05, 200, [$html, 'X-RS-Cache: ' . ($i % 2 === 0 ? 'hit' : 'miss')]);
                }
                for ($i = 0; $i < 3; $i++) {
                    timesRequest($s, timesReq('/rare'), 3.0, 200, [$html]);
                }
                timesRequest($s, timesReq('/api/data'), 0.9, 200, ['Content-Type: application/json']);
                $day = timesDay($s);
                same([12, 6, 0], [$day['pt:people|/shop/search|7'] ?? 0, $day['pc:people|/news/'] ?? 0, $day['pc:people|/shop/search'] ?? 0], "$store: each page view's band, and its hits");
                same([], array_values(array_filter(array_keys($day), static fn (string $k): bool => strpos($k, '/api/data') !== false && strncmp($k, 'p', 1) === 0)), "$store: JSON is no page view: no page time");
                $pages = StatsReport::build($s, null, 1)['times']['pages'] ?? [];
                same(['/shop/search', '/news/'], array_map('strval', array_keys($pages)), "$store: the slowest first, without its query; /rare has too few views");
                same([12, 6], [$pages['/news/']['count'] ?? 0, $pages['/news/']['hits'] ?? 0], "$store: half of /news/ came from the cache");
                truthy(($pages['/shop/search']['p50'] ?? 0) > 250000 && ($pages['/shop/search']['p50'] ?? 0) <= 500000, "$store: its median in its band: " . json_encode($pages['/shop/search'] ?? null));
                $page = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['view' => 'all', 'lang' => 'en', 'days' => 1]);
                truthy(strpos($page, 'Slowest pages (at least 10 views)') !== false && strpos($page, '<code>/shop/search</code>') !== false, "$store: on the page");
            } finally {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    },
    'RSF06-03 times per page are kept for the same pages as the views: past the views\' limit a page\'s times are "(other)" too -- with APCu at once, in files at the roll-up' => function (): void {
        // Files: the roll-up keeps the most viewed pages (cap()); the times of a page whose views
        // did not stay go to "(other)".
        $cap = new \ReflectionMethod(Stats::class, 'cap');
        $cap->setAccessible(true);
        $counts = ['pg:people|/a' => 5, 'pt:people|/a|3' => 5, 'ps:people|/a' => 90000, 'pt:people|/b|7' => 2, 'ps:people|/b' => 700000, 'pc:people|/b' => 1];
        same(['pg:people|/a' => 5, 'pt:people|/a|3' => 5, 'ps:people|/a' => 90000, 'pt:people|(other)|7' => 2, 'ps:people|(other)' => 700000, 'pc:people|(other)' => 1],
            $cap->invoke(null, $counts), 'a page whose views are gone: its times as "(other)"');
        if (!ApcuStore::usable()) {
            skip('no APCu here: the limit at counting time is APCu\'s');
        }
        $dir = timesDir();
        try {
            $s = Settings::from(['storeDir' => $dir, 'store' => 'apcu', 'ext' => ['stats' => ['enabled' => true, 'parts' => ['pages', 'times'], 'flush' => 0]]]);
            for ($i = 1; $i <= Stats::TOP + 1; $i++) {
                timesRequest($s, timesReq("/p$i"), 0.03, 200, ['Content-Type: text/html']);
            }
            $day = timesDay($s);
            $last = '/p' . (Stats::TOP + 1);
            same([1, 0, 1, 1], [$day['pg:people|(other)'] ?? 0, $day["pt:people|$last|4"] ?? 0, $day['pt:people|(other)|4'] ?? 0, $day['pt:people|/p1|4'] ?? 0],
                'the page past the limit: its view and its time both "(other)"; the first ones kept');
            same(true, isset($day['ps:people|(other)']), 'its sum too');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
            apcu_clear_cache();
        }
    },
    'RSF06-03 times: the speed card\'s hours are the period\'s last 48 -- a period in the past has its curve too' => function (): void {
        $dir = timesDir();
        try {
            $s = Settings::from(['storeDir' => $dir, 'store' => 'file', 'ext' => ['stats' => ['enabled' => true, 'parts' => ['requests', 'times']]]]);
            $day = (int) strtotime('2026-09-01 00:00 UTC');
            Stats::of($s)->count(['rt:people|past|4'], $day + 3600 * 20);
            Stats::of($s)->count(['rt:people|past|7'], $day + 3600 * 22);
            $hourly = StatsReport::build($s, null, 1, time(), ['from' => '20260901', 'to' => '20260901'])['times']['hourly'] ?? [];
            same(['2026090120', '2026090122'], array_map('strval', array_keys($hourly)), 'the hours of 1 September, a month ago');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
