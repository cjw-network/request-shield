<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Waf;

use CjwNetwork\RequestShield\Api\ListsChanges;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Rules\Lists;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Texts;

/**
 * Keeping the lists in the dashboard (proposal 0026): both lists with a
 * search, an entry added with a comment of one's own, extended, its comment
 * changed, removed -- and the active bans, lifted with one click.
 *
 * Writes only the list files (allow.rules, deny.rules), through the same code
 * as the command line, with its guards and one more: never the address of the
 * person clicking. Changes come as POST with a token (ListsChanges::token(): HMAC of
 * the shield's secret, the viewer's address and the hour; an embedding CMS
 * may check its own form token instead, 'csrfChecked' => true). The site decides
 * who may open the page, as for the statistics.
 */
final class ListsPage implements \CjwNetwork\RequestShield\RoutePage
{
    /** Entries shown at most; the search narrows a long list. */
    public const SHOWN = 200;

    private const T = [
        'en' => [
            'title' => 'Lists', 'intro' => 'Addresses kept out (403 before every other check) and let in (never counted or checked, still refused for what only attackers ask for). Every server reads a change within its recheck.',
                        'add' => 'Add an entry', 'kind' => 'List', 'deny' => 'keep out', 'exempt' => 'let in', 'address' => 'Address or range', 'for' => 'For', 'note' => 'Comment',
            'notePh' => 'why — say why, not who', 'f.1h' => '1 hour', 'f.1d' => '1 day', 'f.7d' => '7 days', 'f.30d' => '30 days', 'f.date' => 'until …',             'confirm' => 'a wide range: I mean it', 'save' => 'Add', 'entries' => 'The entries', 'search' => 'Search', 'searchPh' => 'address, ID or comment',
            'none' => 'No entries yet: add an address above, or keep one out from a row of the live view.', 'more' => '%s more — narrow the search.', 'id' => 'ID', 'until' => 'Until', 'added' => 'Added', 'good' => 'for good — review now and then',
            'ended' => 'ended', 'extend' => 'Extend', 'keepTime' => 'as it is', 'change' => 'Save', 'remove' => 'Remove',
            'ruleFiles' => 'Entries written in the rule files themselves are shown on the rules page and are not changed here.',
            'bans' => 'Active bans', 'bansNone' => 'No address is banned right now: a ban rule (ban …) bans an address for a while when it keeps knocking at closed doors.', 'bansApcu' => 'Bans of this server (APCu: each server has its own).',
            'lift' => 'Lift', 'again' => 'banned %d times today — keep out for good?', 'banned' => 'Address (network)',
                                                                                ],
        'de' => [
            'title' => 'Listen', 'intro' => 'Ausgesperrte Adressen (403 vor jeder anderen Prüfung) und hereingelassene (nie gezählt oder geprüft, für das, was nur Angreifer aufrufen, trotzdem abgewiesen). Jeder Server liest eine Änderung bei seiner nächsten Prüfung.',
                        'add' => 'Eintrag hinzufügen', 'kind' => 'Liste', 'deny' => 'aussperren', 'exempt' => 'hereinlassen', 'address' => 'Adresse oder Bereich', 'for' => 'Für', 'note' => 'Kommentar',
            'notePh' => 'warum — sagen Sie warum, nicht wer', 'f.1h' => '1 Stunde', 'f.1d' => '1 Tag', 'f.7d' => '7 Tage', 'f.30d' => '30 Tage', 'f.date' => 'bis …',             'confirm' => 'ein großer Bereich: so gewollt', 'save' => 'Hinzufügen', 'entries' => 'Die Einträge', 'search' => 'Suchen', 'searchPh' => 'Adresse, ID oder Kommentar',
            'none' => 'Noch keine Einträge: oben eine Adresse eintragen, oder eine aus einer Zeile der Live-Ansicht aussperren.', 'more' => '%s weitere — die Suche eingrenzen.', 'id' => 'ID', 'until' => 'Bis', 'added' => 'Eingetragen', 'good' => 'dauerhaft — ab und zu prüfen',
            'ended' => 'abgelaufen', 'extend' => 'Verlängern', 'keepTime' => 'wie es ist', 'change' => 'Speichern', 'remove' => 'Entfernen',
            'ruleFiles' => 'Einträge, die in den Regeldateien selbst stehen, zeigt die Regelseite; sie werden hier nicht geändert.',
            'bans' => 'Aktive Sperren', 'bansNone' => 'Gerade ist keine Adresse gesperrt: Eine Sperrregel (ban …) sperrt eine Adresse für eine Weile, wenn sie immer wieder an verschlossene Türen klopft.', 'bansApcu' => 'Sperren dieses Servers (APCu: jeder Server hat seine eigenen).',
            'lift' => 'Aufheben', 'again' => 'heute %d-mal gesperrt — dauerhaft aussperren?', 'banned' => 'Adresse (Netz)',
                                                                                ],
    ];

