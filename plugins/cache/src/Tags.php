<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Cache;

/**
 * Purge by tag without an index (0031 G.4, proposal 0039): for each tag the
 * time it was last purged. A kept answer remembers when its request began
 * and its tags; it is out of date when one of them -- or everything (ALL) --
 * was purged since. A purge is one write, whatever the number of pages,
 * and nothing is searched.
 *
 * The times are files (`<dir>/tags/ab/<md5>`), the truth that outlives a
 * restart and that every process sees; with APCu a copy of each time for
 * MEMORY seconds (0: never purged), so a hit asks the memory once for all
 * its tags. A purge from elsewhere -- the command line, whose APCu is its
 * own, another server on a shared folder -- counts once the copy has run
 * out; one APCu dropped is read again from the file. A purge time is kept
 * MAX_AGE -- no answer is kept longer.
 *
 * A hit asks first when anything was purged last (LAST: one file, or one
 * APCu read): before its answer was made, the answer is good, and no tag is
 * read -- the common case costs one read whatever the number of tags.
 */
final class Tags
{
    /** Everything: "purge all" (*, ez-all from X-Location-Id: *). */
    public const ALL = '*';

    /** The time of the last purge of any tag (a name no header can carry: tags are visible characters). */
    private const LAST = "\0last";

    /** The longest an answer is kept, and a purge remembered: 30 days. */
    public const MAX_AGE = 2592000;

    /** How long APCu keeps its copy of a purge time: what a purge from another process may lag behind. */
    public const MEMORY = 10;

    private string $prefix;

    public function __construct(private string $dir, private bool $apcu)
    {
        // Sites that share a PHP pool share APCu: the folder tells them apart.
        $this->prefix = 'rshield:hc:' . substr(md5($dir), 0, 12) . ':';
    }

    /**
     * Whether one of these tags, or everything, was purged at or after
     * $since (when the answer's request began): the time of the last purge
     * of any tag first, the tags only when that is not older.
     *
     * @param list<string> $tags
     */
    public function purgedSince(array $tags, float $since): bool
    {
        return $this->times([self::LAST]) >= $since && $this->newest($tags) >= $since;
    }

    /**
     * When one of these tags, or everything, was purged last (0.0: never).
     *
     * @param list<string> $tags
     */
    public function newest(array $tags): float
    {
        $tags[] = self::ALL;
        return $this->times($tags);
    }

    /**
     * The newest purge time of these names (0.0: none).
     *
     * @param list<string> $tags
     */
    private function times(array $tags): float
    {
        $newest = 0.0;
        if (!$this->apcu) {
            foreach ($tags as $tag) {
                $newest = max($newest, $this->fromFile($tag));
            }
            return $newest;
        }
        $keys = [];
        foreach ($tags as $tag) {
            $keys[$this->prefix . md5($tag)] = $tag;
        }
        $got = apcu_fetch(array_keys($keys));
        $got = is_array($got) ? $got : [];
        foreach ($keys as $key => $tag) {
            if (isset($got[$key]) && (is_float($got[$key]) || is_int($got[$key]))) {
                $time = (float) $got[$key];
            } else {
                // Not in memory (never asked, or dropped): the file says; a purge between
                // the read and this add has stored its time already, and the add does not win.
                $time = $this->fromFile($tag);
                apcu_add($key, $time, self::MEMORY);
            }
            $newest = max($newest, $time);
        }
        return $newest;
    }

    /**
     * Purges tags: every answer that carries one is out of date from now on.
     * Returns false when a time could not be written (nothing is lost for
     * the visitor: the answers then run out by their time to live).
     *
     * @param list<string> $tags
     */
    public function purge(array $tags, float $now): bool
    {
        $ok = true;
        // LAST after the tags: a reader that sees it new finds their times written.
        foreach ([...array_values(array_unique($tags)), self::LAST] as $tag) {
            // In file-mode, its folder in dir-mode (Files); '.tmp' as the cache's own, for the sweep.
            if (!\CjwNetwork\RequestShield\Files::write($this->file($tag), sprintf('%.6F', $now), '.tmp')) {
                $ok = false;
                continue;           // not in memory either: a purge that is said to have failed must not count for a while
            }
            if ($this->apcu) {
                apcu_store($this->prefix . md5($tag), $now, self::MEMORY);
            }
        }
        return $ok;
    }

    /** Removes the purge times older than MAX_AGE in one folder (ab), as FileCache sweeps. */
    public function sweep(string $folder, float $now): int
    {
        $n = 0;
        foreach (glob(rtrim($this->dir, '/') . "/tags/$folder/*") ?: [] as $file) {
            if ((int) @filemtime($file) < (int) $now - self::MAX_AGE) {
                $n += @unlink($file) ? 1 : 0;
            }
        }
        return $n;
    }

    /**
     * The tags of a header's value: split at whitespace and commas (as Ibexa's
     * handler does for xkey and X-Cache-Tags), each at most 200 bytes of
     * visible characters -- others are left out.
     *
     * @return list<string>
     */
    public static function split(string $value): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
            if (strlen($tag) <= 200 && preg_match('/^[\x21-\x7e]+$/', $tag) === 1) {
                $out[] = $tag;
            }
        }
        return $out;
    }

    private function fromFile(string $tag): float
    {
        $v = @file_get_contents($this->file($tag));
        return $v !== false && is_numeric($v) ? (float) $v : 0.0;
    }

    private function file(string $tag): string
    {
        $h = md5($tag);
        return rtrim($this->dir, '/') . '/tags/' . substr($h, 0, 2) . "/$h";
    }
}
