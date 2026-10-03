<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\StatsExtension;
use CjwNetwork\RequestShield\Stats\StatsPlugin;

/**
 * The statistics' settings as an extension (0031 B.3/B.4): the stats words
 * and set keys of the rule file compile into ext.stats, StatsPlugin joins the
 * plugins when something is counted, the core carries none of it. Uses
 * ruleDir()/rulesFail() from RuleFileTest.php.
 */

/** The settings a rule text compiles to (the main file site.rules; more files by name). */
function statsExtSettings(string $text, ?string $site = null, array $more = []): Settings
{
    $dir = ruleDir(['site.rules' => $text] + $more);
    try {
        return Settings::from(RuleFile::read(["$dir/site.rules"], $site)['config']);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

return [
    'offered by the bootstrap: a rule file\'s stats words compile to the slot, and StatsPlugin joins the plugins' => function (): void {
        same(StatsExtension::class, Vocabulary::extension('stats'), 'the bootstrap names it, the registry offers it on the first lookup');
        $s = statsExtSettings("set stats requests pages\nset stats-flush 30s\nset stats-hosts a.example\nstats-skip /x/**\nmatch /app/** {\n  stats-skip\n}\n");
        $skip = ['#^/x(?:/.*)?$#', '#^/app(?:/.*)?$#'];
        same(['enabled' => true, 'parts' => ['requests', 'pages'], 'hours' => 7, 'days' => 400, 'months' => 0, 'flush' => 30, 'depth' => 2, 'path' => '/rs/stats', 'hosts' => ['a.example'], 'skip' => $skip, 'groups' => [],
            'crawlerLog' => ['dir' => null, 'kinds' => [], 'days' => 30, 'query' => true]], $s->ext['stats'] ?? null, 'the exact shape, every key present');
        same($s->ext['stats'] ?? null, StatsExtension::of($s), 'of(): the slot');
        same([StatsPlugin::class], $s->plugins, 'the plugin, added at compile time');
        same('/x/**', $s->origin('written', $skip[0]), 'a path as written, for the pages');
        // The crawler log's keys land below crawlerLog; the path is relative to the rule file.
        $dir = ruleDir(['site.rules' => "set crawler-log logs/crawlers\nset crawler-log-kinds ai-training\nset crawler-log-days 14\nset crawler-log-query off\n"]);
        try {
            $log = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        same(['dir' => "$dir/logs/crawlers", 'kinds' => ['ai-training'], 'days' => 14, 'query' => false], StatsExtension::of($log)['crawlerLog']);
        same([false, [StatsPlugin::class]], [StatsExtension::of($log)['enabled'], $log->plugins], 'the crawler log alone needs the plugin too');
    },
    'set stats off: nothing counted, no plugin; the defaults when nothing is set' => function (): void {
        $off = statsExtSettings("set stats on\nset stats off\n");
        same([false, []], [StatsExtension::of($off)['enabled'], $off->plugins]);
        same(StatsExtension::defaults(), StatsExtension::of(statsExtSettings('')), 'an empty rule file: the defaults, compiled');
        same(StatsExtension::defaults(), StatsExtension::of(Settings::from([])), 'an empty array: the same');
        same(false, StatsExtension::defaults()['enabled']);
    },
    'inside a site block: stats-hosts is about the server; the other keys are the website\'s' => function (): void {
        // Checked when the website's block is read (the base skips the blocks, as for the core's server-wide keys).
        try {
            statsExtSettings("host a.example\nsite a.example {\n  set stats-hosts a.example\n}\n", 'a.example');
            throw new TestFailure('accepted set stats-hosts inside a site block');
        } catch (\CjwNetwork\RequestShield\Rules\RuleFileException $e) {
            same('site.rules:3: set stats-hosts is about the server, not a website -- put it above the site blocks', $e->getMessage());
        }
        $s = statsExtSettings("host a.example b.example\nset stats on\nsite b.example {\n  set stats-depth 3\n}\n", 'b.example');
        same([true, 3], [StatsExtension::of($s)['enabled'], StatsExtension::of($s)['depth']], 'the base\'s values and its own');
    },
    'mistakes keep their messages: a part, a host name, a kind, a depth' => function (): void {
        rulesFail(['site.rules' => "set stats everything\n"], 'site.rules:1', 'stats is on, off or what to count: requests, crawlers, not-found, bots, pages, forms -- not "everything"');
        rulesFail(['site.rules' => "set stats-hosts a_b.de\n"], 'site.rules:1', 'stats-hosts takes website names (www.example.org, *.example.org), host or sites -- not "a_b.de"');
        rulesFail(['site.rules' => "set crawler-log-kinds robots\n"], 'site.rules:1', 'crawler-log-kinds takes kinds of crawler (search, ai-search, ai-user, ai-training), not "robots"');
        rulesFail(['site.rules' => "set stats-depth many\n"], 'site.rules:1', 'stats-depth is a number');
        rulesFail(['site.rules' => "stats-skip\n"], 'site.rules:1', 'stats-skip needs at least one value');
        rulesFail(['site.rules' => "match /x/** {\n  stats-skip /y/**\n}\n"], 'site.rules:2', 'inside match, stats-skip takes no paths');
        foreach ([[['depth' => 0], 'ext.stats.depth'], [['parts' => ['everything']], 'ext.stats.parts'], [['crawlerLog' => ['dir' => '']], 'ext.stats.crawlerLog.dir'], [['hosts' => [1]], 'ext.stats.hosts']] as [$bad, $key]) {
            try {
                Settings::from(['ext' => ['stats' => $bad]]);
                throw new TestFailure('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), "'$key'") !== false, "names $key: " . $e->getMessage());
            }
        }
    },
    'from a PHP array: the defaults around what is given; a group\'s websites are counted apart too' => function (): void {
        $s = Settings::from(['ext' => ['stats' => ['enabled' => true]]]);
        same(['enabled' => true] + StatsExtension::defaults(), StatsExtension::of($s));
        same([StatsPlugin::class], $s->plugins);
        $s = Settings::from(['hosts' => ['A.de', 'c.de'], 'ext' => ['stats' => ['hosts' => ['host', 'x.org.']]]]);
        same(['a.de', 'c.de', 'x.org'], StatsExtension::of($s)['hosts'], 'host: the host rule\'s names; lower case, no trailing dot');
        $g = statsExtSettings("set stats on\nset stats-hosts c.de\nstats-group \"Customer A\" a.de www.a.de\n");
        same(['c.de', 'a.de', 'www.a.de'], StatsExtension::of($g)['hosts'], 'stats-group (still the core\'s) feeds the hosts');
        $round = Settings::import(eval('return ' . var_export($g->export(), true) . ';'));
        same(serialize($g), serialize($round), 'export/import keep the slot and the plugin');
    },
    'the core carries none of it: no stats property in Settings, no stats key in RuleFile' => function (): void {
        $settings = (string) file_get_contents(__DIR__ . '/../src/Settings.php');
        foreach (['statsEnabled', 'statsHours', 'statsDays', 'statsParts', 'statsFlush', 'statsMonths', 'statsDepth', 'statsHosts', 'statsSkip', 'crawlerLogDir', 'crawlerLogKinds', 'crawlerLogDays', 'crawlerLogQuery', 'STATS_PARTS'] as $name) {
            truthy(strpos($settings, '$' . $name) === false && strpos($settings, '->' . $name) === false && strpos($settings, '::' . $name) === false, "Settings.php still has $name");
        }
        $ruleFile = (string) file_get_contents(__DIR__ . '/../src/Rules/RuleFile.php');
        foreach (["'stats-flush'", "'stats-hosts'", "'crawler-log'", "'stats-skip'", "'stats' =>"] as $key) {
            truthy(strpos($ruleFile, $key) === false, "RuleFile.php still has $key");
        }
        same([], array_intersect(['stats', 'stats-flush', 'stats-hosts', 'crawler-log'], RuleFile::coreSettings()), 'not core settings');
        truthy(!in_array('stats-skip', RuleFile::coreWords(), true), 'stats-skip is not a core word');
        same(['stats-skip'], array_values(array_intersect(Vocabulary::known()['words'], ['stats-skip'])), 'but the extension\'s');
    },
    'a passing request pays nothing for the shipped extension: the bootstrap loads no registry class (ADR 0008)' => function (): void {
        // A fresh CLI process: after bootstrap.php, the extension is named (the constant) but nothing of it is loaded;
        // the first lookup loads and offers it. The autoloader's matter: the source tree's bootstrap also when the
        // suite runs against the single file (which declares its classes, cheaply, from OPcache).
        $code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . ' $loaded = array_values(array_intersect(get_declared_classes(), ["CjwNetwork\\RequestShield\\Stats\\StatsExtension", "CjwNetwork\\RequestShield\\Extension", "CjwNetwork\\RequestShield\\Rules\\Vocabulary"]));'
            . ' $named = defined("REQUEST_SHIELD_EXTENSIONS") ? constant("REQUEST_SHIELD_EXTENSIONS") : null;'
            . ' $offered = \CjwNetwork\RequestShield\Rules\Vocabulary::extensions();'
            . ' echo json_encode([$loaded, $named, $offered, class_exists("CjwNetwork\\RequestShield\\Stats\\StatsExtension", false)]);';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $exit);
        $got = json_decode(implode("\n", $out), true);
        same(0, $exit, implode("\n", $out));
        same([[], [StatsExtension::class], ['stats' => StatsExtension::class], true], $got, 'loaded after the bootstrap: none; named: the stats extension; after the first lookup: offered and loaded');
        truthy(!is_subclass_of(StatsExtension::class, \CjwNetwork\RequestShield\Plugin::class), 'the extension itself is no plugin: it is never on the request path');
    },
    'a Composer install without bootstrap.php: the autoload file names the shipped extension, so set stats on is known there too' => function (): void {
        if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
            skip('no vendor/autoload.php (composer install)');
        }
        if (!function_exists('exec')) {
            skip('no exec');
        }
        $code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; '
            . 'echo json_encode([defined("REQUEST_SHIELD_EXTENSIONS") ? constant("REQUEST_SHIELD_EXTENSIONS") : null, array_keys(\\CjwNetwork\\RequestShield\\Rules\\Vocabulary::extensions())]);';
        exec(PHP_BINARY . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $exit);
        same(0, $exit, implode("\n", $out));
        same([[StatsExtension::class], ['stats']], json_decode(implode("\n", $out), true), 'named by plugins/stats/shipped.php (composer.json autoload files), offered on the first lookup');
    },
];
