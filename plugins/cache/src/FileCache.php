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

    /** @param list<string> $headers */
    public function put(string $key, int $status, array $headers, string $body, int $ttl, float $now): bool
    {
        $file = $this->path($key);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        $meta = json_encode(['key' => $key, 'status' => $status, 'headers' => $headers, 'stored' => (int) $now, 'expires' => (int) $now + $ttl], JSON_UNESCAPED_SLASHES);
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
     * with $path (/news/ -- every host). Returns how many.
     */
    public function purge(?string $path = null, ?float $now = null): int
    {
        $n = 0;
        foreach ($this->files() as $file) {
            if ($path !== null || $now !== null) {
                $h = @fopen($file, 'rb');
                $line = $h !== false ? (string) fgets($h) : '';
                if ($h !== false) {
                    fclose($h);
                }
                $meta = json_decode($line, true);
                $key = is_array($meta) && is_string($meta['key'] ?? null) ? $meta['key'] : '';
                $keyPath = (string) parse_url($key, PHP_URL_PATH);
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

    /** @return list<string> */
    private function files(): array
    {
        return glob(rtrim($this->dir, '/') . '/*/*/*.cache') ?: [];
    }

    private function path(string $key): string
    {
        $h = sha1($key);
        return rtrim($this->dir, '/') . '/' . substr($h, 0, 2) . '/' . substr($h, 2, 2) . "/$h.cache";
    }
}
