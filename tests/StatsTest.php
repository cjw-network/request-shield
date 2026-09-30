<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Report\RulesPage;
use CjwNetwork\RequestShield\Report\StatsReport;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Stats;
use CjwNetwork\RequestShield\Store\ApcuStore;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** Counters for the dashboard and crawler statistics (proposals 0012, 0014). */

const STATS_T0 = 1790763600;                // 2026-09-30 10:20 UTC
const STATS_CLAUDEBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';
const STATS_ANTHROPIC = '216.73.216.5';     // in rules/crawlers/anthropic.json

function statsDir(): string
{
    $dir = sys_get_temp_dir() . '/rshield-stats-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    return $dir;
}

function statsReq(string $uri, string $ip = '203.0.113.9', string $ua = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/136.0', array $headers = []): Request
{
    return Request::fromServer(['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua] + $headers);
}

/** The counters of one day as Stats::read() sums them. */
function statsDay(Stats $st, int $t): array
{
    return $st->read(gmdate('Ymd', $t), gmdate('Ymd', $t))['days'][gmdate('Ymd', $t)] ?? [];
}

/** An array sorted by key, to compare with what read() returns. */
function statsSorted(array $a): array
{
    ksort($a);
    return $a;
}

/** Both kinds of store, where there are: files always, APCu when it is on. */
function statsBackends(): array
{
    return ApcuStore::usable() ? ['files' => false, 'apcu' => true] : ['files' => false];
}

return [
    'rule files and PHP settings: set stats, crawler-log and their mistakes' => function (): void {
        $dir = statsDir();
        try {
            file_put_contents("$dir/site.rules", "set stats on\nset stats-hours 3\nset stats-days 90\nset crawler-log logs/crawlers\nset crawler-log-kinds ai-training ai-user\nset crawler-log-days 14\nset crawler-log-query off\n");
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same([true, 3, 90, "$dir/logs/crawlers", ['ai-training', 'ai-user'], 14, false],
                [$s->statsEnabled, $s->statsHours, $s->statsDays, $s->crawlerLogDir, $s->crawlerLogKinds, $s->crawlerLogDays, $s->crawlerLogQuery]);
            file_put_contents("$dir/bad.rules", "set crawler-log-kinds robots\n");
            try {
                RuleFile::read(["$dir/bad.rules"]);
                throw new TestFailure('accepted a kind "robots"');
            } catch (\CjwNetwork\RequestShield\Rules\RuleFileException $e) {
                truthy(strpos($e->getMessage(), 'bad.rules:1: crawler-log-kinds takes kinds of crawler') === 0, $e->getMessage());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        same([false, null], [Settings::from([])->statsEnabled, Settings::from([])->crawlerLogDir], 'off by default');
        $dir = statsDir();
        try {
            file_put_contents("$dir/p.rules", "set stats crawlers not-found\nset stats-flush 30s\n");
            $p = Settings::from(RuleFile::read(["$dir/p.rules"])['config']);
            same([true, ['crawlers', 'not-found'], 30], [$p->statsEnabled, $p->statsParts, $p->statsFlush], 'only some parts; the flush');
            same(Settings::STATS_PARTS, Settings::from(['stats' => ['enabled' => true]])->statsParts, 'all parts by default');
            file_put_contents("$dir/q.rules", "set stats everything\n");
            try {
                RuleFile::read(["$dir/q.rules"]);
                throw new TestFailure('accepted "everything"');
            } catch (\CjwNetwork\RequestShield\Rules\RuleFileException $e) {
                truthy(strpos($e->getMessage(), 'q.rules:1: stats is on, off or what to count: requests, crawlers, not-found, bots') === 0, $e->getMessage());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        foreach ([['stats' => ['enabled' => 'yes']], ['crawlerLog' => ['dir' => '']], ['crawlerLog' => ['kinds' => ['robots']]]] as $bad) {
            try {
                Settings::from($bad);
                throw new TestFailure('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'stats') !== false || strpos($e->getMessage(), 'crawlerLog') !== false, $e->getMessage());
            }
        }
    },
    'counting: per hour, read at once; the finished hours rolled into the day file; old hours summed, old days gone -- files and APCu' => function (): void {
        foreach (statsBackends() as $label => $apcu) {
            $dir = statsDir();
            try {
                if ($apcu) {
                    foreach (new APCUIterator('/^rshield:stat/') as $k => $_) {
                        apcu_delete((string) $k);
                    }
                }
                $st = new Stats($dir, $apcu, 2, 5);
                $st->count(['a:allow', 'o:curl'], STATS_T0);
                $st->count(['a:allow'], STATS_T0 + 60);
                $st->count(['a:reject', 'r:SCAN-HIDDEN', 's:404'], STATS_T0 + 120);
                same(statsSorted(['a:allow' => 2, 'o:curl' => 1, 'a:reject' => 1, 'r:SCAN-HIDDEN' => 1, 's:404' => 1]), statsDay($st, STATS_T0), "$label: read while the hour runs");
                $st->count(['a:allow'], STATS_T0 + 3600);                         // 11:20: hour 10 is over
                $day = json_decode((string) file_get_contents("$dir/d-20260930.json"), true);
                same(statsSorted(['a:allow' => 2, 'o:curl' => 1, 'a:reject' => 1, 'r:SCAN-HIDDEN' => 1, 's:404' => 1]), statsSorted($day['hours']['10'] ?? []), "$label: hour 10 in the day file");
                same(3, statsDay($st, STATS_T0)['a:allow'] ?? 0, "$label: the day file and the running hour together");
                if (!$apcu) {
                    same(['h-2026093011.log'], array_map('basename', glob("$dir/h-*.log") ?: []), 'only the running hour is left as lines');
                }
                $read = $st->read('20260930', '20260930');
                truthy(isset($read['hours']['2026093010'], $read['hours']['2026093011']), "$label: hours kept");
                // Three days later: hours past 2 days summed into the day's total.
                $st->count(['a:allow'], STATS_T0 + 3 * 86400);
                $day = json_decode((string) file_get_contents("$dir/d-20260930.json"), true);
                same([[], 3], [$day['hours'], $day['total']['a:allow'] ?? 0], "$label: old hours become the day's total");
                same(3, statsDay($st, STATS_T0)['a:allow'] ?? 0, "$label: read the same");
                // A week later: days past 5 gone.
                $st->count(['a:allow'], STATS_T0 + 7 * 86400);
                same(false, is_file("$dir/d-20260930.json"), "$label: an old day removed");
            } finally {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    },
    'APCu survives a restart: every flush seconds the counts go to the hour\'s file -- nothing counted twice, nothing lost' => function (): void {
        if (!ApcuStore::usable()) {
            skip('APCu not enabled (php -d apc.enable_cli=1)');
        }
        $dir = statsDir();
        try {
            foreach (new APCUIterator('/^rshield:stat/') as $k => $_) {
                apcu_delete((string) $k);
            }
            $st = new Stats($dir, true, 7, 400, null, 30, 60);
            $st->count(['a:allow', 'p:CRAWL-X:/a', 'l:CRAWL-X|' . STATS_T0 . '|192.0.2.1'], STATS_T0);
            $st->count(['a:allow'], STATS_T0 + 1);
            $st->flush();
            $line = (string) file_get_contents("$dir/h-2026093010.log");
            preg_match_all('/a:allow\*(\d+)/', $line, $m);
            same(2, array_sum(array_map('intval', $m[1])), 'on disk, as name*count lines (the first count flushed already): ' . $line);
            truthy(strpos($line, 'l:CRAWL-X|' . STATS_T0 . '|192.0.2.1') !== false, 'the last visit on disk too');
            same(0, apcu_fetch('rshield:stat:2026093010:a:allow'), 'and out of APCu');
            $st->count(['a:allow'], STATS_T0 + 2);
            same(3, statsDay($st, STATS_T0)['a:allow'] ?? 0, 'APCu and the file together, nothing twice');
            foreach (new APCUIterator('/^rshield:stat/') as $k => $_) {
                apcu_delete((string) $k);                 // PHP-FPM restarts: APCu is empty
            }
            same([2, 1], [statsDay($st, STATS_T0)['a:allow'] ?? 0, statsDay($st, STATS_T0)['p:CRAWL-X:/a'] ?? 0], 'what was flushed is still there');
            same('192.0.2.1', $st->read('20260930', '20260930')['last']['CRAWL-X'][1] ?? null, 'the last visit too');
            $st->count(['a:allow'], STATS_T0 + 3600);                  // the hour is rolled up
            same(3, statsDay($st, STATS_T0)['a:allow'] ?? 0, 'rolled up once');
            same([], glob("$dir/h-2026093010.log") ?: [], 'the flushed file is part of the roll-up');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'only the parts switched on are counted; without "crawlers" no crawler is even looked at' => function (): void {
        $dir = statsDir();
        try {
            foreach ([['requests'], ['crawlers'], ['not-found', 'bots']] as $parts) {
                exec('rm -rf ' . escapeshellarg("$dir/stats"));
                $s = Settings::from(['storeDir' => $dir, 'store' => 'file', 'stats' => ['enabled' => true, 'parts' => $parts]]);
                $shield = new Shield($s, new MemoryStore());
                foreach ([statsReq('/', STATS_ANTHROPIC, STATS_CLAUDEBOT), statsReq('/', '198.51.100.5', 'curl/8.5')] as $r) {
                    $d = $shield->decide($r, STATS_T0);
                    $shield->record($r, $d, null, STATS_T0);
                }
                $types = array_values(array_unique(array_map(static fn (string $k): string => (string) strstr($k, ':', true), array_keys(statsDay(Stats::of($s), STATS_T0)))));
                sort($types);
                same(['requests' => ['a', 's'], 'crawlers' => ['c', 'p'], 'not-found' => ['o']][$parts[0]], $types, implode(' ', $parts) . ' (no page was missing here: bots only)');
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        same(['s:404', 'n:/a%2A3'], Shield::statusKeys(statsReq('/a*3'), 404), 'a "*" in a path would read as a count: escaped');
    },
    'groups with a limit: 50 pages per crawler, 50 pages not found, 5 referrers each -- the rest as "(other)"' => function (): void {
        foreach (statsBackends() as $label => $apcu) {
            $dir = statsDir();
            try {
                if ($apcu) {
                    foreach (new APCUIterator('/^rshield:stat/') as $k => $_) {
                        apcu_delete((string) $k);
                    }
                }
                $st = new Stats($dir, $apcu);
                for ($i = 1; $i <= 60; $i++) {
                    $st->count(["p:CRAWL-X:/page/$i", 'n:/gone/' . $i, 'nr:/gone|/ref/' . $i], STATS_T0 + $i);
                }
                $st->count(['p:CRAWL-X:/page/1'], STATS_T0 + 100);
                $st->count(['a:allow'], STATS_T0 + 3600);                         // rolled
                $day = statsDay($st, STATS_T0);
                $pages = array_filter($day, static fn (string $k): bool => strncmp($k, 'p:CRAWL-X:/page/', 16) === 0, ARRAY_FILTER_USE_KEY);
                same([50, 10, 2], [count($pages), $day['p:CRAWL-X:(other)'] ?? 0, $day['p:CRAWL-X:/page/1'] ?? 0], "$label: 50 pages, the rest (other); a page seen twice counted twice");
                same(10, $day['n:(other)'] ?? 0, "$label: pages not found too");
                same(55, $day['nr:/gone|(other)'] ?? 0, "$label: 5 referrers a page");
            } finally {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    },
    'recording a request: action, rule, status; a crawler verified or only claimed, its page, robots.txt, its last visit; other bots by family' => function (): void {
        $dir = statsDir();
        try {
            $s = Settings::from(['storeDir' => $dir, 'store' => 'file', 'stats' => ['enabled' => true], 'crawlerPolicy' => ['CRAWL-GPTBOT' => 'block'],
                'crawlerLog' => ['dir' => "$dir/crawlers", 'query' => false]]);
            $shield = new Shield($s, new MemoryStore());
            $record = static function (Request $r, float $t) use ($shield): Decision {
                $d = $shield->decide($r, $t);
                $shield->record($r, $d, $shield->explain($d, $r), $t);
                return $d;
            };
            $record(statsReq('/'), STATS_T0);
            $record(statsReq('/robots.txt', STATS_ANTHROPIC, STATS_CLAUDEBOT), STATS_T0 + 1);
            $record(statsReq('/news/a?utm_source=x', STATS_ANTHROPIC, STATS_CLAUDEBOT), STATS_T0 + 2);
            $record(statsReq('/x', '198.51.100.4', STATS_CLAUDEBOT), STATS_T0 + 3);
            $record(statsReq('/', '198.51.100.5', 'python-requests/2.32'), STATS_T0 + 4);
            $record(statsReq('/index.php/.env'), STATS_T0 + 5);
            $day = statsDay(Stats::of($s), STATS_T0);
            same(5, $day['a:allow'] ?? 0);
            same([1, 1, 1], [$day['a:reject'] ?? 0, $day['r:SCAN-HIDDEN'] ?? 0, $day['s:404'] ?? 0], 'a refusal: its rule and status');
            same([3, 2, 1, 2, 1], [$day['c:CRAWL-CLAUDEBOT:seen'] ?? 0, $day['c:CRAWL-CLAUDEBOT:verified'] ?? 0, $day['c:CRAWL-CLAUDEBOT:claimed'] ?? 0,
                $day['c:CRAWL-CLAUDEBOT:allowed'] ?? 0, $day['c:CRAWL-CLAUDEBOT:robots'] ?? 0], 'ClaudeBot: seen, verified, claimed, allowed, robots.txt');
            same([1, 1], [$day['p:CRAWL-CLAUDEBOT:/robots.txt'] ?? 0, $day['p:CRAWL-CLAUDEBOT:/news/a'] ?? 0], 'its pages, without the query');
            same(1, $day['o:python'] ?? 0, 'a bot family');
            same(STATS_ANTHROPIC, Stats::of($s)->read('20260930', '20260930')['last']['CRAWL-CLAUDEBOT'][1] ?? null, 'the last visit, the operator\'s address');
            $log = (string) file_get_contents("$dir/crawlers/CRAWL-CLAUDEBOT/" . date('Y-m-d', STATS_T0) . '.log');
            truthy(strpos($log, STATS_ANTHROPIC . ' allow 200 "" "GET http://www.example.org/news/a"') !== false, 'the crawler log: the verified address in full, the query left out: ' . $log);
            truthy(strpos($log, '198.51.100.0/24 allow 200 "" claimed=CRAWL-CLAUDEBOT') !== false, 'one that only claims: masked, noted');
            // Refused by the site's policy
            $d = $record(statsReq('/', '20.171.207.2', 'Mozilla/5.0 (compatible; GPTBot/1.2)'), STATS_T0 + 6);
            same(Decision::REJECT, $d->action);
            same([1, 1], [statsDay(Stats::of($s), STATS_T0)['c:CRAWL-GPTBOT:refused'] ?? 0, statsDay(Stats::of($s), STATS_T0)['s:403'] ?? 0], 'refused as set');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'status keys: pages not found and where the links to them are -- the site\'s own path, another site\'s host only' => function (): void {
        same(['s:200'], Shield::statusKeys(statsReq('/a'), 200));
        same(['s:404', 'n:/old'], Shield::statusKeys(statsReq('/old'), 404));
        same(['s:404', 'n:/old', 'nr:/old|/news/x'], Shield::statusKeys(statsReq('/old', '203.0.113.9', 'x', ['HTTP_REFERER' => 'https://www.example.org/news/x?id=7']), 404), 'a broken link on the site');
        same(['s:410', 'n:/old', 'nr:/old|other.example'], Shield::statusKeys(statsReq('/old', '203.0.113.9', 'x', ['HTTP_REFERER' => 'https://Other.Example/a/b?q=secret']), 410), 'another site: its host, nothing else');
        same(['s:404', 'n:/a%7Cb%20c'], Shield::statusKeys(statsReq('/a|b%20c'), 404), 'a counter name is one word');
        foreach (['python-requests/2.32' => 'python', 'curl/8.5' => 'curl', 'Go-http-client/2.0' => 'go', 'Mozilla/5.0 HeadlessChrome/130' => 'headless', '' => 'empty',
            'MyCrawler/1.0' => 'other', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/136.0' => null] as $ua => $family) {
            same($family, Stats::botFamily((string) $ua), (string) $ua);
        }
    },
    'the report: totals, rules, statuses, pages not found, crawlers -- and in words' => function (): void {
        $dir = statsDir();
        try {
            $s = Settings::from(['storeDir' => $dir, 'store' => 'file', 'stats' => ['enabled' => true], 'crawlerPolicy' => ['CRAWL-GPTBOT' => 'block']]);
            $st = Stats::of($s);
            $st->count(['a:allow', 's:200', 'c:CRAWL-CLAUDEBOT:seen', 'c:CRAWL-CLAUDEBOT:verified', 'c:CRAWL-CLAUDEBOT:allowed', 'p:CRAWL-CLAUDEBOT:/news', 'l:CRAWL-CLAUDEBOT|' . STATS_T0 . '|' . STATS_ANTHROPIC], STATS_T0);
            $st->count(['a:reject', 'r:site.rules:4', 's:403', 'c:CRAWL-GPTBOT:seen', 'c:CRAWL-GPTBOT:verified', 'c:CRAWL-GPTBOT:refused'], STATS_T0 + 1);
            $st->count(['a:allow', 's:404', 'n:/old', 'nr:/old|/news/x', 'c:CRAWL-GOOGLE:seen', 'c:CRAWL-GOOGLE:claimed'], STATS_T0 + 2);
            $r = StatsReport::build($s, $st, 7, STATS_T0 + 10);
            same(['allow' => 2, 'reject' => 1], $r['totals']);
            same(['site.rules:4' => 1], $r['rules']);
            same(['200' => 1, '403' => 1, '404' => 1], array_combine(array_map('strval', array_keys($r['statuses'])), $r['statuses']));
            same(['/old' => ['count' => 1, 'referrers' => ['/news/x' => 1]]], $r['notFound']);
            same([1, 1, ['/news' => 1]], [$r['crawlers']['CRAWL-CLAUDEBOT']['verified'], $r['crawlers']['CRAWL-CLAUDEBOT']['allowed'], $r['crawlers']['CRAWL-CLAUDEBOT']['pages']]);
            same(['passed' => 2, 'uncached' => 0, 'checked' => 0, 'throttled' => 0, 'refused' => 1], $r['daily']['20260930']);
            $words = implode("\n", $r['sentences']);
            truthy(strpos($words, "Anthropic's training crawler (CRAWL-CLAUDEBOT) came 1× in the last 7 days: every time let through.") !== false, $words);
            truthy(strpos($words, "(CRAWL-GPTBOT) came 1× in the last 7 days: every time refused (as set: block).") !== false, 'refused as set');
            truthy(strpos($words, '1 request only pretended to be') !== false && strpos($words, '(CRAWL-GOOGLE)') !== false, 'a false Googlebot');
            truthy(strpos($words, 'No verified visit in the last 7 days from: CRAWL-OAI-SEARCH') !== false, 'the AI crawlers that did not come');
            truthy(strpos($words, 'Broken link: /news/x links to /old, which was not found (1×).') !== false, 'a broken link');
            truthy(json_encode($r) !== false, 'JSON for a CMS');
            $html = RulesPage::render($s, ['now' => STATS_T0 + 10]);
            truthy(strpos($html, '7 days: 1 visits (1 let through), last') !== false, 'the rules page: what each crawler did');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'the real path: protect() counts every request, the site\'s 404s with their referrers; bin/request-shield stats says it' => function (): void {
        if (!function_exists('proc_open') || !function_exists('exec')) {
            skip('no proc_open');
        }
        $dir = statsDir();
        mkdir("$dir/docroot");
        file_put_contents("$dir/docroot/index.php", '<?php if (strpos($_SERVER["REQUEST_URI"], "/missing") === 0) { http_response_code(404); echo "not found"; exit; } echo "ok";');
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset stats on\nexempt none\n");
        $port = freePort();
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1', escapeshellarg("$dir/site.rules"),
            escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__) . '/bootstrap.php'), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri, string $headers = '') use ($port): void {
                @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['header' => $headers, 'ignore_errors' => true, 'timeout' => 10]]));
            };
            $get('/');
            $get('/missing-page', "Referer: http://127.0.0.1:$port/news/x\r\n");
            $get('/index.php/.env');
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/request-shield');
            exec("$bin stats " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            $shown = implode("\n", $out);
            same(0, $code, $shown);
            truthy(strpos($shown, '3 requests: 2 let through, 0 checked, 0 told to wait, 1 refused') !== false, $shown);
            truthy(strpos($shown, 'answers: 200: 1, 404: 2') !== false, 'the site\'s 404 and the shield\'s');
            truthy(preg_match('~1\s+/missing-page\s+linked from: /news/x \(1\)~', $shown) === 1, 'the page not found and the link to it');
            $out = [];
            exec("$bin stats " . escapeshellarg("$dir/site.rules") . ' --json 2>&1', $out, $code);
            same(3, array_sum((array) (json_decode(implode("\n", $out), true)['totals'] ?? [])), 'JSON');
            file_put_contents("$dir/off.rules", "set store-dir $dir/store\n");
            $out = [];
            exec("$bin stats " . escapeshellarg("$dir/off.rules") . ' 2>&1', $out, $code);
            same(1, $code, 'no statistics: says how to switch them on');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
