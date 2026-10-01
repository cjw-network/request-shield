<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Store;

/**
 * Counters in files, for hosting without APCu.
 *
 * A request appends one byte to the file of its key and window; the file's
 * size is the count. An O_APPEND write of one byte is atomic, so neither a
 * lock nor a read-modify-write is needed, and concurrent PHP processes never
 * lose a count. Files of past windows are removed by a small sweep that runs
 * on a fraction of the requests.
 */
final class FileStore implements Store
{
    public function __construct(
        private string $dir,
        private float $sweepChance = 0.001,
        private int $sweepBudget = 500,
    ) {
    }

    public function hit(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        $file = $this->path($key, $window, $slot);
        $fh = @fopen($file, 'ab');
        // The directory is missing on the first hits. Processes creating it at
        // the same moment can make a recursive mkdir() fail in one of them (a
        // parent appeared in between) -- so try again a few times, or that hit
        // would not be counted.
        for ($try = 0; $fh === false && $try < 3; $try++) {
            @mkdir(dirname($file), 0700, true);
            $fh = @fopen($file, 'ab');
        }
        if ($fh === false) {
            return 0.0;     // cannot count: never stop a request for it
        }
        fwrite($fh, "\n");
        $stat = fstat($fh);
        fclose($fh);
        $current = $stat === false ? 1 : (int) $stat['size'];
        if ($this->sweepChance > 0 && mt_rand() / mt_getrandmax() < $this->sweepChance) {
            $this->sweep($now);
        }
        return SlidingWindow::estimate($current, $this->size($this->path($key, $window, $slot - 1)), $weight);
    }

    public function reset(string $key, int $window, float $now): void
    {
        [$slot] = SlidingWindow::position($window, $now);
        @unlink($this->path($key, $window, $slot));
        @unlink($this->path($key, $window, $slot - 1));
    }

    public function peek(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        return SlidingWindow::estimate(
            $this->size($this->path($key, $window, $slot)),
            $this->size($this->path($key, $window, $slot - 1)),
            $weight
        );
    }

    /**
     * A mark is a small file holding its end; written whole (a temporary file,
     * then renamed), so a reader never sees half of one. Read on every request
     * while bans are on: one failed open when there is none.
     */
    public function mark(string $key, int $until, float $now): void
    {
        $file = $this->markPath($key);
        if ($until <= $now) {
            @unlink($file);
            return;
        }
        @mkdir(dirname($file), 0700, true);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        // The key after the time, for marks(): the file name is only its hash.
        if (@file_put_contents($tmp, $until . "\n" . $key) !== false && !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    public function marked(string $key, float $now): int
    {
        $file = $this->markPath($key);
        // Nearly always asked for an address nobody banned: a stat is far
        // cheaper than a failed open (whose warning PHP builds, then drops).
        clearstatcache(false, $file);
        if (!is_file($file)) {
            return 0;
        }
        $until = (int) @file_get_contents($file);
        if ($until > $now) {
            return $until;
        }
        if ($until > 0) {
            @unlink($file);                 // over: gone
        }
        return 0;
    }

    public function marks(string $prefix, float $now): array
    {
        $out = [];
        foreach (glob($this->dir . '/*/*.m', GLOB_NOSORT) ?: [] as $file) {
            $text = (string) @file_get_contents($file);
            $nl = strpos($text, "\n");
            $until = (int) $text;
            if ($nl === false || $until <= $now) {
                if ($until > 0 && $until <= $now) {
                    @unlink($file);                 // over: gone
                }
                continue;
            }
            $key = substr($text, $nl + 1);
            if (strncmp($key, $prefix, strlen($prefix)) === 0) {
                $out[$key] = $until;
            }
        }
        return $out;
    }

    private function markPath(string $key): string
    {
        $hash = substr(hash('sha256', 'mark:' . $key), 0, 24);
        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.m';
    }

    /** Removes counter files whose window ended more than one window ago. */
    public function sweep(float $now): int
    {
        $removed = 0;
        $seen = 0;
        foreach (glob($this->dir . '/*/*.c', GLOB_NOSORT) ?: [] as $file) {
            if (++$seen > $this->sweepBudget) {
                break;
            }
            // <window>-<slot>-<hash>.c
            if (preg_match('#/(\d+)-(\d+)-[0-9a-f]+\.c$#', $file, $m)
                && ((int) $m[2] + 2) * (int) $m[1] < $now && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function path(string $key, int $window, int $slot): string
    {
        $hash = substr(hash('sha256', $key), 0, 24);
        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $window . '-' . $slot . '-' . $hash . '.c';
    }

    private function size(string $file): int
    {
        clearstatcache(false, $file);
        $size = @filesize($file);
        return $size === false ? 0 : $size;
    }
}