    /**
     * The page.
     *
     * @param array<string, mixed> $o action (where the forms are sent), ip (the viewer's address), csrf (a token of the
     *                                site's own; else token()), get ($_GET: address, note, for to fill the form; q to search),
     *                                message (handle()'s answer), links, lang, accept, store, title, home, homeLabel, now
     */
    public static function render(Settings $s, array $o): string
    {
        $lang = Texts::language(is_string($o['lang'] ?? null) ? $o['lang'] : 'auto', is_string($o['accept'] ?? null) ? $o['accept'] : null);
        $lang = $lang === 'de' ? 'de' : 'en';
        $t = self::T[$lang] + ListsChanges::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $now = is_int($o['now'] ?? null) ? $o['now'] : time();
        $ip = is_string($o['ip'] ?? null) ? $o['ip'] : '';
        $action = is_string($o['action'] ?? null) ? $o['action'] : '';
        $get = is_array($o['get'] ?? null) ? $o['get'] : [];
        $g = static fn (string $k): string => is_string($get[$k] ?? null) ? $get[$k] : '';
        $token = is_string($o['csrf'] ?? null) ? $o['csrf'] : ListsChanges::token($s, $ip, $now);
        $hidden = static fn (string $do, array $more = []): string => '<input type="hidden" name="token" value="' . $e($token) . '"><input type="hidden" name="do" value="' . $do . '">'
            . implode('', array_map(static fn ($k, $v): string => '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e(is_scalar($v) ? (string) $v : '') . '">', array_keys($more), $more));
        /** @var array<string, string> $links */
        $links = array_filter((array) ($o['links'] ?? []), 'is_string');
        $o['help'] = Help::link('RSF06-02', '', $s->docsUrl, $lang);
        $h = Frame::tabs($s, $links, 'lists', $lang) . '<p class="note">' . $e($t['intro']) . '</p>';
        $msg = is_array($o['message'] ?? null) ? $o['message'] : null;
        if ($msg !== null && is_string($msg['message'] ?? null)) {
            $h .= '<div class="msg ' . (($msg['ok'] ?? false) ? 'ok' : 'bad') . '" role="status">' . $e($msg['message']) . '</div>';
        }
        $title = is_string($o['title'] ?? null) ? $o['title'] : $t['title'];
        $dir = $s->listsDir;
        if ($dir === null) {
            return Frame::page($title, $lang, $h . '<div class="msg bad">' . $e($t['noDir']) . '</div>', $o, self::CSS);
        }
        // Add: prefilled from the live view ("keep out" on a row).
        $for = array_key_exists($g('for'), ListsChanges::FOR) ? $g('for') : '7d';
        $fors = '';
        foreach (['1h', '1d', '7d', '30d', 'date', 'good'] as $f) {
            $fors .= '<option value="' . $f . '"' . ($f === $for ? ' selected' : '') . '>' . $e($t['f.' . $f]) . '</option>';
        }
        $h .= '<form class="card add" method="post" action="' . $e($action) . '">' . Frame::h2($t['add'], $s, 'RSF01-02', 'the-list-files', $lang) . $hidden('add')
            . '<label>' . $e($t['kind']) . ' <select name="kind"><option value="deny"' . ($g('kind') !== 'exempt' ? ' selected' : '') . '>' . $e($t['deny']) . '</option><option value="exempt"'
            . ($g('kind') === 'exempt' ? ' selected' : '') . '>' . $e($t['exempt']) . '</option></select></label>'
            . '<label>' . $e($t['address']) . ' <input name="address" required maxlength="64" class="mono" value="' . $e($g('address')) . '" placeholder="203.0.113.7, 198.51.100.0/24"></label>'
            . '<label>' . $e($t['for']) . ' <span class="pair"><select name="for">' . $fors . '</select><input type="date" name="until" aria-label="' . $e($t['f.date']) . '"></span></label>'
            . '<label class="wide">' . $e($t['note']) . ' <input name="note" maxlength="200" value="' . $e(Lists::note($g('note'))) . '" placeholder="' . $e($t['notePh']) . '"></label>'
            . '<label class="small"><input type="checkbox" name="confirm" value="1"> ' . $e($t['confirm']) . '</label>'
            . '<button class="primary">' . $e($t['save']) . '</button></form>';

        // The entries: newest first, searched.
        $q = $g('q');
        $found = Lists::find($dir, $q, self::SHOWN);
        $h .= '<div class="card">' . Frame::h2($t['entries'], $s, 'RSF01-02', 'the-list-files', $lang) . '<form method="get" action="' . $e($action) . '" class="search">'
            . '<input type="search" name="q" value="' . $e($q) . '" placeholder="' . $e($t['searchPh']) . '"><input type="hidden" name="lang" value="' . $lang . '"><button>' . $e($t['search']) . '</button></form>';
        if ($found['entries'] === []) {
            $h .= '<p class="note">' . $e($t['none']) . '</p>';
        } else {
            $h .= '<div class="wrap"><table><thead><tr><th>' . $e($t['kind']) . '</th><th>' . $e($t['id']) . '</th><th>' . $e($t['address']) . '</th><th>' . $e($t['note'])
                . '</th><th>' . $e($t['until']) . '</th><th></th></tr></thead><tbody>';
            foreach ($found['entries'] as $entry) {
                [$note, $stamp] = Lists::split($entry['note']);
                $state = $entry['until'] === null ? '<span class="review">' . $e($t['good']) . '</span>'
                    : ($entry['until'] > $now ? $e(ListsChanges::date($entry['until'], $lang)) : '<span class="note">' . $e($t['ended'] . ' ' . ListsChanges::date($entry['until'], $lang)) . '</span>');
                $extend = '<option value="">' . $e($t['keepTime']) . '</option>';
                foreach (['1d', '7d', '30d'] as $f) {
                    $extend .= '<option value="' . $f . '">+ ' . $e($t['f.' . $f]) . '</option>';
                }
                if ($entry['kind'] === 'deny') {
                    $extend .= '<option value="good">' . $e($t['f.good']) . '</option>';
                }
                $h .= '<tr><td><span class="badge ' . $entry['kind'] . '">' . $e($t[$entry['kind']]) . '</span></td><td class="mono">' . $e($entry['id']) . '</td>'
                    . '<td class="mono">' . $e(implode(' ', $entry['addresses'])) . '</td>'
                    . '<td><form method="post" action="' . $e($action) . '" class="inline">' . $hidden('update', ['id' => $entry['id']])
                    . '<input name="note" maxlength="200" value="' . $e($note) . '" aria-label="' . $e($t['note']) . '"> <select name="for" aria-label="' . $e($t['extend']) . '">' . $extend . '</select>'
                    . ' <button>' . $e($t['change']) . '</button></form><span class="stamp">' . $e($stamp) . '</span></td>'
                    . '<td>' . $state . '</td>'
                    . '<td><form method="post" action="' . $e($action) . '">' . $hidden('remove', ['id' => $entry['id']]) . '<button class="danger">' . $e($t['remove']) . '</button></form></td></tr>';
            }
            $h .= '</tbody></table></div>';
            if ($found['total'] > count($found['entries'])) {
                $h .= '<p class="note">' . $e(sprintf($t['more'], number_format($found['total'] - count($found['entries']), 0, '', $lang === 'de' ? '.' : ','))) . '</p>';
            }
        }
        $h .= '<p class="note">' . $e($t['ruleFiles']) . (isset($links['rules']) ? ' <a href="' . $e($links['rules']) . '">→</a>' : '') . '</p></div>';

        // The active bans, from the store.
        $store = ListsChanges::store($s, $o);
        $bans = $s->bans === [] ? [] : $store->marks('ban:', (float) $now);
        arsort($bans);
        $h .= '<div class="card">' . Frame::h2($t['bans'], $s, 'RSF01-02', 'bans', $lang);
        if ($bans === []) {
            $h .= '<p class="note">' . $e($t['bansNone']) . '</p>';
        } else {
            $h .= '<div class="wrap"><table><thead><tr><th>' . $e($t['banned']) . '</th><th>' . $e($t['until']) . '</th><th></th><th></th></tr></thead><tbody>';
            foreach ($bans as $key => $until) {
                $bucket = substr($key, 4);
                $times = (int) round($store->peek('bans:' . $bucket, 86400, (float) $now));
                $address = $bucket;                                 // IPv4 as it is, IPv6 its /64 (IpAddress::bucket())
                $again = $times >= 3 && $action !== '' ? ' <a href="' . $e($action . (strpos($action, '?') === false ? '?' : '&') . http_build_query(['address' => $address, 'for' => 'good', 'note' => sprintf($t['again'], $times)])) . '">'
                    . $e(sprintf($t['again'], $times)) . '</a>' : '';
                $h .= '<tr><td class="mono">' . $e($address) . '</td><td>' . $e(ListsChanges::date($until, $lang)) . '</td><td>' . $again . '</td>'
                    . '<td><form method="post" action="' . $e($action) . '">' . $hidden('lift', ['bucket' => $bucket]) . '<button>' . $e($t['lift']) . '</button></form></td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        if ($s->store !== 'file' && $s->bans !== []) {
            $h .= '<p class="note">' . $e($t['bansApcu']) . '</p>';
        }
        $h .= '</div>';
        return Frame::page($title, $lang, $h, $o, self::CSS);
    }

    private const CSS = <<<'CSS'
.add{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:end}.add h2{width:100%;margin:0}.add label{display:flex;flex-direction:column;gap:2px;font-size:13px;color:var(--m)}
.add label.wide{flex:1;min-width:240px}.pair{display:flex;gap:4px}.card h2{margin-top:0}.add label.small{flex-direction:row;align-items:center;gap:6px}.add input[name=address]{width:230px}
.search{display:flex;gap:6px;margin:0 0 8px}.search input{flex:1;max-width:360px}.inline{display:flex;flex-wrap:wrap;gap:4px}.inline input{min-width:160px;flex:1}
.stamp{display:block;color:var(--m);font-size:12px;margin-top:2px}.review{color:var(--throttled)}.badge.deny{border-color:var(--refused);color:var(--refused)}.badge.exempt{border-color:var(--through);color:var(--through)}
CSS;

    /**
     * The route (0031 B.6): a POST is a change (handle(), with the page's token), then the page.
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
        /** @var array<mixed> $post */
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : [];
        $lang = is_string($ctx['lang'] ?? null) ? $ctx['lang'] : 'auto';
        $accept = is_string($ctx['accept'] ?? null) ? $ctx['accept'] : null;
        $ip = is_string($ctx['ip'] ?? null) ? $ctx['ip'] : '';
        $who = is_string($ctx['who'] ?? null) ? $ctx['who'] : '*';
        $message = ($ctx['method'] ?? 'GET') === 'POST'
            ? ListsChanges::handle($s, $post, ['ip' => $ip, 'ruleFile' => $ctx['ruleFile'] ?? null, 'lang' => Texts::language($lang, $accept), 'user' => $who === '*' ? '' : $who])
            : null;
        $label = is_string($ctx['homeLabel'] ?? null) ? $ctx['homeLabel'] : '';
        return \CjwNetwork\RequestShield\Response::html(200, self::render($s, ['action' => $links['lists'] ?? ((is_string($ctx['prefix'] ?? null) ? $ctx['prefix'] : '') . (is_string($route['path'] ?? null) ? $route['path'] : '')), 'get' => $get, 'message' => $message,
            'links' => $links, 'lang' => $lang, 'accept' => $accept, 'ip' => $ip, 'home' => is_string($ctx['home'] ?? null) ? $ctx['home'] : '/', 'homeLabel' => $label, 'title' => 'Lists — ' . $label]));
    }
}
