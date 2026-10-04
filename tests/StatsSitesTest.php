<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Stats\Report\StatsPage;
use CjwNetwork\RequestShield\Stats\Report\StatsReport;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Stats\Stats;
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
    'RSF06-03 set stats-hosts: names, *.domain, "host" (the host rule\'s), "sites" (the site blocks\') -- about the server; mistakes named' => function (): void {
        $dir = sitesStatsDir();
        try {
            same(['a.de', 'www.a.de', '*.b.de'], sitesStatsSettings($dir, "set stats-hosts A.de www.a.de. *.b.de\n")->ext['stats']['hosts'], 'lower case, without the trailing dot');
            same(['a.de', 'c.de', 'x.org'], sitesStatsSettings($dir, "host a.de c.de\nset stats-hosts host x.org\n")->ext['stats']['hosts'], 'host: the names the host rule allows');
            same(['shop.a.de', 'a.de', '*.b.de'], sitesStatsSettings($dir, "set stats-hosts sites\nsite shop.a.de a.de {\n}\nsite *.b.de {\n}\nsite default {\n}\n")->ext['stats']['hosts'],
                'sites: the site blocks\' names, not default');
            same([], sitesStatsSettings($dir, '')->ext['stats']['hosts'], 'unset: one statistics, as before');
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
    'RSF06-03 whose statistics: the name exactly, *.domain one label deep, anything else "other" -- a made-up Host gets none of its own' => function (): void {
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
    'RSF06-03 counted per website, read per website or all added up -- what was counted before stats-hosts stays in the sum' => function (): void {
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
    'RSF06-03 a quiet website\'s hour is rolled up too: the first request of an hour tends the others' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "set stats-hosts a.de b.de\n");
            sitesCount(new Shield($s, new MemoryStore()), 'b.de', '/', SITES_T0);
            $closed = gmdate('YmdH', SITES_T0);
            truthy(!is_file("$dir/store/stats/hosts/b.de/rolled-$closed"), 'not yet');
            // An hour later, a request for a.de only.
            $tended = new ReflectionProperty(\CjwNetwork\RequestShield\Stats\StatsPlugin::class, 'tended');
            $tended->setAccessible(true);
            $tended->setValue(null, -1);
            sitesCount(new Shield($s, new MemoryStore()), 'a.de', '/', SITES_T0 + 3600);
            truthy(is_file("$dir/store/stats/hosts/b.de/rolled-$closed"), 'b.de\'s hour rolled up by a request for a.de');
            same(1, sitesRequests($s, 'b.de'), 'and still counted');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 stats-skip: paths that are no pages (a map proxy) are not counted when they pass -- refused or checked they are; protected all the same' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "stats-skip **/osm-proxy/** /tiles/**\nmatch /app/** {\n  stats-skip\n}\n[P] limit requests 4/min\nexempt none\n");
            same(3, count($s->ext['stats']['skip']), 'two paths, and a match block\'s area');
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
    'RSF06-03 stats-group: websites per customer -- read together and each on its own; counted apart without naming them twice; mistakes named' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "set stats-hosts c.de\n[G-A] stats-group \"Customer A\" a.de www.a.de b.de   # the agency's customer\nstats-group Reseller b.de c.de\n");
            same(['customer-a' => ['name' => 'Customer A', 'sites' => ['a.de', 'www.a.de', 'b.de'], 'rule' => 'G-A'], 'reseller' => ['name' => 'Reseller', 'sites' => ['b.de', 'c.de'], 'rule' => 'site.rules:6']],
                $s->ext['stats']['groups'], 'an ID for addresses; a website in two groups');
            same(['c.de', 'a.de', 'www.a.de', 'b.de'], $s->ext['stats']['hosts'], 'a group\'s websites are counted apart too');
            foreach (['a.de', 'www.a.de', 'b.de', 'b.de', 'c.de', 'x.example'] as $host) {
                sitesCount(new Shield($s, new MemoryStore()), $host);
            }
            same([4, 3, 1, 6], [sitesRequests($s, 'group:customer-a'), sitesRequests($s, 'group:reseller'), sitesRequests($s, 'c.de'), sitesRequests($s, null)],
                'a group: its websites added up; all: everything once');
            same(6, sitesRequests($s, 'group:nobody'), 'a group that is not there: all');
            foreach (["site a.de {\n  stats-group X a.de\n}\n" => 'stats-group is about the server', "match /x/** {\n  stats-group X a.de\n}\n" => 'does not go inside a match block',
                "stats-group X a_b.de\n" => 'takes website names', "stats-group \"X\"\n" => 'stats-group "<name>"',
                "stats-group \"Kunde A\" a.de\nstats-group kunde-a b.de\n" => 'used twice'] as $text => $says) {
                try {
                    file_put_contents("$dir/site.rules", "set store-dir $dir/store\n" . $text);
                    Settings::from(RuleFile::read(["$dir/site.rules"], strpos($text, 'site ') === 0 ? 'a.de' : null)['config']);
                    throw new TestFailure('accepted: ' . json_encode($text));
                } catch (RuleFileException | InvalidArgumentException $e) {
                    truthy(strpos($e->getMessage(), $says) !== false, $e->getMessage());
                }
            }
            $s = sitesStatsSettings($dir, "set stats-hosts c.de\nstats-group \"Customer A\" a.de b.de\n");
            $h = StatsPage::render($s, ['view' => 'site', 'action' => '/rs/stats', 'lang' => 'en', 'now' => SITES_T0, 'site' => 'group:customer-a']);
            truthy(strpos($h, '<optgroup label="Customer A"><option value="group:customer-a" selected>Customer A: all 2 websites</option><option value="a.de">a.de</option><option value="b.de">b.de</option></optgroup>'
                . '<optgroup label="In no group"><option value="c.de">c.de</option></optgroup><option value="(other)">') !== false, 'the switch: a section per group, then the rest');
            truthy(strpos($h, 'Customer A: all 2 websites: a.de, b.de</p>') !== false && strpos($h, 'site=group%3Acustomer-a') !== false, 'named under the title, kept in the links');
            truthy(strpos(\CjwNetwork\RequestShield\Report\SetupPage::render($s, 'en', []), 'Customer A: a.de, b.de') !== false, 'shown with the settings');
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' stats ' . escapeshellarg("$dir/site.rules");
            exec("$bin --group=\"Customer A\" --json 2>&1", $out, $code);
            same(0, $code, implode("\n", $out));
            exec("$bin --group=nobody 2>&1", $bad, $code);
            truthy($code === 2 && strpos(implode(' ', $bad), 'no group nobody (customer-a)') !== false, implode(' ', $bad));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 all websites at a glance: each group and its websites, where the traffic is, against the period before -- only with stats-hosts' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "set stats-hosts c.de d.de\nstats-group \"Customer A\" a.de b.de\n");
            $now = SITES_T0;
            $prev = SITES_T0 - 7 * 86400;
            // Page views: this week a.de 5, b.de 1, c.de 3, d.de 0; the week before a.de 2, b.de 2, c.de 3.
            foreach (['a.de' => [5, 2], 'b.de' => [1, 2], 'c.de' => [3, 3]] as $site => [$cur, $before]) {
                $st = Stats::of($s, $site);
                for ($i = 0; $i < $cur; $i++) {
                    $st->count(['a:allow', 'pg:people|/p'], (float) ($now - $i * 3600));
                }
                for ($i = 0; $i < $before; $i++) {
                    $st->count(['a:allow', 'pg:people|/p'], (float) ($prev - $i * 3600));
                }
            }
            Stats::of($s, Stats::OTHER)->count(['a:reject'], (float) $now);
            Stats::of($s, 'a.de')->count(['c:CRAWL-GOOGLE:seen', 'c:CRAWL-GOOGLE:seen', 'c:CRAWL-GPTBOT:seen', 'c:CRAWL-CLAUDE-USER:seen'], (float) $now);
            $x = StatsReport::sites($s, gmdate('Ymd', $now - 6 * 86400), gmdate('Ymd', $now));
            same([5, 2, 1, 2, 3, 3, 0, 0], [$x['sites']['a.de']['views'], $x['sites']['a.de']['prev'], $x['sites']['b.de']['views'], $x['sites']['b.de']['prev'],
                $x['sites']['c.de']['views'], $x['sites']['c.de']['prev'], $x['sites']['d.de']['views'], $x['sites']['d.de']['prev']], 'per website, this period and the one before');
            same([6, 4], [$x['groups']['customer-a']['views'], $x['groups']['customer-a']['prev']], 'a group: its websites added up');
            same([9, 1], [$x['all']['views'], $x['all']['stopped']], 'all, and what was stopped (on another host)');
            same([2, 2, 2, 2], [$x['sites']['a.de']['search'], $x['sites']['a.de']['ai'], $x['groups']['customer-a']['search'], $x['all']['ai']], 'crawlers by kind: search engines apart from AI crawlers');
            $h = StatsPage::render($s, ['view' => 'sites', 'action' => '/rs/sites', 'links' => StatsPage::links($s), 'lang' => 'en', 'now' => $now]);
            $pos = static fn (string $needle): int => (int) strpos($h, $needle);
            truthy(strpos($h, '<a class="tab on" href="/rs/stats/sites') !== false, 'its own tab, first');
            truthy($pos('>Customer A</a>') < $pos('>a.de</a>') && $pos('>a.de</a>') < $pos('>b.de</a>') && $pos('>b.de</a>') < $pos('>c.de</a>') && $pos('>c.de</a>') < $pos('>d.de</a>')
                && $pos('>d.de</a>') < $pos('>other hosts'), 'the group with its websites (most traffic first), then the rest, then other hosts');
            truthy(strpos($h, '+50 %') !== false && strpos($h, '+150 %') !== false && strpos($h, '−50 %') !== false && strpos($h, '+0 %') !== false, 'the change: group, a.de, b.de, c.de');
            truthy(strpos($h, 'href="/rs/stats/visitors?site=group%3Acustomer-a') !== false && strpos($h, 'href="/rs/stats/visitors?site=a.de') !== false, 'each opens its statistics');
            truthy(strpos($h, '<svg class="spark"') !== false, 'a curve each');
            $plain = sitesStatsSettings($dir, '');
            same(false, isset(StatsPage::links($plain)['sites']), 'without stats-hosts: no such view');
            truthy(strpos(StatsPage::render($plain, ['view' => 'sites', 'lang' => 'en', 'now' => $now]), '<table class="sites">') === false, 'asked for all the same: the overview instead');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 several websites read together: every page with its website in front -- the same path on two is two pages; the filter takes website/path' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "stats-group \"Customer A\" a.de b.de\n");
            foreach (['a.de' => ['/', '/news/x'], 'b.de' => ['/', '/kontakt']] as $site => $paths) {
                foreach ($paths as $path) {
                    Stats::of($s, $site)->count(['a:allow', 'pg:people|' . $path, 'n:/gone', 'pb:refused|/wp-login.php', 'pg:people|(other)'], (float) SITES_T0);
                }
            }
            $all = StatsReport::build($s, null, 1, SITES_T0);
            same(['a.de/', 'a.de/news/x', 'b.de/', 'b.de/kontakt'], array_values(array_filter(array_keys($all['pages']), static fn (string $p): bool => $p !== '(other)')), 'each page with its website');
            same(['a.de/gone', 'b.de/gone'], array_keys($all['notFound']), 'not found too');
            same(['pg:people|a.de/x' => 1, 'pg:people|(other)' => 2, 'n:a.de/y' => 3, 'nr:a.de/y|/z' => 1, 'pb:refused|a.de/w' => 1, 'p:CRAWL-GOOGLE:a.de/v' => 1, 'sm:a.de/sitemap.xml|200' => 1, 'a:allow' => 5],
                Stats::withHost(['pg:people|/x' => 1, 'pg:people|(other)' => 2, 'n:/y' => 3, 'nr:/y|/z' => 1, 'pb:refused|/w' => 1, 'p:CRAWL-GOOGLE:/v' => 1, 'sm:/sitemap.xml|200' => 1, 'a:allow' => 5], 'a.de'),
                'only real paths get the website; "(other)", counters without a path stay');
            same(['a.de/news/x'], array_keys(StatsReport::build($s, null, 1, SITES_T0, ['path' => 'a.de/news/'])['pages']), 'the filter: a website and its path');
            same(['/', '/news/x'], array_values(array_filter(array_keys(StatsReport::build($s, null, 1, SITES_T0, ['site' => 'a.de'])['pages']), static fn (string $p): bool => $p !== '(other)')), 'one website: the paths as they are');
            same(['a.de/', 'a.de/news/x'], array_values(array_filter(array_keys(StatsReport::build($s, null, 1, SITES_T0, ['site' => 'group:customer-a', 'path' => 'a.de/'])['pages']),
                static fn (string $p): bool => $p !== '(other)')), 'a group: its websites in front as well');
            $h = StatsPage::render($s, ['view' => 'site', 'action' => '/rs/stats/visitors', 'lang' => 'en', 'now' => SITES_T0, 'path' => 'a.de/news/']);
            truthy(strpos($h, 'a.de/news/x') !== false, 'the page takes the filter as it is');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 looking at the numbers does not change them: the dashboard\'s own requests that pass are not counted; refused, they are' => function (): void {
        $dir = sitesStatsDir();
        try {
            $s = sitesStatsSettings($dir, "[R-RS] restrict **/rs/** to 127.0.0.1\n");
            $shield = new Shield($s, new MemoryStore());
            foreach (['/rs/stats/visitors', '/rs/waf/live', '/demo/index.php/rs/stats', '/page', '/rs/waf/lists'] as $i => $path) {
                $r = Request::fromServer(['REQUEST_URI' => $path . ($i === 1 ? '?format=json' : ''), 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'a.de', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/136.0']);
                $d = $shield->decide($r, (float) SITES_T0);
                $shield->record($r, $d, $shield->explain($d, $r), (float) SITES_T0);
            }
            $r = Request::fromServer(['REQUEST_URI' => '/rs/waf/live', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'a.de', 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/136.0']);
            $d = $shield->decide($r, (float) SITES_T0);
            $shield->record($r, $d, $shield->explain($d, $r), (float) SITES_T0);
            $day = StatsReport::read($s, null, gmdate('Ymd', SITES_T0), gmdate('Ymd', SITES_T0))['days'][gmdate('Ymd', SITES_T0)] ?? [];
            same(['allow' => 1, 'reject' => 1], ['allow' => ($day['a:allow'] ?? 0) + ($day['a:allow-uncached'] ?? 0), 'reject' => $day['a:reject'] ?? 0],
                'only /page of the passing ones; the refusal from elsewhere is counted');
            $h = StatsPage::render(sitesStatsSettings($dir, "set stats-hosts a.de\n"), ['view' => 'sites', 'lang' => 'de', 'now' => SITES_T0]);
            truthy(strpos($h, '>Menschen</th>') !== false && strpos($h, '>Bots</th>') !== false && strpos($h, 'title="jede Anfrage eines Menschen, nicht nur Seiten') !== false
                && strpos($h, '>Verlauf</th>') !== false && strpos($h, 'title="Seitenaufrufe gegenüber dem gleich langen Zeitraum davor">±</th>') !== false, 'short headings, each explained on hover; the curve at the end');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 set stats-path: the plugin\'s own address -- its pages below it, the core\'s stay at <dashboard-path>/waf/' => function (): void {
        $dir = sitesStatsDir();
        try {
            same('/rs/stats', sitesStatsSettings($dir, '')->ext['stats']['path'] ?? null, 'the default: <dashboard-path>/stats (ext.stats.path, 0031 B.5)');
            same('/admin/rs/stats', sitesStatsSettings($dir, "set dashboard-path /admin/rs\n")->ext['stats']['path'] ?? null);
            $s = sitesStatsSettings($dir, "set stats-path /statistik\nset stats-hosts a.de\n");
            same(['sites' => '/statistik/sites', 'all' => '/statistik/overview', 'site' => '/statistik/visitors', 'shield' => '/statistik/protection', 'rules' => '/rs/waf/rules'],
                StatsPage::links($s), 'the plugin\'s pages below its path; rules is the core\'s');
            same(['sites', 'site', null], [StatsPage::viewFor($s, '/statistik'), StatsPage::viewFor($s, '/statistik/visitors'), StatsPage::viewFor($s, '/rs/stats/visitors')]);
            same('/statistik/visitors', \CjwNetwork\RequestShield\Frame::links($s)['site'], 'the tabs know it');
            same([true, true, false], [\CjwNetwork\RequestShield\Frame::isPage($s, '/demo/statistik/overview'), \CjwNetwork\RequestShield\Frame::isPage($s, '/rs/waf/live'),
                \CjwNetwork\RequestShield\Frame::isPage($s, '/rs/stats/overview')], 'the dashboard\'s pages (for the pace, the live view)');
            try {
                sitesStatsSettings($dir, "set stats-path statistik\n");
                throw new TestFailure('accepted a path without /');
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'stats.path') !== false, $e->getMessage());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-03 the page: a website switch (all, each, other), the choice kept in the links; the command line: --site' => function (): void {
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
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' stats ' . escapeshellarg("$dir/site.rules");
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
