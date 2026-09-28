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
        private readonly string $dir,
        private readonly float $sweepChance = 0.001,
        private readonly int $sweepBudget = 500,
    ) {
    }

    public function hit(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        $file = $this->path($key, $window, $slot);
        $fh = @fopen($file, 'ab');
        if ($fh === false) {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $fh = @fopen($file, 'ab');
            if ($fh === false) {
                return 0.0;     // cannot count: never stop a request for it
            }
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

    public function peek(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        return SlidingWindow::estimate(
            $this->size($this->path($key, $window, $slot)),
            $this->size($this->path($key, $window, $slot - 1)),
            $weight
        );
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
