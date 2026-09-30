<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats;

/**
 * The counters (Stats) of the last days, summed up for people and for code:
 * what the shield let through, checked and refused, which rules decided most,
 * what each known crawler did -- and a few sentences that answer the usual
 * question ("did GPTBot crawl the site, or was it refused?"). The data of
 * the dashboard (0012) and of "bin/request-shield stats"; json_encode() it
 * for a CMS.
 */
final class StatsReport
{
    /**
     * The last $days days, or a period: $o['from'] and $o['to'] (yyyymmdd),
     * grouped by $o['by'] (day, week, month, year) into 'periods', and
     * $o['crawler'] to look at one crawler only. Days older than the stats
     * keep them are only in their month's total: counted under the month.
     *
     * @param array{from?: string, to?: string, by?: string, crawler?: string} $o
     * @return array{from: string, to: string, days: int, by: string, periods: array<string, array<string, int>>, totals: array<string, int>, monitor: array<string, int>,
     *   daily: array<string, array<string, int>>, hourly: array<string, array<string, int>>, rules: array<string, int>,
     *   crawlers: array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}>, bots: array<string, int>, statuses: array<string, int>,
     *   notFound: array<string, array{count: int, referrers: array<string, int>}>, sentences: list<string>}
     */
    public static function build(Settings $s, ?Stats $stats = null, int $days = 7, ?int $now = null, array $o = []): array
    {
        $now ??= time();
        $days = max(1, $days);
        $to = $o['to'] ?? gmdate('Ymd', $now);
        $from = $o['from'] ?? gmdate('Ymd', (int) strtotime($to . ' UTC') - ($days - 1) * 86400);
        $days = (int) round(((int) strtotime($to . ' UTC') - (int) strtotime($from . ' UTC')) / 86400) + 1;
        $by = in_array($o['by'] ?? 'day', ['day', 'week', 'month', 'year'], true) ? ($o['by'] ?? 'day') : 'day';
        $only = $o['crawler'] ?? null;
        $read = ($stats ?? Stats::of($s))->read($from, $to);
        // Days and, where the days are gone, their months: each with the period it belongs to.
        $sets = [];
        foreach ($read['days'] as $day => $counts) {
            $t = (int) strtotime($day . ' UTC');
            $sets[] = [['day' => gmdate('Y-m-d', $t), 'week' => gmdate('o-\\WW', $t), 'month' => gmdate('Y-m', $t), 'year' => gmdate('Y', $t)][$by], $counts];
        }
        foreach ($read['months'] as $month => $counts) {
            $t = (int) strtotime($month . '01 UTC');
            $sets[] = [['day' => gmdate('Y-m', $t) . ' (month)', 'week' => gmdate('Y-m', $t) . ' (month)', 'month' => gmdate('Y-m', $t), 'year' => gmdate('Y', $t)][$by], $counts];
        }
        $periods = [];
        foreach ($sets as [$label, $counts]) {
            $b = self::buckets($counts);
            if ($only !== null) {
                foreach (['seen', 'verified', 'claimed', 'allowed', 'checked', 'refused', 'throttled'] as $e) {
                    $b[$e] = $counts["c:$only:$e"] ?? 0;
                }
            }
            $periods[$label] = Stats::add($periods[$label] ?? [], $b);
        }
        ksort($periods);

        $totals = [];
        $monitor = [];
        $rules = [];
        $bots = [];
        $crawlers = [];
        $pages = [];
        $daily = [];
        $statuses = [];
        $missing = [];
        $referrers = [];
        foreach ($read['days'] as $day => $counts) {
            $daily[(string) $day] = self::buckets($counts);
        }
        foreach ($sets as [, $counts]) {
            foreach ($counts as $k => $n) {
                [$type, $rest] = explode(':', (string) $k, 2) + ['', ''];
                switch ($type) {
                    case 'a':
                        $totals[$rest] = ($totals[$rest] ?? 0) + $n;
                        break;
                    case 'm':
                        $monitor[$rest] = ($monitor[$rest] ?? 0) + $n;
                        break;
                    case 'r':
                        $rules[$rest] = ($rules[$rest] ?? 0) + $n;
                        break;
                    case 'o':
                        $bots[$rest] = ($bots[$rest] ?? 0) + $n;
                        break;
                    case 's':
                        $statuses[$rest] = ($statuses[$rest] ?? 0) + $n;
                        break;
                    case 'n':
                        $missing[$rest] = ($missing[$rest] ?? 0) + $n;
                        break;
                    case 'nr':
                        [$path, $source] = explode('|', $rest, 2) + ['', ''];
                        $referrers[$path][$source] = ($referrers[$path][$source] ?? 0) + $n;
                        break;
                    case 'c':
                        $colon = strrpos($rest, ':');
                        $id = substr($rest, 0, (int) $colon);
                        $crawlers[$id][substr($rest, (int) $colon + 1)] = ($crawlers[$id][substr($rest, (int) $colon + 1)] ?? 0) + $n;
                        break;
                    case 'p':
                        $colon = (int) strpos($rest, ':');
                        $id = substr($rest, 0, $colon);
                        $pages[$id][substr($rest, $colon + 1)] = ($pages[$id][substr($rest, $colon + 1)] ?? 0) + $n;
                        break;
                }
            }
        }
        $hourly = [];
        foreach ($read['hours'] as $hour => $counts) {
            if ((string) $hour >= gmdate('YmdH', $now - 47 * 3600)) {
                $hourly[(string) $hour] = self::buckets($counts);
            }
        }
        arsort($rules);
        arsort($bots);
        ksort($statuses);
        arsort($missing);
        $notFound = [];
        foreach (array_slice($missing, 0, 20, true) as $path => $n) {
            $sources = $referrers[(string) $path] ?? [];
            arsort($sources);
            $notFound[(string) $path] = ['count' => $n, 'referrers' => array_slice($sources, 0, Stats::REFERRERS, true)];
        }

        $out = [];
        foreach ($s->crawlers as $id => $x) {
            if ($only !== null && $id !== $only) {
                continue;
            }
            $c = $crawlers[$id] ?? [];
            $top = $pages[$id] ?? [];
            arsort($top);
            $out[$id] = ['kind' => $x['kind'], 'policy' => $x['policy'], 'name' => $s->origin('text', $id) ?? self::names()[$id] ?? $id,
                'seen' => $c['seen'] ?? 0, 'verified' => $c['verified'] ?? 0, 'claimed' => $c['claimed'] ?? 0, 'allowed' => $c['allowed'] ?? 0,
                'checked' => $c['checked'] ?? 0, 'refused' => $c['refused'] ?? 0, 'throttled' => $c['throttled'] ?? 0, 'robots' => $c['robots'] ?? 0,
                'pages' => array_slice($top, 0, 10, true), 'last' => $read['last'][$id] ?? null];
        }
        return ['from' => $from, 'to' => $to, 'days' => $days, 'by' => $by, 'periods' => $periods, 'totals' => $totals, 'monitor' => $monitor, 'daily' => $daily, 'hourly' => $hourly,
            'rules' => array_slice($rules, 0, 20, true), 'crawlers' => $out, 'bots' => $bots, 'statuses' => $statuses, 'notFound' => $notFound,
            'sentences' => array_merge(self::sentences($out, $days), self::missing($notFound, $days))];
    }

