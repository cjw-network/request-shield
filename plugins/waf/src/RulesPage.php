<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Waf;

use CjwNetwork\RequestShield\Counts;
use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\LogStats;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Store\Store;

/**
 * "Active rules": the rules in plain words, how often each one decided, what
 * happened lately, and a check that tries any address against them step by
 * step -- for site owners, not only for technicians.
 *
 *   echo RulesPage::render($settings, ['check' => $_GET]);
 *
 * It shows how the site is protected, so it belongs behind the site's admin
 * login or a `restrict` rule. Nothing here runs on a normal request: the
 * classes are loaded only when the page is opened.
 */
final class RulesPage
{
    /**
     * @param array{check?: array<mixed>, action?: string, title?: string, store?: Store, now?: int, ip?: string, home?: string, homeLabel?: string} $o
     *   check: the form's values (method, url, ip), usually $_GET; action: the form's URL;
     *   ip: the address the check starts with (the viewer's own, say)
     */
    public static function render(Settings $s, array $o = []): string
    {
        $now = $o['now'] ?? time();
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $stats = LogStats::read($s->logFile, $now - 86400);
        $title = $o['title'] ?? 'Active rules';

        $check = $o['check'] ?? [];
        $url = is_string($check['url'] ?? null) ? trim($check['url']) : '';
        $method = is_string($check['method'] ?? null) && preg_match('/^[A-Za-z]{1,10}$/', $check['method']) ? strtoupper($check['method']) : 'GET';
        $ip = is_string($check['ip'] ?? null) && @inet_pton(trim($check['ip'])) !== false ? trim($check['ip']) : ($o['ip'] ?? '198.51.100.7');

        $h = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $e($title) . '</title><style>' . self::CSS . '</style></head><body>';
        if (isset($o['home'])) {
            $h .= '<header class="bar"><div><a class="name" href="' . $e($o['home']) . '">← ' . $e($o['homeLabel'] ?? 'Back') . '</a></div></header>';
        }
        $h .= '<main>';
        $help = Help::link('RSF06-01', '', $s->docsUrl);
        $h .= '<h1>' . $e($title) . ($help !== '' ? ' ' . $help : '') . '</h1><p class="lead">How this site is protected, in plain words — and what happens to any address you try.</p>';
        $versions = $s->origins['versions'] ?? [];
        if ($versions !== []) {
            $h .= '<p class="note">Rule sets: ' . implode(' · ', array_map(static fn (string $n, string $v): string => '<code>' . htmlspecialchars("$n $v", ENT_QUOTES) . '</code>', array_keys($versions), $versions)) . '</p>';
        }
        foreach ($s->origins['warnings'] ?? [] as $w) {
            $h .= '<div class="verdict note"><span class="icon">!</span><div><strong>Please check:</strong> ' . $e($w) . '</div></div>';
        }
        $mode = self::mode($s);
        if ($mode !== null) {
            $h .= '<div class="verdict ' . ($s->mode === 'strict' ? 'stop' : 'note') . '" id="mode"><span class="icon">!</span><div>' . $e($mode) . '</div></div>';
        }

        // ── Summary ─────────────────────────────────────────────────────────
        $stopped = ($stats['actions']['reject'] ?? 0) + ($stats['actions']['throttle'] ?? 0);
        $h .= '<div class="tiles" id="tiles" data-live>'
            . self::tile((string) count($s->blockedPaths), 'kinds of address refused')
            . self::tile((string) count($s->restricted), 'restricted areas')
            . self::tile((string) count($s->budgets), 'limits per visitor')
            . self::tile($s->logFile === null ? '—' : (string) $stopped, 'requests refused in 24 h')
            . self::tile($s->logFile === null ? '—' : (string) ($stats['actions']['challenge'] ?? 0), 'browser checks in 24 h')
            . '</div>';

        // ── Try an address ───────────────────────────────────────────────────
        $action = $o['action'] ?? '';
        $h .= '<section class="card" id="check">' . Frame::h2('Try an address', $s, 'RSF06-01', 'the-rule-tester', 'en') . '<form method="get" action="' . $e($action) . '" class="try">'
            . '<select name="method" aria-label="Kind of request">';
        foreach (array_unique(array_merge(['GET', 'POST'], $s->methods)) as $m) {
            $h .= '<option' . ($m === $method ? ' selected' : '') . '>' . $e($m) . '</option>';
        }
        $h .= '</select><input type="text" name="url" value="' . $e($url) . '" placeholder="https://www.example.org/wp-login.php" aria-label="Address">'
            . '<input type="text" name="ip" value="' . $e($ip) . '" aria-label="Visitor\'s address" title="The visitor\'s address (IP)" class="ip">'
            . '<button type="submit">Check</button></form>'
            . '<p class="note">Nothing is counted: the check only looks at the visitor\'s counters.</p>';
        if ($url !== '') {
            $t = (new Inspector($s, $o['store'] ?? null))->trace(Inspector::request($method, $url, $ip), (float) $now);
            $state = $t['decision']->passes() ? ($t['decision']->action === 'allow' && $t['watched'] === null ? 'pass' : 'note')
                : ($t['decision']->action === 'challenge' || $s->mode === 'monitor' ? 'note' : 'stop');
            $h .= '<div class="diagram">' . Diagram::trace($t, $method . ' ' . (string) (parse_url($url, PHP_URL_PATH) ?: $url)) . '</div>';
            $h .= '<div class="verdict ' . $state . '"><span class="icon">' . self::icon($state) . '</span><div><strong>This visitor ' . $e($t['verdict']) . '.</strong>'
                . ($t['rule'] !== null ? '<br><span class="note">Decided by: <code>' . $e($t['rule']) . '</code></span>' : '')
                . ($t['watched'] !== null ? '<br><span class="note">Watched: it ' . $e($t['watched']) . '.</span>' : '') . '</div></div><ol class="steps">';
            foreach ($t['steps'] as $st) {
                $h .= '<li class="' . $st['state'] . '"><span class="icon">' . self::icon($st['state']) . '</span><div><strong>' . $e($st['check']) . '</strong><br>' . $e($st['text'])
                    . ($st['rule'] !== null ? ' <code class="origin">' . $e($st['rule']) . '</code>' : '') . '</div></li>';
            }
            $h .= '</ol>';
        }
        $h .= '</section>';

        // ── The rules ───────────────────────────────────────────────────────
        $h .= Frame::h2('The rules', $s, 'RSF06-01', 'the-rules', 'en') . '<p class="note">Each with its ID, where it is written and, from the log, how often it decided in the last 24 hours. The text is the comment after the rule in the rule file.</p>';
        // The counters of the last 7 days, from the plugins that keep them (RuleCounts, 0031 B.8).
        $counted = Counts::crawlers($s, 7, (float) $now);
        foreach (self::groups($s, $stats['claims'], $counted) as [$heading, $intro, $rows]) {
            $h .= '<section class="card"><h3>' . $e($heading) . '</h3><p class="intro">' . $e($intro) . '</p>';
            if ($rows !== []) {
                $h .= '<table>';
                foreach ($rows as $r) {
                    $log = $r['log'] !== '' ? $r['log'] : null;
                    $hit = $log !== null ? ($stats['rules'][$log] ?? null) : null;
                    $h .= '<tr><td>' . $e($r['text']) . ($r['detail'] !== null ? '<br><code class="rule">' . $e($r['detail']) . '</code>' : '') . '</td>'
                        . '<td class="meta">' . ($r['id'] !== null ? '<code class="origin">' . $e($r['id']) . '</code>' : '')
                        . ($r['where'] !== null ? '<br><span class="note">' . $e($r['where']) . '</span>' : '') . '</td>'
                        . '<td class="hits"' . ($log !== null ? ' data-live id="hits-' . md5($log) . '"' : '') . '>'
                        . ($hit !== null ? '<span class="badge">' . $hit['count'] . '×</span> <span class="note">last ' . $e(self::ago($now - $hit['last'])) . '</span>' : ($s->logFile !== null && $log !== null ? '<span class="note">—</span>' : ''))
                        . '</td></tr>';
                }
                $h .= '</table>';
            }
            if ($heading === 'Browser check') {
                $h .= str_replace('%DIAGRAM%', '<div class="diagram">' . Diagram::browserCheck() . '</div>', self::BROWSER_CHECK);
            }
            $h .= '</section>';
        }

        // ── Lately ──────────────────────────────────────────────────────────
        $h .= Frame::h2('Lately', $s, 'RSF05-05', '', 'en') . '<section class="card" id="lately" data-live>';
        if ($s->logFile === null) {
            $h .= '<p class="intro">No log: <code>set log /path/shield.log</code> in the rule file shows here what was refused or checked.</p>';
        } elseif ($stats['recent'] === []) {
            $h .= '<p class="intro">Nothing refused or checked in the last 24 hours. A row appears as soon as the shield refuses or checks a request, such as a scanner asking for /.env.</p>';
        } else {
            $h .= '<table class="recent"><tr><th>When</th><th>Visitor</th><th>What happened</th><th>Address</th></tr>';
            foreach ($stats['recent'] as $r) {
                $state = $r['action'] === 'reject' || $r['action'] === 'throttle' ? 'stop' : 'note';
                $h .= '<tr class="' . $state . '"><td>' . $e(date('H:i:s', $r['time'])) . '</td><td><code>' . $e($r['client']) . '</code></td>'
                    . '<td><span class="icon">' . self::icon($state) . '</span> ' . $e(self::happened($r['action'], $r['status'])) . ': ' . $e(Describe::reason($r['reason']))
                    . ($r['rule'] !== null ? ' <code class="origin">' . $e($r['rule']) . '</code>' : '') . '</td>'
                    . '<td class="url">' . $e($r['method'] . ' ' . $r['url']) . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '</section><p class="note">Updated ' . $e(date('H:i:s', $now)) . ' · the summary, the counts and this list refresh every 10 seconds.</p>';

        return $h . '</main><script>' . self::SCRIPT . '</script></body></html>';
    }

    /**
     * The rules, grouped the way a site owner thinks about them. Each row: its
     * text for people (the rule's description, else what it does), the rule
     * as written when a description stands in front, its ID, where it is
     * written, and the ID the log counts it under. In English or German ($lang);
     * the rules' own descriptions stay as written.
     *
     * @param array<string, int> $claims crawler ID => how often a request named it without coming from it (the log)
     * @param array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}>|null $counted what each crawler did in the last days (StatsReport), when counted
     * @return list<array{0: string, 1: string, 2: list<array{text: string, detail: ?string, id: ?string, where: ?string, log: ?string}>}>
     */
    public static function groups(Settings $s, array $claims = [], ?array $counted = null, string $lang = 'en'): array
    {
        $w = static fn (string $en, string ...$a): string => vsprintf($lang === 'de' ? (self::DE[$en] ?? $en) : $en, $a);
        $o = static fn (string $setting, string $what): ?string => $s->origin($setting, $what);
        $row = static function (string $says, ?string $id, ?string $log = null) use ($s, $w): array {
            $text = $id !== null ? $s->origin('text', $id) : null;
            $where = $id !== null ? $s->origin('at', $id) : null;
            if ($where !== null && $s->origin('rev', (string) $id) !== null) {
                $where .= $w(' · revision %s', (string) $s->origin('rev', (string) $id));
            }
            if ($id !== null && $s->origin('area', $id) !== null) {
                $where = ($where ?? $id) . $w(' · in match %s', (string) $s->origin('area', $id));
            }
            return ['text' => $text ?? $says, 'detail' => $text !== null ? $says : null, 'id' => $id, 'where' => $where, 'log' => $log ?? $id];
        };
        $pattern = static fn (string $p): string => Describe::pattern($s, $p);
        $g = [];

        // Kept out and let in: the deny list, exempt (also for a while), the lists' files.
        $rows = [];
        $when = static fn (?int $until): string => $until === null ? '' : $w(' — until %s', date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $until));
        foreach ($s->deny as $e) {
            $rows[] = $row($w('kept out: %s', implode(', ', $e['ips'])) . $when($e['until']), $e['rule'], $e['rule']);
        }
        if ($s->denyCount > count($s->deny)) {
            $rows[] = $row($w('and %s more kept out (bin/request-shield lists)', number_format($s->denyCount - count($s->deny), 0, '', $lang === 'de' ? '.' : ',')), null, '');
        }
        foreach ($s->exemptIps as $ip) {
            $rows[] = $row($w('let in, never counted or checked: %s', $ip), $o('exempt', $ip), '');
        }
        if ($rows !== [] || $s->listsDir !== null) {
            $g[] = [$w('Kept out and let in'), $w('A kept-out address gets 403 before every other check; one let in is never counted or checked, but still refused for blocked addresses and attack patterns.')
                . ($s->listsDir !== null ? $w(' The lists: %s (bin/request-shield deny, allow, unlist, lists).', $s->listsDir) : ''), $rows];
        }

        // Public blocklists (feed …): each with what it does, how many entries, how old.
        $rows = [];
        $actions = ['deny' => $w('kept out: 403'), 'check' => $w('the browser check'), 'signal' => $w('counts towards a ban')];
        foreach (array_merge($s->feeds, $s->monitor !== null ? array_map(static fn (array $f): array => $f + ['watched' => true], $s->monitor->feeds) : []) as $f) {
            $state = $f['state'] === 'in force' ? $w('%s entries, fetched %s', number_format($f['count'], 0, '', $lang === 'de' ? '.' : ','), date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $f['fetched']))
                : ($f['state'] === 'too old' ? $w('too old, not used: fetched %s', $f['fetched'] > 0 ? date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $f['fetched']) : '-') : $w('not fetched yet: bin/request-shield feeds <main.rules> update'));
            $rows[] = $row($w('%s (%s): %s', $f['title'], $f['name'], isset($f['watched']) ? $w('only counted and logged (count)') : ($actions[$f['action']] ?? $f['action'])
                . ($f['action'] === 'signal' ? ' ×' . $f['weight'] : '') . ($f['paths'] !== [] ? ' ' . $w('at %s', implode(', ', array_map(static fn (string $p): string => Describe::pattern($s, $p), $f['paths']))) : ''))
                . ' — ' . $state . ($f['terms'] !== '' ? ' — ' . $w('terms: %s', $f['terms']) : ''), $f['rule'], $f['rule']);
        }
        if ($rows !== []) {
            $g[] = [$w('Public blocklists'), $w('Lists fetched by bin/request-shield feeds <main.rules> update (cron), compiled with the rules; nothing is looked up per request and nothing is sent anywhere. Never an address let in, a trusted proxy or a verified crawler; a list older than %s is not used.',
                Describe::span($s->feedsMaxAge, $lang)), $rows];
        }

