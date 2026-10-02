<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

/**
 * "Visitors & pages" (proposal 0022, phase 1): one calm page -- six numbers,
 * each with its change against the period before, one chart (the number
 * picked), and two cards with tabs: the pages (pages, sections, stopped, not
 * found) and the crawlers & AI assistants that read the site.
 *
 * No script: the number shown in the chart and the cards' tabs are radio
 * buttons and CSS (they work without JavaScript); a point's numbers are its
 * <title>. Everything from a request -- a path, a referrer -- is escaped.
 */
final class VisitorsPage
{
    private const T = [
        'en' => [
            'views' => 'Page views', 'people' => 'Requests by people', 'crawlers' => 'Crawler visits', 'bots' => 'Bot requests', 'stopped' => 'Stopped', 'notFound' => 'Not found',
            'before' => 'before', 'new' => 'new', 'same' => '±0 %', 'dashed' => 'dashed: the period before', 'pages' => 'Pages', 'tPages' => 'Pages', 'tSections' => 'Sections',
            'tStopped' => 'Stopped', 'tMissing' => 'Not found', 'forms' => 'Forms', 'tForms' => 'Visitors\' forms', 'tBackend' => 'Editors (backend)',
            'from' => 'from', 'fromNone' => 'no page given', 'fromOther' => 'another website', 'fromSite' => 'this website (page not said)', 'fSaved' => 'saved', 'fErrors' => 'errors', 'fStopped' => 'stopped',
            'fCross' => 'from another website', 'fSent' => 'sent', 'fLive' => 'stopped: in the live view', 'crawlersAi' => 'Crawlers & AI', 'tSearch' => 'Search', 'tAi' => 'AI assistants', 'tTraining' => 'AI training',
            'none' => 'Nothing in this period.', 'lastVisit' => 'last', 'linked' => 'linked from', 'people2' => 'people', 'crawlers2' => 'crawlers', 'bots2' => 'bots',
            'refused' => 'refused', 'checked' => 'checked', 'throttled' => 'told to wait', 'verified' => 'verified visits', 'pathStarts' => 'path starts with', 'filter' => 'Filter',
            'clear' => 'all pages', 'subtree' => 'Subtree', 'viewsOf' => 'views',
        ],
        'de' => [
            'views' => 'Seitenaufrufe', 'people' => 'Anfragen von Menschen', 'crawlers' => 'Crawler-Besuche', 'bots' => 'Bot-Anfragen', 'stopped' => 'Gestoppt', 'notFound' => 'Nicht gefunden',
            'before' => 'vorher', 'new' => 'neu', 'same' => '±0 %', 'dashed' => 'gestrichelt: der Zeitraum davor', 'pages' => 'Seiten', 'tPages' => 'Seiten', 'tSections' => 'Bereiche',
            'tStopped' => 'Gestoppt', 'tMissing' => 'Nicht gefunden', 'forms' => 'Formulare', 'tForms' => 'Formulare der Besucher', 'tBackend' => 'Redaktion (Backend)',
            'from' => 'von', 'fromNone' => 'ohne Angabe', 'fromOther' => 'andere Website', 'fromSite' => 'diese Website (Seite ohne Angabe)', 'fSaved' => 'gespeichert', 'fErrors' => 'Fehler', 'fStopped' => 'gestoppt',
            'fCross' => 'von einer anderen Website', 'fSent' => 'gesendet', 'fLive' => 'gestoppt: in der Live-Ansicht', 'crawlersAi' => 'Crawler & KI', 'tSearch' => 'Suche', 'tAi' => 'KI-Assistenten', 'tTraining' => 'KI-Training',
            'none' => 'Nichts in diesem Zeitraum.', 'lastVisit' => 'zuletzt', 'linked' => 'verlinkt von', 'people2' => 'Menschen', 'crawlers2' => 'Crawler', 'bots2' => 'Bots',
            'refused' => 'abgewiesen', 'checked' => 'geprüft', 'throttled' => 'gebremst', 'verified' => 'bestätigte Besuche', 'pathStarts' => 'Pfad beginnt mit', 'filter' => 'Filtern',
            'clear' => 'alle Seiten', 'subtree' => 'Unterbaum', 'viewsOf' => 'Aufrufe',
        ],
    ];

