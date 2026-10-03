<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Report\Describe;
use CjwNetwork\RequestShield\Report\RulesPage;
use CjwNetwork\RequestShield\Stats\Report\StatsReport;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\StatsExtension;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Stats\Stats;
use CjwNetwork\RequestShield\Stats\StatsPlugin;
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
    'RSF6.3 rule files and PHP settings: set stats, crawler-log and their mistakes' => function (): void {
        $dir = statsDir();
        try {
            file_put_contents("$dir/site.rules", "set stats on\nset stats-hours 3\nset stats-days 90\nset crawler-log logs/crawlers\nset crawler-log-kinds ai-training ai-user\nset crawler-log-days 14\nset crawler-log-query off\n");
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            $o = StatsExtension::of($s);
            same([true, 3, 90, "$dir/logs/crawlers", ['ai-training', 'ai-user'], 14, false],
                [$o['enabled'], $o['hours'], $o['days'], $o['crawlerLog']['dir'], $o['crawlerLog']['kinds'], $o['crawlerLog']['days'], $o['crawlerLog']['query']]);
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
        same([false, null], [StatsExtension::of(Settings::from([]))['enabled'], StatsExtension::of(Settings::from([]))['crawlerLog']['dir']], 'off by default');
        // Where the pages live: dashboard-path, something in front of it allowed.
        $page = \CjwNetwork\RequestShield\Stats\Report\StatsPage::class;
        same(['all' => '/rs/stats/overview', 'site' => '/rs/stats/visitors', 'shield' => '/rs/stats/protection', 'rules' => '/rs/waf/rules'], $page::links(Settings::from([])), 'the default: /rs, the plugin\'s pages under /rs/stats/');
        $admin = Settings::from(['dashboardPath' => '/admin/rs']);
        same(['all' => '/admin/rs/stats/overview', 'site' => '/admin/rs/stats/visitors', 'shield' => '/admin/rs/stats/protection', 'rules' => '/admin/rs/waf/rules'], $page::links($admin));
        same(['all', 'site', 'shield', null, null, null], [$page::viewFor($admin, '/admin/rs/stats/overview'), $page::viewFor($admin, '/Admin/RS/stats/Visitors/'), $page::viewFor($admin, '/admin/rs/stats/protection'), $page::viewFor($admin, '/admin/rs/waf/rules'),
            $page::viewFor($admin, '/rs/stats/visitors'), $page::viewFor($admin, '/admin/rs/other')], 'which view a path is: capitals and a trailing slash do not matter; the core\'s rules page is none of its views (0031 B.8)');
        same(['all', null, null, null, null], [$page::viewFor($admin, '/admin/rs/stats'), $page::viewFor($admin, '/admin/rs/dashboard'), $page::viewFor($admin, '/admin/rs/shield'),
            $page::viewFor($admin, '/admin/rs/sites'), $page::viewFor($admin, '/admin/rs/rules')], 'the plugin\'s start (the overview without stats-hosts); the old addresses are gone');
        $hosts = Settings::from(['ext' => ['stats' => ['enabled' => true, 'hosts' => ['a.de']]]]);
        same(['sites', 'sites'], [$page::viewFor($hosts, '/rs/stats'), $page::viewFor($hosts, '/rs/stats/sites')], 'with stats-hosts: all websites first');
        foreach (['admin/rs', '/a b', '/x/../y', ''] as $bad) {
            try {
                Settings::from(['dashboardPath' => $bad]);
                throw new TestFailure("accepted dashboardPath $bad");
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'dashboardPath') !== false, $e->getMessage());
            }
        }
        $dir = statsDir();
        try {
            file_put_contents("$dir/p.rules", "set stats crawlers not-found\nset stats-flush 30s\nset dashboard-path /admin/rs\n");
            $p = Settings::from(RuleFile::read(["$dir/p.rules"])['config']);
            $o = StatsExtension::of($p);
            same([true, ['crawlers', 'not-found'], 30, '/admin/rs'], [$o['enabled'], $o['parts'], $o['flush'], $p->dashboardPath], 'only some parts; the flush; the pages\' path');
            same(StatsExtension::PARTS, StatsExtension::of(Settings::from(['ext' => ['stats' => ['enabled' => true]]]))['parts'], 'all parts by default');
            file_put_contents("$dir/d.rules", "set stats on\nset stats-depth 3\n");
            same([2, 3], [StatsExtension::of(Settings::from([]))['depth'], StatsExtension::of(Settings::from(RuleFile::read(["$dir/d.rules"])['config']))['depth']], 'section levels: 2 by default, stats-depth 3');
            foreach ([0, 5] as $bad) {
                try {
                    Settings::from(['ext' => ['stats' => ['depth' => $bad]]]);
                    throw new TestFailure("accepted stats.depth $bad");
                } catch (InvalidArgumentException $e) {
                    truthy(strpos($e->getMessage(), 'stats.depth') !== false && strpos($e->getMessage(), '1 to 4') !== false, $e->getMessage());
                }
            }
            file_put_contents("$dir/q.rules", "set stats everything\n");
            try {
                RuleFile::read(["$dir/q.rules"]);
                throw new TestFailure('accepted "everything"');
            } catch (\CjwNetwork\RequestShield\Rules\RuleFileException $e) {
                truthy(strpos($e->getMessage(), 'q.rules:1: stats is on, off or what to count: requests, crawlers, not-found, bots, pages') === 0, $e->getMessage());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        foreach ([['ext' => ['stats' => ['enabled' => 'yes']]], ['ext' => ['stats' => ['crawlerLog' => ['dir' => '']]]], ['ext' => ['stats' => ['crawlerLog' => ['kinds' => ['robots']]]]]] as $bad) {
            try {
                Settings::from($bad);
                throw new TestFailure('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'stats') !== false || strpos($e->getMessage(), 'crawlerLog') !== false, $e->getMessage());
            }
        }
    },
    'RSF6.3 counting: per hour, read at once; the finished hours rolled into the day file; old hours summed, old days gone -- files and APCu' => function (): void {
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
    'RSF6.3 APCu survives a restart: every flush seconds the counts go to the hour\'s file -- nothing counted twice, nothing lost' => function (): void {
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
            same(0, apcu_fetch('rshield:stat:' . substr(md5($dir), 0, 8) . ':2026093010:a:allow'), 'and out of APCu');
            // Two sites on one PHP-FPM pool (one APCu): each counts its own.
            $other = new Stats($dir . '-other', true, 7, 400, null, 30, 60);
            $other->count(['a:reject'], STATS_T0 + 2);
            same([0, 1], [statsDay($st, STATS_T0)['a:reject'] ?? 0, statsDay($other, STATS_T0)['a:reject'] ?? 0], 'sites apart');
            exec('rm -rf ' . escapeshellarg($dir . '-other'));
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
    'RSF6.3 only the parts switched on are counted; without "crawlers" no crawler is even looked at' => function (): void {
        $dir = statsDir();
        try {
            foreach ([['requests'], ['crawlers'], ['not-found', 'bots']] as $parts) {
                exec('rm -rf ' . escapeshellarg("$dir/stats"));
                $s = Settings::from(['storeDir' => $dir, 'store' => 'file', 'ext' => ['stats' => ['enabled' => true, 'parts' => $parts]]]);
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
        same(['s:404', 'n:/a%2A3'], StatsPlugin::statusKeys(statsReq('/a*3'), 404), 'a "*" in a path would read as a count: escaped');
    },
    'RSF6.3 days, weeks, months, years: old days summed into their month, kept for good (or stats-months); the report grouped and filtered' => function (): void {
        $dir = statsDir();
        try {
            $st = new Stats($dir, false, 2, 30, null, 30, 60, 0);
            $at = static fn (string $d): float => (float) strtotime("$d 10:20 UTC");
            $st->count(['a:allow', 'a:allow', 'c:CRAWL-GPTBOT:verified'], $at('2026-01-15'));
            $st->count(['a:reject', 'c:CRAWL-GPTBOT:verified', 'c:CRAWL-GPTBOT:refused'], $at('2026-02-10'));
            $st->count(['a:allow'], $at('2026-09-28'));
            $st->count(['a:allow', 'c:CRAWL-GPTBOT:verified'], $at('2026-09-30'));
            $st->count(['a:allow'], $at('2026-09-30') + 3600);                // rolled: January and February are older than 30 days
            same([false, true, true], [is_file("$dir/d-20260115.json"), is_file("$dir/m-202601.json"), is_file("$dir/m-202602.json")], 'old days into their months');
            $s = Settings::from(['storeDir' => $dir]);
            $r = StatsReport::build($s, $st, 7, (int) $at('2026-09-30'), ['from' => '20260101', 'to' => '20260930', 'by' => 'month']);
            same(['2026-01', '2026-02', '2026-09'], array_keys($r['periods']));
            same([2, 0, 1], [$r['periods']['2026-01']['passed'], $r['periods']['2026-01']['refused'], $r['periods']['2026-02']['refused']]);
            same(3, $r['periods']['2026-09']['passed'], 'the running month: its days');
            $y = StatsReport::build($s, $st, 7, (int) $at('2026-09-30'), ['from' => '20260101', 'to' => '20260930', 'by' => 'year']);
            same(['2026' => ['passed' => 5, 'uncached' => 0, 'checked' => 0, 'throttled' => 0, 'refused' => 1, 'people' => 6, 'crawlers' => 0, 'bots' => 0, 'notFound' => 0, 'views' => 0]], $y['periods'], 'a year');
            $w = StatsReport::build($s, $st, 7, (int) $at('2026-09-30'), ['from' => '20260901', 'to' => '20260930', 'by' => 'week']);
            same(['2026-W40'], array_keys($w['periods']), 'ISO weeks (28 and 30 September: week 40)');
            $c = StatsReport::build($s, $st, 7, (int) $at('2026-09-30'), ['from' => '20260101', 'to' => '20260930', 'by' => 'month', 'crawler' => 'CRAWL-GPTBOT']);
            same([['CRAWL-GPTBOT'], 1, 1, 1], [array_keys($c['crawlers']), $c['periods']['2026-01']['verified'], $c['periods']['2026-02']['refused'], $c['periods']['2026-09']['verified']], 'one crawler, per month');
            same(3, $c['crawlers']['CRAWL-GPTBOT']['verified'], 'and in total');
            // Months kept 3: January goes at the next roll-up.
            $short = new Stats($dir, false, 2, 30, null, 30, 60, 3);
            $short->count(['a:allow'], $at('2026-09-30') + 7200);
            same([false, false], [is_file("$dir/m-202601.json"), is_file("$dir/m-202602.json")], 'stats-months 3: months before July removed');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF6.3 groups with a limit: 50 pages per crawler, 50 pages not found, 5 referrers each -- the rest as "(other)"' => function (): void {
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
                    $st->count(['n:/gone', "p:CRAWL-X:/page/$i", 'n:/gone/' . $i, 'nr:/gone|/ref/' . $i, 'nr:/random/' . $i . '|/x'], STATS_T0 + $i);
                }
                $st->count(['p:CRAWL-X:/page/1'], STATS_T0 + 100);
                $st->count(['a:allow'], STATS_T0 + 3600);                         // rolled
                $day = statsDay($st, STATS_T0);
                $pages = array_filter($day, static fn (string $k): bool => strncmp($k, 'p:CRAWL-X:/page/', 16) === 0, ARRAY_FILTER_USE_KEY);
                same([50, 10, 2], [count($pages), $day['p:CRAWL-X:(other)'] ?? 0, $day['p:CRAWL-X:/page/1'] ?? 0], "$label: 50 pages, the rest (other); a page seen twice counted twice");
                same([60, 11], [$day['n:/gone'] ?? 0, $day['n:(other)'] ?? 0], "$label: pages not found too (/gone and 49 others)");
                same(55, $day['nr:/gone|(other)'] ?? 0, "$label: 5 referrers a page");
                same([], array_filter(array_keys($day), static fn (string $k): bool => strncmp($k, 'nr:/random/', 11) === 0), "$label: no referrers for a page that is not on the list");
                // Sections: a limit for each folder level -- 250 deep ones first do not crowd out the few above.
                for ($i = 1; $i <= 250; $i++) {
                    $st->count(["pd:people|/de/news/t$i/"], STATS_T0 + 7200 + $i);
                }
                $st->count(['pd:people|/de/', 'pd:people|/de/news/', 'pd:people|/en/'], STATS_T0 + 7200 + 300);
                $st->count(['a:allow'], STATS_T0 + 3 * 3600);                     // rolled
                $day = statsDay($st, STATS_T0);
                $deep = array_filter($day, static fn (string $k): bool => strncmp($k, 'pd:people|/de/news/t', 20) === 0, ARRAY_FILTER_USE_KEY);
                same([200, 50, 1, 1, 1], [count($deep), $day['pd:people|(other)'] ?? 0, $day['pd:people|/de/'] ?? 0, $day['pd:people|/de/news/'] ?? 0, $day['pd:people|/en/'] ?? 0],
                    "$label: 200 on the third level, the rest (other); the first and second level still counted");
            } finally {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    },
    'RSF6.3 recording a request: action, rule, status; a crawler verified or only claimed, its page, robots.txt, its last visit; other bots by family' => function (): void {
        $dir = statsDir();
        try {
            $s = Settings::from(['storeDir' => $dir, 'store' => 'file', 'ext' => ['stats' => ['enabled' => true, 'crawlerLog' => ['dir' => "$dir/crawlers", 'query' => false]]],
                'crawlerPolicy' => ['CRAWL-GPTBOT' => 'block']]);
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
            // A sitemap: which crawler read it, and when last; the answer counted at the end of the request.
            $record(statsReq('/sitemap.xml', STATS_ANTHROPIC, STATS_CLAUDEBOT), STATS_T0 + 7);
            same(1, statsDay(Stats::of($s), STATS_T0)['smc:/sitemap.xml|CRAWL-CLAUDEBOT'] ?? 0, 'a verified crawler read the sitemap');
            same((int) STATS_T0 + 7, Stats::of($s)->read('20260930', '20260930')['last']['sitemap:/sitemap.xml@CRAWL-CLAUDEBOT'][0] ?? null, 'and when');
            same(['s:200', 'sm:/news/sitemap-news.xml.gz|200'], StatsPlugin::statusKeys(statsReq('/news/sitemap-news.xml.gz'), 200), 'which sitemaps exist: their answers');
            same(['s:200'], StatsPlugin::statusKeys(statsReq('/my-sitemap.html'), 200), 'no sitemap');
            // Refused by the site's policy
            $d = $record(statsReq('/', '20.171.207.2', 'Mozilla/5.0 (compatible; GPTBot/1.2)'), STATS_T0 + 6);
            same(Decision::REJECT, $d->action);
            same([1, 1], [statsDay(Stats::of($s), STATS_T0)['c:CRAWL-GPTBOT:refused'] ?? 0, statsDay(Stats::of($s), STATS_T0)['s:403'] ?? 0], 'refused as set');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF6.3 status keys: pages not found and where the links to them are -- the site\'s own path, another site\'s host only' => function (): void {
        same(['s:200'], StatsPlugin::statusKeys(statsReq('/a'), 200));
        same([true, true, false, true], [StatsPlugin::isHtml([]), StatsPlugin::isHtml(['Content-Type: text/html; charset=utf-8']), StatsPlugin::isHtml(['X-A: b', 'content-type: application/json']),
            StatsPlugin::isHtml(['Content-Type: application/xhtml+xml'])], 'a page: HTML, or no Content-Type (PHP\'s default)');
        same(['s:404', 'n:/old'], StatsPlugin::statusKeys(statsReq('/old'), 404));
        same(['s:404', 'n:/old', 'nr:/old|/news/x'], StatsPlugin::statusKeys(statsReq('/old', '203.0.113.9', 'x', ['HTTP_REFERER' => 'https://www.example.org/news/x?id=7']), 404), 'a broken link on the site');
        same(['s:410', 'n:/old', 'nr:/old|other.example'], StatsPlugin::statusKeys(statsReq('/old', '203.0.113.9', 'x', ['HTTP_REFERER' => 'https://Other.Example/a/b?q=secret']), 410), 'another site: its host, nothing else');
        same(['s:404', 'n:/a%7Cb%20c'], StatsPlugin::statusKeys(statsReq('/a|b%20c'), 404), 'a counter name is one word');
        foreach (['python-requests/2.32' => 'python', 'curl/8.5' => 'curl', 'Go-http-client/2.0' => 'go', 'Mozilla/5.0 HeadlessChrome/130' => 'headless', '' => 'empty',
            'MyCrawler/1.0' => 'other', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/136.0' => null] as $ua => $family) {
            same($family, Stats::botFamily((string) $ua), (string) $ua);
        }
    },
    'RSF6.3 the report: totals, rules, statuses, pages not found, crawlers -- and in words' => function (): void {
        $dir = statsDir();
        try {
            $s = Settings::from(['storeDir' => $dir, 'store' => 'file', 'ext' => ['stats' => ['enabled' => true]], 'crawlerPolicy' => ['CRAWL-GPTBOT' => 'block']]);
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
            same(['passed' => 2, 'uncached' => 0, 'checked' => 0, 'throttled' => 0, 'refused' => 1, 'people' => 0, 'crawlers' => 3, 'bots' => 0, 'notFound' => 1, 'views' => 0], $r['daily']['20260930'], 'who: three crawlers (seen), no people');
            $de = StatsReport::build($s, $st, 7, STATS_T0 + 10, ['lang' => 'de']);
            $worte = implode("\n", $de['sentences']);
            truthy(strpos($worte, "(CRAWL-GPTBOT) kam 1× in den letzten 7 Tagen: jedes Mal abgewiesen (so eingestellt: block).") !== false, $worte);
            truthy(strpos($worte, '1 Anfrage gab sich nur als') !== false && strpos($worte, 'Kaputter Link: /news/x verweist auf /old') !== false, 'German sentences');
            same('1.204', StatsReport::number(1204, 'de'));
            $words = implode("\n", $r['sentences']);
            truthy(strpos($words, "Anthropic's training crawler (CRAWL-CLAUDEBOT) came 1× in the last 7 days: every time let through.") !== false, $words);
            truthy(strpos($words, "(CRAWL-GPTBOT) came 1× in the last 7 days: every time refused (as set: block).") !== false, 'refused as set');
            truthy(strpos($words, '1 request only pretended to be') !== false && strpos($words, '(CRAWL-GOOGLE)') !== false, 'a false Googlebot');
            truthy(strpos($words, 'No verified visit in the last 7 days from: CRAWL-OAI-SEARCH') !== false, 'the AI crawlers that did not come');
            truthy(strpos($words, 'Broken link: /news/x links to /old, which was not found (1×).') !== false, 'a broken link');
            truthy(json_encode($r) !== false, 'JSON for a CMS');
            $st->count(['sm:/sitemap.xml|200', 'smc:/sitemap.xml|CRAWL-GOOGLE', 'l:sitemap:/sitemap.xml@CRAWL-GOOGLE|' . (STATS_T0 + 5) . '|-', 'sm:/old-sitemap.xml|404'], STATS_T0 + 5);
            $maps = StatsReport::build($s, $st, 7, STATS_T0 + 10);
            same(['200' => 1, '403' => 1, '404' => 1], array_combine(array_map('strval', array_keys($maps['statuses'])), $maps['statuses']), 'the sitemaps leave the answers as they are');
            same(['/old-sitemap.xml' => ['statuses' => ['404' => 1], 'crawlers' => []], '/sitemap.xml' => ['statuses' => ['200' => 1], 'crawlers' => ['CRAWL-GOOGLE' => ['count' => 1, 'last' => STATS_T0 + 5]]]], $maps['sitemaps']);
            $words = implode("\n", $maps['sentences']);
            truthy(strpos($words, '/old-sitemap.xml was asked for 1× but does not exist (404).') !== false && strpos($words, 'CRAWL-GOOGLE read /sitemap.xml 1×, last on') !== false, $words);
            // The most visited pages, by who came.
            $st->count(['pg:people|/news', 'pg:people|/news', 'pg:crawlers|/news', 'pg:bots|/', 'pg:people|/about'], STATS_T0 + 7);
            $top = StatsReport::build($s, $st, 7, STATS_T0 + 10)['pages'];
            $z = ['refused' => 0, 'checked' => 0, 'throttled' => 0, 'blocked' => 0];
            same(['/news' => ['people' => 2, 'crawlers' => 1, 'bots' => 0, 'total' => 3] + $z, '/about' => ['people' => 1, 'crawlers' => 0, 'bots' => 0, 'total' => 1] + $z,
                '/' => ['people' => 0, 'crawlers' => 0, 'bots' => 1, 'total' => 1] + $z], $top, 'most visited first; equal: more people first');
            // A subtree: exact where it is a counted folder, else the sum of its pages.
            $st->count(['pg:people|/news/2026/a', 'pd:people|/news/', 'pd:people|/news/2026/', 'pg:crawlers|/news/b', 'pd:crawlers|/news/', 'pd:people|/news/'], STATS_T0 + 8);
            $sub = StatsReport::build($s, $st, 7, STATS_T0 + 10, ['path' => '/news/']);
            same(['path' => '/news/', 'people' => 2, 'crawlers' => 1, 'bots' => 0, 'total' => 3] + $z + ['exact' => true], $sub['subtree'], 'the folder\'s own counter');
            same(['/news/2026/a', '/news/b'], array_keys($sub['pages']), 'only its pages (/news without the slash is not below /news/)');
            same(['/news/2026/'], array_keys($sub['folders']), 'its sections');
            same(false, StatsReport::build($s, $st, 7, STATS_T0 + 10, ['path' => '/news/2026/a'])['subtree']['exact'] ?? null, 'not a counted folder: the sum of the pages');
            // What the shield stopped, on each page: shown with its views, and a list of its own.
            $st->count(['pb:refused|/wp-login.php', 'pb:refused|/wp-login.php', 'pb:refused|/wp-login.php', 'pb:checked|/news/b', 'pb:throttled|/news/b', 'pb:refused|/news', 'pb:nonsense|/x'], STATS_T0 + 9);
            $all = StatsReport::build($s, $st, 7, STATS_T0 + 10);
            same(['refused' => 1, 'checked' => 0, 'throttled' => 0, 'blocked' => 1], array_intersect_key($all['pages']['/news'], $z), 'a visited page shows what was stopped there');
            same(false, isset($all['pages']['/wp-login.php']), 'sorted by views: a page nobody saw is not on the list');
            $stop = StatsReport::build($s, $st, 7, STATS_T0 + 10, ['sort' => 'blocked']);
            same(['/wp-login.php', '/news/b', '/news'], array_keys($stop['pages']), 'most stopped first; only pages the shield stopped');
            same([3, 0], [$stop['pages']['/wp-login.php']['refused'], $stop['pages']['/wp-login.php']['total']], 'refused three times, never seen');
            same(['/news/' => 2], array_map(static fn (array $v): int => $v['blocked'], $stop['folders']), 'sections: from their pages');
            same(['/news/b', '/news/2026/a'], array_keys(StatsReport::build($s, $st, 7, STATS_T0 + 10, ['sort' => 'throttled', 'path' => '/news/'])['pages'] + ['/news/2026/a' => 1]), 'by one kind, in a subtree');
            same([2, 1, 1], [($x = StatsReport::build($s, $st, 7, STATS_T0 + 10, ['path' => '/news/'])['subtree'])['blocked'] ?? 0, $x['checked'] ?? 0, $x['throttled'] ?? 0], 'the subtree: what was stopped in it');
            same('views', StatsReport::build($s, $st, 7, STATS_T0 + 10, ['sort' => '<x>'])['sort'], 'an unknown order: by views');
            $blockedPage = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'lang' => 'de', 'view' => 'shield', 'links' => \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($s)]);
            truthy(strpos($blockedPage, 'Am häufigsten blockierte Seiten') !== false && strpos($blockedPage, '<code>/wp-login.php</code>') !== false
                && strpos($blockedPage, '<option value="blocked" selected>') !== false, 'the protection\'s view: the pages stopped most');
            truthy(strpos($blockedPage, 'href="/rs/stats/protection?days=30&amp;by=day&amp;lang=de"') !== false, 'its own order is not carried in the links');
            $viewsPage = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'lang' => 'de', 'links' => \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($s)]);
            truthy(strpos($viewsPage, '<label for="vp-p2">Gestoppt</label>') !== false && strpos($viewsPage, '<code>/wp-login.php</code>') !== false, 'the editors\' view: what was stopped, a tab of the pages card');
            same(['/wp-login.php', '/news/b', '/news'], array_keys($all['stopped']), 'the report: the pages stopped most, whatever the order of the list');
            truthy(strpos(\CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'lang' => 'en', 'sort' => 'refused', 'links' => \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($s)]),
                'href="/rs/stats/visitors?days=30&amp;by=day&amp;lang=en&amp;sort=refused"') !== false, 'another order is carried in the links');
            same(['/news/', '/news/2026/'], StatsPlugin::folders('/news/2026/10/x'));
            same(['/news/'], StatsPlugin::folders('/news/'), 'a folder\'s own page belongs to it');
            same([], StatsPlugin::folders('/about'));
            same([['/de/'], ['/de/', '/de/news/', '/de/news/2026/'], ['/de/', '/de/news/', '/de/news/2026/']], [StatsPlugin::folders('/de/news/2026/10/x', 1), StatsPlugin::folders('/de/news/2026/10/x', 3), StatsPlugin::folders('/de/news/2026/', 4)], 'stats-depth 1, 3, 4');
            // The page: charts, both languages, everything escaped.
            $st->count(['n:/<script>x', 'o:curl', 'c:CRAWL-GOOGLE:seen'], STATS_T0 + 6);
            $page = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'lang' => 'de', 'action' => '/stats', 'view' => 'all']);
            truthy(strpos($page, '<html lang="de">') !== false && strpos($page, 'Wer kam') !== false && strpos($page, 'Nicht gefundene Seiten') !== false, 'German');
            truthy(substr_count($page, '<svg') >= 4 && strpos($page, 'class="chart"') !== false && strpos($page, 'class="ring"') !== false, 'the charts');
            truthy(strpos($page, '<script>x') === false && strpos($page, '&lt;script&gt;x') !== false, 'a path is never markup');
            truthy(strpos($page, 'Sitemaps') !== false && strpos($page, 'CRAWL-GOOGLE') !== false, 'sitemaps, crawlers');
            truthy(strpos($page, 'Meistbesuchte Seiten') !== false && strpos($page, '<code>/news</code>') !== false, 'the most visited pages');
            // Two views: for editors (visitors and pages) and for admins (the protection).
            $site = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'lang' => 'de', 'action' => '/stats']);
            $shield = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'lang' => 'de', 'action' => '/stats', 'view' => 'shield']);
            truthy(strpos($site, 'SEITENAUFRUFE') === false && strpos($site, '>Seitenaufrufe</span>') !== false && strpos($site, 'Crawler &amp; KI') !== false
                && strpos($site, '<label for="vp-p3">Nicht gefunden</label>') !== false && strpos($site, 'Regeln, die am meisten entschieden') === false, 'the site view (the default): numbers, pages, crawlers -- no rules');
            truthy(strpos($shield, 'Regeln, die am meisten entschieden') !== false && strpos($shield, 'Was der Schutz tat') !== false && strpos($shield, 'Meistbesuchte Seiten') === false, 'the shield view: what it did, rules -- no pages');
            truthy(strpos($site, 'class="tab on"') !== false && strpos($site, 'view=shield') !== false, 'tabs between the two');
            truthy(strpos(\CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'tabs' => false]), 'class="tabs"') === false, 'embedded: one view, no tabs');
            $linked = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'lang' => 'en', 'view' => 'shield',
                'links' => ['all' => '/rs/dashboard', 'site' => '/rs/stats', 'shield' => '/rs/shield']]);
            truthy(strpos($linked, 'href="/rs/dashboard?days=7&amp;lang=en">Overview') !== false && strpos($linked, 'href="/rs/stats?days=7&amp;lang=en">Visitors') !== false, 'one address per view, three tabs');
            truthy(strpos($linked, 'view=') === false && strpos($linked, 'href="/rs/shield?days=30') !== false, 'the path names the view; the filters stay parameters');
            $form = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'links' => \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($s)]);
            truthy(strpos($form, 'name="view"') === false && strpos($form, 'action="/rs/stats/visitors"') !== false, 'the filter form sends no view where the address names it (a site with query strict would refuse it)');
            $en = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'accept' => 'en-US,en;q=0.9', 'fragment' => true]);
            truthy(strpos($en, '>Page views</span>') !== false && strpos($en, '<html') === false, 'the browser\'s language; only the content for the refresh');
            truthy(strpos(\CjwNetwork\RequestShield\Stats\Report\StatsPage::render(Settings::from([])), 'set stats on') !== false, 'without statistics: how to switch them on');
            $html = RulesPage::render($s, ['now' => STATS_T0 + 10]);
            truthy(strpos($html, '7 days: 1 visits (1 let through), last') !== false, 'the rules page: what each crawler did');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF6.3 the real path: protect() counts every request, the site\'s 404s with their referrers; bin/request-shield stats says it' => function (): void {
        if (!function_exists('proc_open') || !function_exists('exec')) {
            skip('no proc_open');
        }
        $dir = statsDir();
        mkdir("$dir/docroot");
        file_put_contents("$dir/docroot/index.php", '<?php if (strpos($_SERVER["REQUEST_URI"], "/missing") === 0) { http_response_code(404); echo "not found"; exit; } echo "ok";');
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset stats on\nset stats-depth 3\nexempt none\nrestrict /rs/** to 127.0.0.1 ::1\n");
        $port = freePort();
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1', escapeshellarg("$dir/site.rules"),
            serverPhp(), escapeshellarg(rsEntry()), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri, string $headers = '') use ($port): void {
                @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['header' => $headers, 'ignore_errors' => true, 'timeout' => 10]]));
            };
            $get('/', "User-Agent: Mozilla/5.0 (X11; Linux x86_64) Firefox/136.0\r\n");
            $get('/', "User-Agent: python-requests/2.32\r\n");
            $get('/de/news/2026/x', "User-Agent: Mozilla/5.0 (X11; Linux x86_64) Firefox/136.0\r\n");
            $get('/missing-page', "Referer: http://127.0.0.1:$port/news/x\r\n");
            $get('/index.php/.env');
            $get('/index.php/.env');
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli());
            exec("$bin stats " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            $shown = implode("\n", $out);
            same(0, $code, $shown);
            truthy(strpos($shown, '6 requests: 4 let through, 0 checked, 0 told to wait, 2 refused') !== false, $shown);
            truthy(strpos($shown, 'answers: 200: 3, 404: 3') !== false, 'the site\'s 404 and the shield\'s (twice)');
            truthy(preg_match('~^  /\s+2\s+1\s+0\s+1\s+0\s+0\s+0\s+0$~m', $shown) === 1, 'the front page: 2 views, 1 person, 1 bot (the 404 and the refusal are no page views): ' . $shown);
            truthy(preg_match('~1\s+/missing-page\s+linked from: /news/x \(1\)~', $shown) === 1, 'the page not found and the link to it');
            $out = [];
            exec("$bin stats " . escapeshellarg("$dir/site.rules") . ' --path=/de/news/2026/ 2>&1', $out, $code);
            truthy(preg_match('~^Subtree /de/news/2026/: 1 views \(people 1, crawlers 0, bots 0\)$~m', implode("\n", $out)) === 1, 'stats-depth 3: the third level exact: ' . implode("\n", $out));
            $out = [];
            exec("$bin check " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            truthy($code === 0 && strpos(implode("\n", $out), 'past the limit') === false, 'check: no note while the sections fit');
            \CjwNetwork\RequestShield\Stats\Stats::of(Settings::from(RuleFile::read(["$dir/site.rules"])['config']))->count(['pd:people|(other)', 'pd:bots|(other)'], time());
            $out = [];
            exec("$bin check " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            truthy($code === 0 && strpos(implode("\n", $out), 'note: 2 section views in the last 7 days were past the limit of 200 sections an hour on one folder level: the deepest levels are approximate there -- set stats-depth 2') !== false,
                'check: a note (not a warning) when a level overflows: ' . implode("\n", $out));
            $out = [];
            exec("$bin stats " . escapeshellarg("$dir/site.rules") . ' --sort=blocked 2>&1', $out, $code);
            truthy(preg_match('~^Pages stopped most.*\n  /index\.php/\.env\s+0\s+0\s+0\s+0\s+2\s+2\s+0\s+0$~m', implode("\n", $out)) === 1, 'the pages the shield stopped, from the real path: ' . implode("\n", $out));
            $out = [];
            exec("$bin stats " . escapeshellarg("$dir/site.rules") . ' --json 2>&1', $out, $code);
            same(6, array_sum((array) (json_decode(implode("\n", $out), true)['totals'] ?? [])), 'JSON');
            $out = [];
            exec("$bin stats " . escapeshellarg("$dir/site.rules") . ' --from=' . date('Y-m-d', time() - 86400) . ' --to=' . gmdate('Y-m-d') . ' --by=month 2>&1', $out, $code);
            truthy($code === 0 && preg_match('/^  ' . gmdate('Y-m') . '\s+\d/m', implode("\n", $out)) === 1, 'a period, by month: ' . implode("\n", $out));
            $out = [];
            exec("$bin stats " . escapeshellarg("$dir/site.rules") . ' --crawler=CRAWL-NOBODY 2>&1', $out, $code);
            same(2, $code, 'an unknown crawler');
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
'RSF6.3 rules & setup: the way of a request, every rule in words (English, German), every setting -- never the secret; the protection view explains its rules' => function (): void {
        $dir = statsDir();
        try {
            $secret = str_repeat('never-show-me-', 3);
            file_put_contents("$dir/site.rules", "set store-dir $dir/store\nset stats on\nset secret $secret\nset log $dir/shield.log\n"
                . "[T-AREA]  restrict **/intern/** to 10.0.0.0/8       # the intranet only\n"
                . "[T-OLD]   block **/old-admin/**                     # the old admin area\n"
                . "[T-PACE]  limit requests 30/min challenge-at 10     # per visitor: 30 a minute\n");
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            $page = \CjwNetwork\RequestShield\Report\SetupPage::class;
            $en = $page::render($s, 'en', ['T-AREA' => 4, 'T-OLD' => 2]);
            $de = $page::render($s, 'de', ['T-AREA' => 4]);
            truthy(strpos($en, 'The way of a request') !== false && strpos($en, 'Areas for certain visitors</b> <span class="state">on') !== false
                && strpos($en, 'Website names</b> <span class="state">off') !== false, 'the way: every step, on or off');
            truthy(strpos($en, 'id="rule-T-AREA"') !== false && strpos($en, 'the intranet only') !== false && strpos($en, 'line 5') !== false && strpos($en, '4×') !== false,
                'a rule: its ID as an anchor, its description, where it is written, how often it decided');
            truthy(strpos($en, '&quot;requests&quot;: 30 requests per minute, the browser check from 10, then a pause') !== false, 'a budget in words');
            truthy(strpos($de, 'Der Weg einer Anfrage') !== false && strpos($de, 'Bereiche für bestimmte Besucher') !== false
                && strpos($de, '„requests“: 30 Anfragen pro Minute, der Browser-Check ab 10, dann eine Pause') !== false && strpos($de, '<span class="tag">Bereiche für bestimmte Besucher</span>') !== false && in_array(['Bereiche für bestimmte Besucher', 'Alle anderen bekommen „kein Zugriff“ (403):'], array_map(static fn (array $g): array => [$g[0], $g[1]], RulesPage::groups($s, [], null, 'de')), true), 'in German');
            truthy(strpos($en, 'Technical settings') !== false && strpos($en, "<th>store directory</th><td><code>$dir/store</code>") !== false && strpos($en, 'set (never shown)') !== false, 'the settings');
            truthy(strpos($en . $de, $secret) === false, 'the secret is never shown');
            truthy(strpos($en, '<svg class="rsd setup"') !== false && substr_count($en, '<a href="#step-') === 17 && strpos($en, 'id="step-17"') !== false
                && strpos($en, 'id="step-before"') !== false && strpos($en, 'After: log and statistics') !== false, 'the way as a picture: 17 checks (post-origin among them since 0031 C.3), each a link to its line; before and after');
            truthy(strpos($de, 'Log: sofort, nur Gestopptes (Stufe stop).') !== false && strpos($de, 'was zur Website geht, am Ende der Anfrage') !== false && strpos($de, '>Mensch · Crawler · Bot</text>') !== false,
                'after: when the log and the statistics write; the line from the crawler check to the statistics');
            truthy(preg_match('~<details class="rfile" open><summary><code>site.rules</code> <span class="note">· 3 rules · 6× decided</span></summary><table class="rtable"><tr id="rule-T-AREA"><td class="rid"><code>T-AREA</code></td><td>the intranet only~', $en) === 1,
                'the rules as the file holds them: the file open, the ID first, in its order');
            truthy(strpos($en, '<td class="rline">line 5</td>') !== false && strpos($en, '<span class="tag">Areas for certain visitors</span>') !== false && strpos($en, 'closest("details")') !== false, 'its line, its topic; a link opens the file');
            same(['id' => 'T-AREA', 'text' => 'the intranet only', 'where' => 'site.rules:5'], $page::rule($s, 'site.rules:5'), 'a rule counted by its place: found by it');
            same(['id' => 'T-OLD', 'text' => 'the old admin area', 'where' => 'site.rules:6'], $page::rule($s, 'T-OLD'));
            file_put_contents("$dir/q.rules", "[Q-ONE] query page int at **/a/**   # the first\n[Q-TWO] query page int   sort word at **/b/**   # the second\n");
            $rows = array_values(array_filter(RulesPage::groups(Settings::from(RuleFile::read(["$dir/q.rules"])['config'])), static fn (array $g): bool => $g[0] === 'Known parameters'))[0][2];
            same([['Q-ONE', 'the first'], ['Q-TWO', 'the second']], array_map(static fn (array $r): array => [$r['id'], $r['text']], $rows), 'two query lines with the same parameter: each its own rule');
            same(['Minute', '10 Sekunden', 'einen Tag', 'eine Stunde'], [Describe::duration(60, 'de'), Describe::duration(10, 'de'), Describe::span(86400, 'de'), Describe::span(3600, 'de')]);
            same(['Kept out and let in', 'Addresses only attackers ask for'], [RulesPage::groups($s)[0][0], RulesPage::groups($s)[1][0]], 'the rules page stays English');
            // The statistics page: a fourth view, and the protection view explains its rules.
            $st = Stats::of($s);
            $st->count(['a:reject', 'r:T-AREA', 'r:built-in', 's:403'], STATS_T0);
            $links = \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($s);
            // Rules & setup is the core's page (0031 B.8): the frame's tabs, the counts from the plugins that have RuleCounts.
            $view = \CjwNetwork\RequestShield\Report\Frame::tabs($s, $links, 'rules', 'de')
                . \CjwNetwork\RequestShield\Report\SetupPage::render($s, 'de', \CjwNetwork\RequestShield\Report\Counts::rules($s, 7, STATS_T0 + 10.0), ['now' => STATS_T0 + 10.0]);
            truthy(strpos($view, 'class="tab on" href="/rs/waf/rules?lang=de">Regeln &amp; Einrichtung') !== false && strpos($view, 'Der Weg einer Anfrage') !== false, 'the core\'s page "Regeln & Einrichtung", in the frame');
            truthy(strpos($view, '1× entschieden') !== false || strpos($view, 'entschieden') !== false, 'the rule\'s count from the statistics plugin (RuleCounts): ' . (strpos($view, 'T-AREA') !== false ? 'T-AREA shown' : 'T-AREA missing'));
            $shield = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'lang' => 'de', 'view' => 'shield', 'links' => $links]);
            truthy(strpos($shield, 'href="/rs/waf/rules?days=7&amp;lang=de#rule-T-AREA"><code>T-AREA</code></a><br>the intranet only<br><span class="note">site.rules:5') !== false,
                'the protection view: a rule with what it does, where, and a link to it');
            truthy(strpos($shield, '#way"><code>built-in</code></a><br>die festen Prüfungen') !== false, 'the fixed checks, explained');
            truthy(strpos(\CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 10, 'view' => 'shield', 'action' => '/stats']), 'view=rules') !== false, 'without addresses: ?view=rules');
            // The rule tester: an address, what each step makes of it, the rule that decides.
            $store = new MemoryStore();
            $tried = $page::render($s, 'de', [], ['check' => ['method' => 'GET', 'url' => 'https://www.example.org/old-admin/x.php', 'ip' => '198.51.100.7'], 'store' => $store, 'action' => '/rs/rules', 'keep' => ['days' => 7, 'lang' => 'de'], 'now' => 1000.0]);
            truthy(strpos($tried, 'Regeltester') !== false && strpos($tried, 'Dieser Besucher bekommt „nicht gefunden“ (404) — die Website sieht sie nie.') !== false
                && strpos($tried, 'Entschieden von:</span> <a class="rid" href="#rule-T-OLD"><code>T-OLD</code></a>') !== false, 'the tester: the verdict, the rule, linked');
            truthy(strpos($tried, 'abgewiesen: the old admin area') !== false && strpos($tried, 'nicht geprüft: schon oben abgewiesen') !== false && strpos($tried, '>Gesperrt</text>') !== false,
                'every step in German, the diagram too');
            truthy(strpos($tried, 'name="days" value="7"') !== false && strpos($tried, 'action="/rs/rules#try"') !== false && strpos($tried, 'value="https://www.example.org/old-admin/x.php"') !== false, 'the form keeps what it was given');
            $area = $page::render($s, 'en', [], ['check' => ['url' => '/intern/', 'ip' => '10.1.2.3', 'ua' => 'Mozilla/5.0'], 'store' => $store, 'now' => 1000.0]);
            truthy(strpos($area, 'a restricted area, and 10.1.2.3 is allowed (10.0.0.0/8)') !== false && strpos($area, 'This visitor sees the page') !== false, 'in English, from inside the area');
            truthy(strpos($page::render($s, 'en', [], ['check' => ['url' => '/x', 'ip' => '<script>'], 'store' => $store, 'ip' => '192.0.2.1']), 'value="192.0.2.1"') !== false, 'an address that is none: the viewer\'s');
            same(0, (int) $store->hit('requests:198.51.100.7', 60, 1000.0) - 1, 'the tester counted nothing');
            $viewed = \CjwNetwork\RequestShield\Report\SetupPage::render($s, 'de', [], ['check' => ['url' => '/old-admin/'], 'ip' => '203.0.113.5', 'store' => $store, 'now' => STATS_T0 + 10.0, 'action' => '/rs/waf/rules']);
            truthy(strpos($viewed, 'href="#rule-T-OLD"') !== false && strpos($viewed, 'value="203.0.113.5"') !== false && strpos($viewed, 'name="view"') === false, 'on the core\'s page: its address, no view field');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
'RSF6.3 the visitors page (0022): six numbers against the period before, one chart, cards with tabs -- no script, everything escaped' => function (): void {
        $dir = statsDir();
        try {
            $s = Settings::from(['storeDir' => $dir, 'store' => 'file', 'ext' => ['stats' => ['enabled' => true]]]);
            $st = Stats::of($s);
            $day = 86400;
            // The 7 days before: 2 page views; these 7 days: 3, one of them a path with markup.
            $st->count(['a:allow', 'pg:people|/a', 'pg:people|/a'], STATS_T0 - 8 * $day);
            $st->count(['a:allow', 'pg:people|/a', 'pg:people|/b', 'pg:people|/<script>x', 'a:reject', 'pb:refused|/.env', 'pg:bots|/a'], STATS_T0 - $day);
            $st->count(['a:allow'], STATS_T0 + 3600);                                      // rolled
            $page = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 3600, 'lang' => 'en', 'links' => \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($s)]);
            truthy(preg_match('~<label class="vt" for="vp-m0"><span class="l">Page views</span><span class="n">3</span><span class="d" title="before: 2">↑ 50 %</span></label>~', $page) === 1, 'page views by people, against the 7 days before');
            truthy(strpos($page, '<span class="l">Stopped</span><span class="n">1</span><span class="d" title="before: 0">new</span>') !== false, 'stopped: new, nothing before');
            same(6, substr_count($page, 'name="vp-m"'), 'six numbers, each a radio button for the chart');
            same(1, substr_count($page, 'id="vp-m0" checked'), 'page views picked first');
            truthy(substr_count($page, '<svg class="vline"') === 6 && strpos($page, 'class="vprev"') !== false, 'a chart for each number, the period before dashed');
            same(7 * 6, substr_count($page, '<rect class="vcol"'), 'a point for each of the 7 days, the empty ones too');
            truthy(strpos($page, '<title>Sep 29: 3 (before: 2)</title>') !== false, 'a point\'s numbers, and the same day a week before, as its tooltip');
            truthy(strpos($page, '&lt;script&gt;x') !== false && strpos($page, '<script>x') === false, 'a path is never markup');
            truthy(strpos($page, '<code>/.env</code>') !== false && strpos($page, '>Crawlers &amp; AI</h2>') !== false, 'the cards: pages (stopped) and crawlers & AI');
            same(1, substr_count($page, '<script>'), 'one script: the refresh, which keeps the picked tabs');
            truthy(strpos($page, "querySelectorAll('input[type=radio]:checked')") !== false, 'the refresh keeps what was picked');
            // A range, this month, last month.
            $range = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 3600, 'lang' => 'de', 'from' => '2026-09-01', 'to' => '2026-09-30', 'links' => \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($s)]);
            truthy(strpos($range, '01.09.2026 – 30.09.2026') !== false && substr_count($range, '<rect class="vcol"') === 30 * 6, 'a range: every day of it');
            truthy(strpos($range, 'class="pill on" href="/rs/stats/visitors?from=2026-09-01&amp;to=2026-09-30&amp;lang=de">Dieser Monat') !== false, 'this month is that range');
            truthy(strpos($range, 'href="/rs/stats/visitors?from=2026-08-01&amp;to=2026-08-31&amp;lang=de">Letzter Monat') !== false, 'last month');
            truthy(strpos($range, 'name="from" value="2026-09-01"') !== false && strpos($range, 'name="lang" value="de"') !== false, 'the range form');
            $bad = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 3600, 'lang' => 'en', 'from' => '2026-02-30', 'to' => '"><x>', 'links' => \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($s)]);
            truthy(strpos($bad, '<x>') === false && strpos($bad, 'class="pill on" href="/rs/stats/visitors?days=7') !== false, 'a range that is none: the last 7 days');
            $today = \CjwNetwork\RequestShield\Stats\Report\StatsPage::render($s, ['stats' => $st, 'now' => STATS_T0 + 3600, 'lang' => 'en', 'days' => 1, 'by' => 'hour']);
            same(24 * 6, substr_count($today, '<rect class="vcol"'), 'today: 24 hours, against the 24 before');
            // "now": with APCu a counter a minute, never on disk.
            same(null, $st->lastMinutes(5, STATS_T0), 'without APCu: no "now"');
            if (ApcuStore::usable()) {
                $a = new Stats("$dir/apcu", true);
                $a->minute(STATS_T0);
                $a->minute(STATS_T0 + 61);
                $a->minute(STATS_T0 - 400);                                                  // older than 5 minutes
                same(2, $a->lastMinutes(5, STATS_T0 + 61), 'people\'s requests in the last 5 minutes');
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
