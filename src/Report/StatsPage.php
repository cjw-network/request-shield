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
use CjwNetwork\RequestShield\Texts;

/**
 * The statistics as a page (set stats on): who came -- people, crawlers,
 * bots --, what the shield did, the answers' status codes, what each known
 * crawler did, pages not found and who links to them. Charts drawn as inline
 * SVG and CSS (no script library, no external file); dark mode; English and
 * German. It refreshes itself every minute.
 *
 *   echo StatsPage::render($settings, ['action' => '/stats', 'days' => 7]);
 *
 * Print it where only the site's people see it: behind the CMS's login, or a
 * path restricted to some addresses (restrict /stats to 192.0.2.0/24).
 */
final class StatsPage
{
    private const T = [
        'en' => [
            'title' => 'Statistics', 'today' => '24 hours', 'd7' => '7 days', 'd30' => '30 days', 'm12' => '12 months',
            'requests' => 'Requests', 'people' => 'People', 'crawlers' => 'Crawlers', 'bots' => 'Bots', 'checked' => 'Checked', 'refused' => 'Refused',
            'notFound' => 'Not found', 'through' => 'Let through', 'throttled' => 'Told to wait', 'who' => 'Who came', 'what' => 'What the shield did',
            'answers' => 'Answers', 'short' => 'In short', 'known' => 'Known crawlers', 'missing' => 'Pages not found', 'linked' => 'linked from',
            'rules' => 'Rules that decided most', 'botfam' => 'Other bots', 'nothing' => 'Nothing yet.', 'claimed' => 'only claimed',
            'allowed' => 'let through', 'last' => 'last visit', 'updated' => 'Updated', 'refresh' => 'refreshes every minute', 'hours48' => 'last 48 hours',
            'all' => 'all crawlers', 'kind.search' => 'search', 'kind.ai-search' => 'AI search', 'kind.ai-user' => 'AI, for a person', 'kind.ai-training' => 'AI training',
            'noStats' => 'No statistics: switch them on with "set stats on" in the rule file.', 'sitemaps' => 'Sitemaps', 'noMaps' => 'No sitemap was asked for.',
            'noReader' => 'not read by a verified crawler', 'times' => '×', 'top' => 'Most visited pages', 'noPages' => 'No page views counted yet (set stats … pages).', 'sections' => 'Most visited sections', 'topBlocked' => 'Pages the shield stopped most', 'sectionsBlocked' => 'Sections the shield stopped most', 'noBlocked' => 'The shield stopped no page in this period.', 'sortBy' => 'sorted by', 'blocked' => 'stopped', 'byViews' => 'views', 'byBlocked' => 'stopped (all)',
            'tabAll' => 'Overview', 'tabSite' => 'Visitors & pages', 'tabShield' => 'Protection', 'tabRules' => 'Rules & setup', 'ruleDetails' => 'all rules and settings', 'builtIn' => 'the fixed checks: kind of request, sizes, disguised addresses', 'filter' => 'Filter', 'pathStarts' => 'path starts with', 'subtree' => 'Subtree', 'views' => 'views', 'exact' => 'exact', 'approx' => 'the sum of its most visited pages', 'clear' => 'all pages', 'per' => 'per', 'hour' => 'hour', 'day' => 'day', 'week' => 'week', 'month' => 'month', 'year' => 'year',
        ],
        'de' => [
            'title' => 'Statistik', 'today' => '24 Stunden', 'd7' => '7 Tage', 'd30' => '30 Tage', 'm12' => '12 Monate',
            'requests' => 'Anfragen', 'people' => 'Menschen', 'crawlers' => 'Crawler', 'bots' => 'Bots', 'checked' => 'Geprüft', 'refused' => 'Abgewiesen',
            'notFound' => 'Nicht gefunden', 'through' => 'Durchgelassen', 'throttled' => 'Gebremst', 'who' => 'Wer kam', 'what' => 'Was der Schutz tat',
            'answers' => 'Antworten', 'short' => 'Kurz gesagt', 'known' => 'Bekannte Crawler', 'missing' => 'Nicht gefundene Seiten', 'linked' => 'verlinkt von',
            'rules' => 'Regeln, die am meisten entschieden', 'botfam' => 'Andere Bots', 'nothing' => 'Noch nichts.', 'claimed' => 'nur behauptet',
            'allowed' => 'durchgelassen', 'last' => 'zuletzt', 'updated' => 'Stand', 'refresh' => 'aktualisiert sich jede Minute', 'hours48' => 'letzte 48 Stunden',
            'all' => 'alle Crawler', 'kind.search' => 'Suche', 'kind.ai-search' => 'KI-Suche', 'kind.ai-user' => 'KI, für eine Person', 'kind.ai-training' => 'KI-Training',
            'noStats' => 'Keine Statistik: mit "set stats on" in der Regeldatei einschalten.', 'sitemaps' => 'Sitemaps', 'noMaps' => 'Keine Sitemap wurde abgefragt.',
            'noReader' => 'von keinem bestätigten Crawler gelesen', 'times' => '×', 'top' => 'Meistbesuchte Seiten', 'noPages' => 'Noch keine Seitenaufrufe gezählt (set stats … pages).', 'sections' => 'Meistbesuchte Bereiche', 'topBlocked' => 'Am häufigsten blockierte Seiten', 'sectionsBlocked' => 'Am häufigsten blockierte Bereiche', 'noBlocked' => 'Der Schutz hat in diesem Zeitraum keine Seite blockiert.', 'sortBy' => 'sortiert nach', 'blocked' => 'blockiert', 'byViews' => 'Aufrufe', 'byBlocked' => 'blockiert (alle)',
            'tabAll' => 'Übersicht', 'tabSite' => 'Besucher & Seiten', 'tabShield' => 'Schutz', 'tabRules' => 'Regeln & Aufbau', 'ruleDetails' => 'alle Regeln und Einstellungen', 'builtIn' => 'die festen Prüfungen: Art der Anfrage, Größen, getarnte Adressen', 'filter' => 'Filtern', 'pathStarts' => 'Pfad beginnt mit', 'subtree' => 'Unterbaum', 'views' => 'Aufrufe', 'exact' => 'genau', 'approx' => 'Summe seiner meistbesuchten Seiten', 'clear' => 'alle Seiten', 'per' => 'pro', 'hour' => 'Stunde', 'day' => 'Tag', 'week' => 'Woche', 'month' => 'Monat', 'year' => 'Jahr',
        ],
    ];

