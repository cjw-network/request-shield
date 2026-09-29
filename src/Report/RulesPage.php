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
     * @param array{check?: array<mixed>, action?: string, title?: string, store?: Store, now?: int, ip?: string} $o
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
            . '<meta name="robots" content="noindex,nofollow"><title>' . $e($title) . '</title><style>' . self::CSS . '</style></head><body><main>';
        $h .= '<h1>' . $e($title) . '</h1><p class="lead">How this site is protected, in plain words — and what happens to any address you try.</p>';

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
        $h .= '<section class="card"><h2>Try an address</h2><form method="get" action="' . $e($action) . '" class="try">'
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
            $state = $t['decision']->passes() ? ($t['decision']->action === 'allow' ? 'pass' : 'note') : ($t['decision']->action === 'challenge' ? 'note' : 'stop');
            $h .= '<div class="verdict ' . $state . '"><span class="icon">' . self::icon($state) . '</span><div><strong>This visitor ' . $e($t['verdict']) . '.</strong>'
                . ($t['rule'] !== null ? '<br><span class="note">Decided by: <code>' . $e($t['rule']) . '</code></span>' : '') . '</div></div><ol class="steps">';
            foreach ($t['steps'] as $st) {
                $h .= '<li class="' . $st['state'] . '"><span class="icon">' . self::icon($st['state']) . '</span><div><strong>' . $e($st['check']) . '</strong><br>' . $e($st['text'])
                    . ($st['rule'] !== null ? ' <code class="origin">' . $e($st['rule']) . '</code>' : '') . '</div></li>';
            }
            $h .= '</ol>';
        }
        $h .= '</section>';

        // ── The rules ───────────────────────────────────────────────────────
        $h .= '<h2>The rules</h2><p class="note">Each with where it is written and, from the log, how often it decided in the last 24 hours.</p>';
        foreach (self::groups($s) as [$heading, $intro, $rows]) {
            $h .= '<section class="card"><h3>' . $e($heading) . '</h3><p class="intro">' . $e($intro) . '</p>';
            if ($rows !== []) {
                $h .= '<table>';
                foreach ($rows as [$text, $origin, $id]) {
                    $hit = $id !== null ? ($stats['rules'][$id] ?? null) : null;
                    $h .= '<tr><td>' . $e($text) . '</td><td class="meta">' . ($origin !== null ? '<code class="origin">' . $e($origin) . '</code>' : '')
                        . '</td><td class="hits"' . ($id !== null ? ' data-live id="hits-' . md5($id) . '"' : '') . '>'
                        . ($hit !== null ? '<span class="badge">' . $hit['count'] . '×</span> <span class="note">last ' . $e(self::ago($now - $hit['last'])) . '</span>' : ($s->logFile !== null && $id !== null ? '<span class="note">—</span>' : ''))
                        . '</td></tr>';
                }
                $h .= '</table>';
            }
            $h .= '</section>';
        }

        // ── Lately ──────────────────────────────────────────────────────────
        $h .= '<h2>Lately</h2><section class="card" id="lately" data-live>';
        if ($s->logFile === null) {
            $h .= '<p class="intro">No log: <code>set log /path/shield.log</code> in the rule file shows here what was refused or checked.</p>';
        } elseif ($stats['recent'] === []) {
            $h .= '<p class="intro">Nothing refused or checked in the last 24 hours.</p>';
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
     * The rules, grouped the way a site owner thinks about them.
     *
     * @return list<array{0: string, 1: string, 2: list<array{0: string, 1: ?string, 2: ?string}>}>
     */
    public static function groups(Settings $s): array
    {
        $o = static fn (string $setting, string $what): ?string => $s->origin($setting, $what);
        $row = static fn (string $text, string $setting, string $what, string $fallback): array => [$text, $o($setting, $what), $o($setting, $what) ?? $fallback];
        $g = [];

        $rows = [];
        foreach ($s->blockedPaths as $i => $p) {
            $rows[] = $row(Describe::pattern($s, $p), 'blockedPaths', $p, "blockedPaths[$i]");
        }
        $g[] = ['Addresses only attackers ask for', $rows === [] ? 'None are refused.' : 'Refused with "not found" (404) before the site sees them:', $rows];

        $rows = [];
        foreach ($s->restricted as $n => $r) {
            foreach ($r['paths'] as $p) {
                $rows[] = $row(Describe::pattern($s, $p) . ' — only for ' . implode(', ', $r['ips']), 'restricted', $p, "restricted[$n]");
            }
        }
        $g[] = ['Areas for certain visitors', $rows === [] ? 'No area is restricted.' : 'Everyone else gets "no access" (403):', $rows];

        $rows = [];
        foreach ($s->methodPaths as $m => $patterns) {
            $rows[] = [$m . ' only at: ' . implode(', ', array_map(static fn (string $p): string => Describe::pattern($s, $p), $patterns)), $o('methodPaths', $m), $o('methodPaths', $m) ?? "methodPaths.$m"];
        }
        $g[] = ['Where forms may be sent', 'Accepted kinds of request: ' . implode(', ', $s->methods) . ($rows === [] ? '; forms may be sent anywhere.' : '; anywhere else "not allowed here" (405):'), $rows];

        $g[] = ['Website names and sizes', ($s->hosts === [] ? 'Any website name is accepted. ' : 'The site answers as ' . implode(', ', $s->hosts) . '; any other name gets "not found". ')
            . "Addresses up to $s->maxUri characters and $s->maxQueryParameters parameters, headers up to " . round($s->maxHeaderBytes / 1024) . ' KB; disguised addresses and attempts to leave the site\'s folder are refused.',
            $s->hosts === [] ? [] : [['Website names: ' . implode(', ', $s->hosts), $o('hosts', '*'), $o('hosts', '*') ?? 'hosts']]];

        $rows = [];
        foreach ($s->cacheablePaths ?? [] as $p) {
            $rows[] = [Describe::pattern($s, $p), $o('cacheable.paths', $p), null];
        }
        $query = $s->cacheableQuery === null ? 'with any parameters' : ($s->cacheableQuery === [] ? 'without parameters' : 'only with the parameters ' . implode(', ', array_map(static fn (string $q): string => "\"$q\"", $s->cacheableQuery)));
        $g[] = ['What a cache may keep', ($s->cacheablePaths === null ? 'Every address, ' : 'These addresses, ') . $query
            . '. Anything else is answered by the site, but not kept — so made-up addresses cannot fill a cache.', $rows];

        $rows = [];
        foreach ($s->budgets as $b) {
            $rows[] = [$b->onDemand
                ? "\"$b->name\": at most $b->limit per " . Describe::duration($b->window) . ', counted by the site itself (searches, failed sign-ins, cache misses)'
                : "\"$b->name\": $b->limit requests per " . Describe::duration($b->window) . ($b->challengeAt !== null ? ", the browser check from $b->challengeAt" : '') . ', then a pause',
                $o('budgets', $b->name), $o('budgets', $b->name) ?? "budgets.$b->name"];
        }
        $g[] = ['Pace per visitor', 'Counted per address (IPv6: per /' . $s->ipv6Prefix . ' network)'
            . ($s->exemptIps === [] ? '.' : '; never counted: ' . implode(', ', $s->exemptIps) . '.'), $rows];

        $rows = [];
        foreach ($s->challenge->alwaysPaths as $p) {
            $rows[] = ['always checked: ' . Describe::pattern($s, $p), $o('challenge.alwaysPaths', $p), $o('challenge.alwaysPaths', $p) ?? 'challenge.alwaysPaths'];
        }
        foreach ($s->challenge->exemptPaths as $p) {
            $rows[] = ['never checked: ' . Describe::pattern($s, $p), $o('challenge.exemptPaths', $p), null];
        }
        $g[] = ['Browser check', 'An invisible check that a real browser passes in a moment; a passed check is valid for ' . Describe::span($s->challenge->passTtl)
            . ($s->challenge->searchEngines !== null ? '. Search engines (Google, Bing, …) are recognised and let through.' : '.'), $rows];

        $g[] = ['Proxies and the log', ($s->trustedProxies === [] ? 'No proxy: the visitor\'s address is taken from the connection. ' : 'The visitor\'s real address is believed only from ' . implode(', ', $s->trustedProxies) . '. ')
            . ($s->logFile === null ? 'No log.' : "Log: level \"$s->logLevel\", addresses " . ($s->logIp === 'full' ? 'in full' : 'anonymised') . '.'), []];
        return $g;
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

    private const CSS = <<<'CSS'
:root{--bg:#f6f7f9;--fg:#1d2127;--muted:#5b6470;--card:#fff;--line:#dfe3e8;--accent:#2f62c9;--ok:#1e7b43;--okbg:#e6f4ea;--warn:#8a5a00;--warnbg:#fdf3dc;--no:#a3361f;--nobg:#fbe9e5;--skip:#8a929c}
@media (prefers-color-scheme:dark){:root{--bg:#15181c;--fg:#e7e9ec;--muted:#a0a8b3;--card:#1d2127;--line:#2d333b;--accent:#7aa2ff;--ok:#5fcf8a;--okbg:#17301f;--warn:#f0c060;--warnbg:#3a2f15;--no:#ff8a70;--nobg:#3d1f19;--skip:#6b737d}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.55 system-ui,sans-serif}
main{max-width:60rem;margin:0 auto;padding:1.5rem 1rem 3rem}h1{font-size:1.6rem;margin:.2rem 0}h2{font-size:1.2rem;margin:2rem 0 .6rem}h3{font-size:1.02rem;margin:0 0 .3rem}
.lead,.note,.intro{color:var(--muted)}.lead{margin:0 0 1.2rem}.note{font-size:.9rem}.intro{margin:.1rem 0 .6rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:1rem 1.2rem;margin-bottom:.8rem}.card h2{margin-top:0}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.6rem;margin-bottom:1rem}
.tile{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;color:var(--muted);font-size:.9rem}.tile .n{display:block;font-size:1.6rem;font-weight:650;color:var(--fg)}
table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:.45rem .4rem;border-top:1px solid var(--line);vertical-align:top}tr:first-child td,tr:first-child th{border-top:0}
td.meta{width:1%;white-space:nowrap}td.hits{width:1%;white-space:nowrap;text-align:right}td.url{word-break:break-all;font:13px/1.4 ui-monospace,monospace}
code{font:13px/1.4 ui-monospace,monospace}code.origin{color:var(--muted);background:var(--bg);border:1px solid var(--line);border-radius:4px;padding:0 .3rem}
.badge{display:inline-block;min-width:1.6rem;text-align:center;background:var(--accent);color:#fff;border-radius:99px;padding:0 .45rem;font-size:.85rem;font-weight:600}
form.try{display:flex;gap:.5rem;flex-wrap:wrap}form.try input,form.try select{padding:.45rem .6rem;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg);font:inherit}
form.try input[name=url]{flex:1 1 18rem}form.try input.ip{flex:0 1 11rem}
button{padding:.45rem 1rem;border:0;border-radius:6px;background:var(--accent);color:#fff;cursor:pointer;font:inherit}
.verdict{display:flex;gap:.7rem;align-items:flex-start;border-radius:8px;padding:.8rem 1rem;margin:1rem 0 .6rem}
.verdict.pass{background:var(--okbg)}.verdict.note{background:var(--warnbg)}.verdict.stop{background:var(--nobg)}
ol.steps{list-style:none;margin:0;padding:0}ol.steps li{display:flex;gap:.7rem;padding:.45rem 0;border-top:1px solid var(--line)}ol.steps li:first-child{border-top:0}
.icon{flex:0 0 1.5rem;height:1.5rem;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:#fff}
.pass .icon{background:var(--ok)}.note .icon{background:var(--warn)}.stop .icon{background:var(--no)}.skip .icon{background:var(--skip)}.skip{color:var(--skip)}
table.recent .icon{width:1.3rem;height:1.3rem;display:inline-flex;font-size:.75rem}
@media (max-width:40rem){td.meta,td.hits{white-space:normal}table.recent th:nth-child(2),table.recent td:nth-child(2){display:none}}
CSS;

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