    /** The six numbers, in their order: the chart starts with the first. */
    private const METRICS = ['views', 'people', 'crawlers', 'bots', 'stopped', 'notFound'];

    /**
     * @param array{pages: array<array-key, array{people: int, crawlers: int, bots: int, total: int}>, folders: array<array-key, array{people: int, crawlers: int, bots: int, total: int}>,
     *   stopped: array<array-key, array{refused: int, checked: int, throttled: int, blocked: int}>, notFound: array<array-key, array{count: int, referrers: array<array-key, int>}>,
     *   crawlers: array<array-key, array{kind: string, name: string, verified: int, last: array{0: int, 1: string}|null}>,
     *   subtree: array{path: string, people: int, crawlers: int, bots: int, total: int}|null,
     *   forms?: array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>, backend?: array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}>} $r StatsReport::build()
     * @param array<string, array<string, int>> $cur label => buckets of the period, every label (zeros too), in order
     * @param list<array<string, int>> $prev the period before, by position
     * @param array{lang?: string, by?: string, action?: string, keep?: array<string, string|int>, link?: callable(string): string, clear?: string, path?: ?string, live?: (callable(string): string)|null} $o
     *   by: hour, day, week, month, year (the chart's labels); action, keep: the path filter's form; link: a section's subtree; clear: the address without the filter
     */
    public static function render(array $r, array $cur, array $prev, array $o = []): string
    {
        $lang = isset(self::T[$o['lang'] ?? '']) ? (string) $o['lang'] : 'en';
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $by = (string) ($o['by'] ?? 'day');

        // The numbers per point, now and before.
        $series = [];
        $before = [];
        foreach (self::METRICS as $m) {
            $series[$m] = array_values(array_map(static fn (array $b): int => self::value($b, $m), $cur));
            $before[$m] = array_map(static fn (array $b): int => self::value($b, $m), $prev);
        }
        $labels = array_map(static fn ($l): string => self::label((string) $l, $by, $lang), array_keys($cur));

        // Six numbers, each a label for its radio button: picking one shows its chart.
        $h = '<div class="vp">';
        foreach (self::METRICS as $i => $m) {
            $h .= '<input type="radio" name="vp-m" id="vp-m' . $i . '"' . ($i === 0 ? ' checked' : '') . '>';
        }
        $h .= '<div class="vtiles">';
        foreach (self::METRICS as $i => $m) {
            $now = array_sum($series[$m]);
            $was = array_sum($before[$m]);
            $h .= '<label class="vt" for="vp-m' . $i . '"><span class="l">' . $e($t[$m]) . '</span><span class="n">' . $e($n($now)) . '</span>'
                . '<span class="d" title="' . $e($t['before'] . ': ' . $n($was)) . '">' . $e(self::change($now, $was, $t)) . '</span></label>';
        }
        $h .= '</div><div class="vcharts">';
        foreach (self::METRICS as $m) {
            $h .= '<div class="vc"><p class="note">' . $e($t[$m] . ' · ' . $t['dashed']) . '</p>' . self::chart($series[$m], $before[$m], $labels, $t, $lang) . '</div>';
        }
        $h .= '</div></div>';

        // The pages: pages, sections, stopped, not found -- with the subtree filter.
        $pages = [];
        foreach ($r['pages'] as $p => $v) {
            $pages[] = [(string) $p, (int) $v['total'], $n((int) $v['people']) . ' ' . $t['people2'] . ' · ' . $n((int) $v['crawlers']) . ' ' . $t['crawlers2'] . ' · ' . $n((int) $v['bots']) . ' ' . $t['bots2'], null];
        }
        $link = $o['link'] ?? null;
        $sections = [];
        foreach ($r['folders'] as $p => $v) {
            $sections[] = [(string) $p, (int) $v['total'], $n((int) $v['people']) . ' ' . $t['people2'] . ' · ' . $n((int) $v['crawlers']) . ' ' . $t['crawlers2'] . ' · ' . $n((int) $v['bots']) . ' ' . $t['bots2'],
                $link !== null ? $link((string) $p) : null];
        }
        $stopped = [];
        foreach ($r['stopped'] as $p => $v) {
            $stopped[] = [(string) $p, (int) $v['blocked'], $n((int) $v['refused']) . ' ' . $t['refused'] . ' · ' . $n((int) $v['checked']) . ' ' . $t['checked'] . ' · ' . $n((int) $v['throttled']) . ' ' . $t['throttled'], null];
        }
        $missing = [];
        foreach ($r['notFound'] as $p => $x) {
            $from = [];
            foreach ($x['referrers'] as $source => $c) {
                $from[] = $source . ' (' . $n((int) $c) . ')';
            }
            $missing[] = [(string) $p, (int) $x['count'], '', null, $from === [] ? '' : $t['linked'] . ' ' . implode(', ', $from)];
        }
        $form = '';
        if (isset($o['action'])) {
            $form = '<form class="filter" method="get" action="' . $e((string) $o['action']) . '">';
            foreach ($o['keep'] ?? [] as $k => $v) {
                $form .= '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
            }
            $path = $o['path'] ?? null;
            $form .= '<label>' . $e($t['pathStarts']) . ' <input type="text" name="path" value="' . $e((string) $path) . '" placeholder="/news/"></label> <button type="submit">' . $e($t['filter']) . '</button>'
                . ($path !== null && isset($o['clear']) ? ' <a href="' . $e((string) $o['clear']) . '">' . $e($t['clear']) . '</a>' : '') . '</form>';
            $st = $r['subtree'];
            if ($st !== null) {
                $form .= '<p class="subtree"><b>' . $e($t['subtree'] . ' ' . $st['path']) . ':</b> ' . $e($n((int) $st['total']) . ' ' . $t['viewsOf']) . ' — '
                    . $e($n((int) $st['people']) . ' ' . $t['people2'] . ' · ' . $n((int) $st['crawlers']) . ' ' . $t['crawlers2'] . ' · ' . $n((int) $st['bots']) . ' ' . $t['bots2']) . '</p>';
            }
        }
        $cards = self::card('vp-p', $t['pages'], $form, [$t['tPages'] => $pages, $t['tSections'] => $sections, $t['tStopped'] => $stopped, $t['tMissing'] => $missing], $t, $lang);

        // Forms (proposal 0028): each with where it was sent from and how it ended; the editors' area apart.
        $forms = $r['forms'] ?? [];
        $backend = $r['backend'] ?? [];
        if ($forms !== [] || $backend !== []) {
            $live = $o['live'] ?? null;
            $cards .= self::card('vp-f', $t['forms'], '', [$t['tForms'] => self::formRows($forms, true, $t, $lang, $live), $t['tBackend'] => self::formRows($backend, false, $t, $lang, $live)], $t, $lang);
        }

        // Crawlers & AI: who reads the site for search engines and AI assistants (verified by address).
        $kinds = ['search' => [], 'ai' => [], 'training' => []];
        foreach ($r['crawlers'] as $id => $c) {
            if ((int) $c['verified'] === 0) {
                continue;
            }
            $kind = $c['kind'] === 'search' ? 'search' : ($c['kind'] === 'ai-training' ? 'training' : 'ai');
            $last = $c['last'] !== null ? $t['lastVisit'] . ' ' . date($lang === 'de' ? 'd.m. H:i' : 'M j, H:i', (int) $c['last'][0]) : '';
            $kinds[$kind][] = [$c['name'] . ' (' . $id . ')', (int) $c['verified'], trim($n((int) $c['verified']) . ' ' . $t['verified'] . ($last !== '' ? ' · ' . $last : '')), null];
        }
        foreach ($kinds as $k => $rows) {
            usort($rows, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
            $kinds[$k] = $rows;
        }
        $cards .= self::card('vp-c', $t['crawlersAi'], '', [$t['tSearch'] => $kinds['search'], $t['tAi'] => $kinds['ai'], $t['tTraining'] => $kinds['training']], $t, $lang, false);

        // One under the other, each the full width: the pages' addresses (with their website) need room.
        return $h . $cards;
    }

    /**
     * The rows of the card "Forms": each form, how many were sent, where from and how they ended.
     *
     * @param array<string, array{sent: int, saved: int, error: int, refused: int, checked: int, throttled: int, cross-site: int, from: array<string, int>, stopped: int}> $list
     * @param array<string, string> $t
     * @param (callable(string): string)|null $live a form's stops in the live view
     * @return list<array{0: string, 1: int, 2: string, 3: ?string, 4: string}>
     */
    private static function formRows(array $list, bool $from, array $t, string $lang, ?callable $live): array
    {
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $out = [];
        foreach ($list as $p => $f) {
            $where = [];
            foreach ($from ? $f['from'] : [] as $src => $c) {
                $src = (string) $src;
                $where[] = ($src === '-' ? $t['fromNone'] : ($src === '=' ? $t['fromSite'] : ($src[0] === '@' ? $t['fromOther'] . ' ' . substr($src, 1) : $src))) . ' ' . $n($c);
            }
            $how = $n($f['saved']) . ' ' . $t['fSaved'] . ' · ' . $n($f['error']) . ' ' . $t['fErrors'] . ' · ' . $n($f['stopped']) . ' ' . $t['fStopped']
                . ($f['cross-site'] > 0 ? ' (' . $n($f['cross-site']) . ' ' . $t['fCross'] . ')' : '');
            $out[] = [(string) $p, $f['sent'], $n($f['sent']) . ' ' . $t['fSent'] . ($f['stopped'] > 0 && $live !== null ? ' · ' . $t['fLive'] : ''),
                $f['stopped'] > 0 && $live !== null ? $live((string) $p) : null,
                ($where !== [] ? $t['from'] . ' ' . implode(' · ', $where) . ' — ' : '') . $how];
        }
        return $out;
    }

    /**
     * A bucket's number for one of the six.
     *
     * @param array<string, int> $b
     */
    private static function value(array $b, string $m): int
    {
        return $m === 'stopped' ? ($b['refused'] ?? 0) + ($b['throttled'] ?? 0) : ($b[$m] ?? 0);
    }

    /**
     * "↑ 18 %", "↓ 4 %", "new" (nothing before): the change against the period before.
     *
     * @param array<string, string> $t
     */
    private static function change(int $now, int $was, array $t): string
    {
        if ($was === 0) {
            return $now === 0 ? $t['same'] : $t['new'];
        }
        $pct = (int) round(100 * ($now - $was) / $was);
        return $pct === 0 ? $t['same'] : ($pct > 0 ? '↑ ' : '↓ ') . abs($pct) . ' %';
    }

    /** A point's label: "14:00", "24.09." / "Sep 24", "2026-W39", "Sep 2026". */
    private static function label(string $key, string $by, string $lang): string
    {
        if ($by === 'hour') {
            return $key;
        }
        $ts = strtotime(substr($key, 0, 10) . (strlen($key) === 7 || strpos($key, '(month)') !== false ? '-01' : '') . ' UTC');
        if ($ts === false || $by === 'week') {
            return $key;
        }
        if ($by === 'month' || $by === 'year' || strpos($key, '(month)') !== false) {
            return $by === 'year' ? gmdate('Y', $ts) : gmdate($lang === 'de' ? 'm/Y' : 'M Y', $ts);
        }
        return gmdate($lang === 'de' ? 'd.m.' : 'M j', $ts);
    }

    /**
     * One line, the period before dashed, a column per point with its numbers
     * as the tooltip -- inline SVG, no script.
     *
     * @param list<int> $now
     * @param list<int> $was
     * @param list<string> $labels
     * @param array<string, string> $t
     */
    private static function chart(array $now, array $was, array $labels, array $t, string $lang): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
        $count = count($now);
        if ($count === 0) {
            return '<p class="note">' . $e($t['none']) . '</p>';
        }
        [$w, $h, $left, $top, $bottom] = [800, 200, 44, 10, 26];
        $max = max(1, max($now), $was === [] ? 0 : max($was));
        $step = self::niceStep($max / 4);
        $max = $step * 4;
        $x = static fn (int $i): float => $count === 1 ? $left + ($w - $left) / 2 : $left + $i * ($w - $left - 8) / ($count - 1);
        $y = static fn (int $v): float => $top + ($h - $top - $bottom) * (1 - $v / $max);
        $f = static fn (float $v): string => number_format($v, 1, '.', '');
        $svg = '<svg class="vline" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img" preserveAspectRatio="none" aria-label="' . $e(implode(', ', array_map(static fn (string $l, int $v): string => "$l: $v", $labels, $now))) . '">';
        for ($g = 0; $g <= 4; $g++) {
            $gy = $f($y($step * $g));
            $svg .= '<path class="vg" d="M' . $left . ' ' . $gy . ' H' . $w . '"/><text class="vax" x="' . ($left - 6) . '" y="' . $f((float) $gy + 4) . '" text-anchor="end">' . $e(StatsReport::number($step * $g, $lang)) . '</text>';
        }
        $line = static function (array $values) use ($x, $y, $f): string {
            $d = '';
            foreach (array_values($values) as $i => $v) {
                $d .= ($d === '' ? 'M' : ' L') . $f($x($i)) . ' ' . $f($y(is_int($v) ? $v : 0));
            }
            return $d;
        };
        if ($was !== []) {
            $svg .= '<path class="vprev" d="' . $line(array_slice($was, 0, $count)) . '"/>';
        }
        $d = $line($now);
        $svg .= '<path class="varea" d="' . $d . ' L' . $f($x($count - 1)) . ' ' . $f($y(0)) . ' L' . $f($x(0)) . ' ' . $f($y(0)) . ' Z"/><path class="vnow" d="' . $d . '"/>';
        // A column per point: its tooltip, and the label under every n-th.
        $every = max(1, (int) ceil($count / 8));
        $col = $count === 1 ? $w - $left : ($w - $left - 8) / ($count - 1);
        foreach ($now as $i => $v) {
            $cx = $x($i);
            $tip = $labels[$i] . ': ' . StatsReport::number($v, $lang) . (isset($was[$i]) ? ' (' . $t['before'] . ': ' . StatsReport::number($was[$i], $lang) . ')' : '');
            $svg .= '<rect class="vcol" x="' . $f(max(0.0, $cx - $col / 2)) . '" y="' . $top . '" width="' . $f($col) . '" height="' . ($h - $top - $bottom) . '"><title>' . $e($tip) . '</title></rect>';
            // Every n-th label, and the last -- not one so close to the last that the two overlap.
            if (($i % $every === 0 && $count - 1 - $i > intdiv($every, 2)) || $i === $count - 1) {
                $anchor = $count > 1 && $i === 0 ? 'start' : ($count > 1 && $i === $count - 1 ? 'end' : 'middle');      // the first and last inside the chart
                $svg .= '<text class="vax" x="' . $f($cx) . '" y="' . ($h - 8) . '" text-anchor="' . $anchor . '">' . $e($labels[$i]) . '</text>';
            }
        }
        return $svg . '</svg>';
    }