    /**
     * @param array{action?: string, view?: string, tabs?: bool, links?: array<string, string>, days?: int, by?: string, crawler?: ?string, path?: ?string, sort?: string, lang?: string, accept?: ?string, home?: string, homeLabel?: string,
     *   title?: string, fragment?: bool, now?: int, stats?: Stats, check?: array<mixed>, ip?: string, store?: \CjwNetwork\RequestShield\Store\Store} $o
     *   action: the page's own address (links, refresh); view: site (visitors and pages, for editors), shield
     *   (what the protection did, for admins) or all (everything); links: an address per view -- 'all', 'site',
     *   'shield' => '/rs/dashboard' … -- for the tabs (without: ?view=); tabs: the tabs between them; lang: en, de, or auto (the browser's, from accept);
     *   check: the rule tester's values in the rules view (method, url, ip, ua -- usually $_GET), ip: the address it starts with;
     *   fragment: only the content, for the refresh; sort: the pages by views, or by what the shield stopped there
     *   (blocked, refused, checked, throttled -- the protection's view starts with blocked)
     */
    public static function render(Settings $s, array $o = []): string
    {
        $lang = $o['lang'] ?? 'auto';
        if (!isset(self::T[$lang])) {
            $lang = Texts::language('auto', $o['accept'] ?? null);
            $lang = isset(self::T[$lang]) ? $lang : 'en';
        }
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $days = max(1, min(3660, $o['days'] ?? 7));
        $by = $o['by'] ?? ($days === 1 ? 'hour' : ($days > 62 ? 'month' : 'day'));
        $crawler = $o['crawler'] ?? null;
        $path = isset($o['path']) && $o['path'] !== '' ? '/' . ltrim((string) $o['path'], '/') : null;
        $view = in_array($o['view'] ?? 'site', ['site', 'shield', 'all', 'rules'], true) ? ($o['view'] ?? 'site') : 'site';
        // The pages by views, or by what the shield stopped (the protection's view starts there).
        $sorts = ['views', 'blocked', 'refused', 'checked', 'throttled'];
        $sortDefault = $view === 'shield' ? 'blocked' : 'views';
        $sort = in_array($o['sort'] ?? $sortDefault, $sorts, true) ? ($o['sort'] ?? $sortDefault) : $sortDefault;
        // What every link carries along besides the period and the language.
        $extra = ($crawler !== null ? ['crawler' => $crawler] : []) + ($path !== null ? ['path' => $path] : []) + ($sort !== $sortDefault ? ['sort' => $sort] : []);
        $now = $o['now'] ?? time();
        $action = $o['action'] ?? '';
        // One address per view ('links' => ['all' => '/rs/dashboard', 'site' => '/rs/stats', 'shield' => '/rs/shield']):
        // the path names the view, GET parameters filter. Without links: ?view=.
        $links = [];
        foreach ((array) ($o['links'] ?? []) as $v => $u) {
            if (in_array($v, ['all', 'site', 'shield', 'rules'], true) && $u !== '') {
                $links[$v] = $u;
            }
        }
        $action = $links[$view] ?? $action;
        $query = static fn (array $q): string => $action . '?' . http_build_query($links !== [] ? array_diff_key($q, ['view' => 1]) : $q);

        if (!$s->statsEnabled) {
            $body = '<p class="note">' . $e($t['noStats']) . '</p>';
            return ($o['fragment'] ?? false) ? $body : self::page($body, $t['title'], $lang, $o, $e);
        }
        $r = StatsReport::build($s, $o['stats'] ?? null, $days, $now, ['by' => $by === 'hour' ? 'day' : $by, 'lang' => $lang, 'sort' => $sort] + $extra);
        // Hours: the last 48 for the small curves, today's for a "today" chart.
        $hours = [];
        for ($i = 47; $i >= 0; $i--) {
            $k = gmdate('YmdH', $now - $i * 3600);
            $hours[$k] = $r['hourly'][$k] ?? [];
        }
        $periods = $r['periods'];
        if ($by === 'hour') {
            // "Today": the last 24 hours, labelled in the server's time.
            $periods = [];
            foreach (array_slice($hours, 24, null, true) as $k => $b) {
                $k = (string) $k;           // PHP makes "2026093010" an integer key
                $ts = (int) gmmktime((int) substr($k, 8, 2), 0, 0, (int) substr($k, 4, 2), (int) substr($k, 6, 2), (int) substr($k, 0, 4));
                $periods[date('H', $ts) . ':00'] = $b;
            }
        }
        // Hours of today, or the periods (days, weeks, months -- old months from their files).
        $src = $by === 'hour' ? $periods : $r['periods'];
        $total = self::sum($src, 'passed') + self::sum($src, 'uncached') + self::sum($src, 'checked') + self::sum($src, 'throttled') + self::sum($src, 'refused');
        $tile = static fn (string $key, int $value, string $cls, array $curve): string => '<div class="tile ' . $cls . '"><span class="n">' . $e($n($value)) . '</span><span class="l">' . $e($t[$key]) . '</span>'
            . self::spark(self::ints($curve)) . '</div>';

        // The tabs: visitors and pages (editors) -- protection (admins). An
        // embedding page can show one only ('tabs' => false).
        $tabs = $links !== [] ? array_intersect_key(['all' => $t['tabAll'], 'site' => $t['tabSite'], 'shield' => $t['tabShield'], 'rules' => $t['tabRules']], $links)
            : ['site' => $t['tabSite'], 'shield' => $t['tabShield'], 'rules' => $t['tabRules']];
        $h = '';
        if (($o['tabs'] ?? true) && count($tabs) > 1) {
            $h .= '<nav class="tabs">';
            foreach ($tabs as $v => $label) {
                $href = isset($links[$v]) ? $links[$v] . '?' . http_build_query(['days' => $days, 'lang' => $lang]) : $query(['view' => $v, 'days' => $days, 'lang' => $lang]);
                $h .= '<a class="tab' . ($v === $view ? ' on' : '') . '" href="' . $e($href) . '">' . $e($label) . '</a>';
            }
            $h .= '</nav>';
        }
        $h .= '<div class="bar"><div class="pills">';
        foreach ([[1, 'hour', 'today'], [7, 'day', 'd7'], [30, 'day', 'd30'], [365, 'month', 'm12']] as [$d, $b, $label]) {
            $h .= '<a class="pill' . ($d === $days ? ' on' : '') . '" href="' . $e($query(['view' => $view, 'days' => $d, 'by' => $b, 'lang' => $lang] + $extra)) . '">' . $e($t[$label]) . '</a>';
        }
        $h .= '</div><div class="pills">';
        foreach (['de' => 'DE', 'en' => 'EN'] as $l => $label) {
            $h .= '<a class="pill' . ($l === $lang ? ' on' : '') . '" href="' . $e($query(['view' => $view, 'days' => $days, 'by' => $by, 'lang' => $l] + $extra)) . '">' . $label . '</a>';
        }
        $h .= '<a class="pill" href="' . $e($query(['days' => $days, 'by' => $by === 'hour' ? 'day' : $by, 'format' => 'json'])) . '">JSON</a></div></div>';
        $h .= '<p class="sub">' . $e(self::date($r['from'], $lang) . ' – ' . self::date($r['to'], $lang)) . ($crawler !== null ? ' · ' . $e($crawler) . ' · <a href="' . $e($query(['days' => $days, 'by' => $by, 'lang' => $lang])) . '">' . $e($t['all']) . '</a>' : '') . '</p>';
        if ($view === 'rules') {
            // Rules & setup: the way of a request, every rule, every setting.
            $h .= SetupPage::render($s, $lang, $r['rules'], ['check' => (array) ($o['check'] ?? []), 'action' => $action, 'now' => (float) $now,
                'keep' => ($links !== [] ? [] : ['view' => 'rules']) + ['days' => $days, 'lang' => $lang]] + (isset($o['ip']) ? ['ip' => (string) $o['ip']] : []) + (isset($o['store']) ? ['store' => $o['store']] : []));
            $h .= '<p class="foot">' . $e($t['updated'] . ' ' . date($lang === 'de' ? 'd.m.Y H:i:s' : 'Y-m-d H:i:s', $now)) . '</p>';
            return ($o['fragment'] ?? false) ? $h : self::page($h, $o['title'] ?? $t['title'], $lang, $o, $e);
        }

        $tiles = [
            'requests' => $tile('requests', $total, 'req', array_map(static fn (int $a, int $b, int $c, int $d, int $x): int => $a + $b + $c + $d + $x, self::curve($hours, 'passed'), self::curve($hours, 'uncached'), self::curve($hours, 'checked'), self::curve($hours, 'throttled'), self::curve($hours, 'refused'))),
            'people' => $tile('people', self::sum($src, 'people'), 'people', self::curve($hours, 'people')),
            'crawlers' => $tile('crawlers', self::sum($src, 'crawlers'), 'crawlers', self::curve($hours, 'crawlers')),
            'bots' => $tile('bots', self::sum($src, 'bots'), 'bots', self::curve($hours, 'bots')),
            'checked' => $tile('checked', self::sum($src, 'checked'), 'checked', self::curve($hours, 'checked')),
            'refused' => $tile('refused', self::sum($src, 'refused') + self::sum($src, 'throttled'), 'refused', array_map(static fn (int $a, int $b): int => $a + $b, self::curve($hours, 'refused'), self::curve($hours, 'throttled'))),
            'notFound' => $tile('notFound', self::sum($src, 'notFound'), 'nf', self::curve($hours, 'notFound')),
        ];
        $per = ' <small>' . $e($t['per'] . ' ' . $t[$by === 'hour' ? 'hour' : $by]) . '</small>';
        $chartWho = '<section class="card"><h2>' . $e($t['who']) . $per . '</h2>'
            . self::stacked($src, ['people' => [$t['people'], 'people'], 'crawlers' => [$t['crawlers'], 'crawlers'], 'bots' => [$t['bots'], 'bots']], $lang) . '</section>';
        $chartWhat = '<section class="card"><h2>' . $e($t['what']) . $per . '</h2>'
            . self::stacked(array_map(static fn (array $b): array => ['through' => self::sum([$b], 'passed') + self::sum([$b], 'uncached'), 'checked' => self::sum([$b], 'checked'), 'throttled' => self::sum([$b], 'throttled'), 'refused' => self::sum([$b], 'refused')], $src),
                ['through' => [$t['through'], 'through'], 'checked' => [$t['checked'], 'checked'], 'throttled' => [$t['throttled'], 'throttled'], 'refused' => [$t['refused'], 'refused']], $lang)
            . '</section>';

        // The answers as a ring, and the sentences.
        $groups = [];
        foreach ($r['statuses'] as $code => $count) {
            $g = substr((string) $code, 0, 1) . 'xx';
            $groups[$g] = ($groups[$g] ?? 0) + $count;
        }
        ksort($groups);
        $answers = '<section class="card"><h2>' . $e($t['answers']) . '</h2>' . ($r['statuses'] === [] ? '<p class="note">' . $e($t['nothing']) . '</p>'
            : '<div class="donut">' . self::donut($groups, $lang) . '<ul class="legend">' . implode('', array_map(static fn ($code, int $c): string => '<li><span class="dot s' . $e(substr((string) $code, 0, 1)) . '"></span><b>' . $e((string) $code) . '</b> ' . $e($n($c)) . '</li>',
                array_keys($r['statuses']), $r['statuses'])) . '</ul></div>') . '</section>';
        $short = '<section class="card"><h2>' . $e($t['short']) . '</h2>' . ($r['sentences'] === [] ? '<p class="note">' . $e($t['nothing']) . '</p>'
            : '<ul class="short"><li>' . implode('</li><li>', array_map($e, $r['sentences'])) . '</li></ul>') . '</section>';
        $body = $h;
        $h = '';

        // The most visited pages and sections: one bar each, by who came; a
        // subtree filter ("path starts with") with its views.
        // What the filter form carries along: the period, the language -- and the
        // view only where no address names it (with links the path does).
        $keep = ($links !== [] ? [] : ['view' => $view]) + ['days' => $days, 'by' => $by, 'lang' => $lang] + ($crawler !== null ? ['crawler' => $crawler] : []);
        $sorted = $sort !== $sortDefault ? ['sort' => $sort] : [];
        $link = static fn (string $p): string => $query($keep + ['path' => $p] + $sorted);
        $h = '';
        $views = $sort === 'views';
        $h .= '<section class="card"><h2>' . $e($t[$views ? 'top' : 'topBlocked']) . '</h2><form class="filter" method="get" action="' . $e($action) . '">';
        foreach ($keep as $k => $v) {
            $h .= '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e((string) $v) . '">';
        }
        $h .= '<label>' . $e($t['pathStarts']) . ' <input type="text" name="path" value="' . $e((string) $path) . '" placeholder="/news/"></label> <label class="sort">' . $e($t['sortBy']) . ' <select name="sort">'
            . implode('', array_map(static fn (string $v): string => '<option value="' . $v . '"' . ($v === $sort ? ' selected' : '') . '>'
                . $e($t[['views' => 'byViews', 'blocked' => 'byBlocked'][$v] ?? $v]) . '</option>', $sorts)) . '</select></label> <button type="submit">' . $e($t['filter']) . '</button>'
            . ($path !== null ? ' <a href="' . $e($query($keep + $sorted)) . '">' . $e($t['clear']) . '</a>' : '') . '</form>';
        if ($r['subtree'] !== null) {
            $st = $r['subtree'];
            $h .= '<p class="subtree"><b>' . $e($t['subtree'] . ' ' . $st['path']) . ':</b> ' . $e($n($st['total']) . ' ' . $t['views']) . ' — '
                . $e($t['people'] . ' ' . $n($st['people']) . ' · ' . $t['crawlers'] . ' ' . $n($st['crawlers']) . ' · ' . $t['bots'] . ' ' . $n($st['bots']))
                . ' <span class="note">(' . $e($st['exact'] ? $t['exact'] : $t['approx']) . ')</span>'
                . ($st['blocked'] > 0 ? ' — <span class="stop">' . $e($n($st['blocked']) . ' ' . $t['blocked'] . ': ' . self::stopped($st, $t, $lang)) . '</span>' : '') . '</p>';
        }
        $h .= $r['pages'] === [] ? '<p class="note">' . $e($t[$views ? 'noPages' : 'noBlocked']) . '</p>' : self::rows($r['pages'], null, $t, $lang, $views);
        if ($r['folders'] !== []) {
            $h .= '<h2 class="sub2">' . $e($t[$views ? 'sections' : 'sectionsBlocked']) . '</h2>' . self::rows($r['folders'], $link, $t, $lang, $views);
        }
        $h .= '<p class="legend inline">' . ($views ? '<span class="dot people"></span>' . $e($t['people']) . ' <span class="dot crawlers"></span>' . $e($t['crawlers']) . ' <span class="dot bots"></span>' . $e($t['bots'])
            : '<span class="dot refused"></span>' . $e($t['refused']) . ' <span class="dot checked"></span>' . $e($t['checked']) . ' <span class="dot throttled"></span>' . $e($t['throttled'])) . '</p></section>';
        $topBlock = $h;
        $h = '';

        // Crawlers: one bar each -- let through, checked or told to wait, refused; and how often the name was only claimed.
        $seen = array_filter($r['crawlers'], static fn (array $c): bool => $c['seen'] > 0);
        uasort($seen, static fn (array $a, array $b): int => $b['seen'] <=> $a['seen']);
        $max = max(1, ...array_map(static fn (array $c): int => $c['seen'], array_values($seen) ?: [['seen' => 1]]));
        $h .= '<section class="card"><h2>' . $e($t['known']) . '</h2>';
        if ($seen === []) {
            $h .= '<p class="note">' . $e($t['nothing']) . '</p>';
        }
        foreach ($seen as $id => $c) {
            $w = static fn (int $v): string => number_format(100 * $v / $max, 2, '.', '');
            $h .= '<div class="crow"><div class="cname"><a href="' . $e($query(['days' => $days, 'by' => $by, 'lang' => $lang, 'crawler' => (string) $id])) . '">' . $e((string) $id) . '</a>'
                . ' <span class="kind">' . $e($t['kind.' . $c['kind']] ?? $c['kind']) . '</span><br><span class="note">' . $e($c['name'])
                . ($c['last'] !== null ? ' · ' . $e($t['last']) . ' ' . $e(date($lang === 'de' ? 'd.m. H:i' : 'M j, H:i', $c['last'][0])) : '') . '</span></div>'
                . '<div class="hbar" title="' . $e($n($c['allowed']) . ' ' . $t['allowed'] . ' · ' . $n($c['checked'] + $c['throttled']) . ' ' . $t['checked'] . ' · ' . $n($c['refused']) . ' ' . $t['refused'] . ' · ' . $n($c['claimed']) . ' ' . $t['claimed']) . '">'
                . '<i class="through" style="width:' . $w($c['allowed']) . '%"></i><i class="checked" style="width:' . $w($c['checked'] + $c['throttled']) . '%"></i>'
                . '<i class="refused" style="width:' . $w($c['refused']) . '%"></i><i class="claimed" style="width:' . $w($c['claimed']) . '%"></i></div>'
                . '<div class="cnum">' . $e($n($c['verified'])) . ($c['claimed'] > 0 ? ' <span class="note">+' . $e($n($c['claimed'])) . ' ' . $e($t['claimed']) . '</span>' : '') . '</div></div>';
        }
        $h .= '<p class="legend inline"><span class="dot through"></span>' . $e($t['through']) . ' <span class="dot checked"></span>' . $e($t['checked']) . ' <span class="dot refused"></span>' . $e($t['refused'])
            . ' <span class="dot claimed"></span>' . $e($t['claimed']) . '</p></section>';

        $crawlersBlock = $h;
        $h = '';
        // Sitemaps: which exist (the answers), who read each, when last.
        $h .= '<section class="card"><h2>' . $e($t['sitemaps']) . '</h2>';
        if ($r['sitemaps'] === []) {
            $h .= '<p class="note">' . $e($t['noMaps']) . '</p>';
        } else {
            $h .= '<table class="list">';
            foreach ($r['sitemaps'] as $path => $x) {
                $h .= '<tr><td><code>' . $e($path) . '</code><br>' . implode(' ', array_map(static fn ($code, int $c): string => '<span class="badge s' . $e(substr((string) $code, 0, 1)) . '">' . $e((string) $code) . ' · ' . $e($n($c)) . '</span>',
                    array_keys($x['statuses']), $x['statuses'])) . '</td><td>'
                    . ($x['crawlers'] === [] ? '<span class="note">' . $e($t['noReader']) . '</span>' : implode('<br>', array_map(static fn ($id, array $c): string => '<b>' . $e((string) $id) . '</b> '
                        . $e($n($c['count']) . $t['times']) . ($c['last'] !== null ? ' <span class="note">· ' . $e($t['last']) . ' ' . $e(date($lang === 'de' ? 'd.m. H:i' : 'M j, H:i', $c['last'])) . '</span>' : ''),
                        array_keys($x['crawlers']), $x['crawlers'])))
                    . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '</section>';

        $sitemapsBlock = $h;
        $h = '';
        // Not found, rules, bots: lists with a bar each.
        $missing = [];
        foreach ($r['notFound'] as $path => $x) {
            $from = [];
            foreach ($x['referrers'] as $source => $c) {
                $from[] = "$source ($c)";
            }
            $missing[] = ['<code>' . $e((string) $path) . '</code>' . ($from !== [] ? '<br><span class="note">' . $e($t['linked'] . ' ' . implode(', ', $from)) . '</span>' : ''), $x['count']];
        }
        // The rules that decided most: their ID (a link to the rule in "Rules & setup"), what they do, where written.
        $rules = [];
        $setup = $links['rules'] ?? ($links === [] ? $action : null);
        foreach (array_slice($r['rules'], 0, 10, true) as $rule => $c) {
            $info = SetupPage::rule($s, (string) $rule);
            $fixed = $info['id'] === 'built-in';          // method, sizes, disguised addresses: steps, not rules
            $info['text'] ??= $fixed ? $t['builtIn'] : null;
            $id = '<code>' . $e($info['id']) . '</code>';
            $href = $setup !== null ? ($links !== [] ? $setup . '?' . http_build_query(['days' => $days, 'lang' => $lang]) : $query(['view' => 'rules', 'days' => $days, 'lang' => $lang])) . ($fixed ? '#way' : '#rule-' . SetupPage::anchor($info['id'])) : null;
            $rules[] = [($href !== null ? '<a href="' . $e($href) . '">' . $id . '</a>' : $id)
                . ($info['text'] !== null ? '<br>' . $e($info['text']) : '') . ($info['where'] !== null ? '<br><span class="note">' . $e($info['where']) . '</span>' : ''), $c];
        }
        $bots = [];
        foreach ($r['bots'] as $family => $c) {
            $bots[] = [$e((string) $family), $c];
        }
        $missingBlock = '<section class="card"><h2>' . $e($t['missing']) . '</h2>' . self::bars($missing, $lang, $t['nothing']) . '</section>';
        $rulesBlock = '<section class="card"><h2>' . $e($t['rules']) . '</h2>' . self::bars($rules, $lang, $t['nothing'])
            . ($setup !== null ? '<p class="note"><a href="' . $e($links !== [] ? $setup . '?' . http_build_query(['days' => $days, 'lang' => $lang]) : $query(['view' => 'rules', 'days' => $days, 'lang' => $lang])) . '">' . $e($t['ruleDetails']) . ' →</a></p>' : '')
            . '<h2>' . $e($t['botfam']) . '</h2>' . self::bars($bots, $lang, $t['nothing']) . '</section>';
        // Two views: the site's (for editors: visitors, pages, links, crawlers,
        // sitemaps) and the shield's (for admins: what it did, answers, rules, bots).
        $grid = static fn (string ...$cards): string => '<div class="grid2">' . implode('', $cards) . '</div>';
        $hint = '<p class="hint">' . $e($t['hours48']) . '</p>';
        if ($view === 'shield') {
            $h = $body . '<div class="tiles">' . $tiles['requests'] . $tiles['bots'] . $tiles['checked'] . $tiles['refused'] . '</div>' . $hint
                . $grid($chartWhat, $answers) . $topBlock . $crawlersBlock . $grid($rulesBlock, $chartWho);
        } elseif ($view === 'site') {
            $h = $body . '<div class="tiles">' . $tiles['people'] . $tiles['crawlers'] . $tiles['notFound'] . '</div>' . $hint
                . $topBlock . $grid($chartWho, $short) . $crawlersBlock . $grid($missingBlock, $sitemapsBlock);
        } else {
            $h = $body . '<div class="tiles">' . implode('', $tiles) . '</div>' . $hint . $grid($chartWho, $chartWhat) . $grid($answers, $short) . $topBlock . $crawlersBlock
                . $sitemapsBlock . $grid($missingBlock, $rulesBlock);
        }
        $h .= '<p class="foot">' . $e($t['updated'] . ' ' . date($lang === 'de' ? 'd.m.Y H:i:s' : 'Y-m-d H:i:s', $now) . ' · ' . $t['refresh']) . '</p>';

        if ($o['fragment'] ?? false) {
            return $h;
        }
        return self::page($h, $o['title'] ?? $t['title'], $lang, $o + ['refresh' => $query(['view' => $view, 'days' => $days, 'by' => $by, 'lang' => $lang, 'fragment' => 1] + $extra)], $e);
    }

    /**
     * The addresses of the four views under the settings' dashboard-path:
     * the dashboard (everything), stats (visitors and pages), shield (the
     * protection), rules (the way of a request, every rule and setting) --
     * for 'links', and for a site's routes.
     *
     * @return array{all: string, site: string, shield: string, rules: string}
     */
    public static function links(Settings $s, string $prefix = ''): array
    {
        $base = $prefix . $s->dashboardPath;
        return ['all' => $base . '/dashboard', 'site' => $base . '/stats', 'shield' => $base . '/shield', 'rules' => $base . '/rules'];
    }

    /** Which view a path asks for (all, site, shield, rules), or null; capitals do not matter. */
    public static function viewFor(Settings $s, string $path): ?string
    {
        $p = strtolower(rtrim($path, '/'));
        foreach (self::links($s) as $view => $link) {
            if ($p === strtolower($link)) {
                return $view;
            }
        }
        return null;
    }

    /**
     * Pages or sections, a bar each: by who came (people, crawlers, bots), or
     * by what the shield did (refused, checked, told to wait).
     *
     * @param array<array-key, array{people: int, crawlers: int, bots: int, total: int, refused: int, checked: int, throttled: int, blocked: int}> $list
     * @param (callable(string): string)|null $href a link for each (a section: its subtree)
     * @param array<string, string> $t
     */
    private static function rows(array $list, ?callable $href, array $t, string $lang, bool $views = true): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $most = 1;
        foreach ($list as $v) {
            $most = max($most, $views ? $v['total'] : $v['blocked']);
        }
        $out = '';
        foreach ($list as $p => $v) {
            $w = static fn (int $x): string => number_format(100 * $x / $most, 2, '.', '');
            $name = '<code>' . $e((string) $p) . '</code>';
            $out .= '<div class="prow"><div class="pname">' . ($href !== null ? '<a href="' . $e($href((string) $p)) . '">' . $name . '</a>' : $name) . '</div>';
            if ($views) {
                // Views by who came; what the shield stopped there as a note.
                $out .= '<div class="hbar thin" title="' . $e($n($v['people']) . ' ' . $t['people'] . ' · ' . $n($v['crawlers']) . ' ' . $t['crawlers'] . ' · ' . $n($v['bots']) . ' ' . $t['bots']) . '">'
                    . '<i class="people" style="width:' . $w($v['people']) . '%"></i><i class="crawlers" style="width:' . $w($v['crawlers']) . '%"></i><i class="bots" style="width:' . $w($v['bots']) . '%"></i></div>'
                    . '<div class="cnum">' . $e($n($v['total'])) . ' <span class="note">' . $e($n($v['people']) . ' · ' . $n($v['crawlers']) . ' · ' . $n($v['bots'])) . '</span>'
                    . ($v['blocked'] > 0 ? ' <span class="stop" title="' . $e(self::stopped($v, $t, $lang)) . '">' . $e($n($v['blocked']) . ' ' . $t['blocked']) . '</span>' : '') . '</div></div>';
            } else {
                // What the shield stopped, by how; the page's views as a note.
                $out .= '<div class="hbar thin" title="' . $e(self::stopped($v, $t, $lang)) . '">'
                    . '<i class="refused" style="width:' . $w($v['refused']) . '%"></i><i class="checked" style="width:' . $w($v['checked']) . '%"></i><i class="throttled" style="width:' . $w($v['throttled']) . '%"></i></div>'
                    . '<div class="cnum"><span class="stop">' . $e($n($v['blocked'])) . '</span> <span class="note">' . $e($n($v['refused']) . ' · ' . $n($v['checked']) . ' · ' . $n($v['throttled']))
                    . ' — ' . $e($n($v['total']) . ' ' . $t['views']) . '</span></div></div>';
            }
        }
        return $out;
    }

    /**
     * What the shield stopped, in words: "3 Refused · 1 Checked".
     *
     * @param array{refused: int, checked: int, throttled: int} $v
     * @param array<string, string> $t
     */
    private static function stopped(array $v, array $t, string $lang): string
    {
        $out = [];
        foreach (['refused', 'checked', 'throttled'] as $k) {
            if ($v[$k] > 0) {
                $out[] = StatsReport::number($v[$k], $lang) . ' ' . $t[$k];
            }
        }
        return implode(' · ', $out);
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = is_int($v) ? $v : 0;
        }
        return $out;
    }

