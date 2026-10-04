<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

/**
 * Settings and decisions in plain words, for people who do not read regular
 * expressions: the rules page and the command line use it.
 */
final class Describe
{
    /** A built-in pattern, as a site owner would say it. */
    public static function builtIn(string $pattern): ?string
    {
        return Config::builtIns()[$pattern][1] ?? null;
    }

    /**
     * A rule for people: its description (the comment after it in the rule
     * file), else the pattern as written.
     */
    public static function rule(Settings $s, string $setting, string $pattern): string
    {
        $id = $s->origin($setting, $pattern);
        $text = $id !== null ? $s->origin('text', $id) : null;
        return $text ?? self::pattern($s, $pattern);
    }

    /** Where a rule is written: "site.rules:12", "built-in scanners.rules:10". */
    public static function where(Settings $s, ?string $id): ?string
    {
        return $id === null ? null : $s->origin('at', $id);
    }

    /** A path pattern as it was written in a rule file, the built-in name, or the expression. */
    public static function pattern(Settings $s, string $pattern): string
    {
        $written = $s->origin('written', $pattern);
        if ($written !== null) {
            return $written;
        }
        return self::builtIn($pattern) ?? 'regex ' . trim((string) preg_replace('/^#|#i?$/', '', $pattern));
    }

    /** "minute", "10 seconds": a unit, or so many of it (German: "Minute", "10 Sekunden"). */
    public static function duration(int $seconds, string $lang = 'en'): string
    {
        $units = $lang === 'de' ? [86400 => ['Tag', 'Tage'], 3600 => ['Stunde', 'Stunden'], 60 => ['Minute', 'Minuten'], 1 => ['Sekunde', 'Sekunden']]
            : [86400 => ['day', 'days'], 3600 => ['hour', 'hours'], 60 => ['minute', 'minutes'], 1 => ['second', 'seconds']];
        foreach ($units as $size => [$one, $many]) {
            if ($seconds % $size === 0 && $seconds > 0) {
                $n = intdiv($seconds, $size);
                return $n === 1 ? $one : "$n $many";
            }
        }
        return "$seconds " . ($lang === 'de' ? 'Sekunden' : 'seconds');
    }

    /** "1 minute", "2 hours": a length of time rather than a unit. */
    public static function span(int $seconds, string $lang = 'en'): string
    {
        $d = self::duration($seconds, $lang);
        return ctype_digit($d[0]) ? $d : ($lang === 'de' ? ($seconds % 86400 === 0 ? 'einen ' : 'eine ') : '1 ') . $d;
    }

    /** What a visitor gets, in one sentence. */
    public static function verdict(Decision $d, string $lang = 'en'): string
    {
        if ($lang === 'de') {
            return self::verdictDe($d);
        }
        switch ($d->action) {
            case Decision::ALLOW:
                return 'sees the page — a cache may keep it';
            case Decision::ALLOW_UNCACHED:
                return 'sees the page — answered by the site, but a cache must not keep it';
            case Decision::CHALLENGE:
                return 'gets the browser check first: an invisible moment, then the page (not with a valid pass)';
            case Decision::THROTTLE:
                return "has to wait {$d->retryAfter} seconds (429 Too Many Requests)";
        }
        $what = [
            400 => 'a broken request (400) — the site never sees it',
            403 => 'no access (403) — the site never sees it',
            404 => '"not found" (404) — the site never sees it',
            405 => '"not allowed here" (405) — this kind of request is not accepted at this address',
            414 => 'an address that is too long (414)',
            431 => 'too much header data (431)',
        ];
        return 'gets ' . ($what[$d->status] ?? "an error ($d->status)");
    }

    /** What a visitor gets, in German. */
    private static function verdictDe(Decision $d): string
    {
        switch ($d->action) {
            case Decision::ALLOW:
                return 'sieht die Seite — ein Cache darf sie behalten';
            case Decision::ALLOW_UNCACHED:
                return 'sieht die Seite — von der Website beantwortet, aber ein Cache darf sie nicht behalten';
            case Decision::CHALLENGE:
                return 'bekommt zuerst den Browser-Check: ein unsichtbarer Moment, dann die Seite (nicht mit gültigem Pass)';
            case Decision::THROTTLE:
                return "muss {$d->retryAfter} Sekunden warten (429 Too Many Requests)";
        }
        $what = [
            400 => 'eine kaputte Anfrage (400) — die Website sieht sie nie',
            403 => 'kein Zugriff (403) — die Website sieht sie nie',
            404 => '„nicht gefunden“ (404) — die Website sieht sie nie',
            405 => '„hier nicht erlaubt“ (405) — diese Art von Anfrage wird an dieser Adresse nicht angenommen',
            414 => 'eine zu lange Adresse (414)',
            431 => 'zu viele Header-Daten (431)',
        ];
        return 'bekommt ' . ($what[$d->status] ?? "einen Fehler ($d->status)");
    }

