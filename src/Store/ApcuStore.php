<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Store;

/**
 * Counters in APCu: one atomic apcu_inc() per request. Shared by the processes
 * of one PHP pool (FPM, LSAPI, a forking server), not across servers.
 */
final class ApcuStore implements Store
{
    public function __construct(private readonly string $prefix = 'rshield:')
    {
    }

    public static function usable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function hit(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        $k = $this->prefix . $window . ':' . $key . ':';
        $ok = false;
        $current = apcu_inc($k . $slot, 1, $ok, $window * 2 + 1);
        if (!$ok || $current === false) {
            $current = 1;
        }
        return SlidingWindow::estimate((int) $current, (int) apcu_fetch($k . ($slot - 1)), $weight);
    }

    public function peek(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        $k = $this->prefix . $window . ':' . $key . ':';
        return SlidingWindow::estimate((int) apcu_fetch($k . $slot), (int) apcu_fetch($k . ($slot - 1)), $weight);
    }
}
