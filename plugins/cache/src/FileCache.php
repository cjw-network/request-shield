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
 * when), then the body. Written to a temporary file and renamed, so a reader
 * never sees half a page; an expired one is removed when it is read.
 */
final class FileCache
{
    public function __construct(private string $dir)
    {
    }

    /**
     * What is kept for a key, unless it has expired.
     *
     * @return array{status: int, headers: list<string>, body: string, stored: int, expires: int}|null
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
        return ['status' => $meta['status'], 'headers' => array_values(array_filter($meta['headers'], 'is_string')), 'body' => (string) substr($data, $nl + 1),
            'stored' => $meta['stored'], 'expires' => $meta['expires']];
    }

    /**
     * Keeps an answer; one store in a hundred also removes what has expired
     * in one of the 256 folders, so the cache cleans up by itself.
     *
     * @param list<string> $headers
     * @param string $path the address's path, for a purge below a path
     */
    public function put(string $key, int $status, array $headers, string $body, int $ttl, float $now, string $path = ''): bool
    {
        if (mt_rand(1, 100) === 1) {
            $this->sweep(sprintf('%02x', mt_rand(0, 255)), $now);
        }
        $file = $this->path($key);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        $meta = json_encode(['key' => $key, 'path' => $path, 'status' => $status, 'headers' => $headers, 'stored' => (int) $now, 'expires' => (int) $now + $ttl], JSON_UNESCAPED_SLASHES);
        if ($meta === false) {
            return false;           // a header that is no UTF-8: not kept, rather than a file that never reads
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $meta . "\n" . $body) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
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