    /**
     * Pages the site did not find, in words -- the ones its own pages link to
     * first: those are broken links to fix.
     *
     * @param array<string, array{count: int, referrers: array<string, int>}> $notFound
     * @return list<string>
     */
    private static function missing(array $notFound, int $days): array
    {
        if ($notFound === []) {
            return [];
        }
        $out = [];
        foreach ($notFound as $path => $x) {
            foreach ($x['referrers'] as $from => $n) {
                if (strncmp((string) $from, '/', 1) === 0) {
                    $out[] = "Broken link: $from links to $path, which was not found ({$n}×).";
                }
            }
        }
        $top = array_slice($notFound, 0, 3, true);
        $out[] = count($notFound) . ' ' . (count($notFound) === 1 ? 'page was' : 'pages were') . ' not found ' . ($days === 1 ? 'today' : "in the last $days days")
            . '; most asked for: ' . implode(', ', array_map(static fn (string $p, array $x): string => "$p ({$x['count']}×)", array_keys($top), $top)) . '.';
        return $out;
    }

    /**
     * The shipped crawlers' descriptions (rules/crawlers.php), for settings
     * that have none of their own.
     *
     * @return array<string, string>
     */
    private static function names(): array
    {
        $ready = require dirname(__DIR__, 2) . '/rules/crawlers.php';
        /** @var array<string, string> */
        return is_array($ready) && is_array($ready['names'] ?? null) ? $ready['names'] : [];
    }

    /**
     * A day's or an hour's counters as five numbers: let through (cacheable),
     * let through (not cacheable), checked, told to wait, refused.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function buckets(array $counts): array
    {
        return ['passed' => $counts['a:allow'] ?? 0, 'uncached' => $counts['a:allow-uncached'] ?? 0, 'checked' => $counts['a:challenge'] ?? 0,
            'throttled' => $counts['a:throttle'] ?? 0, 'refused' => $counts['a:reject'] ?? 0];
    }

    /**
     * In words: what each crawler did, who only borrowed a name, which AI
     * crawlers did not come.
     *
     * @param array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}> $crawlers
     * @return list<string>
     */
    private static function sentences(array $crawlers, int $days): array
    {
        $span = $days === 1 ? 'today' : "in the last $days days";
        $out = [];
        $absent = [];
        foreach ($crawlers as $id => $c) {
            $name = $c['name'] === $id ? $id : $c['name'] . " ($id)";
            $verified = $c['verified'];
            if ($verified > 0) {
                $got = [];
                foreach (['allowed' => 'let through', 'checked' => 'checked', 'throttled' => 'told to wait', 'refused' => 'refused'] as $e => $word) {
                    if ($c[$e] > 0) {
                        $got[] = ($c[$e] === $verified ? 'every time ' : number_format($c[$e]) . '× ') . $word;
                    }
                }
                $out[] = "$name came " . number_format($verified) . "× $span: " . implode(', ', $got)
                    . ($c['policy'] !== 'allow' ? " (as set: {$c['policy']})" : '') . ($c['robots'] > 0 ? '; it read robots.txt' : '') . '.';
            } elseif ($c['kind'] !== 'search') {
                $absent[] = $id;
            }
            if ($c['claimed'] > 0) {
                $out[] = number_format($c['claimed']) . ($c['claimed'] === 1 ? ' request' : ' requests') . " only pretended to be $name — treated as ordinary visitors.";
            }
        }
        if ($absent !== []) {
            $out[] = "No verified visit $span from: " . implode(', ', $absent) . '.';
        }
        return $out;
    }
}
