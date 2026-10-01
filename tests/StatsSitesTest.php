<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Report\StatsPage;
use CjwNetwork\RequestShield\Report\StatsReport;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Stats;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** Statistics per website (proposal 0023, phase 2). */

const SITES_T0 = 1790763600;                // 2026-09-30 10:20 UTC

function sitesStatsDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-sstats-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    return $dir;
}

function sitesStatsSettings(string $dir, string $rules): Settings
{
    file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset stats on\n" . $rules);
    return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
}

/** One request for $host, counted at once (as the shield does for a request it answered itself). */
function sitesCount(Shield $shield, string $host, string $path = '/', int $t = SITES_T0): void
{
    $r = Request::fromServer(['REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => $host, 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/136.0']);
    $d = $shield->decide($r, (float) $t);
    $shield->record($r, $d, $shield->explain($d, $r), (float) $t);
}

function sitesRequests(Settings $s, ?string $site, int $t = SITES_T0): int
{
    $read = StatsReport::read($s, null, gmdate('Ymd', $t), gmdate('Ymd', $t), $site);
    $day = $read['days'][gmdate('Ymd', $t)] ?? [];
    $n = 0;
    foreach ($day as $k => $v) {
        $n += strncmp((string) $k, 'a:', 2) === 0 ? $v : 0;
    }
    return $n;
}

