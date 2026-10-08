<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Stats\Report;

use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\Stats;
use CjwNetwork\RequestShield\Stats\StatsExtension;
use CjwNetwork\RequestShield\Stats\StatsPlugin;

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
     * @param array{from?: string, to?: string, by?: string, crawler?: string, lang?: string, path?: string, sort?: string, site?: string|null} $o  lang: the sentences' language, en or de;
     *   site: with stats-hosts, one website's numbers (a name, or Stats::OTHER), else all added up;
     *   path: only pages below it (a subtree: /news/), and how many views it had; sort: views (the default),
     *   blocked (refused + checked + told to wait), refused, checked or throttled -- the pages and sections with most of it
     * @return array{from: string, to: string, days: int, by: string, periods: array<string, array<string, int>>, totals: array<string, int>, monitor: array<string, int>,
     *   daily: array<string, array<string, int>>, hourly: array<string, array<string, int>>, rules: array<string, int>,
     *   crawlers: array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}>, bots: array<string, int>, statuses: array<string, int>,
     *   notFound: array<string, array{count: int, referrers: array<string, int>}>,
     *   sitemaps: array<string, array{statuses: array<string, int>, crawlers: array<string, array{count: int, last: ?int}>}>,
     *   pages: array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}>, folders: array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}>,
     *   subtree: array{path: string, people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int, exact: bool}|null, sort: string,
     *   stopped: array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}>, sentences: list<string>,
     *   forms: array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>, backend: array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>,
     *   times: array{kinds: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, site: array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int},
     *     who: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, shield: ?int, reasons: array<string, int>, hitShare: ?float, saved: int,
     *     hourly: array<string, array{count: int, p50: ?int, p95: ?int}>, slow: list<string>}|null}  times (0046, in microseconds): null when nothing was timed
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
        $read = self::read($s, $stats, $from, $to, $o['site'] ?? null);
        $sets = self::grouped($read, $by);
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
        $zero = ['people' => 0, 'crawlers' => 0, 'bots' => 0, 'total' => 0, 'refused' => 0, 'checked' => 0, 'throttled' => 0, 'blocked' => 0];
        /** @var array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}> $views */
        $views = [];
        /** @var array<string, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}> $folders */
        $folders = [];
        /** @var array<string, array{refused: int, checked: int, throttled: int}> $stopped */
        $stopped = [];
        /** @var array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>}> $forms */
        $forms = [];
        /** @var array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>}> $backend */
        $backend = [];
        $form0 = ['sent' => 0, 'saved' => 0, 'error' => 0, 'refused' => 0, 'checked' => 0, 'throttled' => 0, 'cross-site' => 0, 'from' => []];
        /** @var array<string, array<string, array{count: int, sum: int, bands: list<int>}>> $timed who => cache => counts (0046) */
        $timed = [];
        $shieldUs = 0;
        /** @var array<string, int> $reasons */
        $reasons = [];
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
                    case 'pg':
                    case 'pd':
                        [$who, $path] = explode('|', $rest, 2) + ['', ''];
                        if (in_array($who, ['people', 'crawlers', 'bots'], true)) {
                            if ($type === 'pg') {
                                $views[$path] ??= $zero;
                                $views[$path][$who] += $n;
                                $views[$path]['total'] += $n;
                            } else {
                                $folders[$path] ??= $zero;
                                $folders[$path][$who] += $n;
                                $folders[$path]['total'] += $n;
                            }
                        }
                        break;
                    case 'pb':
                        // A page the shield stopped: refused, checked or told to wait.
                        [$how, $path] = explode('|', $rest, 2) + ['', ''];
                        if (in_array($how, ['refused', 'checked', 'throttled'], true)) {
                            $stopped[$path] ??= ['refused' => 0, 'checked' => 0, 'throttled' => 0];
                            $stopped[$path][$how] += $n;
                        }
                        break;
                    case 'f':
                    case 'fb':
                        // A form sent (fb: in the editors' area, by area).
                        $at = $type === 'fb' ? rawurldecode($rest) : $rest;
                        if ($type === 'fb') {
                            $backend[$at] ??= $form0;
                            $backend[$at]['sent'] += $n;
                        } else {
                            $forms[$at] ??= $form0;
                            $forms[$at]['sent'] += $n;
                        }
                        break;
                    case 'fo':
                    case 'fbo':
                        $bar = (int) strrpos($rest, '|');
                        $at = substr($rest, 0, $bar);
                        $how = substr($rest, $bar + 1);
                        if (isset($form0[$how]) && $how !== 'sent' && $how !== 'from') {
                            if ($type === 'fbo') {
                                $at = rawurldecode($at);
                                $backend[$at] ??= $form0;
                                $backend[$at][$how] += $n;
                            } else {
                                $forms[$at] ??= $form0;
                                $forms[$at][$how] += $n;
                            }
                        }
                        break;
                    case 'ff':
                        $bar = (int) strrpos($rest, '|');
                        $at = substr($rest, 0, $bar);
                        $forms[$at] ??= $form0;
                        $forms[$at]['from'][substr($rest, $bar + 1)] = ($forms[$at]['from'][substr($rest, $bar + 1)] ?? 0) + $n;
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
                    case 'rt':
                    case 'rs':
                        // How long the site took (0046): a band, or the sum of the microseconds.
                        $parts = explode('|', $rest);
                        if (count($parts) === ($type === 'rt' ? 3 : 2)) {
                            $timed[$parts[0]][$parts[1]] ??= ['count' => 0, 'sum' => 0, 'bands' => array_fill(0, count(Stats::BANDS) + 1, 0)];
                            if ($type === 'rs') {
                                $timed[$parts[0]][$parts[1]]['sum'] += $n;
                            } elseif (isset($timed[$parts[0]][$parts[1]]['bands'][(int) $parts[2]])) {
                                $timed[$parts[0]][$parts[1]]['bands'][(int) $parts[2]] += $n;
                                $timed[$parts[0]][$parts[1]]['count'] += $n;
                            }
                        }
                        break;
                    case 'rq':
                        $shieldUs += $n;
                        break;
                    case 'rn':
                        $reasons[$rest] = ($reasons[$rest] ?? 0) + $n;
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
        // The most visited pages and sections: by all, the people's number first when equal.
        unset($views['(other)'], $folders['(other)'], $stopped['(other)']);
        // What the shield stopped, on its pages -- and on its sections (their
        // first two folders, from the pages: blocked pages are counted one by one).
        foreach ($stopped as $path => $x) {
            $path = (string) $path;
            $views[$path] = self::stop($views[$path] ?? $zero, $x);
            foreach (StatsPlugin::folders($path, StatsExtension::of($s)['depth']) as $at) {
                $folders[$at] = self::stop($folders[$at] ?? $zero, $x);
            }
        }
        // By views (the people's number first when equal), or by what the shield stopped.
        $sort = in_array($o['sort'] ?? 'views', ['views', 'blocked', 'refused', 'checked', 'throttled'], true) ? ($o['sort'] ?? 'views') : 'views';
        $key = $sort === 'views' ? 'total' : $sort;
        $order = $sort === 'views' ? static fn (array $a, array $b): int => [$b['total'], $b['people']] <=> [$a['total'], $a['people']]
            : static fn (array $a, array $b): int => [$b[$key], $b['blocked'], $b['total']] <=> [$a[$key], $a['blocked'], $a['total']];
        // A subtree: its pages, and its views -- exact where it is a counted folder
        // (the first two levels), else the sum of its pages on the lists.
        $subtree = null;
        $prefix = $o['path'] ?? null;
        if ($prefix !== null && $prefix !== '') {
            $views = array_filter($views, static fn (string $p): bool => strncmp($p, $prefix, strlen($prefix)) === 0, ARRAY_FILTER_USE_KEY);
            $sum = $zero;
            // Views exact where the folder is counted; what was stopped always from the pages.
            $exact = isset($folders[$prefix]) && $folders[$prefix]['total'] > 0;
            foreach ($exact ? [$folders[$prefix]] : $views as $v) {
                foreach (['people', 'crawlers', 'bots', 'total'] as $k) {
                    $sum[$k] += $v[$k];
                }
            }
            foreach ($views as $v) {
                foreach (['refused', 'checked', 'throttled', 'blocked'] as $k) {
                    $sum[$k] += $v[$k];
                }
            }
            $subtree = ['path' => $prefix] + $sum + ['exact' => $exact];
            $folders = array_filter($folders, static fn (string $p): bool => strncmp($p, $prefix, strlen($prefix)) === 0 && $p !== $prefix, ARRAY_FILTER_USE_KEY);
        }
        // The pages the shield stopped most, whatever the list is sorted by (the visitors page's tab).
        $stoppedTop = array_filter($views, static fn (array $v): bool => $v['blocked'] > 0);
        uasort($stoppedTop, static fn (array $a, array $b): int => [$b['blocked'], $b['total']] <=> [$a['blocked'], $a['total']]);
        $stoppedTop = array_slice($stoppedTop, 0, 20, true);
        // Only what has the number sorted by: a page nobody stopped is not on the blocked list.
        $views = array_filter($views, static fn (array $v): bool => $v[$key] > 0);
        $folders = array_filter($folders, static fn (array $v): bool => $v[$key] > 0);
        uasort($views, $order);
        uasort($folders, $order);
        $views = array_slice($views, 0, 20, true);
        $folders = array_slice($folders, 0, 20, true);
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
        // Forms: the most sent first; each with where it was sent from, the most first.
        unset($forms['(other)']);
        $forms = self::byForm(array_filter($forms, static fn (array $f): bool => $f['sent'] > 0));
        $backend = self::byForm($backend);
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
        $times = $timed === [] ? null : self::times($timed, $shieldUs, $reasons, $read['hours'], $now, self::slowLines($s, $stats, $from, $to, $o['site'] ?? null));
        return ['times' => $times, 'from' => $from, 'to' => $to, 'days' => $days, 'by' => $by, 'periods' => $periods, 'totals' => $totals, 'monitor' => $monitor, 'daily' => $daily, 'hourly' => $hourly,
            'rules' => $rules, 'crawlers' => $out, 'bots' => $bots, 'statuses' => $statuses, 'notFound' => $notFound, 'sitemaps' => $maps, 'pages' => $views, 'folders' => $folders, 'subtree' => $subtree, 'sort' => $sort, 'stopped' => $stoppedTop, 'forms' => $forms, 'backend' => $backend,
            'sentences' => array_merge(self::sentences($out, $days, $o['lang'] ?? 'en'), self::maps($maps, $days, $o['lang'] ?? 'en'), self::missing($notFound, $days, $o['lang'] ?? 'en'))];
    }

    /**
     * How long the site took (0046), from the counters: per cache kind and
     * kind of visitor the bands, the sum, the average and the median and the
     * slow end read from the bands; the site's own (what did not come from the
     * cache) per hour of the last two days; the share of hits and what they
     * saved; the shield's share; why misses were not kept; the slow log.
     *
     * @param array<string, array<string, array{count: int, sum: int, bands: list<int>}>> $timed
     * @param array<string, int> $reasons
     * @param array<string, array<string, int>> $hours
     * @param list<string> $slow
     * @return array{kinds: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, site: array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int},
     *   who: array<string, array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}>, shield: ?int, reasons: array<string, int>, hitShare: ?float, saved: int,
     *   hourly: array<string, array{count: int, p50: ?int, p95: ?int}>, slow: list<string>}
     */
    private static function times(array $timed, int $shieldUs, array $reasons, array $hours, int $now, array $slow): array
    {
        $zero = ['count' => 0, 'sum' => 0, 'bands' => array_fill(0, count(Stats::BANDS) + 1, 0)];
        $kinds = ['hit' => $zero, 'miss' => $zero, 'nostore' => $zero, 'past' => $zero];
        $who = [];
        $site = $zero;
        foreach ($timed as $w => $byCache) {
            foreach ($byCache as $cache => $t) {
                if (!isset($kinds[$cache])) {
                    continue;
                }
                $kinds[$cache] = self::addTimes($kinds[$cache], $t);
                if ($cache !== 'hit') {
                    $site = self::addTimes($site, $t);
                    $who[(string) $w] = self::addTimes($who[(string) $w] ?? $zero, $t);
                }
            }
        }
        $all = $site['count'] + $kinds['hit']['count'];
        $hits = $kinds['hit']['count'];
        $asked = $hits + $kinds['miss']['count'];
        // What the hits saved: each would have taken as long as a miss usually does -- the misses' median (or,
        // without misses, the site's): one slow page does not make every hit look like a great saving.
        $instead = self::percentile($kinds['miss']['bands'], 0.5) ?? self::percentile($site['bands'], 0.5) ?? 0;
        $saved = $hits > 0 ? max(0, (int) round($hits * $instead - $kinds['hit']['sum'])) : 0;
        // The period's last 48 hours (the hours kept and read for it): up to its end, not to now.
        $hourly = [];
        $lastHour = $hours === [] ? 0 : (int) strtotime(substr((string) max(array_map('strval', array_keys($hours))), 0, 8) . ' UTC') + 3600 * (int) substr((string) max(array_map('strval', array_keys($hours))), 8, 2);
        foreach ($hours as $hour => $counts) {
            if ((string) $hour < gmdate('YmdH', $lastHour - 47 * 3600)) {
                continue;
            }
            $h = $zero;
            foreach ($counts as $k => $n) {
                $k = (string) $k;
                if (strncmp($k, 'rt:', 3) === 0) {
                    $p = explode('|', substr($k, 3));
                    if (count($p) === 3 && $p[1] !== 'hit' && isset($h['bands'][(int) $p[2]])) {
                        $h['bands'][(int) $p[2]] += $n;
                        $h['count'] += $n;
                    }
                }
            }
            if ($h['count'] > 0) {
                $hourly[(string) $hour] = ['count' => $h['count'], 'p50' => self::percentile($h['bands'], 0.5), 'p95' => self::percentile($h['bands'], 0.95)];
            }
        }
        arsort($reasons);
        return ['kinds' => array_map([self::class, 'summed'], $kinds), 'site' => self::summed($site), 'who' => array_map([self::class, 'summed'], $who), 'shield' => $all > 0 ? (int) round($shieldUs / $all) : null,
            'reasons' => $reasons, 'hitShare' => $asked > 0 ? $hits / $asked : null, 'saved' => $saved, 'hourly' => $hourly, 'slow' => $slow];
    }

    /**
     * Two counts of times added up.
     *
     * @param array{count: int, sum: int, bands: list<int>} $a
     * @param array{count: int, sum: int, bands: list<int>} $b
     * @return array{count: int, sum: int, bands: list<int>}
     */
    private static function addTimes(array $a, array $b): array
    {
        $a['count'] += $b['count'];
        $a['sum'] += $b['sum'];
        foreach ($b['bands'] as $i => $n) {
            if (isset($a['bands'][$i])) {
                $a['bands'][$i] += $n;
            }
        }
        return $a;
    }

    /**
     * A count of times with its average, median and slow end.
     *
     * @param array{count: int, sum: int, bands: list<int>} $t
     * @return array{count: int, sum: int, bands: list<int>, avg: ?int, p50: ?int, p95: ?int}
     */
    private static function summed(array $t): array
    {
        return $t + ['avg' => $t['count'] > 0 ? (int) round($t['sum'] / $t['count']) : null, 'p50' => self::percentile($t['bands'], 0.5), 'p95' => self::percentile($t['bands'], 0.95)];
    }

    /**
     * A percentile read from the bands (0046), in microseconds: the band it
     * falls in, and within it by its share -- exact to the band's width; over
     * 10 s it says 10 s. Null without a count.
     *
     * @param list<int> $bands
     */
    public static function percentile(array $bands, float $q): ?int
    {
        $total = array_sum($bands);
        if ($total <= 0) {
            return null;
        }
        $target = $q * $total;
        $below = 0;
        foreach ($bands as $i => $n) {
            if ($n > 0 && $below + $n >= $target) {
                $low = $i === 0 ? 0 : Stats::BANDS[$i - 1];
                if (!isset(Stats::BANDS[$i])) {
                    return $low;            // over the last band's end: "over 10 s"
                }
                return (int) round($low + (Stats::BANDS[$i] - $low) * ($target - $below) / $n);
            }
            $below += $n;
        }
        return Stats::BANDS[count(Stats::BANDS) - 1];
    }

    /**
     * The slow log's last lines of a period (0046): the statistics given, or
     * with stats-hosts one website's or all websites' -- the newest last.
     *
     * @return list<string>
     */
    private static function slowLines(Settings $s, ?Stats $stats, string $from, string $to, ?string $site): array
    {
        $lines = [];
        foreach ($stats !== null ? [$stats] : Stats::all($s, $site !== null && Stats::known($s, $site) ? $site : null) as $one) {
            foreach ($one->slowLines($from, $to, 30) as $line) {
                $lines[] = $line;
            }
        }
        sort($lines);           // each starts with its time
        return array_slice($lines, -30);
    }

    /**
     * Forms, the most sent first (the most stopped when equal), the first 20;
     * each with how many were stopped, and its sources, the most first.
     *
     * @param array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>}> $list
     * @return array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>
     */
    private static function byForm(array $list): array
    {
        $out = [];
        foreach ($list as $k => $f) {
            $from = $f['from'];
            arsort($from);
            $out[$k] = ['from' => array_slice($from, 0, Stats::REFERRERS + 1, true), 'stopped' => $f['refused'] + $f['checked'] + $f['throttled'] + $f['cross-site']] + $f;
        }
        uasort($out, static fn (array $a, array $b): int => [$b['sent'], $b['stopped']] <=> [$a['sent'], $a['stopped']]);
        return array_slice($out, 0, 20, true);
    }

    /**
     * The overview of all websites (stats-hosts, stats-group): for each
     * website, each group (its websites added up), "other hosts" and all --
     * page views, people, crawlers, bots, what was stopped, pages not found,
     * the page views of the period before, and the page views per period
     * for a small curve.
     *
     * @return array{sites: array<string, array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>}>,
     *   groups: array<string, array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>}>,
     *   all: array{views: int, people: int, crawlers: int, search: int, ai: int, bots: int, stopped: int, notFound: int, prev: int, curve: array<string, int>}|null} all: null for a customer
     */
    public static function sites(Settings $s, string $from, string $to, string $by = 'day', string $who = '*'): array
    {
        $len = (int) round(((int) strtotime($to . ' UTC') - (int) strtotime($from . ' UTC')) / 86400) + 1;
        $pTo = gmdate('Ymd', (int) strtotime($from . ' UTC') - 86400);
        $pFrom = gmdate('Ymd', (int) strtotime($from . ' UTC') - $len * 86400);
        $kinds = [];
        foreach ($s->crawlers as $id => $c) {
            $kinds[(string) $id] = $c['kind'];
        }
        $row = static function (?string $site) use ($s, $from, $to, $pFrom, $pTo, $by, $kinds): array {
            $r = ['views' => 0, 'people' => 0, 'crawlers' => 0, 'search' => 0, 'ai' => 0, 'bots' => 0, 'stopped' => 0, 'notFound' => 0, 'prev' => 0, 'curve' => []];
            $periods = [];
            foreach (self::grouped(self::read($s, null, $from, $to, $site), $by) as [$label, $counts]) {
                $b = self::buckets($counts);
                foreach (['views', 'people', 'crawlers', 'bots', 'notFound'] as $k) {
                    $r[$k] += $b[$k] ?? 0;
                }
                $r['stopped'] += ($b['refused'] ?? 0) + ($b['checked'] ?? 0) + ($b['throttled'] ?? 0);
                $periods[(string) $label] = ($periods[(string) $label] ?? 0) + ($b['views'] ?? 0);
                // Crawlers by kind: search engines apart from the AI crawlers (search, assistants, training).
                foreach ($counts as $k => $n) {
                    $k = (string) $k;
                    if (strncmp($k, 'c:', 2) === 0 && substr($k, -5) === ':seen') {
                        $kind = $kinds[substr($k, 2, -5)] ?? 'search';
                        $r[$kind === 'search' ? 'search' : 'ai'] += $n;
                    }
                }
            }
            ksort($periods);
            $r['curve'] = $periods;
            foreach (self::periods($s, null, $pFrom, $pTo, $by, $site) as $b) {
                $r['prev'] += $b['views'] ?? 0;
            }
            return $r;
        };
        // A customer (who: a group's ID): its group and its websites, nothing else.
        $own = $who === '*' ? null : StatsExtension::of($s)['groups'][$who] ?? ['sites' => []];
        $sites = [];
        foreach ($own === null ? array_merge(StatsExtension::of($s)['hosts'], [Stats::OTHER]) : $own['sites'] as $name) {
            $sites[$name] = $row($name);
        }
        $groups = [];
        foreach ($own === null ? StatsExtension::of($s)['groups'] : array_intersect_key(StatsExtension::of($s)['groups'], [$who => 1]) as $id => $g) {
            $sum = ['views' => 0, 'people' => 0, 'crawlers' => 0, 'search' => 0, 'ai' => 0, 'bots' => 0, 'stopped' => 0, 'notFound' => 0, 'prev' => 0, 'curve' => []];
            foreach ($g['sites'] as $name) {
                foreach ($sites[$name] ?? [] as $k => $v) {
                    if ($k === 'curve') {
                        foreach ((array) $v as $label => $n) {
                            $sum['curve'][(string) $label] = ($sum['curve'][(string) $label] ?? 0) + (int) $n;
                        }
                    } else {
                        $sum[$k] += (int) $v;
                    }
                }
            }
            ksort($sum['curve']);
            $groups[(string) $id] = $sum;
        }
        return ['sites' => $sites, 'groups' => $groups, 'all' => $own === null ? $row(null) : null];
    }

    /**
     * What was counted: the statistics given, or with stats-hosts one
     * website's ($site) or every website's added up (null).
     *
     * @return array{days: array<string, array<string, int>>, hours: array<string, array<string, int>>, months: array<string, array<string, int>>, last: array<string, array{0: int, 1: string}>}
     */
    public static function read(Settings $s, ?Stats $stats, string $from, string $to, ?string $site = null): array
    {
        if ($stats !== null) {
            return $stats->read($from, $to);
        }
        $all = Stats::all($s, $site !== null && Stats::known($s, $site) ? $site : null);
        return count($all) === 1 ? $all[0]->read($from, $to) : Stats::readAll($all, $from, $to);
    }

    /**
     * Only the numbers per period of a span ("the period before" for a
     * comparison): the buckets of each day, week, month or year, nothing else.
     *
     * @return array<string, array<string, int>>
     */
    public static function periods(Settings $s, ?Stats $stats, string $from, string $to, string $by = 'day', ?string $site = null): array
    {
        $periods = [];
        foreach (self::grouped(self::read($s, $stats, $from, $to, $site), $by) as [$label, $counts]) {
            $periods[$label] = Stats::add($periods[$label] ?? [], self::buckets($counts));
        }
        ksort($periods);
        return $periods;
    }

    /**
     * Days and, where the days are gone, their months: each with the period
     * (day, week, month, year) it belongs to.
     *
     * @param array{days: array<string, array<string, int>>, months: array<string, array<string, int>>} $read
     * @return list<array{0: string, 1: array<string, int>}>
     */
    private static function grouped(array $read, string $by): array
    {
        $sets = [];
        foreach ($read['days'] as $day => $counts) {
            $t = (int) strtotime($day . ' UTC');
            $sets[] = [['day' => gmdate('Y-m-d', $t), 'week' => gmdate('o-\\WW', $t), 'month' => gmdate('Y-m', $t), 'year' => gmdate('Y', $t)][$by] ?? gmdate('Y-m-d', $t), $counts];
        }
        foreach ($read['months'] as $month => $counts) {
            $t = (int) strtotime($month . '01 UTC');
            $sets[] = [['day' => gmdate('Y-m', $t) . ' (month)', 'week' => gmdate('Y-m', $t) . ' (month)', 'month' => gmdate('Y-m', $t), 'year' => gmdate('Y', $t)][$by] ?? gmdate('Y-m', $t), $counts];
        }
        return $sets;
    }

    /**
     * A page's or section's numbers, plus what the shield stopped there.
     *
     * @param array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int} $v
     * @param array{refused: int, checked: int, throttled: int} $x
     * @return array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}
     */
    private static function stop(array $v, array $x): array
    {
        $v['refused'] += $x['refused'];
        $v['checked'] += $x['checked'];
        $v['throttled'] += $x['throttled'];
        $v['blocked'] += $x['refused'] + $x['checked'] + $x['throttled'];
        return $v;
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

    /** A time in microseconds as a person reads it: 40 µs, 0.4 ms, 120 ms, 2.4 s, 3.5 min, 1.2 h (German with a comma). */
    public static function duration(int $us, string $lang = 'en'): string
    {
        $f = static fn (float $v, int $d): string => $lang === 'de' ? number_format($v, $d, ',', '.') : number_format($v, $d);
        if ($us < 100) {
            return $us . ' µs';
        }
        if ($us < 10000) {
            return $f($us / 1000, 1) . ' ms';
        }
        if ($us < 1000000) {
            return $f($us / 1000, 0) . ' ms';
        }
        if ($us < 60000000) {
            return $f($us / 1000000, 1) . ' s';
        }
        if ($us < 3600000000) {
            return $f($us / 60000000, 1) . ' min';
        }
        return $f($us / 3600000000, 1) . ' h';
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
     * The shipped crawlers' descriptions (Shipped::crawlers()), for settings
     * that have none of their own.
     *
     * @return array<string, string>
     */
    private static function names(): array
    {
        return \CjwNetwork\RequestShield\Rules\Shipped::crawlers()['names'];
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
        $views = 0;
        foreach ($counts as $k => $n) {
            $k = (string) $k;
            if (strncmp($k, 'pg:people|', 10) === 0) {
                $views += $n;               // page views by people (part pages)
            } elseif (strncmp($k, 'a:', 2) === 0) {
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
            'notFound' => ($counts['s:404'] ?? 0) + ($counts['s:410'] ?? 0), 'views' => $views];
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
