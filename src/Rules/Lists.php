<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

/**
 * The list files (proposal 0013): allow.rules (addresses let in -- exempt)
 * and deny.rules (kept out) in the lists directory, one entry per line with
 * its reason, who added it and when:
 *
 *   [LIST-D3] deny 203.0.113.7 until 2026-10-07T15:30   # scraper · cli 2026-10-01 09:12
 *
 * Written only here (the command line, later the dashboard), always whole: a
 * temporary file, then renamed -- a server never reads half a list. The rule
 * reader accepts nothing but deny and exempt lines in them.
 */
final class Lists
{
    public const FILES = ['exempt' => 'allow.rules', 'deny' => 'deny.rules'];

    /**
     * The entries of both files.
     *
     * @return list<array{kind: string, id: string, addresses: list<string>, until: ?int, note: string}>
     */
    public static function read(string $dir): array
    {
        $out = [];
        foreach (self::FILES as $kind => $name) {
            foreach (@file("$dir/$name", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $e = self::parse($line, $kind);
                if ($e !== null) {
                    $out[] = $e;
                }
            }
        }
        return $out;
    }

    /**
     * Adds an entry and returns its ID (LIST-D<n>, LIST-A<n>).
     *
     * @param string $kind deny or exempt
     */
    public static function add(string $dir, string $kind, string $address, ?int $until, string $note, string $by = 'cli'): string
    {
        if (!isset(self::FILES[$kind])) {
            throw new \InvalidArgumentException("a list is deny or exempt, not $kind");
        }
        self::check($address);
        $prefix = $kind === 'deny' ? 'LIST-D' : 'LIST-A';
        $next = 1;
        foreach (self::read($dir) as $e) {
            if (strncmp($e['id'], $prefix, strlen($prefix)) === 0) {
                $next = max($next, (int) substr($e['id'], strlen($prefix)) + 1);
            }
        }
        $id = $prefix . $next;
        $line = self::line($id, $kind, [$address], $until, self::note($note), self::by($by) . ' ' . date('Y-m-d H:i'));
        $file = $dir . '/' . self::FILES[$kind];
        $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $lines[] = $line;
        self::write($file, $lines);
        return $id;
    }

    /**
     * Changes an entry: a new end (null: keep it; 0: for good) and/or a new
     * comment (null: keep it). Who changed it and when is noted. False when
     * there is no such entry.
     */
    public static function update(string $dir, string $id, ?int $until, ?string $note, string $by = 'cli'): bool
    {
        foreach (self::FILES as $kind => $name) {
            $file = "$dir/$name";
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $i => $line) {
                if (strncmp($line, "[$id]", strlen($id) + 2) !== 0) {
                    continue;
                }
                $e = self::parse($line, $kind);
                if ($e === null) {
                    return false;
                }
                [$old, $stamp] = self::split($e['note']);
                $lines[$i] = self::line($id, $kind, $e['addresses'], $until === null ? $e['until'] : ($until === 0 ? null : $until),
                    $note === null ? $old : self::note($note), $stamp . ($stamp !== '' ? ', ' : '') . 'changed ' . self::by($by) . ' ' . date('Y-m-d H:i'));
                self::write($file, $lines);
                return true;
            }
        }
        return false;
    }

    /** Removes the entry with this ID; false when there is none. */
    public static function removeId(string $dir, string $id): bool
    {
        foreach (self::FILES as $name) {
            $file = "$dir/$name";
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            $kept = array_values(array_filter($lines, static fn (string $l): bool => strncmp($l, "[$id]", strlen($id) + 2) !== 0));
            if (count($kept) !== count($lines)) {
                self::write($file, $kept);
                return true;
            }
        }
        return false;
    }