return [
    'set stats-hosts: names, *.domain, "host" (the host rule\'s), "sites" (the site blocks\') -- about the server; mistakes named' => function (): void {
        $dir = sitesStatsDir();
        try {
            same(['a.de', 'www.a.de', '*.b.de'], sitesStatsSettings($dir, "set stats-hosts A.de www.a.de. *.b.de\n")->statsHosts, 'lower case, without the trailing dot');
            same(['a.de', 'c.de', 'x.org'], sitesStatsSettings($dir, "host a.de c.de\nset stats-hosts host x.org\n")->statsHosts, 'host: the names the host rule allows');
            same(['shop.a.de', 'a.de', '*.b.de'], sitesStatsSettings($dir, "set stats-hosts sites\nsite shop.a.de a.de {\n}\nsite *.b.de {\n}\nsite default {\n}\n")->statsHosts,
                'sites: the site blocks\' names, not default');
            same([], sitesStatsSettings($dir, '')->statsHosts, 'unset: one statistics, as before');
            foreach (["set stats-hosts a_b.de\n" => 'takes website names', "set stats-hosts *.*.de\n" => 'takes website names',
                "site a.de {\n  set stats-hosts a.de\n}\n" => 'is about the server'] as $text => $says) {
                try {
                    file_put_contents("$dir/site.rules", "set store-dir $dir/store\n" . $text);
                    Settings::from(RuleFile::read(["$dir/site.rules"], strpos($text, 'site ') === 0 ? 'a.de' : null)['config']);
                    throw new TestFailure('accepted: ' . json_encode($text));
                } catch (RuleFileException $e) {
                    truthy(strpos($e->getMessage(), $says) !== false, $e->getMessage());
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'whose statistics: the name exactly, *.domain one label deep, anything else "other" -- a made-up Host gets none of its own' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "set stats-hosts a.de www.a.de *.b.de\n");
            same(['a.de', 'www.a.de', '*.b.de', '*.b.de', Stats::OTHER, Stats::OTHER, Stats::OTHER], [Stats::siteOf($s, 'a.de'), Stats::siteOf($s, 'www.a.de'), Stats::siteOf($s, 'news.b.de'),
                Stats::siteOf($s, 'shop.b.de'), Stats::siteOf($s, 'b.de'), Stats::siteOf($s, 'x.news.b.de'), Stats::siteOf($s, 'evil.example')]);
            same(null, Stats::siteOf(sitesStatsSettings($dir, ''), 'a.de'), 'without stats-hosts: one statistics');
            same(["$dir/store/stats/hosts/a.de", "$dir/store/stats/hosts/+.b.de", "$dir/store/stats/hosts/(other)", "$dir/store/stats"],
                [Stats::of($s, 'a.de')->dir(), Stats::of($s, '*.b.de')->dir(), Stats::of($s, Stats::OTHER)->dir(), Stats::of($s)->dir()], 'a directory each');
            $r = Request::fromServer(['REQUEST_URI' => '/', 'HTTP_HOST' => 'WWW.A.DE.:8443', 'REMOTE_ADDR' => '203.0.113.9']);
            same('www.a.de', (new \CjwNetwork\RequestShield\Seen($r, new Shield($s, new MemoryStore())))->host(), 'the Host normalised: lower case, no port, no trailing dot');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'counted per website, read per website or all added up -- what was counted before stats-hosts stays in the sum' => function (): void {
        $dir = sitesStatsDir();
        try {
            // Before: one statistics.
            $before = sitesStatsSettings($dir, '');
            sitesCount(new Shield($before, new MemoryStore()), 'a.de');
            $s = sitesStatsSettings($dir, "set stats-hosts a.de *.b.de\n");
            $shield = new Shield($s, new MemoryStore());
            foreach (['a.de', 'a.de', 'news.b.de', 'shop.b.de', 'made-up.example', 'A.DE:443'] as $host) {
                sitesCount(new Shield($s, new MemoryStore()), $host);
            }
            same([3, 2, 1, 7], [sitesRequests($s, 'a.de'), sitesRequests($s, '*.b.de'), sitesRequests($s, Stats::OTHER), sitesRequests($s, null)], 'per website, and all (with the one from before)');
            same(7, sitesRequests($s, 'not-a-site'), 'a name stats-hosts does not have: all');
            $r = StatsReport::build($s, null, 1, SITES_T0, ['site' => 'a.de']);
            same(3, ($r['totals']['allow'] ?? 0) + ($r['totals']['allow-uncached'] ?? 0), 'the report for one website');
            same(7, array_sum(StatsReport::build($s, null, 1, SITES_T0)['totals']), 'and for all');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'a quiet website\'s hour is rolled up too: the first request of an hour tends the others' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "set stats-hosts a.de b.de\n");
            sitesCount(new Shield($s, new MemoryStore()), 'b.de', '/', SITES_T0);
            $closed = gmdate('YmdH', SITES_T0);
            truthy(!is_file("$dir/store/stats/hosts/b.de/rolled-$closed"), 'not yet');
            // An hour later, a request for a.de only.
            $tended = new ReflectionProperty(\CjwNetwork\RequestShield\StatsPlugin::class, 'tended');
            $tended->setAccessible(true);
            $tended->setValue(null, -1);
            sitesCount(new Shield($s, new MemoryStore()), 'a.de', '/', SITES_T0 + 3600);
            truthy(is_file("$dir/store/stats/hosts/b.de/rolled-$closed"), 'b.de\'s hour rolled up by a request for a.de');
            same(1, sitesRequests($s, 'b.de'), 'and still counted');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'stats-skip: paths that are no pages (a map proxy) are not counted when they pass -- refused or checked they are; protected all the same' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "stats-skip **/osm-proxy/** /tiles/**\nmatch /app/** {\n  stats-skip\n}\n[P] limit requests 4/min\nexempt none\n");
            same(3, count($s->statsSkip), 'two paths, and a match block\'s area');
            $shield = new Shield($s, new MemoryStore());
            foreach (['/', '/osm-proxy/12/2133/1390.png', '/tiles/a', '/app/x', '/osm-proxy/.env', '/osm-proxy/b', '/osm-proxy/c'] as $i => $path) {
                sitesCount($shield, 'a.de', $path, SITES_T0 + $i);
            }
            $day = StatsReport::read($s, null, gmdate('Ymd', SITES_T0), gmdate('Ymd', SITES_T0))['days'][gmdate('Ymd', SITES_T0)] ?? [];
            same(['allow' => 1, 'reject' => 1, 'challenge' => 2], ['allow' => ($day['a:allow'] ?? 0) + ($day['a:allow-uncached'] ?? 0), 'reject' => $day['a:reject'] ?? 0,
                'challenge' => ($day['a:challenge'] ?? 0) + ($day['a:throttle'] ?? 0)], 'only "/" of the passing ones; the refusal (/.env) and the checks past the pace at the proxy are counted');
            same('reject', $shield->decide(Request::fromServer(['REQUEST_URI' => '/osm-proxy/.git/config', 'HTTP_HOST' => 'a.de', 'REMOTE_ADDR' => '203.0.113.9']), SITES_T0 + 9.0)->action,
                'protected all the same');
            truthy(strpos(\CjwNetwork\RequestShield\Report\SetupPage::render($s, 'en', []), 'not counted when they pass (stats-skip)') !== false, 'shown with the settings');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'the page: a website switch (all, each, other), the choice kept in the links; the command line: --site' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "set stats-hosts a.de b.de\n");
            sitesCount(new Shield($s, new MemoryStore()), 'a.de');
            $h = StatsPage::render($s, ['view' => 'site', 'action' => '/rs/stats', 'links' => ['site' => '/rs/stats', 'shield' => '/rs/shield'], 'lang' => 'en', 'now' => SITES_T0, 'site' => 'a.de']);
            truthy(strpos($h, '<select name="site"><option value="">All websites</option><option value="a.de" selected>a.de</option><option value="b.de">b.de</option><option value="(other)">other hosts') !== false, 'the switch');
            truthy(strpos($h, 'site=a.de') !== false && strpos($h, ' · a.de</p>') !== false, 'kept in the links, named under the title');
            $all = StatsPage::render($s, ['view' => 'site', 'action' => '/rs/stats', 'lang' => 'de', 'now' => SITES_T0]);
            truthy(strpos($all, '<option value="" selected>Alle Websites</option>') !== false && strpos($all, 'site=') === false, 'all: no site in the links');
            truthy(strpos(StatsPage::render(sitesStatsSettings($dir, ''), ['view' => 'site', 'lang' => 'en', 'now' => SITES_T0]), 'name="site"') === false, 'without stats-hosts: no switch');
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/request-shield') . ' stats ' . escapeshellarg("$dir/site.rules");
            file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset stats on\nset stats-hosts a.de b.de\n");
            exec("$bin --site=b.de --json 2>&1", $out, $code);
            same(0, $code, implode("\n", $out));
            exec("$bin --site=c.de 2>&1", $bad, $code);
            truthy($code === 2 && strpos(implode(' ', $bad), 'no website c.de in stats-hosts (a.de, b.de, other)') !== false, implode(' ', $bad));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
