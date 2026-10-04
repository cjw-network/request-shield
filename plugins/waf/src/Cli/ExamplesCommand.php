<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Waf\Cli;

use CjwNetwork\RequestShield\Cli\Command;
use CjwNetwork\RequestShield\Cli\Context;
use CjwNetwork\RequestShield\Rules\Examples;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Waf\DemoSite;
use CjwNetwork\RequestShield\Waf\ExamplesPage;

/**
 * `request-shield examples <main.rules>`: the "# demo:" groups of a rule file
 * (one per feature, 0031 F.4/F.5) -- as Markdown for the docs, as a page that
 * needs no server (recorded by test), or what is covered.
 */
final class ExamplesCommand implements Command
{
    public static function usage(): string
    {
        return 'request-shield examples <main.rules> --markdown [--feature=RSF02-06] | --html [--out=<file>] | --coverage';
    }

    public static function run(Context $c): int
    {
        $show = $c->option('show', []);
        $show = is_array($show) ? $show : [];
        $feature = is_string($show['feature'] ?? null) ? $show['feature'] : null;
        $out = is_string($show['out'] ?? null) ? $show['out'] : null;
        try {
            $groups = DemoSite::groups($c->file);
            if ($feature !== null) {
                // One feature's groups, in every form; an id with none is a mistake, never an empty table.
                $groups = array_values(array_filter($groups, static fn (array $g): bool => $g['id'] === $feature));
                if ($groups === []) {
                    fwrite(STDERR, "request-shield: no \"# demo: $feature\" group in {$c->file}\n");
                    return 2;
                }
            }
            $run = ($show['html'] ?? false) || ($show['coverage'] ?? false) ? Examples::run([...$c->sources, $c->file]) : ['results' => [], 'without' => []];
        } catch (RuleFileException | \InvalidArgumentException $e) {
            fwrite(STDERR, 'request-shield: ' . $e->getMessage() . "\n");
            return 2;
        }
        if ($show['markdown'] ?? false) {
            echo ExamplesPage::markdown($groups);
            return 0;
        }
        if ($show['html'] ?? false) {
            $results = [];
            foreach ($run['results'] as $r) {
                $results[$r['example']['at']] = ['status' => $r['status'], 'got' => $r['got'], 'gotRule' => $r['gotRule'], 'http' => $r['http']];
            }
            $page = ExamplesPage::html($groups, $results, 'request-shield demo -- ' . basename($c->file),
                'Recorded by request-shield test, ' . Shield::VERSION . ' (' . Shield::BUILD . '): every row decided on a fresh store, as the rules say. The live demo answers the same: php -S 127.0.0.1:8080 examples/demo/router.php');
            if ($out !== null) {
                if (@file_put_contents($out, $page) === false) {
                    fwrite(STDERR, "request-shield: cannot write $out\n");
                    return 2;
                }
                echo "written: $out\n";
            } else {
                echo $page;
            }
            return 0;
        }
        if ($show['coverage'] ?? false) {
            // Per feature group: rows, decided, passing; then the rules without an example.
            foreach ($groups as $g) {
                $expects = array_filter($g['rows'], static fn (array $r): bool => $r['kind'] === 'expect');
                $ok = 0;
                foreach ($run['results'] as $r) {
                    foreach ($expects as $x) {
                        $ok += $r['example']['at'] === $x['at'] && $r['status'] === 'pass' ? 1 : 0;
                    }
                }
                echo str_pad($g['id'], 10) . str_pad((string) count($expects), 4, ' ', STR_PAD_LEFT) . ' examples, ' . $ok . ' pass, ' . (count($g['rows']) - count($expects)) . ' to look at  ' . $g['title'] . "\n";
            }
            echo "\n" . count($run['without']) . ' rule(s) without an example' . ($run['without'] !== [] ? ': ' . implode(', ', $run['without']) : '') . "\n";
            return 0;
        }
        fwrite(STDERR, 'usage: ' . self::usage() . "\n");
        return 2;
    }
}
