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
 * The HTTP cache's store (0031 G.2): one file per address, under a folder of
 * the store directory -- a line of JSON (status, headers, when kept, until
 * when, its tags and when its request began: Tags), then the body. Written to a temporary file and renamed, so a reader
 * never sees half a page; an expired one is removed when it is read.
 *
 * The cap (http-cache-disk, 0031 G.4 part 3) holds per folder: each of the
 * 256 folders keeps at most a 256th of it -- the addresses spread evenly
 * over them (sha1), so no folder list is ever read whole. With APCu each
 * folder's bytes are counted as answers are written; past its share the
 * folder is measured, and the expired and then the oldest answers go
 * until it holds nine tenths. Without APCu the sweep (one store in a
 * hundred) does the same for the folder it sweeps -- the cap then holds
 * on average, not for every folder at every moment.
 */
final class FileCache
{
    /** The folders the answers are spread over (the first two hex digits of the key's sha1). */
    private const FOLDERS = 256;

    /** @param int $cap the most the folder may hold, in bytes (0: no cap) */
    public function __construct(private string $dir, private int $cap = 0, private bool $apcu = false)
    {
    }

    /**
     * What is kept for a key, unless it has expired.
     *
     * @return array{status: int, headers: list<string>, body: string, stored: int, expires: int, tags: list<string>, born: float}|null
     */
    public function get(string $key, float $now): ?array
    {
        $file = $this->path($key);
        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }
        $nl = strpos($data, "\n");
        $meta = $nl === false ? null : json_decode(substr($data, 0, $nl), true);
        if (!is_array($meta) || !is_int($meta['expires'] ?? null) || !is_int($meta['status'] ?? null) || !is_int($meta['stored'] ?? null)
            || ($meta['key'] ?? null) !== $key || !is_array($meta['headers'] ?? null)) {
            @unlink($file);
            return null;
        }
        if ($meta['expires'] <= (int) $now) {
            @unlink($file);
            return null;
        }
        $born = $meta['born'] ?? null;
        return ['status' => $meta['status'], 'headers' => array_values(array_filter($meta['headers'], 'is_string')), 'body' => (string) substr($data, $nl + 1),
            'stored' => $meta['stored'], 'expires' => $meta['expires'], 'tags' => array_values(array_filter(is_array($meta['tags'] ?? null) ? $meta['tags'] : [], 'is_string')),
            'born' => is_float($born) || is_int($born) ? (float) $born : (float) $meta['stored']];
    }

    /**
     * Keeps an answer; one store in a hundred also removes what has expired
     * in one of the 256 folders (and the purge times run out there), so the
     * cache cleans up by itself.
     *
     * @param list<string> $headers
     * @param string $path the address's path, for a purge below a path
     * @param list<string> $tags what purges it (Tags)
     * @param ?float $born when its request began (default $now): a purge after it makes it out of date
     */
    public function put(string $key, int $status, array $headers, string $body, int $ttl, float $now, string $path = '', array $tags = [], ?float $born = null): bool
    {
        if (mt_rand(1, 100) === 1) {
            $folder = sprintf('%02x', mt_rand(0, 255));
            $this->sweep($folder, $now);
            (new Tags($this->dir, false))->sweep($folder, $now);
            if ($this->cap > 0 && !$this->apcu) {
                $this->trim($folder, $now);
            }
        }
        $file = $this->path($key);
        $meta = json_encode(['key' => $key, 'path' => $path, 'status' => $status, 'headers' => $headers, 'stored' => (int) $now, 'expires' => (int) $now + $ttl,
            'tags' => $tags, 'born' => $born ?? $now], JSON_UNESCAPED_SLASHES);
        if ($meta === false) {
            return false;           // a header that is no UTF-8: not kept, rather than a file that never reads
        }
        if (!\CjwNetwork\RequestShield\Files::write($file, $meta . "\n" . $body, '.tmp')) {
            return false;
        }
        if ($this->cap > 0 && $this->apcu) {
            $this->count(substr(sha1($key), 0, 2), strlen($meta) + 1 + strlen($body), $now);
        }
        return true;
    }

    /**
     * Counts what was written into a folder; past its share of the cap the
     * folder is measured and trimmed, and the count set to what it holds. A
     * count that is missing (APCu restarted) measures once.
     */
    private function count(string $folder, int $bytes, float $now): void
    {
        $key = 'rshield:hc:' . substr(md5($this->dir), 0, 12) . ":d:$folder";
        $held = apcu_fetch($key);
        $held = is_int($held) ? apcu_inc($key, $bytes) : false;
        if (is_int($held) && $held <= intdiv($this->cap, self::FOLDERS)) {
            return;
        }
        apcu_store($key, $this->trim($folder, $now));
    }

    /**
     * Brings a folder within its share of the cap: the expired answers
     * first, then the oldest (by when they were written) until it holds nine
     * tenths of it. Returns the bytes it holds then.
     */
    public function trim(string $folder, float $now): int
    {
        $limit = intdiv($this->cap, self::FOLDERS);
        $this->sweep($folder, $now);
        $files = [];
        $held = 0;
        foreach ($this->files($folder) as $file) {
            $size = (int) @filesize($file);
            $files[$file] = [(int) @filemtime($file), $size];
            $held += $size;
        }
        if ($this->cap <= 0 || $held <= $limit) {
            return $held;
        }
        uasort($files, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($files as $file => [, $size]) {
            if ($held <= intdiv($limit * 9, 10)) {
                break;
            }
            if (@unlink($file)) {
                $held -= $size;
            }
        }
        return $held;
    }

    /** Every folder within its share of the cap (the command line's "expired"); returns the bytes held. */
    public function trimAll(float $now): int
    {
        $held = 0;
        for ($i = 0; $i < self::FOLDERS; $i++) {
            $held += $this->trim(sprintf('%02x', $i), $now);
        }
        return $held;
    }

    /**
     * Removes what is kept: everything, or the addresses whose path starts
     * with $path, literally (/news/ -- every host; /news also takes
     * /newsletter), or ($now) what has expired. Returns how many. Files a
     * write left half done (*.tmp) go too, once they are a minute old.
     */
    public function purge(?string $path = null, ?float $now = null, string $folder = '*'): int
    {
        $n = 0;
        foreach (glob(rtrim($this->dir, '/') . "/$folder/*/*.tmp") ?: [] as $tmp) {
            if ($path === null && (int) @filemtime($tmp) < (int) ($now ?? microtime(true)) - 60) {
                @unlink($tmp);
            }
        }
        foreach ($this->files($folder) as $file) {
            if ($path !== null || $now !== null) {
                $h = @fopen($file, 'rb');
                $line = $h !== false ? (string) fgets($h) : '';
                if ($h !== false) {
                    fclose($h);
                }
                $meta = json_decode($line, true);
                $key = is_array($meta) && is_string($meta['key'] ?? null) ? $meta['key'] : '';
                $keyPath = is_array($meta) && is_string($meta['path'] ?? null) && $meta['path'] !== '' ? $meta['path'] : (string) parse_url($key, PHP_URL_PATH);
                $expired = $now !== null && is_array($meta) && is_int($meta['expires'] ?? null) && $meta['expires'] <= (int) $now;
                if (!$expired && ($path === null || strncmp($keyPath, $path, strlen($path)) !== 0)) {
                    continue;
                }
            }
            $n += @unlink($file) ? 1 : 0;
        }
        return $n;
    }

    /** @return array{entries: int, bytes: int} */
    public function stats(): array
    {
        $entries = 0;
        $bytes = 0;
        foreach ($this->files() as $file) {
            $entries++;
            $bytes += (int) @filesize($file);
        }
        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /** What has expired in one folder (ab: one 256th of the cache). */
    public function sweep(string $folder, float $now): int
    {
        return $this->purge(null, $now, $folder);
    }

    /** @return list<string> */
    private function files(string $folder = '*'): array
    {
        return glob(rtrim($this->dir, '/') . "/$folder/*/*.cache") ?: [];
    }

    private function path(string $key): string
    {
        $h = sha1($key);
        return rtrim($this->dir, '/') . '/' . substr($h, 0, 2) . '/' . substr($h, 2, 2) . "/$h.cache";
    }
}
