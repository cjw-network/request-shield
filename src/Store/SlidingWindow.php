<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Store;

/** The arithmetic every store shares. */
final class SlidingWindow
{
    /**
     * @return array{0: int, 1: float} the current window's number and the
     *         weight of the previous window (1 at its start, 0 at its end)
     */
    public static function position(int $window, float $now): array
    {
        $window = max(1, $window);
        $slot = (int) floor($now / $window);
        $elapsed = ($now - $slot * $window) / $window;
        return [$slot, 1.0 - $elapsed];
    }

    public static function estimate(int $current, int $previous, float $weight): float
    {
        return $current + $previous * $weight;
    }
}
