<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Store;

/** Counters for one process only: tests, and a long-running server's own loop. */
final class MemoryStore implements Store
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, int> key => until */
    private array $marks = [];

    public function mark(string $key, int $until, float $now): void
    {
        if ($until <= $now) {
            unset($this->marks[$key]);
            return;
        }
        $this->marks[$key] = $until;
    }

    public function marked(string $key, float $now): int
    {
        $until = $this->marks[$key] ?? 0;
        return $until > $now ? $until : 0;
    }

    public function hit(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        $k = $window . ':' . $key . ':';
        $this->counts[$k . $slot] = ($this->counts[$k . $slot] ?? 0) + 1;
        return SlidingWindow::estimate($this->counts[$k . $slot], $this->counts[$k . ($slot - 1)] ?? 0, $weight);
    }

    public function reset(string $key, int $window, float $now): void
    {
        [$slot] = SlidingWindow::position($window, $now);
        $k = $window . ':' . $key . ':';
        unset($this->counts[$k . $slot], $this->counts[$k . ($slot - 1)]);
    }

    public function peek(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        $k = $window . ':' . $key . ':';
        return SlidingWindow::estimate($this->counts[$k . $slot] ?? 0, $this->counts[$k . ($slot - 1)] ?? 0, $weight);
    }
}
