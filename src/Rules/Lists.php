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
                if (preg_match('/^\[([A-Za-z0-9-]+)\]\s+(deny|exempt)\s+(.*?)\s*(?:#\s*(.*))?$/', $line, $m) !== 1) {
                    continue;
                }
                $words = preg_split('/\s+/', trim($m[3])) ?: [];
                $until = null;
                $i = array_search('until', $words, true);
                if ($i !== false) {
                    $until = self::time($words[$i + 1] ?? '');
                    $words = array_slice($words, 0, (int) $i);
                }
                $out[] = ['kind' => $kind, 'id' => $m[1], 'addresses' => $words, 'until' => $until, 'note' => trim($m[4] ?? '')];
            }
        }
        return $out;
    }

    /**
     * Adds an entry and returns its ID (LIST-D<n>, LIST-A<n>).
     *
     * @param string $kind deny or exempt
     */
    public static function add(string $dir, string $kind, string $address, ?int $until, string $note): string
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
        // A note is one line, without what the rule reader would read as more.
        $note = trim((string) preg_replace('/[\x00-\x1f\x7f#\[\]]+/', ' ', $note));
        $line = "[$id] $kind $address" . ($until !== null ? ' until ' . date('Y-m-d\TH:i', $until) : '') . '   # ' . ($note !== '' ? "$note · " : '') . 'cli ' . date('Y-m-d H:i');
        $file = $dir . '/' . self::FILES[$kind];
        $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $lines[] = $line;
        self::write($file, $lines);
        return $id;
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
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create $dir");
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $lines === [] ? '' : implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException("cannot write $file");
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("cannot write $file");
        }
    }
}
