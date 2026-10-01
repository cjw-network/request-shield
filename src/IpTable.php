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
 * Many addresses and ranges, each with its rule ID, as a sorted table in a few
 * strings: per family one string of fixed-width records (first address, last
 * address, where its ID starts), in hex, so the compiled settings hold three
 * strings however long the list is. OPcache keeps them once for every worker;
 * a lookup is a binary search with substr() -- about 20 steps for a million
 * entries. Hex compares like the addresses it spells (same length, big-endian),
 * and var_export() writes it as it is.
 *
 * Overlapping ranges are merged; the merged range keeps the ID of the one that
 * starts first.
 */
final class IpTable
{
    /** Hex characters per record: first + last address + the ID's offset (8). */
    private const WIDTH = ['4' => 8 + 8 + 8, '6' => 32 + 32 + 8];

    /** IPv4 records from which a table gets its directory (65,537 entries, 512 KB). */
    private const DIRECTORY_FROM = 256;

    /**
     * @param iterable<array{0: list<string>, 1: string}> $entries addresses or ranges, and their rule ID
     * @return array{4: string, 6: string, ids: string, dir?: string} empty strings when there is nothing
     */
    public static function build(iterable $entries): array
    {
        $ids = '';
        $rows = ['4' => [], '6' => []];
        foreach ($entries as [$ranges, $id]) {
            $offset = sprintf('%08x', strlen($ids));
            $ids .= $id . "\n";
            foreach ($ranges as $range) {
                $slash = strpos($range, '/');
                $net = @inet_pton($slash === false ? $range : substr($range, 0, $slash));
                if ($net === false) {
                    continue;
                }
                $len = strlen($net);
                $bits = $slash === false ? $len * 8 : max(0, min($len * 8, (int) substr($range, $slash + 1)));
                if ($bits === $len * 8) {
                    $hex = bin2hex($net);                       // one address: the most common entry
                    $rows[$len === 4 ? '4' : '6'][] = $hex . $hex . $offset;
                } elseif ($len === 4) {
                    $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
                    $u = unpack('N', $net);
                    $first = (is_array($u) && is_int($u[1] ?? null) ? $u[1] : 0) & $mask;
                    $rows['4'][] = sprintf('%08x%08x', $first, $first | (~$mask & 0xFFFFFFFF)) . $offset;
                } else {
                    $first = $last = '';
                    for ($i = 0; $i < 16; $i++) {
                        $keep = max(0, min(8, $bits - $i * 8));   // bits of this byte that belong to the network
                        $m = (0xFF << (8 - $keep)) & 0xFF;
                        $b = ord($net[$i]) & $m;
                        $first .= chr($b);
                        $last .= chr($b | (~$m & 0xFF));
                    }
                    $rows['6'][] = bin2hex($first) . bin2hex($last) . $offset;
                }
            }
        }
        $out = ['4' => '', '6' => '', 'ids' => $ids];
        foreach ($rows as $family => $list) {
            // Sorted as strings: by first address, then last, then the ID's place.
            sort($list, SORT_STRING);
            $half = (self::WIDTH[$family] - 8) >> 1;
            $table = [];
            $cur = null;
            foreach ($list as $r) {
                if ($cur !== null && strncmp($r, $cur, $half) >= 0 && strcmp(substr($r, 0, $half), substr($cur, $half, $half)) <= 0) {
                    if (strcmp(substr($r, $half, $half), substr($cur, $half, $half)) > 0) {
                        $cur = substr($cur, 0, $half) . substr($r, $half, $half) . substr($cur, 2 * $half);   // overlaps: one range, the first one's ID
                    }
                    continue;
                }
                if ($cur !== null) {
                    $table[] = $cur;
                }
                $cur = $r;
            }
            if ($cur !== null) {
                $table[] = $cur;
            }
            $out[$family] = implode('', $table);
        }
        // A big IPv4 table gets a directory by the first two bytes: where the
        // records of each /16 start, so a search covers only its few records.
        $n = intdiv(strlen($out['4']), self::WIDTH['4']);
        if ($n > self::DIRECTORY_FROM) {
            $dir = [];
            $j = 0;
            for ($p = 0; $p <= 0x10000; $p++) {
                while ($j < $n && hexdec(substr($out['4'], $j * self::WIDTH['4'], 4)) < $p) {
                    $j++;
                }
                $dir[] = sprintf('%08x', $j);
            }
            $out['dir'] = implode('', $dir);
        }
        return $out;
    }

    /**
     * The rule ID of the entry that holds $ip, or null.
     *
     * @param array{4: string, 6: string, ids: string, dir?: string} $table from build()
     */
    public static function find(string $ip, array $table): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        $family = strlen($bin) === 4 ? '4' : '6';
        $rows = $table[$family];
        if ($rows === '') {
            return null;
        }
        $w = self::WIDTH[$family];
        $half = ($w - 8) >> 1;
        $hex = bin2hex($bin);
        // The last record that starts at or before the address.
        $lo = 0;
        $hi = intdiv(strlen($rows), $w) - 1;
        if ($family === '4' && isset($table['dir'])) {
            // From the record before this /16 (a wider range may hold the
            // address) to the last one in it.
            $p = (int) hexdec(substr($hex, 0, 4));
            $lo = max(0, (int) hexdec(substr($table['dir'], $p * 8, 8)) - 1);
            $hi = (int) hexdec(substr($table['dir'], ($p + 1) * 8, 8)) - 1;
        }
        $at = -1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if (substr_compare($rows, $hex, $mid * $w, $half) <= 0) {
                $at = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($at < 0 || substr_compare($rows, $hex, $at * $w + $half, $half) < 0) {
            return null;
        }
        $offset = (int) hexdec(substr($rows, $at * $w + 2 * $half, 8));
        $end = strpos($table['ids'], "\n", $offset);
        return substr($table['ids'], $offset, ($end === false ? strlen($table['ids']) : $end) - $offset);
    }

    /** @param array{4: string, 6: string, ids: string, dir?: string}|array{} $table */
    public static function isEmpty(array $table): bool
    {
        return $table === [] || ($table['4'] === '' && $table['6'] === '');
    }
}