        // Automatic, temporary bans.
        $rows = [];
        $signals = ['limits' => $w('times past a limit'), 'refusals' => $w('refusals for what only attackers ask for'), 'checks' => $w('check pages not solved')];
        foreach ($s->bans as $b) {
            $rows[] = $row($w('after %s %s in %s: banned for %s', (string) $b['after'], $signals[$b['signal']] ?? $w('"%s" past its limit', $b['signal']),
                Describe::span($b['in'], $lang), Describe::span($b['for'], $lang)), $b['rule'], $b['rule']);
        }
        if ($rows !== []) {
            $g[] = [$w('Banned for a while'), $w('Nothing but 429 with the time to wait, longer each time within a day (%s×, at most %s); never an address let in, a trusted proxy or a verified crawler.',
                (string) $s->banGrowth, Describe::span($s->banMax, $lang)), $rows];
        }

        $rows = [];
        foreach ($s->blockedPaths as $i => $p) {
            $id = $o('blockedPaths', $p);
            $text = $id !== null ? $s->origin('text', $id) : null;
            $text ??= Describe::builtIn($p);
            $says = $pattern($p);
            $where = $id !== null ? $s->origin('at', $id) : null;
            if ($where !== null && $s->origin('rev', (string) $id) !== null) {
                $where .= $w(' · revision %s', (string) $s->origin('rev', (string) $id));
            }
            $rows[] = ['text' => $text ?? $says, 'detail' => $text !== null && $text !== $says ? $says : null, 'id' => $id ?? \CjwNetwork\RequestShield\Config::setName($p),
                'where' => $where, 'log' => $id ?? \CjwNetwork\RequestShield\Config::setName($p) ?? "blockedPaths[$i]"];
        }
        foreach ($s->blockExceptions as $x) {
            $what = $x['patterns'] === null ? $w('every block above') : implode('; ', array_map(static fn (string $p): string => Describe::rule($s, 'blockedPaths', $p), $x['patterns']));
            $rows[] = $row($w('Open at %s: %s', implode(', ', array_map($pattern, $x['paths'])), $what)
                . ($x['ips'] !== [] ? $w(' — only for %s', implode(', ', $x['ips'])) : $w(' — ⚠ for everyone: make sure only admins reach it')),
                $o('blockExceptions', $x['paths'][0] ?? ''), '');
        }
        $g[] = [$w('Addresses only attackers ask for'), $rows === [] ? $w('None are refused.') : $w('Refused with "not found" (404) before the site sees them:'), $rows];