    /** A round step for the grid: 1, 2, 5, 10, 20, 50 … */
    private static function niceStep(float $raw): int
    {
        $p = 10 ** (int) floor(log10(max(1.0, $raw)));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $p >= $raw) {
                return (int) max(1, $m * $p);
            }
        }
        return (int) (10 * $p);
    }

    /**
     * A card with tabs (radio buttons): each tab a list, a bar per row.
     *
     * @param array<string, list<array{0: string, 1: int, 2: string, 3: ?string, 4?: string}>> $tabs title => rows (name, number, tooltip, link, a note shown under the name)
     * @param array<string, string> $t
     */
    private static function card(string $id, string $title, string $top, array $tabs, array $t, string $lang, bool $code = true): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = '<section class="card vtab"><h2>' . $e($title) . '</h2>';
        $i = 0;
        foreach ($tabs as $name => $_) {
            $h .= '<input type="radio" name="' . $id . '" id="' . $id . $i . '"' . ($i === 0 ? ' checked' : '') . '>';
            $i++;
        }
        $h .= '<div class="vtl">';
        $i = 0;
        foreach ($tabs as $name => $_) {
            $h .= '<label for="' . $id . $i . '">' . $e($name) . '</label>';
            $i++;
        }
        $h .= '</div>' . $top . '<div class="vpanes">';
        foreach ($tabs as $rows) {
            $h .= '<div>';
            if ($rows === []) {
                $h .= '<p class="note">' . $e($t['none']) . '</p>';
            }
            $most = 1;
            foreach ($rows as $row) {
                $most = max($most, $row[1]);
            }
            foreach ($rows as $row) {
                [$name, $count, $tip, $href] = $row;
                $label = $code ? '<code>' . $e($name) . '</code>' : $e($name);
                $h .= '<div class="vrow"' . ($tip !== '' ? ' title="' . $e($tip) . '"' : '') . '><i style="width:' . number_format(100 * $count / $most, 1, '.', '') . '%"></i>'
                    . '<span class="vn">' . ($href !== null ? '<a href="' . $e($href) . '">' . $label . '</a>' : $label)
                    . (($row[4] ?? '') !== '' ? '<br><small class="note">' . $e((string) $row[4]) . '</small>' : '') . '</span><span class="vv">' . $e(StatsReport::number($count, $lang)) . '</span></div>';
            }
            $h .= '</div>';
        }
        return $h . '</div></section>';
    }

    /** The page's styles: tiles, chart, tabs (radio buttons, no script). */
    public const CSS = <<<'CSS'
