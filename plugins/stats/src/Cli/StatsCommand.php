<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Stats\Cli;

use CjwNetwork\RequestShield\Cli\Command;
use CjwNetwork\RequestShield\Cli\Context;
use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Stats\Report\StatsReport;
use CjwNetwork\RequestShield\Stats\Stats;
use CjwNetwork\RequestShield\Stats\StatsExtension;

/**
 * `request-shield stats <main.rules>`: what the counters (set stats on) say
 * about the last days -- requests let through, checked, refused, the rules
 * behind them, the answers' status codes, pages not found and who links to
 * them, what each known crawler did, other bots -- in words, or as JSON for a
 * CMS. The statistics extension's command (Extension::commands(), 0031 D.1).
 */
final class StatsCommand implements Command
{
    public static function usage(): string
    {
        return 'request-shield stats <main.rules> [--days=7 | --from=YYYY-MM-DD --to=YYYY-MM-DD] [--by=day|week|month|year] [--crawler=<ID>] [--path=/news/] [--sort=views|blocked|refused|checked|throttled] [--site=<name>|--group=<name>] [--json]';
    }

    public static function run(Context $c): int
    {
        /** @var array{from?: string, to?: string, by?: string, crawler?: string, path?: string, sort?: string, site?: string} $period */
        $period = is_array($c->option('period')) ? $c->option('period') : [];
        $days = is_int($c->option('days')) ? $c->option('days') : 7;
        $json = $c->option('json') === true;
        $so = StatsExtension::of($c->settings);
        if (!$so['enabled']) {
            fwrite(STDERR, "no statistics: switch them on with \"set stats on\" in the rule file\n");
            return 1;
        }
        if (isset($period['crawler']) && !isset($c->settings->crawlers[$period['crawler']])) {
            fwrite(STDERR, "no crawler {$period['crawler']} -- see bin/request-shield crawlers " . basename($c->file) . "\n");
            return 2;
        }
        if (isset($period['site']) && $period['site'] !== 'other' && !Stats::known($c->settings, $period['site'])) {
            fwrite(STDERR, $so['hosts'] === [] ? "no statistics per website: set stats-hosts (or stats-group) in the rule file\n"
                : (strncmp($period['site'], 'group:', 6) === 0 ? 'no group ' . substr($period['site'], 6) . ' (' . implode(', ', array_keys($so['groups'])) . ")\n"
                : "no website {$period['site']} in stats-hosts (" . implode(', ', $so['hosts']) . ", other)\n"));
            return 2;
        }
        if (($period['site'] ?? null) === 'other') {
            $period['site'] = Stats::OTHER;
        }
        /** @var array{from?: string, to?: string, by?: string, crawler?: string, path?: string, sort?: string, site?: string} $period */
        $r = StatsReport::build($c->settings, null, $days, null, $period);
        if ($json) {
            echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }
        $n = static fn (int $v): string => number_format($v);
        $t = $r['totals'];
        $all = array_sum($t);
        echo ($period === [] ? "Last $days day(s), " : '') . substr($r['from'], 0, 4) . '-' . substr($r['from'], 4, 2) . '-' . substr($r['from'], 6) . ' to ' . substr($r['to'], 0, 4) . '-' . substr($r['to'], 4, 2) . '-' . substr($r['to'], 6) . ":\n\n";
        echo '  ' . $n($all) . ' requests: ' . $n(($t['allow'] ?? 0) + ($t['allow-uncached'] ?? 0)) . ' let through, ' . $n($t['challenge'] ?? 0) . ' checked, '
            . $n($t['throttle'] ?? 0) . ' told to wait, ' . $n($t['reject'] ?? 0) . " refused\n";
        if ($r['monitor'] !== []) {
            echo '  monitor mode would have: ' . implode(', ', array_map(static fn (string $a, int $v): string => "$a $v", array_keys($r['monitor']), $r['monitor'])) . "\n";
        }
        if ($r['statuses'] !== []) {
            echo '  answers: ' . implode(', ', array_map(static fn (string $c, int $v): string => "$c: $v", array_map('strval', array_keys($r['statuses'])), $r['statuses'])) . "\n";
        }
        $tm = $r['times'];
        if ($tm !== null) {
            // How long the site took (0046): what did not come from the cache; the cache; the shield.
            $d = static fn (?int $us): string => $us === null ? '-' : StatsReport::duration($us);
            $site = $tm['site'];
            echo '  the site took: median ' . $d($site['p50']) . ', slow end (p95) ' . $d($site['p95']) . ', average ' . $d($site['avg']) . ' -- ' . $n($site['count']) . " requests\n";
            $hit = $tm['kinds']['hit'];
            if ($tm['hitShare'] !== null) {
                echo '  HTTP cache: ' . (int) round(100 * $tm['hitShare']) . ' % hits (hit ' . $d($hit['p50']) . ', miss ' . $d($tm['kinds']['miss']['p50']) . '), saved ' . $d($tm['saved'])
                    . ($tm['reasons'] !== [] ? '; not kept: ' . implode(', ', array_map(static fn (string $w, int $v): string => "$w $v", array_map('strval', array_keys($tm['reasons'])), $tm['reasons'])) : '') . "\n";
            }
            if ($tm['shield'] !== null) {
                echo '  the shield: ' . $d($tm['shield']) . " a request\n";
            }
            if ($tm['slow'] !== []) {
                echo "\nSlow requests (the last):\n  " . implode("\n  ", $tm['slow']) . "\n";
            }
        }
        if (isset($period['by']) || isset($period['crawler'])) {
            $cols = isset($period['crawler']) ? ['verified' => 'visits', 'allowed' => 'let through', 'checked' => 'checked', 'refused' => 'refused', 'claimed' => 'claimed']
                : ['passed' => 'cacheable', 'uncached' => 'uncached', 'checked' => 'checked', 'throttled' => 'waited', 'refused' => 'refused'];
            echo "\n" . str_pad('', 20) . implode('', array_map(static fn (string $c): string => str_pad($c, 13, ' ', STR_PAD_LEFT), $cols)) . "\n";
            foreach ($r['periods'] as $label => $b) {
                echo '  ' . str_pad((string) $label, 18) . implode('', array_map(static fn (string $k): string => str_pad($n($b[$k] ?? 0), 13, ' ', STR_PAD_LEFT), array_keys($cols))) . "\n";
            }
        }
        if ($r['rules'] !== []) {
            echo "\nRules that decided most:\n";
            foreach (array_slice($r['rules'], 0, 10, true) as $rule => $v) {
                echo '  ' . str_pad($n($v), 8, ' ', STR_PAD_LEFT) . "  $rule\n";
            }
        }
        // A page's or section's numbers: its views by who came, what the shield stopped by how.
        $cols = static fn (array $v): string => implode('', array_map(static fn (string $k, int $w): string => str_pad($n(is_int($v[$k] ?? null) ? $v[$k] : 0), $w, ' ', STR_PAD_LEFT),
            ['total', 'people', 'crawlers', 'bots', 'blocked', 'refused', 'checked', 'throttled'], [8, 9, 9, 8, 9, 8, 8, 8]));
        if ($r['subtree'] !== null) {
            $st = $r['subtree'];
            echo "\nSubtree {$st['path']}: " . $n($st['total']) . ' views (people ' . $n($st['people']) . ', crawlers ' . $n($st['crawlers']) . ', bots ' . $n($st['bots']) . ')'
                . ($st['exact'] ? '' : ' -- the sum of its most visited pages') . ($st['blocked'] > 0 ? '; stopped ' . $n($st['blocked']) . ' (refused ' . $n($st['refused']) . ', checked ' . $n($st['checked']) . ', told to wait ' . $n($st['throttled']) . ')' : '') . "\n";
        }
        if ($r['folders'] !== []) {
            echo "\n" . str_pad($r['sort'] === 'views' ? 'Most visited sections' : 'Sections stopped most', 24) . "    total   people crawlers    bots  stopped refused checked  waited\n";
            foreach (array_slice($r['folders'], 0, 10, true) as $folder => $v) {
                echo '  ' . str_pad((string) $folder, 24) . $cols($v) . "\n";
            }
        }
        if ($r['pages'] !== []) {
            echo "\n" . str_pad($r['sort'] === 'views' ? 'Most visited pages' : 'Pages stopped most', 24) . "    total   people crawlers    bots  stopped refused checked  waited\n";
            foreach (array_slice($r['pages'], 0, 10, true) as $path => $v) {
                echo '  ' . str_pad((string) $path, 24) . $cols($v) . "\n";
            }
        }
        // Forms (proposal 0028): sent, from where, how they ended; the editors' area apart.
        foreach (['forms' => 'forms', 'backend' => "forms in the editors' area"] as $key => $title) {
            if ($r[$key] === []) {
                continue;
            }
            echo "\n$title:\n";
            foreach ($r[$key] as $path => $f) {
                $from = [];
                foreach ($f['from'] as $src => $count) {
                    $src = (string) $src;
                    $from[] = ($src === '-' ? 'no page given' : ($src === '=' ? 'this website' : ($src[0] === '@' ? 'another website ' . substr($src, 1) : $src))) . " $count";
                }
                printf("  %-40s %6d sent   %d saved · %d errors · %d stopped%s%s\n", $path, $f['sent'], $f['saved'], $f['error'], $f['stopped'],
                    $f['cross-site'] > 0 ? ' (' . $f['cross-site'] . ' from another website)' : '', $from !== [] ? '   from ' . implode(' · ', $from) : '');
            }
        }
        if ($r['notFound'] !== []) {
            echo "\nNot found:\n";
            foreach ($r['notFound'] as $path => $x) {
                echo '  ' . str_pad($n($x['count']), 8, ' ', STR_PAD_LEFT) . "  $path" . ($x['referrers'] !== [] ? '   linked from: ' . implode(', ', array_map(static fn (string $f, int $v): string => "$f ($v)", array_map('strval', array_keys($x['referrers'])), $x['referrers'])) : '') . "\n";
            }
        }
        echo "\nKnown crawlers                  seen  verified claimed allowed checked refused  last\n";
        foreach ($r['crawlers'] as $id => $cr) {
            if ($cr['seen'] === 0) {
                continue;
            }
            echo '  ' . str_pad($id, 26) . str_pad($n($cr['seen']), 8, ' ', STR_PAD_LEFT) . str_pad($n($cr['verified']), 10, ' ', STR_PAD_LEFT) . str_pad($n($cr['claimed']), 8, ' ', STR_PAD_LEFT)
                . str_pad($n($cr['allowed']), 8, ' ', STR_PAD_LEFT) . str_pad($n($cr['checked']), 8, ' ', STR_PAD_LEFT) . str_pad($n($cr['refused']), 8, ' ', STR_PAD_LEFT)
                . '  ' . (is_array($cr['last']) ? date('Y-m-d H:i', (int) $cr['last'][0]) : '-') . "\n";
        }
        if ($r['bots'] !== []) {
            echo "\nOther bots (by what they say they are): " . implode(', ', array_map(static fn (string $f, int $v): string => "$f $v", array_keys($r['bots']), $r['bots'])) . "\n";
        }
        if ($r['sentences'] !== []) {
            echo "\nIn short:\n  " . implode("\n  ", $r['sentences']) . "\n";
        }
        return 0;    }
}