        $rows = [];
        foreach ($s->contentRules as $n => $r) {
            foreach ($r['patterns'] as $p) {
                $id = $o('contentRules', $p);
                $text = $id !== null ? $s->origin('text', $id) : null;
                $where = $id !== null ? $s->origin('at', $id) : null;
                if ($where !== null && $s->origin('rev', (string) $id) !== null) {
                    $where .= $w(' · revision %s', (string) $s->origin('rev', (string) $id));
                }
                // The written form already says where it looks: "query …", "header:user-agent …".
                $says = $pattern($p);
                $rows[] = ['text' => $text ?? $says, 'detail' => $text !== null && $text !== $says ? $says : null, 'id' => $id,
                    'where' => $where, 'log' => $id ?? "contentRules[$n]"];
            }
        }
        if ($rows !== []) {
            $g[] = [$w('Attack patterns in the request'), $w('Refused with "no access" (403), matched on the normalised values:'), $rows];
        }

        $rows = [];
        foreach ($s->queryParams as $n => $q) {
            $names = [];
            foreach ($q['exact'] + $q['globs'] as $name => $type) {
                $shown = strncmp((string) $name, '#^', 2) === 0 ? str_replace('.*', '*', substr((string) $name, 2, -2)) : (string) $name;
                $names[] = stripslashes($shown) . ' (' . (strncmp($type, '#', 1) === 0 ? $w('pattern') : $type) . ')';
            }
            $first = (string) array_key_first($q['exact'] + $q['globs']);
            $rows[] = $row(implode(', ', $names) . ($q['paths'] !== null ? $w(' — at %s', implode(', ', array_map($pattern, $q['paths']))) : ''),
                $o('queryParams', '#' . $n) ?? $o('query', $first), "queryParams[$n]");
        }
        if ($rows !== [] || $s->queryStrict) {
            $g[] = [$w('Known parameters'), $s->queryStrict ? $w('Any other parameter, or a value not of its type, gets "not found" (404):')
                : $w('Values of these types are not scanned by the attack patterns; any other parameter is answered, but not cached:'), $rows];
        }