    /**
     * The sum of one of the numbers over periods.
     *
     * @param array<array-key, array<string, int>> $list
     */
    private static function sum(array $list, string $k): int
    {
        $total = 0;
        foreach ($list as $b) {
            $total += $b[$k] ?? 0;
        }
        return $total;
    }

    /**
     * One number over the hours, for a small curve.
     *
     * @param array<array-key, array<string, int>> $hours
     * @return list<int>
     */
    private static function curve(array $hours, string $k): array
    {
        $out = [];
        foreach ($hours as $b) {
            $out[] = $b[$k] ?? 0;
        }
        return $out;
    }

    /**
     * Rows with a bar each: the label (HTML, escaped already), the count.
     *
     * @param list<array{0: string, 1: int}> $list
     */
    private static function bars(array $list, string $lang, string $nothing): string
    {
        if ($list === []) {
            return '<p class="note">' . htmlspecialchars($nothing, ENT_QUOTES) . '</p>';
        }
        $max = 1;
        foreach ($list as $row) {
            $max = max($max, $row[1]);
        }
        $out = '<table class="list">';
        foreach ($list as [$label, $count]) {
            $out .= '<tr><td>' . $label . '</td><td class="num">' . htmlspecialchars(StatsReport::number($count, $lang), ENT_QUOTES) . '</td>'
                . '<td class="barcell"><i style="width:' . number_format(100 * $count / $max, 2, '.', '') . '%"></i></td></tr>';
        }
        return $out . '</table>';
    }

