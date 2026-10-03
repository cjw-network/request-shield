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
 * The crawlers' published address lists (rules/crawlers/<name>.json): read
 * when the settings are compiled, and updated outside the request -- "php
 * bin/request-shield crawlers update" into store-dir (a cron job, a deploy),
 * or bin/update-crawler-lists into the library itself before a release.
 *
 * A list file: {"source": "<where it comes from>", "creationTime": "<the
 * operator's>", "fetched": "<date>", "prefixes": ["192.0.2.0/24", …]}. The
 * operators' own format ("prefixes": [{"ipv4Prefix": …}, {"ipv6Prefix": …}])
 * is read as well.
 */
final class CrawlerLists
{
    /** A new list with fewer than this share of the old one's entries is refused (a broken download, not a change). */
    public const SHRINK = 0.5;

    /**
     * The shipped list, or the one in store-dir when it is at least as new.
     * $shipped is "@<name>" for a list the library ships (Shipped::crawlerList()),
     * else a path; "files" are what to watch.
     *
     * @return array{prefixes: list<string>, source: ?string, created: ?string, fetched: ?string, from: string, files: list<string>}
     */
    public static function read(string $shipped, ?string $stored): array
    {
        $watch = strncmp($shipped, '@', 1) === 0 ? Shipped::crawlerListFile(substr($shipped, 1)) : $shipped;
        $files = $watch === null ? [] : [$watch];
        $s = self::parse(self::contents($shipped));
        if ($s === null) {
            throw new RuleFileException("request-shield: the address list $shipped cannot be read (JSON with \"prefixes\")");
        }
        $from = 'shipped';
        if ($stored !== null && is_file($stored)) {
            $files[] = $stored;
            $u = self::parse((string) @file_get_contents($stored));
            // An updated list wins unless it is older than the shipped one (a new release).
            if ($u !== null && (string) $u['created'] >= (string) $s['created']) {
                [$s, $from] = [$u, 'updated'];
            }
        }
        return ['prefixes' => $s['prefixes'], 'source' => $s['source'], 'created' => $s['created'], 'fetched' => $s['fetched'], 'from' => $from, 'files' => $files];
    }

    /** A list's JSON: "@<name>" the shipped one, else a file's; '' when there is none. */
    private static function contents(string $source): string
    {
        return strncmp($source, '@', 1) === 0 ? (string) Shipped::crawlerList(substr($source, 1)) : (string) @file_get_contents($source);
    }

    /**
     * @return array{prefixes: list<string>, source: ?string, created: ?string, fetched: ?string}|null null: not a list
     */
    public static function parse(string $json): ?array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['prefixes']) || !is_array($d['prefixes'])) {
            return null;
        }
        $prefixes = [];
        foreach ($d['prefixes'] as $p) {
            $p = is_array($p) ? ($p['ipv4Prefix'] ?? $p['ipv6Prefix'] ?? null) : $p;
            if (!is_string($p) || !self::isRange($p)) {
                return null;            // one bad entry: the list is not trusted at all
            }
            $prefixes[] = $p;
        }
        if ($prefixes === []) {
            return null;
        }
        $str = static fn ($v): ?string => is_string($v) && $v !== '' ? $v : null;
        return ['prefixes' => $prefixes, 'source' => $str($d['source'] ?? null), 'created' => $str($d['creationTime'] ?? null), 'fetched' => $str($d['fetched'] ?? null)];
    }

    /** 192.0.2.0/24, 2001:db8::/32 or a single address. */
    public static function isRange(string $p): bool
    {
        $slash = strpos($p, '/');
        $ip = $slash === false ? $p : substr($p, 0, $slash);
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if ($slash === false) {
            return true;
        }
        $bits = substr($p, $slash + 1);
        return ctype_digit($bits) && (int) $bits <= strlen($bin) * 8;
    }

    /**
     * Fetches each list from where it comes from and writes it into $dir,
     * checked: valid JSON with valid prefixes, and not less than half of what
     * was there (unless $force). Nothing is written for a list that fails.
     *
     * @param array<string, string> $lists name => the file it is read from now, "@<name>" for the shipped one (its "source" says where to fetch it)
     * @param (callable(string): (string|false))|null $fetch the body of an https address; default: Http::get() with a timeout
     * @return array<string, string> name => what happened, in words
     */
    public static function update(array $lists, string $dir, ?callable $fetch = null, bool $force = false): array
    {
        $fetch ??= static function (string $url) {
            // file_get_contents, or curl where allow_url_fopen is off (Http).
            $r = \CjwNetwork\RequestShield\Http::get($url, [], 20, 0, 'request-shield crawler list update');
            return $r !== null && $r['status'] === 200 ? $r['body'] : false;
        };
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create $dir");
        }
        $report = [];
        foreach ($lists as $name => $current) {
            $old = self::parse(self::contents($current));
            $source = $old['source'] ?? null;
            if ($source === null || strncmp($source, 'https://', 8) !== 0) {
                $report[$name] = 'skipped: no https source in ' . basename($current);
                continue;
            }
            $body = $fetch($source);
            $new = is_string($body) ? self::parse($body) : null;
            if ($new === null) {
                $report[$name] = "failed: $source did not answer with a list (kept the old one)";
                continue;
            }
            $before = $old === null ? 0 : count($old['prefixes']);
            if (!$force && $before > 0 && count($new['prefixes']) < $before * self::SHRINK) {
                $report[$name] = "refused: $source has " . count($new['prefixes']) . " entries instead of $before (kept the old one; --force to take it)";
                continue;
            }
            $file = "$dir/$name.json";
            if ($old !== null && $old['prefixes'] === $new['prefixes'] && $old['created'] === $new['created'] && strncmp($current, '@', 1) !== 0 && realpath($current) === realpath($file)) {
                // Nothing new: the file stays as it is (a diff shows only real changes).
                $report[$name] = 'unchanged: ' . count($new['prefixes']) . ' entries' . ($new['created'] !== null ? ', created ' . $new['created'] : '');
                continue;
            }
            $out = ['source' => $source, 'creationTime' => $new['created'], 'fetched' => gmdate('Y-m-d'), 'prefixes' => $new['prefixes']];
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                $report[$name] = "failed: cannot write $file";
                continue;
            }
            $same = $old !== null && $old['prefixes'] === $new['prefixes'];
            $report[$name] = ($same ? 'unchanged' : 'updated') . ': ' . count($new['prefixes']) . ' entries' . ($same ? '' : " (was $before)")
                . ($new['created'] !== null ? ', created ' . $new['created'] : '');
        }
        return $report;
    }
}
