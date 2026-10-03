<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

use CjwNetwork\RequestShield\IpTable;

/**
 * Public blocklists ("feeds", proposal 0025): the catalog (rules/feeds.json),
 * fetching them into store-dir (bin/request-shield feeds <main.rules> update,
 * from cron), reading their formats, and what is taken out of every list:
 * the site's own network (private and special ranges) and ranges wider than
 * /16 (IPv4) or /32 (IPv6), unless the list is meant to hold whole networks.
 *
 * Nothing is looked up per request and nothing about the site's visitors is
 * sent anywhere: the lists are fetched (pull only) and compiled with the rules.
 *
 * In <store-dir>/feeds: <name>.txt (one range per line, as compiled),
 * <name>.<n>.part (for a list from several addresses: each one's part, so a
 * 304 for one of them keeps its part) and
 * <name>.json (when it was fetched, the validators, the counts).
 */
final class Feeds
{
    /** Less than this share of the entries before: a broken download, not a change. */
    public const SHRINK = 0.5;

    /** The most a list may be, in bytes. */
    public const MAX_BYTES = 67108864;

    /** The site's own network and what is never a visitor: taken out of every list (an entry that touches one is dropped). */
    public const SPECIAL = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/3',
        '::/127', '::ffff:0:0/96', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var array<string, array{title: string, about: string, urls: list<string>, format: string, every: int, wideOk: bool, suggested: string, terms: string}>|null */
    private static ?array $catalog = null;

    /**
     * The shipped catalog: name => title, about, urls, format, every, wideOk, suggested, terms.
     *
     * @return array<string, array{title: string, about: string, urls: list<string>, format: string, every: int, wideOk: bool, suggested: string, terms: string}>
     */
    public static function catalog(): array
    {
        if (self::$catalog === null) {
            $json = json_decode(Shipped::feeds(), true);
            self::$catalog = [];
            $str = static fn ($v, string $or = ''): string => is_string($v) ? $v : $or;
            foreach ((array) (is_array($json) ? ($json['feeds'] ?? []) : []) as $name => $f) {
                if (is_array($f)) {
                    self::$catalog[(string) $name] = [
                        'title' => $str($f['title'] ?? null, (string) $name), 'about' => $str($f['about'] ?? null), 'urls' => array_values(array_filter((array) ($f['urls'] ?? []), 'is_string')),
                        'format' => $str($f['format'] ?? null, 'plain'), 'every' => is_int($f['every'] ?? null) ? $f['every'] : 3600, 'wideOk' => ($f['wideOk'] ?? false) === true,
                        'suggested' => $str($f['suggested'] ?? null), 'terms' => $str($f['terms'] ?? null),
                    ];
                }
            }
        }
        /** @var array<string, array{title: string, about: string, urls: list<string>, format: string, every: int, wideOk: bool, suggested: string, terms: string}> */
        return self::$catalog;
    }

    /** Whether a format is one this reads: plain, dshield, jsonl:<field>, json:<field>[,<field>…]. */
    public static function isFormat(string $format): bool
    {
        return in_array($format, ['plain', 'dshield'], true) || preg_match('/^jsonl?:[A-Za-z0-9_]+(,[A-Za-z0-9_]+)*$/', $format) === 1;
    }

    /**
     * The addresses and ranges in a list, as written (not yet checked).
     *
     * - plain: one per line, the first word; "#" and ";" start a comment
     * - dshield: start, end, prefix length, tab-separated (DShield's block.txt)
     * - jsonl:<field>: one JSON object per line, the field's value (Spamhaus DROP)
     * - json:<f1>,<f2>: one JSON document, every value of those fields anywhere in it (AWS, Google Cloud)
     *
     * @return list<string>
     */
    public static function parse(string $body, string $format): array
    {
        $out = [];
        if ($format === 'plain' || $format === 'dshield') {
            foreach (explode("\n", $body) as $line) {
                $line = trim((string) preg_replace('/[#;].*$/', '', $line));
                if ($line === '') {
                    continue;
                }
                $words = preg_split('/\s+/', $line) ?: [];
                if ($format === 'dshield') {
                    if (count($words) >= 3 && ctype_digit($words[2])) {
                        $out[] = $words[0] . '/' . $words[2];
                    }
                    continue;
                }
                $out[] = $words[0];
            }
            return $out;
        }
        $fields = array_flip(explode(',', (string) substr($format, (int) strpos($format, ':') + 1)));
        if (strncmp($format, 'jsonl:', 6) === 0) {
            foreach (explode("\n", $body) as $line) {
                $o = json_decode(trim($line), true);
                if (is_array($o)) {
                    foreach ($fields as $f => $_) {
                        if (is_string($o[$f] ?? null)) {
                            $out[] = $o[$f];
                        }
                    }
                }
            }
            return $out;
        }
        $doc = json_decode($body, true);
        $walk = static function ($v) use (&$walk, &$out, $fields): void {
            if (!is_array($v)) {
                return;
            }
            foreach ($v as $k => $x) {
                if (is_string($k) && isset($fields[$k]) && is_string($x)) {
                    $out[] = $x;
                } elseif (is_array($x)) {
                    $walk($x);
                }
            }
        };
        $walk($doc);
        return $out;
    }

