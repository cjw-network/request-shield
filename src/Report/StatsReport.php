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
     * @param array{from?: string, to?: string, by?: string, crawler?: string, lang?: string} $o  lang: the sentences' language, en or de
     * @return array{from: string, to: string, days: int, by: string, periods: array<string, array<string, int>>, totals: array<string, int>, monitor: array<string, int>,
     *   daily: array<string, array<string, int>>, hourly: array<string, array<string, int>>, rules: array<string, int>,
     *   crawlers: array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}>, bots: array<string, int>, statuses: array<string, int>,
     *   notFound: array<string, array{count: int, referrers: array<string, int>}>,
     *   sitemaps: array<string, array{statuses: array<string, int>, crawlers: array<string, array{count: int, last: ?int}>}>, sentences: list<string>}
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
        /** @var array<string, array{statuses?: array<string, int>, crawlers?: array<string, array{count?: int}>}> $sitemaps */
        $sitemaps = [];
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
                    case 'sm':
                        [$path, $code] = explode('|', $rest, 2) + ['', ''];
                        $sitemaps[$path]['statuses'][$code] = ($sitemaps[$path]['statuses'][$code] ?? 0) + $n;
                        break;
                    case 'smc':
                        [$path, $id] = explode('|', $rest, 2) + ['', ''];
                        $sitemaps[$path]['crawlers'][$id]['count'] = ($sitemaps[$path]['crawlers'][$id]['count'] ?? 0) + $n;
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
        // Sitemaps: the answers, and which crawler read each when last.
        $maps = [];
        foreach ($sitemaps as $path => $x) {
            $readers = [];
            foreach ($x['crawlers'] ?? [] as $id => $c) {
                $readers[(string) $id] = ['count' => (int) ($c['count'] ?? 0), 'last' => $read['last']['sitemap:' . $path . '@' . $id][0] ?? null];
            }
            uasort($readers, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $answers = $x['statuses'] ?? [];
            ksort($answers);
            $maps[(string) $path] = ['statuses' => array_combine(array_map('strval', array_keys($answers)), array_values($answers)), 'crawlers' => $readers];
        }
        ksort($maps);
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
            'rules' => array_slice($rules, 0, 20, true), 'crawlers' => $out, 'bots' => $bots, 'statuses' => $statuses, 'notFound' => $notFound, 'sitemaps' => $maps,
            'sentences' => array_merge(self::sentences($out, $days, $o['lang'] ?? 'en'), self::maps($maps, $days, $o['lang'] ?? 'en'), self::missing($notFound, $days, $o['lang'] ?? 'en'))];
    }

    /**
     * Sitemaps in words: which crawler read which, and when last; a sitemap
     * asked for that does not exist.
     *
     * @param array<string, array{statuses: array<string, int>, crawlers: array<string, array{count: int, last: ?int}>}> $maps
     * @return list<string>
     */
    private static function maps(array $maps, int $days, string $lang): array
    {
        $de = $lang === 'de';
        $out = [];
        foreach ($maps as $path => $x) {
            $ok = 0;
            $missing = 0;
            foreach ($x['statuses'] as $code => $n) {
                $code = (int) $code;
                $ok += $code >= 200 && $code < 300 ? $n : 0;
                $missing += $code === 404 || $code === 410 ? $n : 0;
            }
            if ($missing > 0 && $ok === 0) {
                $out[] = $de ? "$path wurde " . self::number($missing, $lang) . '× abgefragt, gibt es aber nicht (404).'
                    : "$path was asked for " . self::number($missing, $lang) . '× but does not exist (404).';
            }
            foreach ($x['crawlers'] as $id => $c) {
                $when = $c['last'] !== null ? date($de ? 'd.m. H:i' : 'M j, H:i', $c['last']) : '';
                $out[] = $de ? "$id hat $path " . self::number($c['count'], $lang) . '× gelesen, zuletzt ' . $when . '.'
                    : "$id read $path " . self::number($c['count'], $lang) . '×' . ($when !== '' ? ", last on $when" : '') . '.';
            }
        }
        return $out;
    }

    /**
     * Pages the site did not find, in words -- the ones its own pages link to
     * first: those are broken links to fix.
     *
     * @param array<string, array{count: int, referrers: array<string, int>}> $notFound
     * @return list<string>
     */
    private static function missing(array $notFound, int $days, string $lang = 'en'): array
    {
        if ($notFound === []) {
            return [];
        }
        $de = $lang === 'de';
        $out = [];
        foreach ($notFound as $path => $x) {
            foreach ($x['referrers'] as $from => $n) {
                if (strncmp((string) $from, '/', 1) === 0) {
                    $out[] = $de ? "Kaputter Link: $from verweist auf $path, die nicht gefunden wurde ({$n}×)."
                        : "Broken link: $from links to $path, which was not found ({$n}×).";
                }
            }
        }
        $top = array_slice($notFound, 0, 3, true);
        $list = implode(', ', array_map(static fn (string $p, array $x): string => "$p ({$x['count']}×)", array_map('strval', array_keys($top)), $top));
        $count = count($notFound);
        $out[] = $de ? self::number($count, $lang) . ($count === 1 ? ' Seite wurde ' : ' Seiten wurden ') . self::span($days, $lang) . " nicht gefunden; am häufigsten: $list."
            : $count . ' ' . ($count === 1 ? 'page was' : 'pages were') . ' not found ' . self::span($days, $lang) . "; most asked for: $list.";
        return $out;
    }

    /** A number as the language writes it: 1,204 or 1.204. */
    public static function number(int $n, string $lang = 'en'): string
    {
        return $lang === 'de' ? number_format($n, 0, ',', '.') : number_format($n);
    }

    private static function span(int $days, string $lang): string
    {
        if ($lang === 'de') {
            return $days === 1 ? 'heute' : "in den letzten $days Tagen";
        }
        return $days === 1 ? 'today' : "in the last $days days";
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
     * A day's or an hour's counters as numbers: let through (cacheable), let
     * through (not cacheable), checked, told to wait, refused; people,
     * crawlers, bots; pages not found.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function buckets(array $counts): array
    {
        $crawlers = 0;
        $bots = 0;
        $requests = 0;
        foreach ($counts as $k => $n) {
            $k = (string) $k;
            if (strncmp($k, 'a:', 2) === 0) {
                $requests += $n;
            } elseif (strncmp($k, 'o:', 2) === 0) {
                $bots += $n;
            } elseif (strncmp($k, 'c:', 2) === 0 && substr($k, -5) === ':seen') {
                $crawlers += $n;
            }
        }
        return ['passed' => $counts['a:allow'] ?? 0, 'uncached' => $counts['a:allow-uncached'] ?? 0, 'checked' => $counts['a:challenge'] ?? 0,
            'throttled' => $counts['a:throttle'] ?? 0, 'refused' => $counts['a:reject'] ?? 0,
            // Who: people are what is neither a known crawler nor a bot that says so.
            'people' => max(0, $requests - $crawlers - $bots), 'crawlers' => $crawlers, 'bots' => $bots,
            'notFound' => ($counts['s:404'] ?? 0) + ($counts['s:410'] ?? 0)];
    }

    /**
     * In words: what each crawler did, who only borrowed a name, which AI
     * crawlers did not come.
     *
     * @param array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}> $crawlers
     * @return list<string>
     */
    private static function sentences(array $crawlers, int $days, string $lang = 'en'): array
    {
        $de = $lang === 'de';
        $span = self::span($days, $lang);
        $words = $de ? ['allowed' => 'durchgelassen', 'checked' => 'geprüft', 'throttled' => 'gebremst', 'refused' => 'abgewiesen']
            : ['allowed' => 'let through', 'checked' => 'checked', 'throttled' => 'told to wait', 'refused' => 'refused'];
        $out = [];
        $absent = [];
        foreach ($crawlers as $id => $c) {
            $name = $c['name'] === $id ? $id : $c['name'] . " ($id)";
            $verified = $c['verified'];
            if ($verified > 0) {
                $got = [];
                foreach ($words as $e => $word) {
                    if ($c[$e] > 0) {
                        $got[] = ($c[$e] === $verified ? ($de ? 'jedes Mal ' : 'every time ') : self::number($c[$e], $lang) . '× ') . $word;
                    }
                }
                $out[] = $name . ($de ? ' kam ' : ' came ') . self::number($verified, $lang) . "× $span: " . implode(', ', $got)
                    . ($c['policy'] !== 'allow' ? ($de ? " (so eingestellt: {$c['policy']})" : " (as set: {$c['policy']})") : '')
                    . ($c['robots'] > 0 ? ($de ? '; robots.txt gelesen' : '; it read robots.txt') : '') . '.';
            } elseif ($c['kind'] !== 'search') {
                $absent[] = $id;
            }
            if ($c['claimed'] > 0) {
                $out[] = self::number($c['claimed'], $lang) . ($de
                    ? ($c['claimed'] === 1 ? ' Anfrage gab sich nur als ' : ' Anfragen gaben sich nur als ') . "$name aus — als normale Besucher behandelt."
                    : ($c['claimed'] === 1 ? ' request' : ' requests') . " only pretended to be $name — treated as ordinary visitors.");
            }
        }
        if ($absent !== []) {
            $out[] = ($de ? "Kein bestätigter Besuch $span von: " : "No verified visit $span from: ") . implode(', ', $absent) . '.';
        }
        return $out;
    }
}