    /**
     * @param array<string, mixed> $o
     * @param callable(string): string $e
     */
    private static function page(string $body, string $title, string $lang, array $o, callable $e): string
    {
        $refresh = is_string($o['refresh'] ?? null) ? $o['refresh'] : '';
        return '<!doctype html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $e($title) . '</title><style>' . self::CSS . '</style></head><body>'
            . (isset($o['home']) && is_string($o['home']) ? '<header><a href="' . $e($o['home']) . '">← ' . $e(is_string($o['homeLabel'] ?? null) ? $o['homeLabel'] : 'Back') . '</a></header>' : '')
            . '<main><h1>' . $e($title) . '</h1><div id="stats"' . ($refresh !== '' ? ' data-refresh="' . $e($refresh) . '"' : '') . '>' . $body . '</div></main>'
            . '<script>' . self::SCRIPT . '</script></body></html>';
    }

    /**
     * A small curve of the last hours.
     *
     * @param list<int> $values
     */
    private static function spark(array $values): string
    {
        $max = max(1, ...($values ?: [0]));
        $count = count($values);
        if ($count < 2) {
            return '';
        }
        $points = [];
        foreach ($values as $i => $v) {
            $points[] = number_format($i * 120 / ($count - 1), 1, '.', '') . ',' . number_format(26 - 24 * $v / $max, 1, '.', '');
        }
        return '<svg class="spark" viewBox="0 0 120 28" preserveAspectRatio="none" aria-hidden="true"><polyline points="' . implode(' ', $points) . '"/></svg>';
    }