    /**
     * What is kept of a list: valid addresses and ranges, normalised, sorted,
     * once each; without the site's own network (SPECIAL) and without ranges
     * wider than /16 (IPv4) or /32 (IPv6) unless $wideOk.
     *
     * @param list<string> $ranges
     * @return array{ranges: list<string>, special: int, wide: int, invalid: int}
     */
    public static function clean(array $ranges, bool $wideOk): array
    {
        $special = [];
        foreach (self::SPECIAL as $r) {
            $b = IpTable::bounds($r);
            if ($b !== null) {
                $special[] = $b;
            }
        }
        $kept = [];
        $n = ['special' => 0, 'wide' => 0, 'invalid' => 0];
        foreach ($ranges as $r) {
            $r = trim($r);
            $b = IpTable::bounds($r);
            if ($b === null) {
                $n['invalid']++;
                continue;
            }
            $v6 = strlen($b[0]) === 32;
            $slash = strpos($r, '/');
            $bits = $slash === false ? ($v6 ? 128 : 32) : (int) substr($r, $slash + 1);
            if (!$wideOk && $bits < ($v6 ? 32 : 16)) {
                $n['wide']++;
                continue;
            }
            foreach ($special as $s) {
                if (strlen($s[0]) === strlen($b[0]) && strcmp($b[0], $s[1]) <= 0 && strcmp($s[0], $b[1]) <= 0) {
                    $n['special']++;
                    continue 2;
                }
            }
            // Normalised: the network address, the prefix length only when it is a range.
            $net = (string) inet_ntop((string) hex2bin($b[0]));
            $kept[$bits === ($v6 ? 128 : 32) ? $net : $net . '/' . $bits] = true;
        }
        $list = array_map('strval', array_keys($kept));
        sort($list, SORT_STRING);
        return ['ranges' => $list, 'special' => $n['special'], 'wide' => $n['wide'], 'invalid' => $n['invalid']];
    }