    /**
     * The comments of some entries, by ID -- without reading the whole list
     * into entries (a list may hold hundreds of thousands).
     *
     * @param list<string> $ids
     * @return array<string, string> ID => its comment (without who and when)
     */
    public static function notes(string $dir, array $ids): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach (self::FILES as $name) {
            $text = @file_get_contents("$dir/$name");
            if ($text === false) {
                continue;
            }
            foreach ($ids as $id) {
                $at = strpos($text, "[$id]");
                if ($at === false || ($at > 0 && $text[$at - 1] !== "\n")) {
                    continue;
                }
                $end = strpos($text, "\n", $at);
                $line = substr($text, $at, ($end === false ? strlen($text) : $end) - $at);
                $hash = strpos($line, ' #');
                $out[$id] = $hash === false ? '' : self::split(trim(substr($line, $hash + 2)))[0];
            }
        }
        return $out;
    }

    /** Removes every entry that names $address (both lists); returns how many. */
    public static function remove(string $dir, string $address): int
    {
        $removed = 0;
        foreach (self::FILES as $name) {
            $file = "$dir/$name";
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            $kept = [];
            foreach ($lines as $line) {
                $rule = trim((string) preg_replace('/\s+#.*$/', '', $line));
                if (in_array($address, preg_split('/\s+/', $rule) ?: [], true)) {
                    $removed++;
                    continue;
                }
                $kept[] = $line;
            }
            if (count($kept) !== count($lines)) {
                self::write($file, $kept);
            }
        }
        return $removed;
    }

    /**
     * Why an address must not be kept out, or null: a range that holds a
     * trusted proxy (never -- everyone behind it would be kept out), the
     * address of the person asking (it would lock them out), a range wider
     * than /16 (IPv4) or /32 (IPv6) unless $force.
     *
     * @param list<string> $trusted the trusted proxies
     */
    public static function refuse(string $address, array $trusted, ?string $viewer, bool $force): ?string
    {
        $why = self::refusal($address, $trusted, $viewer, $force);
        return match ($why[0] ?? null) {
            'proxy' => "$address holds a trusted proxy ({$why[1]}) -- every visitor behind it would be kept out",
            'self' => "$address holds your own address ({$why[1]}) -- you would lock yourself out",
            'wide' => "$address is a wide range -- many people may be behind it; confirm if that is meant",
            default => null,
        };
    }

    /**
     * refuse() as a code, for a page in another language: ['proxy', the proxy],
     * ['self', the viewer's address], ['wide', ''] -- or null.
     *
     * @param list<string> $trusted
     * @return array{0: string, 1: string}|null
     */
    public static function refusal(string $address, array $trusted, ?string $viewer, bool $force): ?array
    {
        $net = strpos($address, '/') === false ? $address : substr($address, 0, (int) strpos($address, '/'));
        foreach ($trusted as $proxy) {
            $one = strpos($proxy, '/') === false ? $proxy : substr($proxy, 0, (int) strpos($proxy, '/'));
            if (\CjwNetwork\RequestShield\IpAddress::inRanges($one, [$address]) || \CjwNetwork\RequestShield\IpAddress::inRanges($net, [$proxy])) {
                return ['proxy', $proxy];
            }
        }
        if ($viewer !== null && $viewer !== '' && \CjwNetwork\RequestShield\IpAddress::inRanges($viewer, [$address])) {
            return ['self', $viewer];
        }
        if (!$force && self::width($address) > (strpos($address, ':') === false ? 16 : 96)) {
            return ['wide', ''];
        }
        return null;
    }

    /**
     * Entries whose line holds $q (any case), newest first, at most $limit
     * of them parsed -- and how many there are: a list of hundreds of
     * thousands is searched, not read into entries.
     *
     * @return array{entries: list<array{kind: string, id: string, addresses: list<string>, until: ?int, note: string}>, total: int}
     */
    public static function find(string $dir, string $q, int $limit): array
    {
        $entries = [];
        $total = 0;
        foreach (array_reverse(self::FILES, true) as $kind => $name) {
            $lines = @file("$dir/$name", FILE_IGNORE_NEW_LINES) ?: [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = $lines[$i];
                if ($line === '' || $line[0] !== '[' || ($q !== '' && stripos($line, $q) === false)) {
                    continue;
                }
                $total++;
                if (count($entries) < $limit && ($e = self::parse($line, $kind)) !== null) {
                    $entries[] = $e;
                }
            }
        }
        return ['entries' => $entries, 'total' => $total];
    }

    /** @return array{kind: string, id: string, addresses: list<string>, until: ?int, note: string}|null */
    private static function parse(string $line, string $kind): ?array
    {
        if (preg_match('/^\[([A-Za-z0-9-]+)\]\s+(deny|exempt)\s+(.*?)\s*(?:#\s*(.*))?$/', $line, $m) !== 1) {
            return null;
        }
        $words = preg_split('/\s+/', trim($m[3])) ?: [];
        $until = null;
        $i = array_search('until', $words, true);
        if ($i !== false) {
            $until = self::time($words[$i + 1] ?? '');
            $words = array_slice($words, 0, (int) $i);
        }
        return ['kind' => $kind, 'id' => $m[1], 'addresses' => $words, 'until' => $until, 'note' => trim($m[4] ?? '')];
    }

    /** @param list<string> $addresses */
    private static function line(string $id, string $kind, array $addresses, ?int $until, string $note, string $stamp): string
    {
        return "[$id] $kind " . implode(' ', $addresses) . ($until !== null ? ' until ' . date('Y-m-d\TH:i', $until) : '') . '   # ' . ($note !== '' ? "$note · " : '') . $stamp;
    }

    /** A comment is one line, without what the rule reader would read as more, at most 200 characters. */
    public static function note(string $note): string
    {
        $note = trim((string) preg_replace('/(?:[\x00-\x1f\x7f#\[\]·]|\s)+/u', ' ', $note));
        return function_exists('mb_substr') ? mb_substr($note, 0, 200, 'UTF-8') : substr($note, 0, 200);
    }

    /** Who: "cli", "dashboard", "dashboard <user>" -- a word or two, nothing else. */
    private static function by(string $by): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9 ._@-]+/', '', $by)) ?: 'cli';
    }

    /**
     * A line's comment and its stamp ("scraper · cli 2026-10-01 09:12"): [comment, stamp].
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $note): array
    {
        $at = strrpos($note, ' · ');
        if ($at !== false) {
            return [trim(substr($note, 0, $at)), trim(substr($note, $at + strlen(' · ')))];
        }
        return preg_match('/^(cli|dashboard)\b/', $note) === 1 ? ['', $note] : [$note, ''];
    }

    /** An address or a range (203.0.113.7, 198.51.100.0/24, 2001:db8::/48); throws for anything else. */
    public static function check(string $address): void
    {
        $slash = strpos($address, '/');
        $bin = @inet_pton($slash === false ? $address : substr($address, 0, $slash));
        $bits = $slash === false ? null : substr($address, $slash + 1);
        if ($bin === false || ($bits !== null && (!ctype_digit($bits) || (int) $bits > strlen($bin) * 8))) {
            throw new \InvalidArgumentException("\"$address\" is not an address or a range (203.0.113.7, 198.51.100.0/24, 2001:db8::/48)");
        }
    }

    /** How many addresses a range spans, as its prefix length below the address's own (0: one address). */
    public static function width(string $address): int
    {
        $slash = strpos($address, '/');
        $bin = (string) @inet_pton($slash === false ? $address : substr($address, 0, $slash));
        return $slash === false ? 0 : strlen($bin) * 8 - (int) substr($address, $slash + 1);
    }

    /** "2026-10-07T15:30" or "2026-10-07" (to its end): a Unix time, or null. */
    public static function time(string $v): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (isset($m[5]) && ((int) $m[4] > 23 || (int) $m[5] > 59))) {
            return null;
        }
        return isset($m[5]) ? (int) mktime((int) $m[4], (int) $m[5], 0, (int) $m[2], (int) $m[3], (int) $m[1]) : (int) mktime(23, 59, 59, (int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @param list<string> $lines */
    private static function write(string $file, array $lines): void
    {
        $dir = dirname($file);
        if (!\CjwNetwork\RequestShield\Files::dir($dir)) {
            throw new \RuntimeException("cannot create $dir");
        }
        if (!\CjwNetwork\RequestShield\Files::write($file, $lines === [] ? '' : implode("\n", $lines) . "\n")) {
            throw new \RuntimeException("cannot write $file");
        }
    }
}