    /**
     * Stacked bars, one per period; every part has its tooltip.
     *
     * @param array<array-key, array<string, int>> $periods label => series => count
     * @param array<string, array{0: string, 1: string}> $series key => [label, css class]
     */
    private static function stacked(array $periods, array $series, string $lang): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $count = count($periods);
        $legend = '<p class="legend inline">' . implode(' ', array_map(static fn (array $x): string => '<span class="dot ' . $x[1] . '"></span>' . $e($x[0]), $series)) . '</p>';
        if ($count === 0) {
            return $legend;
        }
        $max = 1;
        foreach ($periods as $b) {
            $sum = 0;
            foreach (array_keys($series) as $k) {
                $sum += (int) ($b[$k] ?? 0);
            }
            $max = max($max, $sum);
        }
        $w = 600;
        $h = 180;
        $slot = $w / $count;
        $bar = max(2.0, min(40.0, $slot * 0.7));
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($h + 22) . '" role="img">';
        foreach ([0.25, 0.5, 0.75, 1.0] as $f) {
            $y = number_format($h - $h * $f, 1, '.', '');
            $svg .= '<line class="grid" x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '"/>';
        }
        $svg .= '<text class="axis" x="2" y="11">' . $e(StatsReport::number($max, $lang)) . '</text>';
        $i = 0;
        $every = (int) max(1, ceil($count / 12));
        foreach ($periods as $label => $b) {
            $x = $i * $slot + ($slot - $bar) / 2;
            $y = $h;
            $tip = [(string) $label];
            foreach ($series as $k => [$name, $cls]) {
                $v = (int) ($b[$k] ?? 0);
                $tip[] = $name . ': ' . StatsReport::number($v, $lang);
                if ($v === 0) {
                    continue;
                }
                $bh = $h * $v / $max;
                $y -= $bh;
                $svg .= '<rect class="' . $cls . '" x="' . number_format($x, 1, '.', '') . '" y="' . number_format($y, 1, '.', '') . '" width="' . number_format($bar, 1, '.', '') . '" height="' . number_format($bh, 1, '.', '') . '"><title>'
                    . $e($label . ' · ' . $name . ': ' . StatsReport::number($v, $lang)) . '</title></rect>';
            }
            $svg .= '<rect class="hit" x="' . number_format($i * $slot, 1, '.', '') . '" y="0" width="' . number_format($slot, 1, '.', '') . '" height="' . $h . '"><title>' . $e(implode("\n", $tip)) . '</title></rect>';
            if ($i % $every === 0) {
                $svg .= '<text class="axis" x="' . number_format($i * $slot + $slot / 2, 1, '.', '') . '" y="' . ($h + 16) . '" text-anchor="middle">' . $e(self::short((string) $label, $lang)) . '</text>';
            }
            $i++;
        }
        return $svg . '</svg>' . $legend;
    }

    /**
     * A ring of the answers by class (2xx, 3xx, 4xx, 5xx).
     *
     * @param array<string, int> $groups
     */
    private static function donut(array $groups, string $lang): string
    {
        $total = max(1, array_sum($groups));
        $r = 15.9155;          // circumference 100
        $offset = 25.0;
        $svg = '<svg viewBox="0 0 42 42" class="ring" role="img">';
        foreach ($groups as $g => $count) {
            $part = 100 * $count / $total;
            $svg .= '<circle class="s' . htmlspecialchars(substr($g, 0, 1), ENT_QUOTES) . '" cx="21" cy="21" r="' . $r . '" fill="none" stroke-width="6" stroke-dasharray="'
                . number_format($part, 2, '.', '') . ' ' . number_format(100 - $part, 2, '.', '') . '" stroke-dashoffset="' . number_format($offset, 2, '.', '') . '"><title>'
                . htmlspecialchars($g . ': ' . StatsReport::number($count, $lang), ENT_QUOTES) . '</title></circle>';
            $offset -= $part;
        }
        return $svg . '<text x="21" y="23" text-anchor="middle" class="ringn">' . htmlspecialchars(StatsReport::number(array_sum($groups), $lang), ENT_QUOTES) . '</text></svg>';
    }

    /** A period's label, short: 30.09., KW 40, Sep 2026, 2026, 14:00. */
    private static function short(string $label, string $lang): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $label, $m)) {
            return $lang === 'de' ? "$m[3].$m[2]." : date('M j', (int) strtotime($label));
        }
        if (preg_match('/^(\d{4})-W(\d{2})$/', $label, $m)) {
            return ($lang === 'de' ? 'KW ' : 'W') . (int) $m[2];
        }
        if (preg_match('/^(\d{4})-(\d{2})(?: \(month\))?$/', $label, $m)) {
            return $lang === 'de' ? "$m[2]/$m[1]" : date('M Y', (int) strtotime("$m[1]-$m[2]-01"));
        }
        return $label;
    }

    private static function date(string $ymd, string $lang): string
    {
        $t = (int) strtotime($ymd . ' UTC');
        return $lang === 'de' ? gmdate('d.m.Y', $t) : gmdate('Y-m-d', $t);
    }

    private const SCRIPT = <<<'JS'