    /**
     * Fetches the feeds that are due: each at most every its catalog's time
     * (or $force), with the validators of the last fetch (304: unchanged);
     * a list that cannot be read, or shrank to less than half (unless
     * $force), is kept as it was. Returns what happened, in words.
     *
     * @param list<array{name: string, urls: list<string>, format: string, every: int, wideOk: bool, file?: ?string}> $feeds
     * @param (callable(string, array<string, string>): (array{status: int, body: string, headers: array<string, string>}|null))|null $fetch
     * @return array<string, string> name => what happened
     */
    public static function update(array $feeds, string $dir, ?callable $fetch = null, bool $force = false, ?int $now = null): array
    {
        $fetch ??= [self::class, 'get'];
        $now ??= time();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create $dir");
        }
        $report = [];
        $done = [];
        foreach ($feeds as $f) {
            $name = $f['name'];
            if (isset($done[$name])) {
                continue;                                   // named by several rules: fetched once
            }
            $done[$name] = true;
            if (is_string($f['file'] ?? null)) {
                $report[$name] = 'a file of the site\'s own (' . basename($f['file']) . '): read with the rules, nothing to fetch';
                continue;
            }
            $meta = self::meta($dir, $name);
            if (!$force && $now - $meta['checked'] < $f['every']) {
                $report[$name] = 'not due: fetched ' . self::ago($now - $meta['checked']) . ' ago (at most every ' . self::ago($f['every']) . ')';
                continue;
            }
            $parts = [];
            $changed = false;
            $failed = null;
            foreach ($f['urls'] as $i => $url) {
                if (strncmp($url, 'https://', 8) !== 0) {
                    $failed = "$url is not https";
                    break;
                }
                $headers = [];
                if (($meta['etag'][$url] ?? '') !== '') {
                    $headers['If-None-Match'] = $meta['etag'][$url];
                }
                if (($meta['modified'][$url] ?? '') !== '') {
                    $headers['If-Modified-Since'] = $meta['modified'][$url];
                }
                $old = @file_get_contents(self::part($dir, $name, $i, count($f['urls'])));
                $r = $fetch($url, $old === false ? [] : $headers);
                if ($r !== null && $r['status'] === 304 && $old !== false) {
                    $parts[] = $old;
                    continue;
                }
                if ($r === null || $r['status'] !== 200) {
                    $failed = "$url answered " . ($r === null ? 'nothing' : $r['status']);
                    break;
                }
                $clean = self::clean(self::parse($r['body'], $f['format']), $f['wideOk']);
                $part = implode("\n", $clean['ranges']) . ($clean['ranges'] === [] ? '' : "\n");
                $changed = $changed || $part !== $old;
                $parts[] = $part;
                $meta['etag'][$url] = $r['headers']['etag'] ?? '';
                $meta['modified'][$url] = $r['headers']['last-modified'] ?? '';
                $meta['dropped'][$url] = ['special' => $clean['special'], 'wide' => $clean['wide'], 'invalid' => $clean['invalid']];
            }
            if ($failed !== null) {
                $report[$name] = "failed: $failed (kept the old list)";
                continue;
            }
            $body = implode('', $parts);
            $count = substr_count($body, "\n");
            if (!$force && $meta['count'] > 0 && $count < $meta['count'] * self::SHRINK) {
                $report[$name] = "refused: $count entries instead of {$meta['count']} (kept the old list; --force to take it)";
                continue;
            }
            if ($count === 0 && !$force) {
                $report[$name] = 'refused: no entries (kept the old list; --force to take it)';
                continue;
            }
            if ($changed || !is_file("$dir/$name.txt")) {
                if (count($parts) > 1) {
                    foreach ($parts as $i => $part) {
                        self::write(self::part($dir, $name, $i, count($parts)), $part);
                    }
                }
                self::write("$dir/$name.txt", $body);
            }
            $was = $meta['count'];
            $meta['checked'] = $now;
            $meta['count'] = $count;
            self::write("$dir/$name.json", (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $report[$name] = ($changed ? 'updated' : 'unchanged') . ": $count entries" . ($changed && $was > 0 ? " (was $was)" : '');
        }
        return $report;
    }

    /**
     * When a feed was last fetched and what it holds.
     *
     * @return array{checked: int, count: int, etag: array<string, string>, modified: array<string, string>, dropped: array<string, array<string, int>>}
     */
    public static function meta(string $dir, string $name): array
    {
        $m = json_decode((string) @file_get_contents("$dir/$name.json"), true);
        $m = is_array($m) ? $m : [];
        $strings = static function ($v): array {
            $out = [];
            foreach (is_array($v) ? $v : [] as $k => $x) {
                if (is_string($x)) {
                    $out[(string) $k] = $x;
                }
            }
            return $out;
        };
        $dropped = [];
        foreach (is_array($m['dropped'] ?? null) ? $m['dropped'] : [] as $url => $d) {
            foreach (is_array($d) ? $d : [] as $k => $x) {
                if (is_int($x)) {
                    $dropped[(string) $url][(string) $k] = $x;
                }
            }
        }
        return [
            'checked' => is_int($m['checked'] ?? null) ? $m['checked'] : 0, 'count' => is_int($m['count'] ?? null) ? $m['count'] : 0,
            'etag' => $strings($m['etag'] ?? null), 'modified' => $strings($m['modified'] ?? null), 'dropped' => $dropped,
        ];
    }

    /**
     * The default fetch: HTTPS with a timeout, at most MAX_BYTES; curl where allow_url_fopen is off.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}|null
     */
    public static function get(string $url, array $headers): ?array
    {
        // file_get_contents, or curl where allow_url_fopen is off (Http).
        return \CjwNetwork\RequestShield\Http::get($url, $headers, 30, self::MAX_BYTES, 'request-shield feed update');
    }

    /** Where one address's part of a list is kept: the list itself when it has one address. */
    private static function part(string $dir, string $name, int $i, int $of): string
    {
        return $of === 1 ? "$dir/$name.txt" : "$dir/$name.$i.part";
    }

    private static function ago(int $s): string
    {
        return $s >= 86400 ? round($s / 86400, 1) . ' d' : ($s >= 3600 ? round($s / 3600, 1) . ' h' : max(0, intdiv($s, 60)) . ' min');
    }

    private static function write(string $file, string $text): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $text) === false || !@chmod($tmp, 0640) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("cannot write $file");
        }
    }
}
