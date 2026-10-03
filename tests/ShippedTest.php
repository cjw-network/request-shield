<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Rules\Feeds;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\Shipped;
use CjwNetwork\RequestShield\Settings;

/**
 * Rules\Shipped (0031 E.1): the one class that reads rules/. In the
 * repository it reads the directory; with its constants filled -- what the
 * single-file build does (E.2) -- it reads nothing there, and the rules
 * compile to the same settings.
 */

/**
 * Shipped.php with the data embedded and rules/ pointed nowhere: what the
 * build will generate, in its smallest form.
 */
function shippedEmbedded(): string
{
    $root = dirname(__DIR__);
    $src = (string) file_get_contents("$root/src/Rules/Shipped.php");
    $rules = [];
    foreach (glob("$root/rules/*.rules") ?: [] as $f) {
        $rules[basename($f, '.rules')] = (string) file_get_contents($f);
    }
    $lists = [];
    foreach (glob("$root/rules/crawlers/*.json") ?: [] as $f) {
        $lists[basename($f, '.json')] = (string) file_get_contents($f);
    }
    $starters = [];
    foreach (glob("$root/rules/starter/*.rules") ?: [] as $f) {
        $starters[basename($f, '.rules')] = (string) file_get_contents($f);
    }
    $out = str_replace(
        ['public const RULES = [];', "public const FEEDS = '';", 'public const CRAWLER_LISTS = [];', 'public const STARTERS = [];', "return dirname(__DIR__, 2) . '/rules';"],
        ['public const RULES = ' . var_export($rules, true) . ';', 'public const FEEDS = ' . var_export((string) file_get_contents("$root/rules/feeds.json"), true) . ';',
            'public const CRAWLER_LISTS = ' . var_export($lists, true) . ';', 'public const STARTERS = ' . var_export($starters, true) . ';', "return '/nonexistent/rules';"],
        $src, $count);
    if ($count !== 5) {
        throw new TestFailure("Shipped.php no longer has the five places the build fills ($count found)");
    }
    return $out;
}

/** The rules every case compiles: two included sets, a block reference and a crawler with a shipped list. */
const SHIPPED_SITE = "host a.example\ninclude @wordpress @tracking\n[OWN-CLAUDE] crawler ai-user ua /Claude-Own/ ranges anthropic\n";

return [
    'in the repository: the sets, the feed catalog and the address lists are read from rules/; a name never leaves the directory' => function (): void {
        if (rsSingle() !== null) {
            skip('the suite runs against the single file, whose data is embedded (SingleFileTest checks it)');
        }
        $root = (string) realpath(dirname(__DIR__) . '/rules');
        same(false, Shipped::embedded());
        truthy(strpos((string) Shipped::rules('scanners'), 'SCAN-HIDDEN') !== false, 'a set by name');
        same(Shipped::rules('wordpress'), Shipped::rules('@wordpress'), 'with or without the @');
        same([null, null, null, null], [Shipped::rules('../README'), Shipped::rules('nothing'), Shipped::rulesFile('Scanners'), Shipped::crawlerList('../feeds')], 'unknown or not a name: none');
        same("$root/scanners.rules", Shipped::rulesFile('@scanners'));
        truthy(array_intersect(['attacks', 'crawlers', 'scanners', 'tracking', 'wordpress'], Shipped::sets()) === ['attacks', 'crawlers', 'scanners', 'tracking', 'wordpress'], implode(', ', Shipped::sets()));
        truthy(is_array(json_decode(Shipped::feeds(), true)) && Feeds::catalog() !== [], 'the feed catalog');
        truthy(strpos((string) Shipped::crawlerList('anthropic'), '"prefixes"') !== false && Shipped::crawlerListFile('anthropic') === "$root/crawlers/anthropic.json", 'an address list and its file');
        same([true, true, false, false], [Shipped::isShipped("$root/scanners.rules"), Shipped::isShipped("$root/crawlers/anthropic.json"), Shipped::isShipped(__FILE__), Shipped::isShipped('/nonexistent')]);
        same(['built-in scanners.rules', null], [Shipped::label("$root/scanners.rules"), Shipped::label(__FILE__)]);
        same(require "$root/crawlers.php", Shipped::crawlers(), 'the ready crawlers are rules/crawlers.php');
        same(RuleFile::shippedReady(), Shipped::crawlers(), 'and what the shipped set compiles to (the single file builds them so)');
        truthy(strpos((string) Shipped::starter('plain'), 'set mode monitor') !== false && Shipped::starter('../plain') === null && Shipped::starter('nothing') === null, 'the starters for init');
    },
    'embedded (the single file): nothing is read from rules/, the same rules compile to the same settings, the file itself is watched' => function (): void {
        if (!function_exists('exec')) {
            skip('no exec');
        }
        if (rsSingle() !== null) {
            skip('the suite runs against the single file: Shipped is declared by it already (SingleFileTest puts a request through it)');
        }
        $dir = sys_get_temp_dir() . '/rs-shipped-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/Shipped.php", shippedEmbedded());
            file_put_contents("$dir/site.rules", SHIPPED_SITE);
            // The same rules read here, from rules/, to compare with.
            $here = RuleFile::read(["$dir/site.rules"]);
            file_put_contents("$dir/run.php", '<?php require ' . var_export("$dir/Shipped.php", true) . '; require ' . var_export(rsEntry(), true) . ";\n"
                . 'use CjwNetwork\\RequestShield\\Rules\\{Feeds, RuleFile, Shipped}; use CjwNetwork\\RequestShield\\Settings;' . "\n"
                . '$read = RuleFile::read([' . var_export("$dir/site.rules", true) . ']);' . "\n"
                . '$s = Settings::from($read["config"]);' . "\n"
                . 'echo json_encode(["embedded" => Shipped::embedded(), "config" => $read["config"], "seen" => array_keys($read["seen"]), "blocked" => count($s->blockedPaths),'
                . ' "crawlers" => array_keys(Settings::from([])->crawlers), "feeds" => array_keys(Feeds::catalog()), "sets" => Shipped::sets(), "starters" => array_map([Shipped::class, "starter"], Shipped::starters()), "isShipped" => Shipped::isShipped(' . var_export("$dir/Shipped.php", true) . ')]);');
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/run.php") . ' 2>&1', $out, $code);
            $got = json_decode(implode("\n", $out), true);
            truthy($code === 0 && is_array($got), 'ran: ' . implode("\n", $out));
            same(true, $got['embedded']);
            same(json_decode((string) json_encode($here['config']), true), $got['config'], 'the same settings, origins and all ("built-in wordpress.rules:3" as before)');
            $rules = (string) realpath(dirname(__DIR__) . '/rules');
            same([], array_values(array_filter($got['seen'], static fn (string $f): bool => strncmp($f, $rules, strlen($rules)) === 0)), 'nothing of rules/ is watched -- it is not there');
            truthy(in_array("$dir/Shipped.php", $got['seen'], true), 'the file the data lives in is: an update rebuilds the settings');
            same(count(Settings::from($here['config'])->blockedPaths), $got['blocked']);
            same(array_keys(Settings::from([])->crawlers), $got['crawlers'], 'settings from a PHP array: the shipped crawlers, built from the embedded set and lists');
            same(array_keys(Feeds::catalog()), $got['feeds'], 'the feed catalog');
            same(Shipped::sets(), $got['sets']);
            same(array_map([Shipped::class, 'starter'], Shipped::starters()), $got['starters'], 'the starters, embedded');
            same(true, $got['isShipped'], 'the single file is "shipped" for check');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
