<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Rules\Lists;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\Store;
use CjwNetwork\RequestShield\Texts;

/**
 * Keeping the lists in the dashboard (proposal 0026): both lists with a
 * search, an entry added with a comment of one's own, extended, its comment
 * changed, removed -- and the active bans, lifted with one click.
 *
 * Writes only the list files (allow.rules, deny.rules), through the same code
 * as the command line, with its guards and one more: never the address of the
 * person clicking. Changes come as POST with a token (token(): HMAC of the
 * shield's secret, the viewer's address and the hour; an embedding CMS may
 * check its own form token instead, 'csrfChecked' => true). The site decides
 * who may open the page, as for the statistics.
 */
final class ListsPage
{
    /** Entries shown at most; the search narrows a long list. */
    public const SHOWN = 200;

    private const FOR = ['1h' => 3600, '1d' => 86400, '7d' => 604800, '30d' => 2592000];

    private const T = [
        'en' => [
            'title' => 'Lists', 'intro' => 'Addresses kept out (403 before every other check) and let in (never counted or checked, still refused for what only attackers ask for). Every server reads a change within its recheck.',
            'noDir' => 'There is no lists directory: set lists-dir (or store-dir) in the rule file.',
            'add' => 'Add an entry', 'kind' => 'List', 'deny' => 'keep out', 'exempt' => 'let in', 'address' => 'Address or range', 'for' => 'For', 'note' => 'Comment',
            'notePh' => 'why — say why, not who', 'f.1h' => '1 hour', 'f.1d' => '1 day', 'f.7d' => '7 days', 'f.30d' => '30 days', 'f.date' => 'until …', 'f.good' => 'for good (keep out only)',
            'confirm' => 'a wide range: I mean it', 'save' => 'Add', 'entries' => 'The entries', 'search' => 'Search', 'searchPh' => 'address, ID or comment',
            'none' => 'No entries yet.', 'more' => '%s more — narrow the search.', 'id' => 'ID', 'until' => 'Until', 'added' => 'Added', 'good' => 'for good — review now and then',
            'ended' => 'ended', 'extend' => 'Extend', 'keepTime' => 'as it is', 'change' => 'Save', 'remove' => 'Remove',
            'ruleFiles' => 'Entries written in the rule files themselves are shown on the rules page and are not changed here.',
            'bans' => 'Active bans', 'bansNone' => 'No address is banned right now.', 'bansApcu' => 'Bans of this server (APCu: each server has its own).',
            'lift' => 'Lift', 'again' => 'banned %d times today — keep out for good?', 'banned' => 'Address (network)',
            'm.added' => '%s: %s added (%s).', 'm.changed' => '%s changed.', 'm.removed' => '%s removed.', 'm.lifted' => 'The ban of %s is lifted.', 'm.noBan' => '%s is not banned.',
            'm.token' => 'The form was too old or not from this page — please try again.', 'm.noEntry' => 'There is no entry %s.', 'm.until' => 'Pick a day in the future.',
            'm.allowEnd' => 'An address let in needs an end — one let in for good belongs in the rule file (exempt).', 'm.goodNote' => 'An entry for good needs a comment: why.',
            'm.what' => 'Nothing to do.', 'untilAt' => 'until %s',
            'r.proxy' => '%s holds a trusted proxy (%s) — every visitor behind it would be kept out.', 'r.self' => '%s holds your own address (%s) — you would lock yourself out.',
            'r.wide' => '%s is a wide range — many people may be behind it. Tick "a wide range: I mean it" if that is meant.%s',
        ],
        'de' => [
            'title' => 'Listen', 'intro' => 'Ausgesperrte Adressen (403 vor jeder anderen Prüfung) und hereingelassene (nie gezählt oder geprüft, für das, was nur Angreifer aufrufen, trotzdem abgewiesen). Jeder Server liest eine Änderung bei seiner nächsten Prüfung.',
            'noDir' => 'Es gibt kein Listen-Verzeichnis: lists-dir (oder store-dir) in der Regeldatei setzen.',
            'add' => 'Eintrag hinzufügen', 'kind' => 'Liste', 'deny' => 'aussperren', 'exempt' => 'hereinlassen', 'address' => 'Adresse oder Bereich', 'for' => 'Für', 'note' => 'Kommentar',
            'notePh' => 'warum — sagen Sie warum, nicht wer', 'f.1h' => '1 Stunde', 'f.1d' => '1 Tag', 'f.7d' => '7 Tage', 'f.30d' => '30 Tage', 'f.date' => 'bis …', 'f.good' => 'dauerhaft (nur aussperren)',
            'confirm' => 'ein großer Bereich: so gewollt', 'save' => 'Hinzufügen', 'entries' => 'Die Einträge', 'search' => 'Suchen', 'searchPh' => 'Adresse, ID oder Kommentar',
            'none' => 'Noch keine Einträge.', 'more' => '%s weitere — die Suche eingrenzen.', 'id' => 'ID', 'until' => 'Bis', 'added' => 'Eingetragen', 'good' => 'dauerhaft — ab und zu prüfen',
            'ended' => 'abgelaufen', 'extend' => 'Verlängern', 'keepTime' => 'wie es ist', 'change' => 'Speichern', 'remove' => 'Entfernen',
            'ruleFiles' => 'Einträge, die in den Regeldateien selbst stehen, zeigt die Regelseite; sie werden hier nicht geändert.',
            'bans' => 'Aktive Sperren', 'bansNone' => 'Gerade ist keine Adresse gesperrt.', 'bansApcu' => 'Sperren dieses Servers (APCu: jeder Server hat seine eigenen).',
            'lift' => 'Aufheben', 'again' => 'heute %d-mal gesperrt — dauerhaft aussperren?', 'banned' => 'Adresse (Netz)',
            'm.added' => '%s: %s eingetragen (%s).', 'm.changed' => '%s geändert.', 'm.removed' => '%s entfernt.', 'm.lifted' => 'Die Sperre von %s ist aufgehoben.', 'm.noBan' => '%s ist nicht gesperrt.',
            'm.token' => 'Das Formular war zu alt oder nicht von dieser Seite — bitte noch einmal.', 'm.noEntry' => 'Es gibt keinen Eintrag %s.', 'm.until' => 'Bitte einen Tag in der Zukunft wählen.',
            'm.allowEnd' => 'Eine hereingelassene Adresse braucht ein Ende — eine dauerhaft hereingelassene gehört in die Regeldatei (exempt).', 'm.goodNote' => 'Ein dauerhafter Eintrag braucht einen Kommentar: warum.',
            'm.what' => 'Nichts zu tun.', 'untilAt' => 'bis %s',
            'r.proxy' => '%s enthält einen vertrauenswürdigen Proxy (%s) — jeder Besucher dahinter wäre ausgesperrt.', 'r.self' => '%s enthält Ihre eigene Adresse (%s) — Sie würden sich selbst aussperren.',
            'r.wide' => '%s ist ein großer Bereich — dahinter können viele Menschen sein. „Ein großer Bereich: so gewollt“ ankreuzen, wenn das gemeint ist.%s',
        ],
    ];

