<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Access;
use CjwNetwork\RequestShield\Report\Frame;
use CjwNetwork\RequestShield\Routes;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\StatsExtension;
use CjwNetwork\RequestShield\Tests\RsTestExtension;

/**
 * The routes registry (0031 B.5): the dashboard's pages are compiled into
 * $s->routes -- the core's, the extensions' (Extension::routes($compiled)) --
 * and the frame, the statistics' links, the pace's exemption and a reader's
 * tabs derive from it. Uses withRegistry() from ExtensionTest.php and
 * ruleDir() from RuleFileTest.php.
 */

/** The settings a rule text compiles to; the directory removed. */
function routesSettings(string $text): Settings
{
    $dir = ruleDir(['site.rules' => $text]);
    try {
        return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

/** key => [path, role] of the routes, in order. */
function routesBrief(Settings $s): array
{
    $out = [];
    foreach ($s->routes as $path => $r) {
        $out[] = [$path, $r['key'], $r['ext'], $r['role'], $r['tab'] !== null];
    }
    return $out;
}

return [
    'the core alone: four routes below <dashboard-path>/waf, in the tabs\' order; links for rules, live, lists' => function (): void {
        withRegistry(function (): void {
            $s = Settings::from([]);
            same([
                '/rs/waf/rules' => ['key' => 'rules', 'ext' => null, 'tab' => ['Rules & setup', 'Regeln & Einrichtung'], 'role' => 'admin', 'order' => 50, 'page' => 'CjwNetwork\\RequestShield\\Report\\SetupPage'],
                '/rs/waf' => ['key' => 'live', 'ext' => null, 'tab' => null, 'role' => 'admin', 'order' => 60, 'page' => 'CjwNetwork\\RequestShield\\Report\\LivePage'],
                '/rs/waf/live' => ['key' => 'live', 'ext' => null, 'tab' => ['Live', 'Live'], 'role' => 'admin', 'order' => 60, 'page' => 'CjwNetwork\\RequestShield\\Report\\LivePage'],
                '/rs/waf/lists' => ['key' => 'lists', 'ext' => null, 'tab' => ['Lists', 'Listen'], 'role' => 'admin', 'order' => 70, 'page' => 'CjwNetwork\\RequestShield\\Report\\ListsPage'],
            ], $s->routes, 'exactly the core\'s, sorted by order then path');
            same(['rules' => '/rs/waf/rules', 'live' => '/rs/waf/live', 'lists' => '/rs/waf/lists'], Routes::links($s), 'one link per key, the pages with a tab');
            same(['rules' => '/demo/rs/waf/rules', 'live' => '/demo/rs/waf/live', 'lists' => '/demo/rs/waf/lists'], Routes::links($s, '/demo'), 'with a prefix');
            same(['rules' => ['Rules & setup', 'Regeln & Einrichtung'], 'live' => ['Live', 'Live'], 'lists' => ['Lists', 'Listen']], Routes::tabs($s));
            same(Routes::links($s), Frame::links($s), 'the frame derives its links');
            same(['/admin/rs/waf/rules', '/admin/rs/waf', '/admin/rs/waf/live', '/admin/rs/waf/lists'], array_keys(Settings::from(['dashboardPath' => '/admin/rs'])->routes), 'set dashboard-path moves them');
        });
    },
    'the statistics extension declares its pages: ext stats, roles, the start alias, the tabs\' order' => function (): void {
        $s = routesSettings("set stats on\nset stats-hosts a.de\n");
        same([
            ['/rs/stats', 'sites', 'stats', 'reader', false],
            ['/rs/stats/sites', 'sites', 'stats', 'reader', true],
            ['/rs/stats/overview', 'all', 'stats', 'admin', true],
            ['/rs/stats/visitors', 'site', 'stats', 'reader', true],
            ['/rs/stats/protection', 'shield', 'stats', 'reader', true],
            ['/rs/waf/rules', 'rules', null, 'admin', true],
            ['/rs/waf', 'live', null, 'admin', false],
            ['/rs/waf/live', 'live', null, 'admin', true],
            ['/rs/waf/lists', 'lists', null, 'admin', true],
        ], routesBrief($s), 'path, key, ext, role, has a tab');
        same(['sites', 'all', 'site', 'shield', 'rules', 'live', 'lists'], array_keys(Routes::tabs($s)), 'the tabs\' order (what Frame::TABS had)');
        same(['sites' => ['All websites', 'Alle Websites'], 'all' => ['Dashboard', 'Dashboard'], 'site' => ['Visitors & pages', 'Besucher & Seiten'], 'shield' => ['Protection', 'Schutz']],
            array_slice(Routes::tabs($s), 0, 4), 'the labels');
        same('sites', Routes::page($s, '/rs/stats'), 'the start with stats-hosts: all websites');
        // Without stats-hosts: no "sites", the start is the overview.
        $plain = routesSettings("set stats on\n");
        same(false, isset(Routes::links($plain)['sites']));
        same(['all', 'admin'], [Routes::page($plain, '/rs/stats/'), $plain->routes['/rs/stats']['role']], 'the start alias: the overview, the admin\'s');
        same(['all', 'site', 'shield', 'rules', 'live', 'lists'], array_keys(Routes::tabs($plain)));
        // The statistics page's own view: its routes and the core's rules.
        same(['sites' => '/rs/stats/sites', 'all' => '/rs/stats/overview', 'site' => '/rs/stats/visitors', 'shield' => '/rs/stats/protection', 'rules' => '/rs/waf/rules'],
            \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($s));
        same([null, null, 'sites'], [\CjwNetwork\RequestShield\Stats\Report\StatsPage::viewFor($s, '/rs/waf/live'), \CjwNetwork\RequestShield\Stats\Report\StatsPage::viewFor($s, '/rs/waf/rules'),
            \CjwNetwork\RequestShield\Stats\Report\StatsPage::viewFor($s, '/RS/stats/')], 'viewFor: never the core\'s pages (live, and rules since 0031 B.8); the start alias');
        same('/rs/stats', StatsExtension::of($s)['path'], 'the path in the slot');
        same(StatsExtension::defaults()['path'], StatsExtension::of(Settings::from([]))['path']);
    },
    'set stats-path moves the statistics\' routes, the frame follows (the rest in StatsSitesTest "set stats-path")' => function (): void {
        $s = routesSettings("set stats on\nset stats-path /statistik\n");
        same(['/statistik', '/statistik/overview', '/statistik/visitors', '/statistik/protection'], array_slice(array_keys($s->routes), 0, 4));
        same([true, true, false, 'site', null], [Frame::isPage($s, '/demo/statistik/visitors'), Frame::isPage($s, '/rs/waf/lists'), Frame::isPage($s, '/rs/stats/visitors'),
            Frame::pageFor($s, '/Statistik/visitors/'), Frame::pageFor($s, '/rs/stats/visitors')]);
        same('/statistik/protection', Frame::links($s)['shield']);
    },
    'an extension\'s route: ext its id, below dashboard-path, moved by set dashboard-path' => function (): void {
        withRegistry(function (): void {
            Vocabulary::offer(RsTestExtension::class);
            $s = routesSettings("set marks-max 3\n");
            same(['key' => 'ping', 'ext' => 'rs-test', 'tab' => null, 'role' => 'admin', 'order' => 90, 'page' => RsTestExtension::class], $s->routes['/rs/rs-test/ping'] ?? null);
            same('/rs/rs-test/ping', array_key_last($s->routes), 'order 90: last');
            $moved = routesSettings("set dashboard-path /admin/rs\nset marks-max 3\n");
            same([false, 'ping'], [isset($moved->routes['/rs/rs-test/ping']), $moved->routes['/admin/rs/rs-test/ping']['key'] ?? null]);
            same(false, isset(Routes::links($moved)['ping']), 'no tab: not among the links');
            same(['ping', 'admin'], [Routes::page($moved, '/admin/rs/rs-test/ping'), Routes::match($moved, '/x/admin/rs/rs-test/ping')['role'] ?? null]);
        });
    },
    'two routes on one path refuse to compile, naming both owners' => function (): void {
        foreach (['/rs/waf/live', '/RS/waf/live/', '/rs/stats/visitors'] as $path) {
            try {
                Settings::from(['routes' => [$path => ['key' => 'mine', 'tab' => null, 'role' => 'admin', 'order' => 1]]]);
                throw new TestFailure("accepted a second route on $path");
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), "'routes'") !== false && strpos($e->getMessage(), $path) !== false && strpos($e->getMessage(), 'the settings (routes)') !== false
                    && strpos($e->getMessage(), $path === '/rs/stats/visitors' ? 'the extension stats' : 'the core') !== false, $e->getMessage());
            }
        }
        // Two extensions: the second one's path.
        withRegistry(function (): void {
            Vocabulary::offer(RsTestExtension::class);
            try {
                Settings::from(['ext' => ['rs-test' => []], 'routes' => ['/rs/rs-test/ping/' => ['key' => 'mine', 'tab' => null, 'role' => 'admin', 'order' => 1]]]);
                throw new TestFailure('accepted a second route on the extension\'s path');
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'the extension rs-test') !== false, $e->getMessage());
            }
        });
        // Another path is fine, and sorted in.
        $s = Settings::from(['routes' => ['/rs/mine' => ['key' => 'mine', 'tab' => ['Mine', 'Meine'], 'role' => 'reader', 'order' => 45]]]);
        same('/rs/mine', Routes::links($s)['mine'] ?? null, 'kept');
        same(['all', 'site', 'shield', 'mine', 'rules', 'live', 'lists'], array_keys(Routes::tabs($s)), 'order 45: between the statistics and the rules');
    },
    'match(): the path or its end, the longest; page(): exact only' => function (): void {
        $s = Settings::from([]);
        same(['key' => 'live', 'ext' => null, 'tab' => ['Live', 'Live'], 'role' => 'admin', 'order' => 60, 'page' => 'CjwNetwork\\RequestShield\\Report\\LivePage', 'path' => '/rs/waf/live'], Routes::match($s, '/demo/index.php/rs/waf/live'), 'below a prefix: the entry with its path');
        same(['/rs/waf', '/rs/waf', '/rs/waf/live', null, null, null], [Routes::match($s, '/rs/waf')['path'] ?? null, Routes::match($s, '/app/RS/WAF/')['path'] ?? null, Routes::match($s, '/rs/waf/live/')['path'] ?? null,
            Routes::match($s, '/rs/waf/livestream'), Routes::match($s, '/xrs/waf'), Routes::match($s, '/')], 'capitals and a trailing / aside; a page below another is itself; never a longer name');
        same(['live', 'live', 'lists', null, null], [Routes::page($s, '/rs/waf'), Routes::page($s, '/RS/waf/live/'), Routes::page($s, '/rs/waf/lists'), Routes::page($s, '/demo/index.php/rs/waf/live'), Routes::page($s, '/rs')], 'page(): exact');
        same([true, false], [Frame::isPage($s, '/demo/index.php/rs/waf/live'), Frame::isPage($s, '/rs/wafx')], 'the frame derives isPage()');
    },
    'Access::links(): the admin gets everything, a reader the routes whose role is reader' => function (): void {
        $s = routesSettings("set stats on\nset stats-hosts a.de\n");
        $links = Frame::links($s);
        same(['sites', 'all', 'site', 'shield', 'rules', 'live', 'lists'], array_keys(Access::links($s, '*', $links)));
        same(['sites', 'site', 'shield'], array_keys(Access::links($s, 'customer-a', $links)), 'from the roles');
        $mine = Settings::from(['routes' => ['/rs/mine' => ['key' => 'mine', 'tab' => ['Mine', 'Meine'], 'role' => 'reader', 'order' => 45]]]);
        same(['site', 'shield', 'mine'], array_keys(Access::links($mine, 'customer-a', Frame::links($mine))), 'a reader\'s route of a site\'s own is a reader\'s tab');
        same([], Access::links($s, 'customer-a', ['live' => '/x', 'rules' => '/y']), 'nothing it may not open');
    },
];
