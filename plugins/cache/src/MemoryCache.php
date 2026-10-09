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
 * The HTTP cache's memory (0031 G.4 part 3, proposal 0039): small answers in
 * APCu, ahead of the disk (FileCache) -- a hit is one apcu_fetch, no file.
 *
 * An answer stays here at most MAX_TTL; one that lives longer is on the disk
 * too, and comes back here on its next hit. What the cache may take of APCu
 * is counted per hour of storing: an answer stored in this hour or the one
 * before may still be here, nothing older -- so the two counters are an
 * upper bound of what lies here (a purged or replaced answer is counted
 * until its hour has passed: the bound only errs on the safe side). Above
 * the share, or when APCu would keep less than a quarter free, the answer
 * goes to the disk: a full APCu with apc.ttl 0 is emptied whole, the
 * shield's budgets and roles with it.
 *
 * APCu is the process's own: the command line cannot reach the web server's.
 * Every answer from here counts the tag TAG as well -- from when it was put
 * here ("kept") -- so a purge of it (the command line's, the API's
 * "everything" or "below a path") makes them all out of date at once; the
 * disk keeps the truth, and what is still good there comes back here.
 */
final class MemoryCache
{
    /** The tag every answer in memory carries: purged, the memory's answers are out of date. */
    public const TAG = 'rs-memory';

    /** The longest an answer stays in memory: the counters' hour. */
    public const MAX_TTL = 3600;

    /** What APCu keeps free at least: a quarter. */
    private const RESERVE = 4;

    /** After APCu was short of room: no answer into memory for this long (a disk hit does not ask APCu's free memory every time). */
    private const FULL_PAUSE = 10;

    private string $prefix;

    public function __construct(string $dir, private int $maxObject, private int $share)
    {
        // Sites that share a PHP pool share APCu: the folder tells them apart (as Tags).
        $this->prefix = 'rshield:hc:' . substr(md5($dir), 0, 12) . ':';
    }

    /**
     * What is kept for a key, unless it has expired -- as FileCache::get(),
     * and since when it is here (kept: TAG's purges count from then).
     *
     * @return array{status: int, headers: list<string>, body: string, stored: int, expires: int, tags: list<string>, born: float, kept: float}|null
     */
    public function get(string $key, float $now): ?array
    {
        $got = apcu_fetch($this->prefix . 'a:' . sha1($key));
        if (!is_array($got) || ($got['key'] ?? null) !== $key || !is_int($got['status'] ?? null) || !is_int($got['expires'] ?? null) || !is_int($got['stored'] ?? null)
            || !is_string($got['body'] ?? null) || !is_array($got['headers'] ?? null) || !is_array($got['tags'] ?? null) || !is_float($got['born'] ?? null)
            || !is_float($got['kept'] ?? null) || $got['expires'] <= (int) $now) {
            return null;
        }
        return ['status' => $got['status'], 'headers' => array_values(array_filter($got['headers'], 'is_string')), 'body' => $got['body'], 'stored' => $got['stored'],
            'expires' => $got['expires'], 'tags' => array_values(array_filter($got['tags'], 'is_string')), 'born' => $got['born'], 'kept' => $got['kept']];
    }

    /**
     * Keeps an answer in memory until $expires (at most MAX_TTL from now),
     * when it is small enough and there is room; false: it was not (the disk
     * takes it). $kept: from when TAG's purges count -- when its request
     * began for a new answer, now for one that came from the disk.
     *
     * @param list<string> $headers
     * @param list<string> $tags
     */
    public function put(string $key, int $status, array $headers, string $body, int $stored, int $expires, float $now, array $tags, float $born, float $kept): bool
    {
        $size = strlen($body) + strlen(implode("\n", $headers)) + strlen(implode(' ', $tags)) + strlen($key);
        $ttl = min($expires - (int) $now, self::MAX_TTL);
        if ($size > $this->maxObject || $ttl <= 0 || $size > $this->share || preg_match('//u', implode("\n", $headers)) !== 1) {
            return false;           // a header that is no UTF-8: as the disk, not kept
        }
        $hour = intdiv((int) $now, 3600);
        $counted = apcu_fetch([$this->prefix . "m:$hour", $this->prefix . 'm:' . ($hour - 1), $this->prefix . 'm:full']);
        $counted = is_array($counted) ? $counted : [];
        if (isset($counted[$this->prefix . 'm:full'])) {
            return false;           // APCu was short of room a moment ago: not asked again for FULL_PAUSE seconds
        }
        $used = array_sum(array_filter($counted, 'is_int'));
        if ($used + $size > $this->share) {
            return false;
        }
        $sma = apcu_sma_info(true);
        // APCu gives the sizes as floats (seg_size, avail_mem), the count as an integer.
        $num = static fn (string $k): int => is_array($sma) && (is_int($sma[$k] ?? null) || is_float($sma[$k] ?? null)) ? (int) $sma[$k] : 0;
        $total = $num('num_seg') * $num('seg_size');
        $avail = $num('avail_mem');
        if ($total <= 0 || $avail - $size < intdiv($total, self::RESERVE)) {
            apcu_store($this->prefix . 'm:full', true, self::FULL_PAUSE);
            return false;
        }
        // Counted first: an answer that is here is always counted (the counter lives two hours).
        apcu_add($this->prefix . "m:$hour", 0, 7200);
        apcu_inc($this->prefix . "m:$hour", $size, $ok, 7200);
        return apcu_store($this->prefix . 'a:' . sha1($key), ['key' => $key, 'status' => $status, 'headers' => $headers, 'body' => $body, 'stored' => $stored,
            'expires' => $expires, 'tags' => $tags, 'born' => $born, 'kept' => $kept], $ttl);
    }

    /**
     * Makes every answer in memory out of date, for every process (the
     * command line's and the API's purges by path, or of everything): after
     * the disk's answers are removed, so an answer read from the disk before
     * that and put into memory counts as out of date too.
     */
    public static function forget(string $dir, bool $apcu, float $now): bool
    {
        return (new Tags($dir, $apcu))->purge([self::TAG], $now);
    }

    /** What the answers in memory take at most, in bytes (the two hours' counters). */
    public function bytes(float $now): int
    {
        $hour = intdiv((int) $now, 3600);
        $counted = apcu_fetch([$this->prefix . "m:$hour", $this->prefix . 'm:' . ($hour - 1)]);
        return is_array($counted) ? array_sum(array_filter($counted, 'is_int')) : 0;
    }
}
