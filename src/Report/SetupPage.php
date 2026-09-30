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
use CjwNetwork\RequestShield\Store\ApcuStore;

/**
 * "Rules & setup", the statistics page's fourth view: the way a request takes
 * through the shield, step by step with what is switched on; every rule in
 * words with its ID, where it is written and how often it decided; and every
 * technical setting -- for admins, in English or German.
 *
 * It shows how the site is protected (and its paths on the server), so it
 * belongs behind the admin login or a restrict rule, like the other views.
 * The challenge secret is never shown.
 */
final class SetupPage
{
    private const T = [
        'en' => [
            'way' => 'The way of a request', 'wayIntro' => 'Every request passes these checks in this order, before the site\'s code runs. The first that refuses ends it; a browser check or "not kept by a cache" lets the rest still look.',
            'on' => 'on', 'off' => 'off', 'always' => 'always', 'answers' => 'answers', 'rulesN' => 'rules', 'rules' => 'The rules', 'rulesIntro' => 'Each with its ID and where it is written; the text is the comment after it in the rule file. The number: how often it decided in this period (statistics).',
            'settings' => 'Technical settings', 'settingsIntro' => 'Everything the shield runs with, as compiled from the rule files.', 'decided' => 'decided', 'where' => 'where', 'none' => 'none',
            'mode' => 'Mode', 'modeEnforce' => 'enforce: every rule decides', 'modeMonitor' => 'monitor: checked and counted, nobody refused', 'modeStrict' => 'strict: under attack -- checks from a quarter of each limit', 'modeOff' => 'off: nothing is checked',
            'yes' => 'yes', 'no' => 'no', 'files' => 'Rule files', 'versions' => 'Rule sets',
            // The steps
            's.client' => 'Visitor\'s address', 's.method' => 'Kind of request', 's.size' => 'Sizes', 's.sanity' => 'Disguised addresses', 's.host' => 'Website names',
            's.blocked' => 'Addresses only attackers ask for', 's.methodPaths' => 'Where forms may be sent', 's.restricted' => 'Areas for certain visitors', 's.crawlers' => 'Known crawlers',
            's.query' => 'Known parameters', 's.attacks' => 'Attack patterns', 's.cache' => 'What a cache may keep', 's.budgets' => 'Pace per visitor', 's.check' => 'Browser check', 's.after' => 'Log and statistics',
            'd.client' => 'from the connection%s; IPv6 counted per /%d network%s', 'd.proxies' => ', X-Forwarded-* believed only from %s', 'd.exempt' => '; never counted: %s',
            'd.method' => 'accepted: %s', 'd.size' => 'address up to %d characters, %d parameters, headers up to %d KB', 'd.sanity' => 'no hidden encoding, no way out of the website\'s folder',
            'd.hostAny' => 'any name', 'd.hosts' => 'only %s', 'd.blocked' => '%d patterns%s', 'd.exceptions' => ', %d opened again (unblock)', 'd.methodPaths' => '%s only at certain addresses', 'd.none' => 'none',
            'd.restricted' => '%d areas', 'd.crawlers' => '%d crawlers, verified by %s', 'd.query' => '%d rules%s', 'd.strict' => ', anything else: 404 (query strict)', 'd.loose' => ', anything else answered but not cached',
            'd.attacks' => '%d patterns in %d rules', 'd.cacheAll' => 'every address', 'd.cachePaths' => '%d address patterns', 'd.cacheQ' => ', parameters: %s', 'd.budgets' => '%d budgets: %s',
            'd.check' => 'a pass is valid %s%s', 'd.alwaysAt' => '; always at %d addresses', 'd.widget' => '; in forms at %s', 'd.after' => 'log: %s; statistics: %s',
            'a.405' => '405', 'a.400' => '400, 414, 431', 'a.404' => '404', 'a.403' => '403', 'a.pass' => 'answered, not kept', 'a.check' => 'browser check, then 429', 'a.page' => 'the check page',
            // The settings' groups
            'g.run' => 'Operation', 'g.clients' => 'Visitors and proxies', 'g.limits' => 'Requests', 'g.store' => 'Counters', 'g.check' => 'Browser check', 'g.crawlers' => 'Known crawlers', 'g.stats' => 'Statistics', 'g.log' => 'Log', 'g.files' => 'Rule files',
            'k.mode' => 'mode', 'k.monitor' => 'rules only watched (monitor)', 'k.debug' => 'X-Request-Shield header', 'k.app' => 'the site may ask for the check',
            'k.proxies' => 'trusted proxies', 'k.strip' => 'X-Forwarded-* from others', 'k.stripYes' => 'removed', 'k.stripNo' => 'ignored', 'k.ipv6' => 'IPv6 counted per', 'k.exempt' => 'never counted',
            'k.methods' => 'accepted methods', 'k.hosts' => 'website names', 'k.uri' => 'longest address', 'k.params' => 'most parameters', 'k.headers' => 'most header data', 'k.strictQ' => 'query strict',
            'k.store' => 'store', 'k.storeDir' => 'store directory', 'k.weight' => 'a request a cache must not keep counts', 'k.budgets' => 'budgets',
            'k.secret' => 'secret', 'k.secretSet' => 'set (never shown)', 'k.secretNone' => 'not set: one is generated and kept in the store directory (one server only)', 'k.pass' => 'a pass is valid', 'k.solution' => 'an answer is valid', 'k.difficulty' => 'difficulty',
            'k.cookie' => 'cookies', 'k.bindUa' => 'pass bound to the browser', 'k.language' => 'language', 'k.home' => 'link to the home page', 'k.widget' => 'check in forms', 'k.api' => 'API addresses (answer in a header)', 'k.logo' => 'logo', 'k.dns' => 'DNS lookups a minute (all requests)',
            'k.crawlers' => 'known crawlers', 'k.verify' => 'verified by', 'k.policy' => 'policies as written', 'k.crawlerLog' => 'crawler logs', 'k.crawlerLogKeep' => 'kept', 'k.crawlerLogQuery' => 'with the query',
            'k.stats' => 'statistics', 'k.parts' => 'what is counted', 'k.depth' => 'section levels', 'k.flush' => 'written to disk every', 'k.hours' => 'hours kept', 'k.days' => 'days kept', 'k.months' => 'months kept', 'k.dashboard' => 'dashboard',
            'k.log' => 'log file', 'k.logLevel' => 'level', 'k.logIp' => 'addresses', 'k.logSize' => 'rotated at', 'k.forGood' => 'for good', 'k.full' => 'in full', 'k.masked' => 'anonymised', 'k.days1' => 'days', 'k.seconds' => 's', 'k.onlyHourly' => 'only hourly',
            'k.ranges' => 'published address lists', 'k.dnsV' => 'DNS', 'k.both' => 'address lists or DNS',
        ],
        'de' => [
            'way' => 'Der Weg einer Anfrage', 'wayIntro' => 'Jede Anfrage durchläuft diese Prüfungen in dieser Reihenfolge, bevor der Code der Website läuft. Die erste, die abweist, beendet sie; ein Browser-Check oder „nicht im Cache“ lässt die übrigen noch prüfen.',
            'on' => 'an', 'off' => 'aus', 'always' => 'immer', 'answers' => 'antwortet', 'rulesN' => 'Regeln', 'rules' => 'Die Regeln', 'rulesIntro' => 'Jede mit ihrer ID und wo sie steht; der Text ist der Kommentar hinter ihr in der Regeldatei. Die Zahl: wie oft sie in diesem Zeitraum entschieden hat (Statistik).',
            'settings' => 'Technische Einstellungen', 'settingsIntro' => 'Alles, womit der Schutz läuft – so, wie es aus den Regeldateien übersetzt wurde.', 'decided' => 'entschieden', 'where' => 'wo', 'none' => 'keine',
            'mode' => 'Modus', 'modeEnforce' => 'enforce: jede Regel entscheidet', 'modeMonitor' => 'monitor: geprüft und gezählt, niemand abgewiesen', 'modeStrict' => 'strict: unter Angriff – Checks ab einem Viertel jedes Limits', 'modeOff' => 'off: nichts wird geprüft',
            'yes' => 'ja', 'no' => 'nein', 'files' => 'Regeldateien', 'versions' => 'Regelsätze',
            's.client' => 'Adresse des Besuchers', 's.method' => 'Art der Anfrage', 's.size' => 'Größen', 's.sanity' => 'Getarnte Adressen', 's.host' => 'Namen der Website',
            's.blocked' => 'Adressen, die nur Angreifer aufrufen', 's.methodPaths' => 'Wohin Formulare dürfen', 's.restricted' => 'Bereiche für bestimmte Besucher', 's.crawlers' => 'Bekannte Crawler',
            's.query' => 'Bekannte Parameter', 's.attacks' => 'Angriffsmuster', 's.cache' => 'Was ein Cache behalten darf', 's.budgets' => 'Tempo pro Besucher', 's.check' => 'Browser-Check', 's.after' => 'Log und Statistik',
            'd.client' => 'aus der Verbindung%s; IPv6 gezählt pro /%d-Netz%s', 'd.proxies' => ', X-Forwarded-* nur von %s geglaubt', 'd.exempt' => '; nie gezählt: %s',
            'd.method' => 'erlaubt: %s', 'd.size' => 'Adresse bis %d Zeichen, %d Parameter, Header bis %d KB', 'd.sanity' => 'keine versteckte Kodierung, kein Weg aus dem Ordner der Website',
            'd.hostAny' => 'jeder Name', 'd.hosts' => 'nur %s', 'd.blocked' => '%d Muster%s', 'd.exceptions' => ', %d wieder geöffnet (unblock)', 'd.methodPaths' => '%s nur an bestimmten Adressen', 'd.none' => 'keine',
            'd.restricted' => '%d Bereiche', 'd.crawlers' => '%d Crawler, erkannt über %s', 'd.query' => '%d Regeln%s', 'd.strict' => ', alles andere: 404 (query strict)', 'd.loose' => ', alles andere beantwortet, aber nicht gecacht',
            'd.attacks' => '%d Muster in %d Regeln', 'd.cacheAll' => 'jede Adresse', 'd.cachePaths' => '%d Adressmuster', 'd.cacheQ' => ', Parameter: %s', 'd.budgets' => '%d Budgets: %s',
            'd.check' => 'ein Pass gilt %s%s', 'd.alwaysAt' => '; immer an %d Adressen', 'd.widget' => '; in Formularen unter %s', 'd.after' => 'Log: %s; Statistik: %s',
            'a.405' => '405', 'a.400' => '400, 414, 431', 'a.404' => '404', 'a.403' => '403', 'a.pass' => 'beantwortet, nicht gecacht', 'a.check' => 'Browser-Check, dann 429', 'a.page' => 'die Check-Seite',
            'g.run' => 'Betrieb', 'g.clients' => 'Besucher und Proxys', 'g.limits' => 'Anfragen', 'g.store' => 'Zähler', 'g.check' => 'Browser-Check', 'g.crawlers' => 'Bekannte Crawler', 'g.stats' => 'Statistik', 'g.log' => 'Log', 'g.files' => 'Regeldateien',
            'k.mode' => 'Modus', 'k.monitor' => 'nur beobachtete Regeln (monitor)', 'k.debug' => 'Header X-Request-Shield', 'k.app' => 'die Website darf den Check anfordern',
            'k.proxies' => 'vertrauenswürdige Proxys', 'k.strip' => 'X-Forwarded-* von anderen', 'k.stripYes' => 'entfernt', 'k.stripNo' => 'ignoriert', 'k.ipv6' => 'IPv6 gezählt pro', 'k.exempt' => 'nie gezählt',
            'k.methods' => 'erlaubte Methoden', 'k.hosts' => 'Namen der Website', 'k.uri' => 'längste Adresse', 'k.params' => 'meiste Parameter', 'k.headers' => 'meiste Header-Daten', 'k.strictQ' => 'query strict',
            'k.store' => 'Speicher', 'k.storeDir' => 'Speicherverzeichnis', 'k.weight' => 'eine nicht cachebare Anfrage zählt', 'k.budgets' => 'Budgets',
            'k.secret' => 'Geheimnis', 'k.secretSet' => 'gesetzt (wird nie angezeigt)', 'k.secretNone' => 'nicht gesetzt: eines wird erzeugt und im Speicherverzeichnis abgelegt (nur für einen Server)', 'k.pass' => 'ein Pass gilt', 'k.solution' => 'eine Antwort gilt', 'k.difficulty' => 'Schwierigkeit',
            'k.cookie' => 'Cookies', 'k.bindUa' => 'Pass an den Browser gebunden', 'k.language' => 'Sprache', 'k.home' => 'Link zur Startseite', 'k.widget' => 'Check in Formularen', 'k.api' => 'API-Adressen (Antwort im Header)', 'k.logo' => 'Logo', 'k.dns' => 'DNS-Abfragen pro Minute (alle Anfragen)',
            'k.crawlers' => 'bekannte Crawler', 'k.verify' => 'erkannt über', 'k.policy' => 'Regeln wie geschrieben', 'k.crawlerLog' => 'Crawler-Logs', 'k.crawlerLogKeep' => 'aufbewahrt', 'k.crawlerLogQuery' => 'mit Query',
            'k.stats' => 'Statistik', 'k.parts' => 'was gezählt wird', 'k.depth' => 'Bereichsebenen', 'k.flush' => 'auf die Platte alle', 'k.hours' => 'Stunden aufbewahrt', 'k.days' => 'Tage aufbewahrt', 'k.months' => 'Monate aufbewahrt', 'k.dashboard' => 'Dashboard',
            'k.log' => 'Logdatei', 'k.logLevel' => 'Stufe', 'k.logIp' => 'Adressen', 'k.logSize' => 'rotiert bei', 'k.forGood' => 'für immer', 'k.full' => 'vollständig', 'k.masked' => 'anonymisiert', 'k.days1' => 'Tage', 'k.seconds' => 's', 'k.onlyHourly' => 'nur stündlich',
            'k.ranges' => 'veröffentlichte Adresslisten', 'k.dnsV' => 'DNS', 'k.both' => 'Adresslisten oder DNS',
        ],
    ];

