<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api;

use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\Rules\Lists;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\Store;

/**
 * Changing the lists (proposal 0026; POST /lists/…): an entry added with a
 * comment, extended, changed, removed, a ban lifted -- through the same code
 * as the command line, with its guards and one more: never the address of
 * the person asking. A form's token (token()) is an HMAC of the shield's
 * secret, the viewer's address and the hour. The dashboard's lists page
 * (plugins/waf) sends its forms here.
 */
final class ListsChanges
{
    public const FOR = ['1h' => 3600, '1d' => 86400, '7d' => 604800, '30d' => 2592000];

    /** What a change answers. */
    public const T = [
        'en' => [
            'f.good' => 'for good (keep out only)',
            'm.added' => '%s: %s added (%s).',
            'm.allowEnd' => 'An address let in needs an end — one let in for good belongs in the rule file (exempt).',
            'm.changed' => '%s changed.',
            'm.goodNote' => 'An entry for good needs a comment: why.',
            'm.lifted' => 'The ban of %s is lifted.',
            'm.noBan' => '%s is not banned.',
            'm.noEntry' => 'There is no entry %s.',
            'm.removed' => '%s removed.',
            'm.token' => 'The form was too old or not from this page — please try again.',
            'm.until' => 'Pick a day in the future.',
            'm.what' => 'Nothing to do.',
            'noDir' => 'There is no lists directory: set lists-dir (or store-dir) in the rule file.',
            'r.proxy' => '%s holds a trusted proxy (%s) — every visitor behind it would be kept out.',
            'r.self' => '%s holds your own address (%s) — you would lock yourself out.',
            'r.wide' => '%s is a wide range — many people may be behind it. Tick "a wide range: I mean it" if that is meant.%s',
            'untilAt' => 'until %s',
        ],
        'de' => [
            'f.good' => 'dauerhaft (nur aussperren)',
            'm.added' => '%s: %s eingetragen (%s).',
            'm.allowEnd' => 'Eine hereingelassene Adresse braucht ein Ende — eine dauerhaft hereingelassene gehört in die Regeldatei (exempt).',
            'm.changed' => '%s geändert.',
            'm.goodNote' => 'Ein dauerhafter Eintrag braucht einen Kommentar: warum.',
            'm.lifted' => 'Die Sperre von %s ist aufgehoben.',
            'm.noBan' => '%s ist nicht gesperrt.',
            'm.noEntry' => 'Es gibt keinen Eintrag %s.',
            'm.removed' => '%s entfernt.',
            'm.token' => 'Das Formular war zu alt oder nicht von dieser Seite — bitte noch einmal.',
            'm.until' => 'Bitte einen Tag in der Zukunft wählen.',
            'm.what' => 'Nichts zu tun.',
            'noDir' => 'Es gibt kein Listen-Verzeichnis: lists-dir (oder store-dir) in der Regeldatei setzen.',
            'r.proxy' => '%s enthält einen vertrauenswürdigen Proxy (%s) — jeder Besucher dahinter wäre ausgesperrt.',
            'r.self' => '%s enthält Ihre eigene Adresse (%s) — Sie würden sich selbst aussperren.',
            'r.wide' => '%s ist ein großer Bereich — dahinter können viele Menschen sein. „Ein großer Bereich: so gewollt“ ankreuzen, wenn das gemeint ist.%s',
            'untilAt' => 'bis %s',
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

    public static function date(int $t, string $lang): string
    {
        return date($lang === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i', $t);
    }

    /** @param array<string, mixed> $o */
    public static function store(Settings $s, array $o): Store
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
}
