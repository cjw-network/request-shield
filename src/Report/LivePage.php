<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

use CjwNetwork\RequestShield\Api\LiveRows;
use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Texts;

/**
 * The live view (proposal 0026): what the shield stops right now, one row per
 * request, newest first -- the website, the address, the request, what
 * happened, why in plain words and where the decision came from (a list, a
 * ban, a feed, the site's own rule, a built-in rule, the pace, the crawler
 * policy). Read from the log's new lines (LogTail), so a request pays
 * nothing for it; the page asks json() every few seconds with its cursor.
 *
 * The site decides who may open it (an address rule, its login), as for the
 * statistics: these are the log's lines, addresses as the log keeps them.
 */
final class LivePage implements \CjwNetwork\RequestShield\RoutePage
{

    private const T = [
        'en' => [
            'title' => 'Live', 'intro' => 'What the shield stops right now — newest first, every %d seconds.',
            'noLog' => 'There is no log to read: switch it on in the rule file (set log <file>), then this page fills.',
            'level' => 'From the log, which keeps %s.', 'lv.stop' => 'refusals and bans (log-level stop) — checks and the uncached are not in it (log-level flag)',
            'lv.flag' => 'refusals, bans, checks and the uncached (log-level flag)', 'lv.all' => 'everything that was not a plain pass (log-level all)', 'lv.off' => 'nothing (log-level off)',
            'time' => 'Time', 'site' => 'Website', 'client' => 'Address', 'request' => 'Request', 'what' => 'What happened', 'why' => 'Why', 'source' => 'From', 'rule' => 'Rule',
            'allSites' => 'all websites', 'allWhat' => 'everything', 'allSources' => 'all sources', 'search' => 'address or request …',
            'pause' => 'Pause', 'resume' => 'Go on', 'waiting' => 'Go on (%d new)', 'shown' => '%d of %d rows', 'empty' => 'Nothing stopped yet: a row appears as soon as the shield refuses, checks or bans a request, such as a scanner asking for /.env.',
            'skipped' => '%d KB of the log were skipped (more than one read): the newest rows are shown.', 'keepOut' => 'keep out', 'unlist' => 'lists',
                                    'whereRule' => 'where this rule is written', 'error' => 'The live view cannot reach the server — trying again.',
            'memory' => 'From the live memory: the last requests stopped, with the full address, kept %s (set live on).', 'skippedRows' => '%d rows skipped (more than one read): the newest are shown.',
            'noMemory' => 'set live on: with set store memory nothing outlasts a request — the log is read instead.',
            'noFeed' => 'The rows as the page was opened: it refreshes itself with the API (request-shield-api.php, set api on).',
        ],
        'de' => [
            'title' => 'Live', 'intro' => 'Was der Schutz gerade aufhält — das Neueste oben, alle %d Sekunden.',
            'noLog' => 'Es gibt kein Log zum Lesen: in der Regeldatei einschalten (set log <Datei>), dann füllt sich diese Seite.',
            'level' => 'Aus dem Log, das %s festhält.', 'lv.stop' => 'Abweisungen und Sperren (log-level stop) — Prüfungen und nicht Gecachtes fehlen (log-level flag)',
            'lv.flag' => 'Abweisungen, Sperren, Prüfungen und nicht Gecachtes (log-level flag)', 'lv.all' => 'alles, was nicht einfach durchging (log-level all)', 'lv.off' => 'nichts (log-level off)',
            'time' => 'Zeit', 'site' => 'Website', 'client' => 'Adresse', 'request' => 'Anfrage', 'what' => 'Was geschah', 'why' => 'Warum', 'source' => 'Woher', 'rule' => 'Regel',
            'allSites' => 'alle Websites', 'allWhat' => 'alles', 'allSources' => 'alle Quellen', 'search' => 'Adresse oder Anfrage …',
            'pause' => 'Anhalten', 'resume' => 'Weiter', 'waiting' => 'Weiter (%d neu)', 'shown' => '%d von %d Zeilen', 'empty' => 'Noch nichts aufgehalten: Eine Zeile erscheint, sobald der Schutz eine Anfrage abweist, prüft oder sperrt, etwa einen Scanner, der nach /.env fragt.',
            'skipped' => '%d KB des Logs übersprungen (mehr als ein Lesen): die neuesten Zeilen stehen hier.', 'keepOut' => 'aussperren', 'unlist' => 'Listen',
                                    'whereRule' => 'wo diese Regel steht', 'error' => 'Die Live-Ansicht erreicht den Server nicht — neuer Versuch.',
            'memory' => 'Aus dem Live-Speicher: die zuletzt aufgehaltenen Anfragen, mit voller Adresse, gehalten %s (set live on).', 'skippedRows' => '%d Zeilen übersprungen (mehr als ein Lesen): die neuesten stehen hier.',
            'noMemory' => 'set live on: mit set store memory überdauert nichts eine Anfrage — stattdessen wird das Log gelesen.',
            'noFeed' => 'Die Zeilen vom Öffnen der Seite: Sie aktualisiert sich mit der API (request-shield-api.php, set api on).',
        ],
    ];