        $rows = [];
        foreach ($s->restricted as $n => $r) {
            foreach ($r['paths'] as $p) {
                $rows[] = $row($pattern($p) . $w(' — only for %s', implode(', ', $r['ips'])), $o('restricted', $p), $o('restricted', $p) ?? "restricted[$n]");
            }
        }
        $g[] = [$w('Areas for certain visitors'), $rows === [] ? $w('No area is restricted.') : $w('Everyone else gets "no access" (403):'), $rows];

        $rows = [];
        if ($s->postOrigin !== null) {
            $rows[] = $row($w('Forms only from this website (Origin, else Referer); neither: %s', $w($s->postOrigin['missing'] === 'check' ? 'the browser check' : ($s->postOrigin['missing'] === 'allow' ? 'let through' : 'refused')))
                . ($s->postOrigin['except'] !== [] ? $w('; not at: %s', implode(', ', array_map($pattern, $s->postOrigin['except']))) : ''), $o('postOrigin', '*'), $o('postOrigin', '*') ?? 'postOrigin');
        }
        foreach ($s->methodPaths as $m => $patterns) {
            $rows[] = $row($w('%s only at: %s', (string) $m, implode(', ', array_map($pattern, $patterns))), $o('methodPaths', $m), $o('methodPaths', $m) ?? "methodPaths.$m");
        }
        $g[] = [$w('Where forms may be sent'), $w('Accepted kinds of request: %s', implode(', ', $s->methods)) . ($rows === [] ? $w('; forms may be sent anywhere.') : $w('; anywhere else "not allowed here" (405):')), $rows];

        $g[] = [$w('Website names and sizes'), ($s->hosts === [] ? $w('Any website name is accepted. ') : $w('The site answers as %s; any other name gets "not found". ', implode(', ', $s->hosts)))
            . $w('Addresses up to %s characters and %s parameters, headers up to %s KB; disguised addresses and attempts to leave the site\'s folder are refused.',
                (string) $s->maxUri, (string) $s->maxQueryParameters, (string) round($s->maxHeaderBytes / 1024)),
            $s->hosts === [] ? [] : [$row($w('Website names: %s', implode(', ', $s->hosts)), $o('hosts', '*'), $o('hosts', '*') ?? 'hosts')]];

        // One row per rule: a cache-path line often lists several paths.
        $byRule = [];
        foreach ($s->cacheablePaths ?? [] as $p) {
            $byRule[$o('cacheable.paths', $p) ?? ''][] = $pattern($p);
        }
        $rows = [];
        foreach ($byRule as $id => $paths) {
            $rows[] = $row(implode('  ', $paths), $id !== '' ? $id : null, '');
        }
        $query = $s->cacheableQuery === null ? $w('with any parameters') : ($s->cacheableQuery === [] ? $w('without parameters')
            : $w(count($s->cacheableQuery) > 1 ? 'only with the parameters %s' : 'only with the parameter %s', implode(', ', array_map(static fn (string $q): string => "\"$q\"", $s->cacheableQuery))));
        $g[] = [$w('What a cache may keep'), ($s->cacheablePaths === null ? $w('Every address, %s.', $query) : $w('These addresses, %s.', $query))
            . $w(' Anything else is answered by the site, but not kept — so made-up addresses cannot fill a cache.'), $rows];

        $rows = [];
        foreach ($s->budgets as $b) {
            $then = $b->earnBack ? $w(', then the check — solved, the counter starts again') : $w(', then a pause');
            $per = Describe::duration($b->window, $lang);
            $rows[] = $row($b->onDemand
                ? $w('"%s": at most %s per %s, counted by the site itself (searches, failed sign-ins, cache misses)', $b->name, (string) $b->limit, $per) . $then
                : $w('"%s": %s requests per %s', $b->name, (string) $b->limit, $per) . ($b->challengeAt !== null ? $w(', the browser check from %s', (string) $b->challengeAt) : '') . $then,
                $o('budgets', $b->name), $o('budgets', $b->name) ?? "budgets.$b->name");
        }
        $g[] = [$w('Pace per visitor'), $w('Counted per address (IPv6: per /%s network)', (string) $s->ipv6Prefix)
            . ($s->exemptIps === [] ? '.' : $w('; never counted: %s.', implode(', ', $s->exemptIps))), $rows];

        $rows = [];
        foreach ($s->challenge->alwaysPaths as $p) {
            $age = $s->challenge->alwaysMaxAge[$p] ?? null;
            $methods = $s->challenge->alwaysMethods[$p] ?? [];
            $rows[] = $row($w('always checked: %s', $pattern($p)) . ($methods !== [] ? $w(' — only %s', implode(', ', $methods)) : '')
                . ($age !== null ? $w(' — a pass from the last %s', Describe::span($age, $lang)) : ''),
                $o('challenge.alwaysPaths', $p), $o('challenge.alwaysPaths', $p) ?? 'challenge.alwaysPaths');
        }
        foreach ($s->challenge->exemptPaths as $p) {
            $rows[] = $row($w('never checked: %s', $pattern($p)), $o('challenge.exemptPaths', $p), '');
        }
        $g[] = [$w('Browser check'), $w('An invisible check that a real browser passes in a moment; a passed check is valid for %s', Describe::span($s->challenge->passTtl, $lang))
            . ($s->crawlers !== [] ? $w('. Known crawlers (search engines, AI crawlers) are recognised by their address, see below.') : '.'), $rows];