    /** Whether a reason names a budget (a limit's name) rather than a check. */
    public static function isBudget(string $reason): bool
    {
        return self::reason($reason) === "the budget \"$reason\"";
    }

    /** Why, in words: a decision's reason (English or German). */
    public static function reason(string $reason, string $lang = 'en'): string
    {
        if ($lang === 'de') {
            $de = [
                'blocked path' => 'eine Adresse, die nur Angreifer aufrufen', 'restricted' => 'ein Bereich nur für bestimmte Adressen',
                'method' => 'diese Art von Anfrage ist nicht erlaubt', 'method not allowed here' => 'ein Formular, gesendet wohin keines gehört',
                'cross-site' => 'ein Formular, von einer anderen Website aus gesendet', 'origin missing' => 'ein Formular, das nicht sagt, woher es kommt (weder Origin noch Referer)',
                'host' => 'ein unbekannter Name der Website', 'uri length' => 'die Adresse ist zu lang', 'query parameters' => 'zu viele Parameter',
                'header size' => 'zu viele Header-Daten', 'path encoding' => 'eine getarnte Adresse', 'path traversal' => 'ein Versuch, den Ordner der Website zu verlassen',
                'query parameter' => 'ein Parameter, den ein Cache nicht behalten darf', 'path not cacheable' => 'eine Adresse, die ein Cache nicht behalten darf',
                'unknown url' => 'eine Adresse, die die Website nicht kennt', 'always' => 'eine Seite, auf der jeder Besucher geprüft wird',
                'app' => 'die Website hat den Browser-Check verlangt (ein Formular)', 'attack' => 'ein Angriffsmuster in der Adresse oder den Headern',
                'unknown parameter' => 'ein Parameter, den die Website nicht kennt, oder ein Wert nicht seines Typs', 'challenge solved' => 'der Browser-Check wurde gerade bestanden',
                'denied' => 'eine ausgesperrte Adresse (die Sperrliste)', 'banned' => 'für eine Weile gesperrt: sie ging immer wieder über die Grenzen',
                'crawler' => 'ein bekannter Crawler, den die Website so nicht will', 'feed' => 'auf einer öffentlichen Sperrliste',
                'access' => 'ein falsches Token oder ein ungültiger Link für die Statistik',
            ];
            return $de[$reason] ?? "das Budget „{$reason}“";
        }
        $words = [
            'blocked path' => 'an address only attackers ask for',
            'restricted' => 'an area only for certain addresses',
            'method' => 'this kind of request is not accepted',
            'method not allowed here' => 'a form sent where there is none',
            'cross-site' => 'a form sent from another website',
            'origin missing' => 'a form that does not say where it comes from (neither Origin nor Referer)',
            'host' => 'an unknown website name',
            'uri length' => 'the address is too long',
            'query parameters' => 'too many parameters',
            'header size' => 'too much header data',
            'path encoding' => 'a disguised address',
            'path traversal' => 'an attempt to leave the website\'s folder',
            'query parameter' => 'a parameter a cache must not keep',
            'path not cacheable' => 'an address a cache must not keep',
            'unknown url' => 'an address the site does not know',
            'always' => 'a page where every visitor is checked',
            'app' => 'the site asked for the browser check (a form)',
            'attack' => 'an attack pattern in the address or the headers',
            'unknown parameter' => 'a parameter the site does not know, or a value not of its type',
            'challenge solved' => 'the browser check was just passed',
            'denied' => 'an address kept out (the deny list)',
            'banned' => 'banned for a while: it kept going past the limits',
            'crawler' => 'a known crawler the site does not want this way',
            'feed' => 'on a public blocklist',
            'access' => 'a wrong token or link for the statistics',
        ];
        return $words[$reason] ?? "the budget \"$reason\"";
    }

    /**
     * What a rule the statistics counted is: its ID, its description, where it
     * is written -- for "site.rules:4" too (a rule without an ID).
     *
     * @return array{id: string, text: ?string, where: ?string}
     */
    public static function ruleInfo(Settings $s, string $counted): array
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

    /** A rule ID as an anchor: letters, digits, "-", "_", "." only. */
    public static function anchor(string $id): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $id);
    }
}