    /**
     * The page: its filters, the table, the script that asks for new rows.
     *
     * @param array<string, mixed> $o feed (the address of json(), required), lists (the lists page: "keep out"),
     *                                links (the dashboard's tabs), lang (auto, en, de), accept (Accept-Language), every (seconds, 3),
     *                                title, home, homeLabel
     */
    public static function render(Settings $s, array $o): string
    {
        $lang = Texts::language(is_string($o['lang'] ?? null) ? $o['lang'] : 'auto', is_string($o['accept'] ?? null) ? $o['accept'] : null);
        $lang = $lang === 'de' ? 'de' : 'en';
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $every = max(1, min(60, is_int($o['every'] ?? null) ? $o['every'] : 3));
        /** @var array<string, string> $links */
        $links = array_filter((array) ($o['links'] ?? []), 'is_string');
        $h = Frame::tabs($s, $links, 'live', $lang);
        $o['help'] = Help::link('RSF06-02', '', $s->docsUrl, $lang);
        $memory = LiveRows::fromMemory($s);
        $h .= '<p class="note">' . $e(sprintf($t['intro'], $every)) . ' '
            . ($memory ? $e(sprintf($t['memory'], Describe::span($s->liveKeep, $lang))) : ($s->logFile !== null ? $e(sprintf($t['level'], $t['lv.' . $s->logLevel] ?? $s->logLevel)) : '')) . '</p>'
            . ($s->liveEnabled && !$memory ? '<p class="note">' . $e($t['noMemory']) . '</p>' : '');
        if (!$memory && $s->logFile === null) {
            return Frame::page(is_string($o['title'] ?? null) ? $o['title'] : $t['title'], $lang, $h . '<div class="msg">' . $e($t['noLog']) . '</div>', $o);
        }
        $first = LiveRows::json($s, null, ['lang' => $lang, 'links' => $links] + (is_string($o['ip'] ?? null) ? ['ip' => $o['ip']] : []));
        $opt = static fn (string $v, string $label): string => '<option value="' . $e($v) . '">' . $e($label) . '</option>';
        $whats = $opt('', $t['allWhat']);
        foreach (['refused', 'banned', 'paused', 'checked', 'uncached'] as $w) {
            $whats .= $opt($w, LiveRows::T[$lang]['w.' . $w]);
        }
        $sources = $opt('', $t['allSources']);
        foreach (['list', 'ban', 'feed', 'own', 'builtin', 'pace', 'crawler', 'shield'] as $k) {
            $sources .= $opt($k, LiveRows::T[$lang]['s.' . $k]);
        }
        $h .= '<div class="card live-bar"><select id="f-host" aria-label="' . $e($t['site']) . '">' . $opt('', $t['allSites']) . '</select>'
            . '<select id="f-what" aria-label="' . $e($t['what']) . '">' . $whats . '</select>'
            . '<select id="f-source" aria-label="' . $e($t['source']) . '">' . $sources . '</select>'
            . '<input id="f-q" type="search" placeholder="' . $e($t['search']) . '" aria-label="' . $e($t['search']) . '">'
            . '<button id="pause" type="button">' . $e($t['pause']) . '</button><span id="count" class="note"></span></div>'
            . '<p id="skipped" class="note" hidden></p><p id="error" class="msg bad" hidden>' . $e($t['error']) . '</p>'
            . '<div class="card wrap"><table><thead><tr><th>' . $e($t['time']) . '</th><th>' . $e($t['site']) . '</th><th>' . $e($t['client']) . '</th><th>' . $e($t['request'])
            . '</th><th>' . $e($t['what']) . '</th><th>' . $e($t['why']) . '</th><th>' . $e($t['source']) . '</th><th>' . $e($t['rule']) . '</th><th></th></tr></thead>'
            . '<tbody id="rows"></tbody></table><p id="empty" class="note">' . $e($t['empty']) . '</p></div>';
        $js = ['whereRule' => $t['whereRule'], 'pause' => $t['pause'], 'resume' => $t['resume'], 'waiting' => $t['waiting'], 'shown' => $t['shown'], 'skipped' => $memory ? $t['skippedRows'] : $t['skipped'], 'keepOut' => $t['keepOut']];
        $feed = is_string($o['feed'] ?? null) ? $o['feed'] : '';
        if ($feed === '') {
            $h .= '<p class="note">' . $e($t['noFeed']) . '</p>';        // nothing asks for new rows
        }
        $feed .= $feed === '' ? '' : (strpos($feed, '?') === false ? '?' : '&') . 'lang=' . $lang;   // the rows in the page's language
        $h = '<div id="live" data-feed="' . $e($feed) . '" data-lists="' . $e(is_string($o['lists'] ?? null) ? $o['lists'] : '') . '" data-every="' . $every . '"'
            . ' data-t="' . $e((string) json_encode($js, JSON_UNESCAPED_UNICODE)) . '" data-init="' . $e((string) json_encode($first, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . '">' . $h . '</div>';
        return Frame::page(is_string($o['title'] ?? null) ? $o['title'] : $t['title'], $lang, $h, $o, self::CSS, self::SCRIPT);
    }

    private const CSS = <<<'CSS'
.live-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.live-bar input{flex:1;min-width:180px}
#rows tr.new{animation:fresh 1.6s ease-out}@keyframes fresh{from{background:color-mix(in srgb,var(--a) 18%,transparent)}to{background:transparent}}
.w{font-weight:600;white-space:nowrap}.w.refused,.w.banned{color:var(--refused)}.w.paused{color:var(--throttled)}.w.checked{color:var(--checked)}.w.uncached{color:var(--m)}.w.watched{color:var(--watched)}
.rule{white-space:nowrap}.req{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}td.why{min-width:240px}.host{white-space:nowrap}.site{color:var(--m);font-size:12px;display:block}
.badge.list,.badge.ban{border-color:var(--refused);color:var(--refused)}.badge.feed{border-color:var(--throttled);color:var(--throttled)}.badge.own{border-color:var(--a);color:var(--a)}
.badge.pace{border-color:var(--throttled)}.badge.crawler{border-color:var(--through);color:var(--through)}
CSS;

    private const SCRIPT = <<<'JS'
(function () {
  var box = document.getElementById('live');
  if (!box) { return; }
  var feed = box.getAttribute('data-feed'), lists = box.getAttribute('data-lists'), every = +box.getAttribute('data-every') * 1000;
  var T = JSON.parse(box.getAttribute('data-t')), first = JSON.parse(box.getAttribute('data-init'));
  var cursor = first.cursor, rows = [], pending = [], paused = false, MAX = 500, hosts = {};
  var body = document.getElementById('rows'), empty = document.getElementById('empty'), count = document.getElementById('count');
  var f = { host: document.getElementById('f-host'), what: document.getElementById('f-what'), source: document.getElementById('f-source'), q: document.getElementById('f-q') };
  var pause = document.getElementById('pause'), q = new URLSearchParams(location.search);
  Object.keys(f).forEach(function (k) { if (q.get(k)) { if (k === 'host') { addHost(q.get(k)); } f[k].value = q.get(k); } });
  function addHost(h) {
    if (!h || hosts[h]) { return; }
    hosts[h] = 1;
    var o = document.createElement('option'); o.value = h; o.textContent = h; f.host.appendChild(o);
  }
  function td(tr, text, cls, title) {
    var c = document.createElement('td'); c.textContent = text == null ? '' : text;
    if (cls) { c.className = cls; } if (title) { c.title = title; } tr.appendChild(c); return c;
  }
  function fits(r) {
    var s = f.q.value.toLowerCase();
    return (!f.host.value || r.host === f.host.value) && (!f.what.value || r.what === f.what.value) && (!f.source.value || r.source === f.source.value)
      && (!s || (r.client + ' ' + r.method + ' ' + r.request + ' ' + r.host).toLowerCase().indexOf(s) >= 0);
  }
  function tr(r, fresh) {
    var x = document.createElement('tr'); if (fresh) { x.className = 'new'; }
    td(x, r.time, 'mono', r.day);
    var h = td(x, r.host, 'host'); if (r.site && r.site !== r.host) { var sp = document.createElement('span'); sp.className = 'site'; sp.textContent = r.site; h.appendChild(sp); }
    td(x, r.client, 'mono');
    td(x, r.method + ' ' + r.request, 'req mono', r.method + ' ' + r.request + '\n' + r.agent);
    td(x, r.label, 'w ' + r.what + (r.watched ? ' watched' : ''));
    td(x, r.why, 'why', r.reason);
    var b = document.createElement('span'); b.className = 'badge ' + r.source; b.textContent = r.sourceLabel; td(x, '').appendChild(b);
    var rc = td(x, r.ruleHref ? '' : r.rule, 'mono rule');
    if (r.ruleHref) { var ra = document.createElement('a'); ra.href = r.ruleHref; ra.textContent = r.rule; ra.title = T.whereRule; rc.appendChild(ra); }
    var a = td(x, '');
    if (r.keep && lists) {
      var l = document.createElement('a');
      l.href = lists + (lists.indexOf('?') < 0 ? '?' : '&') + 'address=' + encodeURIComponent(r.keep) + '&note=' + encodeURIComponent(r.why) + '&for=7d';
      l.textContent = T.keepOut; a.appendChild(l);
    }
    return x;
  }
  function draw(fresh) {
    var frag = document.createDocumentFragment(), shown = 0;
    rows.forEach(function (r, i) { if (fits(r)) { shown++; frag.appendChild(tr(r, i < fresh)); } });
    body.textContent = ''; body.appendChild(frag);
    empty.hidden = shown > 0;
    count.textContent = T.shown.replace('%d', shown).replace('%d', rows.length);
  }
  function add(list) {
    list.forEach(function (r) { addHost(r.host); rows.unshift(r); });
    if (rows.length > MAX) { rows.length = MAX; }
  }
  function remember() {
    var p = new URLSearchParams(location.search);
    Object.keys(f).forEach(function (k) { if (f[k].value) { p.set(k, f[k].value); } else { p.delete(k); } });
    history.replaceState(null, '', location.pathname + (p.toString() ? '?' + p : ''));
    draw(0);
  }
  Object.keys(f).forEach(function (k) { f[k].addEventListener(k === 'q' ? 'input' : 'change', remember); });
  pause.addEventListener('click', function () {
    paused = !paused;
    if (!paused && pending.length) { var n = pending.length; add(pending); pending = []; draw(n); }
    pause.textContent = paused ? T.resume : T.pause;
  });
  function skipped(kb) {
    var p = document.getElementById('skipped'); p.hidden = !kb; if (kb) { p.textContent = T.skipped.replace('%d', kb); }
  }
  add(first.rows); draw(0);
  if (!feed || !window.fetch) { return; }
  setInterval(function () {
    if (document.hidden) { return; }
    fetch(feed + (feed.indexOf('?') < 0 ? '?' : '&') + 'cursor=' + encodeURIComponent(cursor), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) { throw r; } return r.json(); })
      .then(function (j) {
        j = j.data || j;          // the API's envelope, or a site's own feed (LiveRows::json())
        document.getElementById('error').hidden = true;
        cursor = j.cursor; skipped(j.skipped);
        if (!j.rows.length) { return; }
        if (paused) { pending = pending.concat(j.rows); pause.textContent = T.waiting.replace('%d', pending.length); return; }
        add(j.rows); draw(j.rows.length);
      })
      .catch(function () { document.getElementById('error').hidden = false; });
  }, every);
})();
JS;