        $rows = [];
        $kinds = ['search' => $w('search engine'), 'ai-search' => $w('AI search'), 'ai-user' => $w('fetches what a person asks for'), 'ai-training' => $w('collects for AI training')];
        $policies = ['allow' => $w('let through (never the browser check)'), 'check' => $w('checked like any visitor'), 'block' => $w('refused (403)')];
        foreach ($s->crawlers as $id => $x) {
            $how = [];
            if ($x['ranges'] !== [] && $s->crawlerVerify !== 'dns') {
                $dates = array_filter(array_map(static fn (array $l): string => substr((string) ($l['created'] ?? ''), 0, 10), $x['lists']));
                $how[] = $w('its published address list (%s ranges', (string) count($x['ranges'])) . ($dates !== [] ? $w(', of %s', min($dates)) : '') . ')';
            }
            if ($x['dns'] !== [] && $s->crawlerVerify !== 'ranges') {
                $how[] = 'DNS (' . implode(', ', $x['dns']) . ')';
            }
            $claimed = $claims[$id] ?? 0;
            $rows[] = $row(($kinds[$x['kind']] ?? $x['kind']) . ': ' . ($policies[$x['policy']] ?? $x['policy'])
                . ($how === [] ? $w(' — verified by nothing here (crawler-verify %s): an ordinary visitor', $s->crawlerVerify) : $w(' — verified by %s', implode($w(' or '), $how)))
                . ($claimed > 0 ? $w(' · %s× only claimed in 24 h', (string) $claimed) : '') . self::counted($counted[$id] ?? null), (string) $id, '');
        }
        if ($rows !== []) {
            $g[] = [$w('Known crawlers'), $w('Search engines and AI crawlers that behave are recognised by where they come from — the name a request sends proves nothing. ')
                . $w('One that only borrows a name is an ordinary visitor (the log notes it: claimed=…). Change it per kind or crawler: crawlers ai-training block, crawler CRAWL-GPTBOT check.'), $rows];
        }

        $rows = [];
        foreach ($s->origins['monitor'] ?? [] as $rid => $rule) {
            $rows[] = $row('monitor ' . $rule, (string) $rid);
        }
        if ($rows !== []) {
            $g[] = [$w('Watched, not enforced'), $w('Rules marked "monitor": checked on every request and logged as they would decide ("monitor-reject" …), but nobody is refused. When the log shows no false hits, remove the word.'), $rows];
        }

