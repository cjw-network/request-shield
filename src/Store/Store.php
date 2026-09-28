<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Store;

/**
 * Counters for the budgets: how many requests a key made in a window.
 *
 * A sliding window from two fixed ones: the count in the current window plus
 * the previous window's count weighted by how much of it still overlaps. Two
 * integers per key and window, no timestamps per request, and good enough to
 * tell a visitor from a flood.
 */
interface Store
{
    /**
     * Counts one request for $key and returns the estimated number of requests
     * in the last $window seconds, this one included.
     */
    public function hit(string $key, int $window, float $now): float;

    /** The estimate without counting a request. */
    public function peek(string $key, int $window, float $now): float;
}