    /**
     * The view's content: the way, the rules, the settings.
     *
     * @param array<string, int> $decided rule => how often it decided (StatsReport 'rules')
     */
    public static function render(Settings $s, string $lang, array $decided = []): string
    {
        $lang = isset(self::T[$lang]) ? $lang : 'en';
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => StatsReport::number($v, $lang);
        $f = static fn (string $key, string|int ...$args): string => vsprintf($t[$key], $args);
        $list = static fn (array $v): string => self::join($v, $t['none']);

        $h = '';
        $mode = RulesPage::mode($s, $lang);
        if ($mode !== null) {
            $h .= '<p class="setupnote' . ($s->mode === 'enforce' ? '' : ' warn') . '">' . $e($mode) . '</p>';
        }

        // ── The way: every step in order, on or off, what it answers ─────────
        $query = $s->cacheableQuery === null ? '*' : ($s->cacheableQuery === [] ? '—' : implode(', ', $s->cacheableQuery));
        $patterns = 0;
        foreach ($s->contentRules as $r) {
            $patterns += count($r['patterns']);
        }
        $budgets = array_map(static fn ($b): string => $b->name . ' ' . $b->limit . '/' . Describe::duration($b->window), array_values($s->budgets));
        $verify = ['ranges' => $t['k.ranges'], 'dns' => $t['k.dnsV'], 'both' => $t['k.both']][$s->crawlerVerify] ?? $s->crawlerVerify;
        $steps = [
            ['client', true, '', $f('d.client', $s->trustedProxies === [] ? '' : $f('d.proxies', implode(', ', $s->trustedProxies)), $s->ipv6Prefix, $s->exemptIps === [] ? '' : $f('d.exempt', implode(', ', $s->exemptIps)))],
            ['method', true, $t['a.405'], $f('d.method', implode(', ', $s->methods))],
            ['size', true, $t['a.400'], $f('d.size', $s->maxUri, $s->maxQueryParameters, (int) round($s->maxHeaderBytes / 1024))],
            ['sanity', true, '400', $t['d.sanity']],
            ['host', $s->hosts !== [], $t['a.404'], $s->hosts === [] ? $t['d.hostAny'] : $f('d.hosts', implode(', ', $s->hosts))],
            ['blocked', $s->blockedPaths !== [], $t['a.404'], $f('d.blocked', count($s->blockedPaths), $s->blockExceptions === [] ? '' : $f('d.exceptions', count($s->blockExceptions)))],
            ['methodPaths', $s->methodPaths !== [], $t['a.405'], $s->methodPaths === [] ? $t['d.none'] : $f('d.methodPaths', implode(', ', array_keys($s->methodPaths)))],
            ['restricted', $s->restricted !== [], $t['a.403'], $f('d.restricted', count($s->restricted))],
            ['crawlers', $s->crawlers !== [], $t['a.403'], $s->crawlers === [] ? $t['d.none'] : $f('d.crawlers', count($s->crawlers), $verify)],
            ['query', $s->queryParams !== [] || $s->queryStrict, $s->queryStrict ? $t['a.404'] : $t['a.pass'], $f('d.query', count($s->queryParams), $s->queryStrict ? $t['d.strict'] : $t['d.loose'])],
            ['attacks', $s->contentRules !== [], $t['a.403'], $f('d.attacks', $patterns, count($s->contentRules))],
            ['cache', true, $t['a.pass'], ($s->cacheablePaths === null ? $t['d.cacheAll'] : $f('d.cachePaths', count($s->cacheablePaths))) . $f('d.cacheQ', $query)],
            ['budgets', $s->budgets !== [], $t['a.check'], $f('d.budgets', count($s->budgets), $list($budgets))],
            ['check', true, $t['a.page'], $f('d.check', Describe::span($s->challenge->passTtl, $lang), $s->challenge->alwaysPaths === [] ? '' : $f('d.alwaysAt', count($s->challenge->alwaysPaths)))
                . ($s->challenge->widgetPath !== null ? $f('d.widget', $s->challenge->widgetPath) : '')],
            ['after', $s->logFile !== null || $s->statsEnabled, '', $f('d.after', $s->logFile !== null ? $s->logLevel : $t['off'], $s->statsEnabled ? implode(', ', $s->statsParts) : $t['off'])],
        ];
        $h .= '<section class="card" id="way"><h2>' . $e($t['way']) . '</h2><p class="note">' . $e($t['wayIntro']) . '</p><ol class="way">';
        foreach ($steps as $i => [$key, $on, $answers, $what]) {
            $h .= '<li class="' . ($on ? 'on' : 'off') . '"><span class="step">' . ($i + 1) . '</span><div><b>' . $e($t['s.' . $key]) . '</b> <span class="state">' . $e($on ? $t['on'] : $t['off']) . '</span>'
                . ($answers !== '' ? ' <span class="note">· ' . $e($t['answers'] . ' ' . $answers) . '</span>' : '') . '<br><span class="note">' . $e($what) . '</span></div></li>';
        }
        $h .= '</ol></section>';

        // ── The rules, in words ──────────────────────────────────────────────
        $h .= '<section class="card" id="rules"><h2>' . $e($t['rules']) . '</h2><p class="note">' . $e($t['rulesIntro']) . '</p>';
        foreach (RulesPage::groups($s, [], null, $lang) as $g => [$heading, $intro, $rows]) {
            $h .= '<h3 class="rgroup" id="g' . $g . '">' . $e($heading) . '</h3><p class="note">' . $e($intro) . '</p>';
            if ($rows === []) {
                continue;
            }
            $h .= '<table class="rtable">';
            foreach ($rows as $r) {
                $count = $r['log'] !== null && $r['log'] !== '' ? ($decided[str_replace(' ', '_', $r['log'])] ?? 0) : null;
                $h .= '<tr' . ($r['id'] !== null ? ' id="rule-' . $e(self::anchor($r['id'])) . '"' : '') . '><td>' . $e($r['text'])
                    . ($r['detail'] !== null ? '<br><code class="rule">' . $e($r['detail']) . '</code>' : '') . '</td>'
                    . '<td class="rmeta">' . ($r['id'] !== null ? '<code>' . $e($r['id']) . '</code>' : '') . ($r['where'] !== null ? '<br><span class="note">' . $e($r['where']) . '</span>' : '') . '</td>'
                    . '<td class="num">' . ($count !== null && $count > 0 ? $e($n($count) . '×') : '') . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '</section>';

        // ── Every technical setting ──────────────────────────────────────────
        $c = $s->challenge;
        $store = $s->store === 'auto' ? 'auto (' . (ApcuStore::usable() ? 'APCu' : 'files') . ')' : $s->store;
        $yes = static fn (bool $b): string => $b ? $t['yes'] : $t['no'];
        $sec = static fn (int $v): string => Describe::span($v, $lang);
        $policies = [];
        foreach ($s->crawlerPolicy as $who => $p) {
            $policies[] = "$who $p";
        }
        $groups = [
            'run' => [
                'k.mode' => $t['mode' . ucfirst($s->mode)] ?? $s->mode,
                'k.monitor' => (string) count($s->origins['monitor'] ?? []),
                'k.debug' => $yes($s->debugHeader),
                'k.app' => $yes($s->appChallenge),
            ],
            'clients' => [
                'k.proxies' => $list($s->trustedProxies),
                'k.strip' => $s->stripUntrustedForwarded ? $t['k.stripYes'] : $t['k.stripNo'],
                'k.ipv6' => '/' . $s->ipv6Prefix,
                'k.exempt' => $list($s->exemptIps),
            ],
            'limits' => [
                'k.methods' => implode(', ', $s->methods),
                'k.hosts' => $s->hosts === [] ? $t['d.hostAny'] : implode(', ', $s->hosts),
                'k.uri' => (string) $s->maxUri,
                'k.params' => (string) $s->maxQueryParameters,
                'k.headers' => $n($s->maxHeaderBytes) . ' B',
                'k.strictQ' => $yes($s->queryStrict),
            ],
            'store' => [
                'k.store' => $store,
                'k.storeDir' => $s->storeDir,
                'k.weight' => $s->uncachedWeight . '×',
                'k.budgets' => $list($budgets),
            ],
            'check' => [
                'k.secret' => $c->secret !== null ? $t['k.secretSet'] : $t['k.secretNone'],
                'k.pass' => $sec($c->passTtl),
                'k.solution' => $sec($c->solutionTtl),
                'k.difficulty' => $n($c->difficultyMin) . ' – ' . $n($c->difficultyMax),
                'k.cookie' => $c->cookie . ', ' . $c->solutionCookie,
                'k.bindUa' => $yes($c->bindUserAgent),
                'k.language' => $c->language,
                'k.home' => $c->home ?? '—',
                'k.widget' => $c->widgetPath !== null ? $c->widgetPath . ' (' . $n($c->widgetDifficulty) . ')' : $t['off'],
                'k.api' => $list(array_map(static fn (string $p): string => Describe::pattern($s, $p), $c->apiPaths)),
                'k.logo' => $yes($c->logo !== null),
                'k.dns' => (string) $c->dnsLookups,
            ],
            'crawlers' => [
                'k.crawlers' => (string) count($s->crawlers),
                'k.verify' => $verify,
                'k.policy' => $list($policies),
                'k.crawlerLog' => $s->crawlerLogDir === null ? $t['off'] : $s->crawlerLogDir . ($s->crawlerLogKinds !== [] ? ' (' . implode(', ', $s->crawlerLogKinds) . ')' : ''),
                'k.crawlerLogKeep' => $s->crawlerLogDir === null ? '—' : $s->crawlerLogDays . ' ' . $t['k.days1'] . ', ' . $t['k.crawlerLogQuery'] . ': ' . $yes($s->crawlerLogQuery),
            ],
            'stats' => [
                'k.stats' => $s->statsEnabled ? $t['on'] : $t['off'],
                'k.parts' => $list($s->statsParts),
                'k.depth' => (string) $s->statsDepth,
                'k.flush' => $s->statsFlush > 0 ? $s->statsFlush . ' ' . $t['k.seconds'] : $t['k.onlyHourly'],
                'k.hours' => $s->statsHours . ' ' . $t['k.days1'],
                'k.days' => (string) $s->statsDays,
                'k.months' => $s->statsMonths === 0 ? $t['k.forGood'] : (string) $s->statsMonths,
                'k.dashboard' => $s->dashboardPath,
            ],
            'log' => [
                'k.log' => $s->logFile ?? $t['off'],
                'k.logLevel' => $s->logLevel,
                'k.logIp' => $s->logIp === 'full' ? $t['k.full'] : $t['k.masked'],
                'k.logSize' => $n((int) round($s->logMaxSize / 1048576)) . ' MB',
            ],
        ];
        $files = self::files($s);
        $versions = [];
        foreach ($s->origins['versions'] ?? [] as $name => $v) {
            $versions[] = "$name $v";
        }
        $groups['files'] = [$t['files'] => $list($files), $t['versions'] => $list($versions)];
        $h .= '<section class="card" id="settings"><h2>' . $e($t['settings']) . '</h2><p class="note">' . $e($t['settingsIntro']) . '</p>';
        foreach ($groups as $g => $rows) {
            // One group under the other: a name and its value per line, room for long paths.
            $h .= '<h3 class="rgroup">' . $e($t['g.' . $g]) . '</h3><table class="settings">';
            foreach ($rows as $k => $v) {
                $h .= '<tr><th>' . $e($t[$k] ?? $k) . '</th><td><code>' . $e($v) . '</code></td></tr>';
            }
            $h .= '</table>';
        }
        return $h . '</section>';
    }

    /**
     * What a rule the statistics counted is: its ID, its description, where it
     * is written -- for "site.rules:4" too (a rule without an ID).
     *
     * @return array{id: string, text: ?string, where: ?string}
     */
    public static function rule(Settings $s, string $counted): array
    {
        $id = str_replace('_', ' ', $counted);
        if ($s->origin('at', $counted) !== null || $s->origin('text', $counted) !== null) {
            $id = $counted;
        } elseif ($s->origin('at', $id) === null) {
            // A location, not an ID: the rule written there.
            foreach ($s->origins['at'] ?? [] as $rid => $at) {
                if ($at === $counted) {
                    $id = (string) $rid;
                    break;
                }
            }
        }
        return ['id' => $id, 'text' => $s->origin('text', $id), 'where' => $s->origin('at', $id)];
    }

    /**
     * A list for people: "a, b, c", or what "none" is.
     *
     * @param array<mixed> $values
     */
    private static function join(array $values, string $none): string
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = is_scalar($v) ? (string) $v : '';
        }
        return $out === [] ? $none : implode(', ', $out);
    }

    /** A rule ID as an anchor: letters, digits, "-", "_", "." only. */
    public static function anchor(string $id): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $id);
    }

    /**
     * The rule files the settings were read from, from where their rules are
     * written: "site.rules", "built-in scanners.rules".
     *
     * @return list<string>
     */
    private static function files(Settings $s): array
    {
        $files = [];
        foreach ($s->origins['at'] ?? [] as $at) {
            $file = (string) preg_replace('/:\d+$/', '', (string) $at);
            $files[$file] = true;
        }
        ksort($files);
        return array_keys($files);
    }
}