        $g[] = [$w('Proxies and the log'), ($s->trustedProxies === [] ? $w('No proxy: the visitor\'s address is taken from the connection. ') : $w('The visitor\'s real address is believed only from %s. ', implode(', ', $s->trustedProxies)))
            . ($s->logFile === null ? $w('No log.') : $w('Log: level "%s", addresses %s.', $s->logLevel, $s->logIp === 'full' ? $w('in full') : $w('anonymised'))), []];
        return $g;
    }

    /** groups() in German: the English text => its translation (%s: the same values). */
    private const DE = [
        ' — until %s' => ' — bis %s', 'kept out: %s' => 'ausgesperrt: %s', 'and %s more kept out (bin/request-shield lists)' => 'und %s weitere ausgesperrt (bin/request-shield lists)', 'let in, never counted or checked: %s' => 'hereingelassen, nie gezählt oder geprüft: %s', 'Kept out and let in' => 'Ausgesperrt und hereingelassen',
        'A kept-out address gets 403 before every other check; one let in is never counted or checked, but still refused for blocked addresses and attack patterns.' => 'Eine ausgesperrte Adresse bekommt 403 vor jeder anderen Prüfung; eine hereingelassene wird nie gezählt oder geprüft, aber bei gesperrten Adressen und Angriffsmustern trotzdem abgewiesen.',
        ' The lists: %s (bin/request-shield deny, allow, unlist, lists).' => ' Die Listen: %s (bin/request-shield deny, allow, unlist, lists).', 'times past a limit' => 'Mal über einer Grenze',
        'refusals for what only attackers ask for' => 'Abweisungen für das, was nur Angreifer aufrufen', 'check pages not solved' => 'nicht gelöste Check-Seiten', '"%s" past its limit' => '„%s“ über seiner Grenze',
        'after %s %s in %s: banned for %s' => 'nach %s %s in %s: gesperrt für %s',
        'kept out: 403' => 'ausgesperrt: 403', 'the browser check' => 'der Browser-Check', 'counts towards a ban' => 'zählt für eine Sperre', '%s entries, fetched %s' => '%s Einträge, geholt %s',
        'too old, not used: fetched %s' => 'zu alt, nicht benutzt: geholt %s', 'not fetched yet: bin/request-shield feeds <main.rules> update' => 'noch nicht geholt: bin/request-shield feeds <main.rules> update',
        '%s (%s): %s' => '%s (%s): %s', 'only counted and logged (count)' => 'nur gezählt und geloggt (count)', 'at %s' => 'an %s', 'terms: %s' => 'Bedingungen: %s',
        'Public blocklists' => 'Öffentliche Sperrlisten',
        'Lists fetched by bin/request-shield feeds <main.rules> update (cron), compiled with the rules; nothing is looked up per request and nothing is sent anywhere. Never an address let in, a trusted proxy or a verified crawler; a list older than %s is not used.'
            => 'Listen, geholt von bin/request-shield feeds <main.rules> update (cron), mit den Regeln übersetzt; pro Anfrage wird nichts nachgeschlagen und nichts verschickt. Nie eine hereingelassene Adresse, ein vertrauenswürdiger Proxy oder ein bestätigter Crawler; eine Liste älter als %s wird nicht benutzt.', 'Banned for a while' => 'Für eine Weile gesperrt',
        'Nothing but 429 with the time to wait, longer each time within a day (%s×, at most %s); never an address let in, a trusted proxy or a verified crawler.' => 'Nur noch 429 mit der Wartezeit, bei jeder Wiederholung am selben Tag länger (%s×, höchstens %s); nie eine hereingelassene Adresse, ein vertrauenswürdiger Proxy oder ein bestätigter Crawler.',
        ' · revision %s' => ' · Revision %s', ' · in match %s' => ' · im match %s', 'every block above' => 'jede Sperre oben',
        'Open at %s: %s' => 'Offen unter %s: %s', ' — only for %s' => ' — nur für %s', ' — ⚠ for everyone: make sure only admins reach it' => ' — ⚠ für alle: nur Admins dürfen dorthin kommen',
        'Addresses only attackers ask for' => 'Adressen, die nur Angreifer aufrufen', 'None are refused.' => 'Keine wird abgewiesen.',
        'Refused with "not found" (404) before the site sees them:' => 'Abgewiesen mit „nicht gefunden“ (404), bevor die Website sie sieht:',
        'Attack patterns in the request' => 'Angriffsmuster in der Anfrage', 'Refused with "no access" (403), matched on the normalised values:' => 'Abgewiesen mit „kein Zugriff“ (403), geprüft auf den normalisierten Werten:',
        'pattern' => 'Muster', ' — at %s' => ' — unter %s', 'Known parameters' => 'Bekannte Parameter',
        'Any other parameter, or a value not of its type, gets "not found" (404):' => 'Jeder andere Parameter oder ein Wert nicht seines Typs bekommt „nicht gefunden“ (404):',
        'Values of these types are not scanned by the attack patterns; any other parameter is answered, but not cached:' => 'Werte dieser Typen prüfen die Angriffsmuster nicht; jeder andere Parameter wird beantwortet, aber nicht gecacht:',
        'Areas for certain visitors' => 'Bereiche für bestimmte Besucher', 'No area is restricted.' => 'Kein Bereich ist beschränkt.', 'Everyone else gets "no access" (403):' => 'Alle anderen bekommen „kein Zugriff“ (403):',
        '%s only at: %s' => '%s nur unter: %s',
        'Forms only from this website (Origin, else Referer); neither: %s' => 'Formulare nur von dieser Website (Origin, sonst Referer); ohne beides: %s',
        '; not at: %s' => '; nicht unter: %s', 'let through' => 'durchgelassen', 'refused' => 'abgewiesen', 'Where forms may be sent' => 'Wohin Formulare dürfen', 'Accepted kinds of request: %s' => 'Erlaubte Arten von Anfragen: %s',
        '; forms may be sent anywhere.' => '; Formulare dürfen überallhin.', '; anywhere else "not allowed here" (405):' => '; überall sonst „hier nicht erlaubt“ (405):',
        'Website names and sizes' => 'Namen und Größen', 'Any website name is accepted. ' => 'Jeder Name der Website wird angenommen. ',
        'The site answers as %s; any other name gets "not found". ' => 'Die Website antwortet als %s; jeder andere Name bekommt „nicht gefunden“. ',
        'Addresses up to %s characters and %s parameters, headers up to %s KB; disguised addresses and attempts to leave the site\'s folder are refused.' => 'Adressen bis %s Zeichen und %s Parameter, Header bis %s KB; getarnte Adressen und Versuche, den Ordner der Website zu verlassen, werden abgewiesen.',
        'Website names: %s' => 'Namen der Website: %s', 'with any parameters' => 'mit beliebigen Parametern', 'without parameters' => 'ohne Parameter',
        'only with the parameters %s' => 'nur mit den Parametern %s', 'only with the parameter %s' => 'nur mit dem Parameter %s', 'What a cache may keep' => 'Was ein Cache behalten darf',
        'Every address, %s.' => 'Jede Adresse, %s.', 'These addresses, %s.' => 'Diese Adressen, %s.',
        ' Anything else is answered by the site, but not kept — so made-up addresses cannot fill a cache.' => ' Alles andere beantwortet die Website, aber es wird nicht behalten — so können erfundene Adressen keinen Cache füllen.',
        ', then the check — solved, the counter starts again' => ', dann der Check — gelöst, beginnt der Zähler neu', ', then a pause' => ', dann eine Pause',
        '"%s": at most %s per %s, counted by the site itself (searches, failed sign-ins, cache misses)' => '„%s“: höchstens %s pro %s, gezählt von der Website selbst (Suchen, fehlgeschlagene Anmeldungen, Cache-Fehlgriffe)',
        '"%s": %s requests per %s' => '„%s“: %s Anfragen pro %s', ', the browser check from %s' => ', der Browser-Check ab %s', 'Pace per visitor' => 'Tempo pro Besucher',
        'Counted per address (IPv6: per /%s network)' => 'Gezählt pro Adresse (IPv6: pro /%s-Netz)', '; never counted: %s.' => '; nie gezählt: %s.',
        'always checked: %s' => 'immer geprüft: %s', ' — only %s' => ' — nur %s', ' — a pass from the last %s' => ' — ein Pass aus den letzten %s', 'never checked: %s' => 'nie geprüft: %s', 'Browser check' => 'Browser-Check',
        'An invisible check that a real browser passes in a moment; a passed check is valid for %s' => 'Ein unsichtbarer Check, den ein echter Browser in einem Moment besteht; ein bestandener Check gilt %s',
        '. Known crawlers (search engines, AI crawlers) are recognised by their address, see below.' => '. Bekannte Crawler (Suchmaschinen, KI-Crawler) werden an ihrer Adresse erkannt, siehe unten.',
        'search engine' => 'Suchmaschine', 'AI search' => 'KI-Suche', 'fetches what a person asks for' => 'holt, was eine Person fragt', 'collects for AI training' => 'sammelt für KI-Training',
        'let through (never the browser check)' => 'durchgelassen (nie der Browser-Check)', 'checked like any visitor' => 'geprüft wie jeder Besucher', 'refused (403)' => 'abgewiesen (403)',
        'its published address list (%s ranges' => 'seine veröffentlichte Adressliste (%s Bereiche', ', of %s' => ', vom %s', ' or ' => ' oder ',
        ' — verified by nothing here (crawler-verify %s): an ordinary visitor' => ' — hier durch nichts bestätigt (crawler-verify %s): ein gewöhnlicher Besucher', ' — verified by %s' => ' — bestätigt über %s',
        ' · %s× only claimed in 24 h' => ' · %s× nur behauptet in 24 h', 'Known crawlers' => 'Bekannte Crawler',
        'Search engines and AI crawlers that behave are recognised by where they come from — the name a request sends proves nothing. ' => 'Suchmaschinen und KI-Crawler, die sich benehmen, werden daran erkannt, woher sie kommen — der Name, den eine Anfrage schickt, beweist nichts. ',
        'One that only borrows a name is an ordinary visitor (the log notes it: claimed=…). Change it per kind or crawler: crawlers ai-training block, crawler CRAWL-GPTBOT check.' => 'Wer sich nur einen Namen leiht, ist ein gewöhnlicher Besucher (das Log vermerkt es: claimed=…). Ändern pro Art oder Crawler: crawlers ai-training block, crawler CRAWL-GPTBOT check.',
        'Watched, not enforced' => 'Beobachtet, nicht durchgesetzt',
        'Rules marked "monitor": checked on every request and logged as they would decide ("monitor-reject" …), but nobody is refused. When the log shows no false hits, remove the word.' => 'Regeln mit „monitor“: bei jeder Anfrage geprüft und so geloggt, wie sie entscheiden würden („monitor-reject“ …), aber niemand wird abgewiesen. Zeigt das Log keine Fehltreffer, das Wort entfernen.',
        'Proxies and the log' => 'Proxys und Log', 'No proxy: the visitor\'s address is taken from the connection. ' => 'Kein Proxy: die Adresse des Besuchers kommt aus der Verbindung. ',
        'The visitor\'s real address is believed only from %s. ' => 'Die echte Adresse des Besuchers wird nur von %s geglaubt. ', 'No log.' => 'Kein Log.',
        'Log: level "%s", addresses %s.' => 'Log: Stufe „%s“, Adressen %s.', 'in full' => 'vollständig', 'anonymised' => 'anonymisiert',
    ];

    /** The mode in plain words, or null for the usual one (enforce, nothing watched). */
    public static function mode(Settings $s, string $lang = 'en'): ?string
    {
        $de = $lang === 'de';
        $watched = count($s->origins['monitor'] ?? []);
        $also = $watched === 0 ? '' : ($de ? " $watched " . ($watched === 1 ? 'Regel wird' : 'Regeln werden') . ' nur beobachtet (monitor): geloggt, wie sie entscheiden würden, nicht durchgesetzt.'
            : " $watched " . ($watched === 1 ? 'rule is' : 'rules are') . ' only watched (monitor): logged as they would decide, not enforced.');
        switch ($s->mode) {
            case 'off':
                return $de ? 'Der Schutz ist abgeschaltet (set mode off): nichts wird geprüft, gezählt oder geloggt.'
                    : 'The shield is switched off (set mode off): nothing is checked, counted or logged.';
            case 'monitor':
                return $de ? 'Beobachtungsmodus (set mode monitor): jede Regel wird geprüft und gezählt, das Log zeigt, was sie entschieden hätte'
                    . ' — niemand wird abgewiesen, nichts Abgewiesenes gecacht. Auf enforce umstellen, wenn das Log keine Fehltreffer zeigt.'
                    : 'Monitor mode (set mode monitor): every rule is checked and counted, and the log shows what it would have decided'
                    . ' — nobody is refused, nothing refused is cached. Switch to enforce when the log shows no false hits.';
            case 'strict':
                return ($de ? 'Strenger Modus (set mode strict), für eine Website unter Angriff: der Browser-Check ab einem Viertel jedes Limits, ein Pass für '
                    . Describe::span($s->challenge->passTtl, 'de') . ', Adressen, die ein Cache nicht behalten darf, zählen doppelt.'
                    : 'Strict mode (set mode strict), for a site under attack: the browser check from a quarter of each limit, a pass for '
                    . Describe::span($s->challenge->passTtl) . ', addresses a cache must not keep count twice.') . $also;
        }
        return $also === '' ? null : trim($also);
    }

    /**
     * What a crawler did in the last 7 days, in words (set stats on).
     *
     * @param array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}|null $c a crawler of StatsReport
     */
    private static function counted(?array $c): string
    {
        if ($c === null) {
            return '';
        }
        $verified = $c['verified'];
        if ($verified === 0) {
            return ' · no verified visit in 7 days';
        }
        $got = [];
        foreach (['allowed' => 'let through', 'checked' => 'checked', 'throttled' => 'told to wait', 'refused' => 'refused'] as $e => $word) {
            if ($c[$e] > 0) {
                $got[] = number_format($c[$e]) . ' ' . $word;
            }
        }
        $last = $c['last'] !== null ? ', last ' . date('Y-m-d H:i', $c['last'][0]) : '';
        return ' · 7 days: ' . number_format($verified) . ' visits (' . implode(', ', $got) . ')' . $last;
    }

    private static function tile(string $n, string $label): string
    {
        return '<div class="tile"><span class="n">' . htmlspecialchars($n, ENT_QUOTES) . '</span>' . htmlspecialchars($label, ENT_QUOTES) . '</div>';
    }

    private static function icon(string $state): string
    {
        return ['pass' => '✓', 'note' => '!', 'stop' => '✕', 'skip' => '–'][$state] ?? '';
    }

    private static function happened(string $action, int $status): string
    {
        if (strncmp($action, 'monitor-', 8) === 0) {
            return 'watched, would have been ' . self::happened(substr($action, 8), $status);
        }
        return ['reject' => "refused ($status)", 'throttle' => 'told to wait', 'challenge' => 'browser check', 'allow-uncached' => 'answered, not cached', 'allow' => 'answered'][$action] ?? $action;
    }

    private static function ago(int $seconds): string
    {
        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min ago';
        }
        return intdiv($seconds, 3600) . ' h ago';
    }

    /** The browser check in plain words (docs/explained/browser-check.md has more). */
    private const BROWSER_CHECK = '<details class="explain"><summary>How does it work — and what does it bring?</summary>'
        . '<p>Instead of the page, a visitor who asks too often (or opens a page where everyone is checked) gets a small page for a moment: '
        . '<em>"One moment, please"</em>. Its script solves a small arithmetic task — a real browser needs a fraction of a second, nothing to click — '
        . 'and gets a <strong>pass</strong> for the time shown above. Search engines are recognised and never checked.</p>'
        . '<ol><li>The shield sends a task, signed so it cannot be forged: find the number that gives a certain result.</li>'
        . '<li>The browser tries numbers until it finds it — some tens of thousands of attempts, invisible.</li>'
        . '<li>It sends the answer back; the shield checks it in well under a millisecond, and every answer counts only once.</li>'
        . '<li>A signed cookie is the pass: the next pages go straight through.</li></ol>'
        . '<p><strong>What it brings:</strong> simple bots and scrapers run no JavaScript and never get past the small page — the site renders nothing for them. '
        . 'Bots with a real browser engine pay computing time for every pass, and more the more aggressive they are. '
        . 'Real visitors see it at most once in a while. Nothing comes from or goes to a third party: no Google, no Cloudflare, no tracking.</p>'
        . '%DIAGRAM%'
        . '<p><strong>What it does not do:</strong> it is no CAPTCHA against people, and an attacker with many real browsers gets through — slower and at their own cost.</p>'
        . '</details>';

    private const CSS = <<<'CSS'