    /** The form token for $ip in this hour: an HMAC of the shield's secret -- never the secret itself. */
    public static function token(Settings $s, string $ip, ?int $now = null): string
    {
        return self::sign($s, $ip, intdiv($now ?? time(), 3600));
    }

    /** A token from this hour or the last one. */
    public static function verify(Settings $s, string $ip, string $token, ?int $now = null): bool
    {
        $hour = intdiv($now ?? time(), 3600);
        return $token !== '' && (hash_equals(self::sign($s, $ip, $hour), $token) || hash_equals(self::sign($s, $ip, $hour - 1), $token));
    }

    private static function sign(Settings $s, string $ip, int $hour): string
    {
        return substr(hash_hmac('sha256', "lists|$ip|$hour", Secret::resolve($s->challenge->secret, $s->storeDir)), 0, 32);
    }

    /**
     * A change sent by the page's forms (POST): add, update, remove, lift.
     *
     * @param array<mixed> $post $_POST
     * @param array<string, mixed> $o ip (the viewer's address, required), user (who, for the note), ruleFile (touched after
     *                                writing, so every server reads the lists), store, csrfChecked, lang, now
     * @return array{ok: bool, message: string}
     */
    public static function handle(Settings $s, array $post, array $o): array
    {
        $lang = ($o['lang'] ?? 'en') === 'de' ? 'de' : 'en';
        $t = self::T[$lang];
        $now = is_int($o['now'] ?? null) ? $o['now'] : time();
        $ip = is_string($o['ip'] ?? null) ? $o['ip'] : '';
        $v = static fn (string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
        if (!($o['csrfChecked'] ?? false) && !self::verify($s, $ip, $v('token'), $now)) {
            return ['ok' => false, 'message' => $t['m.token']];
        }
        $dir = $s->listsDir;
        if ($dir === null) {
            return ['ok' => false, 'message' => $t['noDir']];
        }
        $by = 'dashboard' . (is_string($o['user'] ?? null) && $o['user'] !== '' ? ' ' . $o['user'] : '');
        try {
            switch ($v('do')) {
                case 'add':
                    $kind = $v('kind') === 'exempt' ? 'exempt' : 'deny';
                    $address = $v('address');
                    Lists::check($address);
                    $until = self::until($v('for'), $v('until'), $now);
                    if ($until === false) {
                        return ['ok' => false, 'message' => $t['m.until']];
                    }
                    if ($kind === 'exempt' && $until === null) {
                        return ['ok' => false, 'message' => $t['m.allowEnd']];
                    }
                    if ($until === null && Lists::note($v('note')) === '') {
                        return ['ok' => false, 'message' => $t['m.goodNote']];
                    }
                    if ($kind === 'deny' && ($why = Lists::refusal($address, $s->trustedProxies, $ip, $v('confirm') !== '')) !== null) {
                        $text = match ($why[0]) {
                            'proxy' => $t['r.proxy'],
                            'self' => $t['r.self'],
                            default => $t['r.wide'],
                        };
                        return ['ok' => false, 'message' => sprintf($text, $address, $why[1])];
                    }
                    $id = Lists::add($dir, $kind, $address, $until, $v('note'), $by);
                    self::touch($o);
                    return ['ok' => true, 'message' => sprintf($t['m.added'], $id, $address, $until === null ? $t['f.good'] : sprintf($t['untilAt'], self::date($until, $lang)))];
                case 'update':
                    $id = $v('id');
                    $until = $v('for') === '' ? null : self::until($v('for'), $v('until'), $now);
                    if ($until === false) {
                        return ['ok' => false, 'message' => $t['m.until']];
                    }
                    $entry = null;
                    foreach (Lists::find($dir, "[$id]", 1)['entries'] as $e) {
                        $entry = $e['id'] === $id ? $e : null;
                    }
                    if ($entry === null) {
                        return ['ok' => false, 'message' => sprintf($t['m.noEntry'], $id)];
                    }
                    $note = array_key_exists('note', $post) ? $v('note') : null;
                    if ($v('for') === 'good' && $entry['kind'] === 'exempt') {
                        return ['ok' => false, 'message' => $t['m.allowEnd']];
                    }
                    if ($v('for') === 'good' && Lists::note($note ?? Lists::split($entry['note'])[0]) === '') {
                        return ['ok' => false, 'message' => $t['m.goodNote']];
                    }
                    Lists::update($dir, $id, $v('for') === 'good' ? 0 : $until, $note, $by);
                    self::touch($o);
                    return ['ok' => true, 'message' => sprintf($t['m.changed'], $id)];
                case 'remove':
                    if (!Lists::removeId($dir, $v('id'))) {
                        return ['ok' => false, 'message' => sprintf($t['m.noEntry'], $v('id'))];
                    }
                    self::touch($o);
                    return ['ok' => true, 'message' => sprintf($t['m.removed'], $v('id'))];
                case 'lift':
                    if (!Shield::liftBan($s, self::store($s, $o), $v('bucket'), (float) $now)) {
                        return ['ok' => false, 'message' => sprintf($t['m.noBan'], $v('bucket'))];
                    }
                    return ['ok' => true, 'message' => sprintf($t['m.lifted'], $v('bucket'))];
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return ['ok' => false, 'message' => $t['m.what']];
    }

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
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $now = is_int($o['now'] ?? null) ? $o['now'] : time();
        $ip = is_string($o['ip'] ?? null) ? $o['ip'] : '';
        $action = is_string($o['action'] ?? null) ? $o['action'] : '';
        $get = is_array($o['get'] ?? null) ? $o['get'] : [];
        $g = static fn (string $k): string => is_string($get[$k] ?? null) ? $get[$k] : '';
        $token = is_string($o['csrf'] ?? null) ? $o['csrf'] : self::token($s, $ip, $now);
        $hidden = static fn (string $do, array $more = []): string => '<input type="hidden" name="token" value="' . $e($token) . '"><input type="hidden" name="do" value="' . $do . '">'
            . implode('', array_map(static fn ($k, $v): string => '<input type="hidden" name="' . $e((string) $k) . '" value="' . $e(is_scalar($v) ? (string) $v : '') . '">', array_keys($more), $more));
        /** @var array<string, string> $links */
        $links = array_filter((array) ($o['links'] ?? []), 'is_string');
        $h = Frame::tabs($links, 'lists', $lang) . '<p class="note">' . $e($t['intro']) . '</p>';
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
        $for = array_key_exists($g('for'), self::FOR) ? $g('for') : '7d';
        $fors = '';
        foreach (['1h', '1d', '7d', '30d', 'date', 'good'] as $f) {
            $fors .= '<option value="' . $f . '"' . ($f === $for ? ' selected' : '') . '>' . $e($t['f.' . $f]) . '</option>';
        }
        $h .= '<form class="card add" method="post" action="' . $e($action) . '"><h2>' . $e($t['add']) . '</h2>' . $hidden('add')
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
        $h .= '<div class="card"><h2>' . $e($t['entries']) . '</h2><form method="get" action="' . $e($action) . '" class="search">'
            . '<input type="search" name="q" value="' . $e($q) . '" placeholder="' . $e($t['searchPh']) . '"><input type="hidden" name="lang" value="' . $lang . '"><button>' . $e($t['search']) . '</button></form>';
        if ($found['entries'] === []) {
            $h .= '<p class="note">' . $e($t['none']) . '</p>';
        } else {
            $h .= '<div class="wrap"><table><thead><tr><th>' . $e($t['kind']) . '</th><th>' . $e($t['id']) . '</th><th>' . $e($t['address']) . '</th><th>' . $e($t['note'])
                . '</th><th>' . $e($t['until']) . '</th><th></th></tr></thead><tbody>';
            foreach ($found['entries'] as $entry) {
                [$note, $stamp] = Lists::split($entry['note']);
                $state = $entry['until'] === null ? '<span class="review">' . $e($t['good']) . '</span>'
                    : ($entry['until'] > $now ? $e(self::date($entry['until'], $lang)) : '<span class="note">' . $e($t['ended'] . ' ' . self::date($entry['until'], $lang)) . '</span>');
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
        $store = self::store($s, $o);
        $bans = $s->bans === [] ? [] : $store->marks('ban:', (float) $now);
        arsort($bans);
        $h .= '<div class="card"><h2>' . $e($t['bans']) . '</h2>';
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
                $h .= '<tr><td class="mono">' . $e($address) . '</td><td>' . $e(self::date($until, $lang)) . '</td><td>' . $again . '</td>'
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

    /** Seconds from now, a day (to its end), for good (null) -- or false for a day that is not in the future. */
    private static function until(string $for, string $date, int $now): int|false|null
    {
        if (isset(self::FOR[$for])) {
            return $now + self::FOR[$for];
        }
        if ($for === 'good') {
            return null;
        }
        $until = Lists::time($date);
        return $until !== null && $until > $now ? $until : false;
    }

    private static function date(int $t, string $lang): string
    {
        return date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $t);
    }

    /** @param array<string, mixed> $o */
    private static function store(Settings $s, array $o): Store
    {
        return ($o['store'] ?? null) instanceof Store ? $o['store'] : Shield::storeFor($s);
    }

    /** @param array<string, mixed> $o */
    private static function touch(array $o): void
    {
        if (is_string($o['ruleFile'] ?? null) && is_file($o['ruleFile'])) {
            @touch($o['ruleFile']);
        }
    }

    private const CSS = <<<'CSS'
.add{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:end}.add h2{width:100%;margin:0}.add label{display:flex;flex-direction:column;gap:2px;font-size:13px;color:var(--m)}
.add label.wide{flex:1;min-width:240px}.pair{display:flex;gap:4px}.card h2{margin-top:0}.add label.small{flex-direction:row;align-items:center;gap:6px}.add input[name=address]{width:230px}
.search{display:flex;gap:6px;margin:0 0 8px}.search input{flex:1;max-width:360px}.inline{display:flex;flex-wrap:wrap;gap:4px}.inline input{min-width:160px;flex:1}
.stamp{display:block;color:var(--m);font-size:12px;margin-top:2px}.review{color:var(--throttled)}.badge.deny{border-color:var(--refused);color:var(--refused)}.badge.exempt{border-color:var(--through);color:var(--through)}
CSS;
}