(function () {
  var box = document.getElementById('stats'), url = box && box.getAttribute('data-refresh');
  if (!url || !window.fetch) { return; }
  setInterval(function () {
    if (document.hidden) { return; }
    fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) { if (html) { box.innerHTML = html; } }, function () {});
  }, 60000);
})();
JS;

    private const CSS = <<<'CSS'
:root{--bg:#f4f6f9;--fg:#1d2127;--m:#5b6470;--card:#fff;--line:#e3e7ec;--a:#2f62c9;--people:#2f62c9;--crawlers:#1e8a52;--bots:#c07a00;--through:#1e8a52;--checked:#2f62c9;--throttled:#c07a00;--refused:#c2412d;--claimed:#9aa3ae;--s2:#1e8a52;--s3:#2f62c9;--s4:#c07a00;--s5:#c2412d;--nf:#8b5cf6}
@media (prefers-color-scheme:dark){:root{--bg:#121519;--fg:#e7e9ec;--m:#9aa4b0;--card:#1b1f24;--line:#2d333b;--a:#7aa2ff;--people:#6f9bff;--crawlers:#4cc38a;--bots:#e0a43c;--through:#4cc38a;--checked:#6f9bff;--throttled:#e0a43c;--refused:#ff7a66;--claimed:#6b7480;--s2:#4cc38a;--s3:#6f9bff;--s4:#e0a43c;--s5:#ff7a66;--nf:#a78bfa}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
header{max-width:1120px;margin:0 auto;padding:14px 16px 0}header a,a{color:var(--a)}main{max-width:1120px;margin:0 auto;padding:6px 16px 28px}
h1{font-size:26px;margin:8px 0 4px}h2{font-size:16px;margin:0 0 10px}h2 small{color:var(--m);font-weight:400}
.bar{display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;margin:6px 0}.pills{display:flex;flex-wrap:wrap;gap:6px}
.pill{padding:4px 12px;border:1px solid var(--line);border-radius:999px;background:var(--card);text-decoration:none;color:var(--fg);font-size:14px}.pill.on{background:var(--a);border-color:var(--a);color:#fff}
.sub,.hint,.note,.foot{color:var(--m)}.sub{margin:4px 0 12px}.hint{font-size:12px;margin:4px 0 0;text-align:right}.foot{font-size:13px;margin-top:16px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:10px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px 12px 6px;position:relative;overflow:hidden}
.tile .n{display:block;font-size:24px;font-weight:700;line-height:1.2}.tile .l{color:var(--m);font-size:13px}
.spark{display:block;width:100%;height:28px;margin-top:4px}.spark polyline{fill:none;stroke:var(--c,var(--a));stroke-width:1.6;vector-effect:non-scaling-stroke}
.tile.people{--c:var(--people)}.tile.crawlers{--c:var(--crawlers)}.tile.bots{--c:var(--bots)}.tile.checked{--c:var(--checked)}.tile.refused{--c:var(--refused)}.tile.nf{--c:var(--nf)}.tile.req{--c:var(--fg)}
.tile::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--c,var(--a))}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:14px;margin-top:14px;align-items:start}@media (max-width:420px){.grid2{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-top:14px;overflow-x:auto}.grid2 .card{margin-top:0}
.chart{width:100%;height:auto;display:block}.chart .grid{stroke:var(--line);stroke-width:1}.chart .axis{fill:var(--m);font-size:11px}.chart .hit{fill:transparent}.chart .hit:hover{fill:rgba(127,127,127,.08)}
.chart .people{fill:var(--people)}.chart .crawlers{fill:var(--crawlers)}.chart .bots{fill:var(--bots)}.chart .through{fill:var(--through)}.chart .checked{fill:var(--checked)}.chart .throttled{fill:var(--throttled)}.chart .refused{fill:var(--refused)}
.legend{list-style:none;padding:0;margin:8px 0 0;font-size:13px;color:var(--m)}.legend.inline{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center}
.dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:middle;background:var(--m)}
.dot.people{background:var(--people)}.dot.crawlers{background:var(--crawlers)}.dot.bots{background:var(--bots)}.dot.through{background:var(--through)}.dot.checked{background:var(--checked)}.dot.throttled{background:var(--throttled)}.dot.refused{background:var(--refused)}.dot.claimed{background:var(--claimed)}
.dot.s2{background:var(--s2)}.dot.s3{background:var(--s3)}.dot.s4{background:var(--s4)}.dot.s5{background:var(--s5)}
.donut{display:flex;gap:16px;align-items:center;flex-wrap:wrap}.ring{width:150px;height:150px}.ring circle{stroke:var(--m)}.ring .s2{stroke:var(--s2)}.ring .s3{stroke:var(--s3)}.ring .s4{stroke:var(--s4)}.ring .s5{stroke:var(--s5)}.ringn{font-size:6px;font-weight:700;fill:var(--fg)}
.short{margin:0;padding-left:18px}.short li{margin:4px 0}
.crow{display:grid;grid-template-columns:minmax(160px,260px) 1fr auto;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--line)}.crow:last-of-type{border-bottom:0}
.cname a{font-weight:600;text-decoration:none}.kind{font-size:11px;padding:1px 7px;border-radius:999px;background:var(--bg);color:var(--m);border:1px solid var(--line)}
.hbar{display:flex;height:14px;border-radius:7px;overflow:hidden;background:var(--bg)}.hbar i{display:block;height:100%}
.hbar .through{background:var(--through)}.hbar .checked{background:var(--checked)}.hbar .refused{background:var(--refused)}.way{list-style:none;margin:8px 0 0;padding:0}.way li{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--line)}.way .step{flex:0 0 26px;height:26px;border-radius:13px;background:var(--a);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px}.way li.off{opacity:.55}.trace li.pass .step{background:var(--crawlers)}.trace li.note .step{background:var(--bots)}.trace li.stop .step{background:var(--refused)}.trace li.skip{opacity:.5}.trace li.skip .step{background:var(--m)}.verdict{padding:10px 12px;border-radius:8px;border-left:4px solid var(--crawlers);background:var(--bg)}.verdict.note{border-left-color:var(--bots)}.verdict.stop{border-left-color:var(--refused)}.diagram{overflow-x:auto;margin:10px 0}.filter.try label.wide{flex:2 1 320px}.filter.try label{flex:1 1 280px}.filter.try input.ip{min-width:140px}.filter select{flex:0 0 auto}a.rid{text-decoration:none}.way li.off .step{background:var(--m)}.way .state{font-size:12px;padding:1px 7px;border-radius:9px;background:var(--bg)}.way li.on .state{color:var(--crawlers)}.rgroup{font-size:15px;margin:18px 0 2px}.rtable{width:100%;border-collapse:collapse}.rtable td{padding:6px 8px 6px 0;border-bottom:1px solid var(--line);vertical-align:top}.rtable td.rmeta{white-space:nowrap;font-size:13px}.rtable tr:target{background:var(--bg)}code.rule{font-size:12px;color:var(--m)}table.settings{width:100%;border-collapse:collapse;margin:2px 0 6px}table.settings th{width:38%;text-align:left;font-weight:400;color:var(--m);padding:5px 12px 5px 0;border-bottom:1px solid var(--line);vertical-align:top}table.settings td{padding:5px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.setupnote{padding:8px 12px;border-radius:8px;background:var(--card);border-left:4px solid var(--a)}.setupnote.warn{border-left-color:var(--bots)}@media (max-width:640px){.rtable td.rmeta{white-space:normal}table.settings th,table.settings td{display:block;width:auto;border:0;padding:2px 0}table.settings td{padding-bottom:8px;border-bottom:1px solid var(--line)}}.hbar .throttled{background:var(--throttled)}.stop{color:var(--refused);font-weight:600}.filter select{font:inherit;padding:3px 6px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--fg)}.filter label.sort{flex:0 0 auto}.hbar .claimed{background:repeating-linear-gradient(45deg,var(--claimed) 0 4px,transparent 4px 7px)}
.prow{display:grid;grid-template-columns:1fr auto;grid-template-areas:"name num" "bar bar";gap:3px 12px;align-items:baseline;padding:7px 0;border-bottom:1px solid var(--line)}.prow:last-of-type{border-bottom:0}
.prow .pname{grid-area:name;min-width:0}.prow .cnum{grid-area:num}.prow .hbar{grid-area:bar}.hbar.thin{height:6px;border-radius:3px}
.hbar .people{background:var(--people)}.hbar .crawlers{background:var(--crawlers)}.hbar .bots{background:var(--bots)}

.tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin:4px 0 10px}.tab{padding:8px 14px;text-decoration:none;color:var(--m);border-bottom:3px solid transparent;font-weight:600}.tab.on{color:var(--fg);border-color:var(--a)}
.filter{margin:0 0 10px;font-size:14px;color:var(--m);display:flex;flex-wrap:wrap;gap:6px 8px;align-items:center}.filter label{display:flex;gap:8px;align-items:center;flex:1 1 320px}
.filter input[type=text]{font:inherit;padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg);flex:1;min-width:0}
.filter button{font:inherit;padding:4px 12px;border:1px solid var(--a);border-radius:6px;background:var(--a);color:#fff;cursor:pointer}.subtree{background:var(--bg);border-radius:8px;padding:8px 10px}.sub2{margin-top:16px}
.cnum{font-weight:700;white-space:nowrap}.cnum .note{font-weight:400;font-size:12px}
@media (max-width:640px){.crow{grid-template-columns:1fr auto}.crow .hbar{grid-column:1/3;order:3}}
.list{width:100%;border-collapse:collapse}.list td{padding:5px 6px;border-bottom:1px solid var(--line);vertical-align:top}.list .num{text-align:right;white-space:nowrap;font-weight:600}
.badge{display:inline-block;font-size:12px;padding:0 7px;border-radius:999px;border:1px solid var(--line);margin:2px 4px 0 0}.badge.s2{color:var(--s2)}.badge.s3{color:var(--s3)}.badge.s4{color:var(--s4)}.badge.s5{color:var(--s5)}
.barcell{width:30%}.barcell i{display:block;height:8px;border-radius:4px;background:var(--a);margin-top:7px}code{font-size:13px;word-break:break-all}
CSS;
}