:root{--bg:#f6f7f9;--fg:#1d2127;--muted:#5b6470;--card:#fff;--line:#dfe3e8;--accent:#2f62c9;--ok:#1e7b43;--okbg:#e6f4ea;--warn:#8a5a00;--warnbg:#fdf3dc;--no:#a3361f;--nobg:#fbe9e5;--skip:#8a929c}
@media (prefers-color-scheme:dark){:root{--bg:#15181c;--fg:#e7e9ec;--muted:#a0a8b3;--card:#1d2127;--line:#2d333b;--accent:#7aa2ff;--ok:#5fcf8a;--okbg:#17301f;--warn:#f0c060;--warnbg:#3a2f15;--no:#ff8a70;--nobg:#3d1f19;--skip:#6b737d}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.55 system-ui,sans-serif}
header.bar{background:var(--card);border-bottom:1px solid var(--line)}header.bar div{max-width:60rem;margin:0 auto;padding:.7rem 1rem}header.bar a{font-weight:700;color:var(--fg);text-decoration:none}
main{max-width:60rem;margin:0 auto;padding:1.5rem 1rem 3rem}h1{font-size:1.6rem;margin:.2rem 0}h2{font-size:1.2rem;margin:2rem 0 .6rem}h3{font-size:1.02rem;margin:0 0 .3rem}
.lead,.note,.intro{color:var(--muted)}.lead{margin:0 0 1.2rem}.note{font-size:.9rem}.intro{margin:.1rem 0 .6rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:1rem 1.2rem;margin-bottom:.8rem}.card h2{margin-top:0}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.6rem;margin-bottom:1rem}
.tile{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;color:var(--muted);font-size:.9rem}.tile .n{display:block;font-size:1.6rem;font-weight:650;color:var(--fg)}
table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:.45rem .4rem;border-top:1px solid var(--line);vertical-align:top}tr:first-child td,tr:first-child th{border-top:0}
td.meta{width:1%;white-space:nowrap}td.hits{width:1%;white-space:nowrap;text-align:right}td.url{word-break:break-all;font:13px/1.4 ui-monospace,monospace}
code{font:13px/1.4 ui-monospace,monospace}code.rule{color:var(--muted);font-size:12px}code.origin{color:var(--muted);background:var(--bg);border:1px solid var(--line);border-radius:4px;padding:0 .3rem}
.badge{display:inline-block;min-width:1.6rem;text-align:center;background:var(--accent);color:#fff;border-radius:99px;padding:0 .45rem;font-size:.85rem;font-weight:600}
form.try{display:flex;gap:.5rem;flex-wrap:wrap}form.try input,form.try select{padding:.45rem .6rem;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg);font:inherit}
form.try input[name=url]{flex:1 1 18rem}form.try input.ip{flex:0 1 11rem}
button{padding:.45rem 1rem;border:0;border-radius:6px;background:var(--accent);color:#fff;cursor:pointer;font:inherit}
.verdict{display:flex;gap:.7rem;align-items:flex-start;border-radius:8px;padding:.8rem 1rem;margin:1rem 0 .6rem}
.verdict.pass{background:var(--okbg)}.verdict.note{background:var(--warnbg)}.verdict.stop{background:var(--nobg)}
ol.steps{list-style:none;margin:0;padding:0}ol.steps li{display:flex;gap:.7rem;padding:.45rem 0;border-top:1px solid var(--line)}ol.steps li:first-child{border-top:0}
.icon{flex:0 0 1.5rem;height:1.5rem;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:#fff}
.pass .icon{background:var(--ok)}.note .icon{background:var(--warn)}.stop .icon{background:var(--no)}.skip .icon{background:var(--skip)}.skip{color:var(--skip)}
.diagram{overflow-x:auto;margin:.8rem 0}.diagram svg{min-width:40rem;max-width:100%;height:auto}details.explain{margin-top:.8rem;border-top:1px solid var(--line);padding-top:.6rem}details.explain summary{cursor:pointer;font-weight:600;color:var(--accent)}details.explain ol{padding-left:1.3rem}
table.recent .icon{width:1.3rem;height:1.3rem;display:inline-flex;font-size:.75rem}
@media (max-width:40rem){td.meta,td.hits{white-space:normal}table.recent th:nth-child(2),table.recent td:nth-child(2){display:none}}
CSS . Help::CSS;

    /** Refreshes the summary, the counts and the latest activity every 10 seconds. */
    private const SCRIPT = <<<'JS'
(function () {
  if (!window.fetch || !window.DOMParser) { return; }
  setInterval(function () {
    if (document.hidden) { return; }
    fetch(location.href, { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : null; }).then(function (html) {
      if (!html) { return; }
      var fresh = new DOMParser().parseFromString(html, 'text/html');
      document.querySelectorAll('[data-live][id]').forEach(function (el) {
        var n = fresh.getElementById(el.id);
        if (n) { el.innerHTML = n.innerHTML; }
      });
    }).catch(function () {});
  }, 10000);
})();
JS;
}