    /**
     * The route (0031 B.6): the page; it asks the API's GET /live for the new rows
     * (0031 G.0). Without the API the page shows the rows of the moment it was opened.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $ctx
     */
    public static function serve(Settings $s, \CjwNetwork\RequestShield\Request $request, array $route, array $ctx): \CjwNetwork\RequestShield\Response
    {
        /** @var array<string, string> $links */
        $links = is_array($ctx['links'] ?? null) ? $ctx['links'] : [];
        /** @var array<mixed> $get */
        $get = is_array($ctx['get'] ?? null) ? $ctx['get'] : [];
        $lang = is_string($ctx['lang'] ?? null) ? $ctx['lang'] : 'auto';
        $accept = is_string($ctx['accept'] ?? null) ? $ctx['accept'] : null;
        $ip = is_string($ctx['ip'] ?? null) ? $ctx['ip'] : '';
        // The new rows come from the API (<dashboard-path>/api/v1/live), when it is there.
        $api = $s->ext['api'] ?? null;
        $feed = is_array($api) && ($api['enabled'] ?? false) === true && is_string($api['base'] ?? null) && isset($s->routes[$api['base'] . '/live'])
            ? (is_string($ctx['prefix'] ?? null) ? $ctx['prefix'] : '') . $api['base'] . '/live' : '';
        $label = is_string($ctx['homeLabel'] ?? null) ? $ctx['homeLabel'] : '';
        return \CjwNetwork\RequestShield\Response::html(200, self::render($s, ['links' => $links, 'lang' => $lang, 'accept' => $accept, 'ip' => $ip, 'home' => is_string($ctx['home'] ?? null) ? $ctx['home'] : '/', 'homeLabel' => $label,
            'feed' => $feed, 'lists' => $links['lists'] ?? null, 'title' => 'Live — ' . $label]));
    }
}