.vnowl{margin:-4px 0 10px;color:var(--m)}.range{display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:13px;color:var(--m)}.range input{font:inherit;padding:3px 6px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--fg)}.range button{font:inherit;padding:3px 10px;border:1px solid var(--a);border-radius:6px;background:var(--a);color:#fff;cursor:pointer}
.vp>input,.vtab>input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}
.vtiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin:0 0 12px}
.vt{display:block;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 12px;cursor:pointer}
.vt .l{display:block;color:var(--m);font-size:12px;text-transform:uppercase;letter-spacing:.03em}.vt .n{display:block;font-size:24px;font-weight:700;margin:2px 0}.vt .d{color:var(--m);font-size:13px}
.vcharts{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 14px;margin:0 0 16px}.vc{display:none}
.vline{display:block;height:220px}.vg{stroke:var(--line)}.vax{fill:var(--m);font-size:11px}.vnow{stroke:var(--a);stroke-width:2;fill:none;vector-effect:non-scaling-stroke}
.vprev{stroke:var(--m);stroke-width:1.5;stroke-dasharray:5 4;fill:none;opacity:.7;vector-effect:non-scaling-stroke}.varea{fill:var(--a);opacity:.08}.vcol{fill:transparent}.vcol:hover{fill:var(--a);opacity:.08}
.vtl{display:flex;flex-wrap:wrap;gap:4px 14px;margin:-4px 0 8px;border-bottom:1px solid var(--line)}.vtl label{cursor:pointer;color:var(--m);padding:4px 0 6px;font-size:14px}
.vpanes>div{display:none}.vrow{position:relative;display:flex;justify-content:space-between;gap:12px;padding:5px 8px;margin:3px 0;border-radius:5px}
.vrow i{position:absolute;left:0;top:0;bottom:0;background:var(--a);opacity:.12;border-radius:5px}.vrow .vn{position:relative;min-width:0;overflow-wrap:anywhere}.vrow .vv{position:relative;font-variant-numeric:tabular-nums;white-space:nowrap}
.vp>input:focus-visible~.vtiles,.vtab>input:focus-visible~.vtl{outline:2px solid var(--a);outline-offset:2px;border-radius:6px}
CSS;

    /** Which tile and tab is picked: one rule per position (up to six numbers, four tabs). */
    public static function css(): string
    {
        $css = self::CSS;
        for ($i = 1; $i <= 6; $i++) {
            $css .= ".vp>input:nth-of-type($i):checked~.vcharts>.vc:nth-child($i){display:block}.vp>input:nth-of-type($i):checked~.vtiles>.vt:nth-child($i){border-color:var(--a);box-shadow:inset 0 -3px 0 var(--a)}";
        }
        for ($i = 1; $i <= 4; $i++) {
            $css .= ".vtab>input:nth-of-type($i):checked~.vpanes>div:nth-child($i){display:block}.vtab>input:nth-of-type($i):checked~.vtl>label:nth-child($i){color:var(--fg);font-weight:600;box-shadow:inset 0 -2px 0 var(--a)}";
        }
        return $css;
    }
}
