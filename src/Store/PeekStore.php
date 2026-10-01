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
 * A store that only looks: hit() answers what a real hit would count, without
 * counting. For trying a request (Report\Inspector) without spending a
 * visitor's budget.
 */
final class PeekStore implements Store
{
    public function __construct(private Store $store)
    {
    }

    public function hit(string $key, int $window, float $now): float
    {
        return $this->store->peek($key, $window, $now) + 1;
    }

    public function peek(string $key, int $window, float $now): float
    {
        return $this->store->peek($key, $window, $now);
    }

    /** Only looks: forgets nothing. */
    public function reset(string $key, int $window, float $now): void
    {
    }

    /** Only looks: marks nothing. */
    public function mark(string $key, int $until, float $now): void
    {
    }

    public function marked(string $key, float $now): int
    {
        return $this->store->marked($key, $now);
    }

    public function marks(string $prefix, float $now): array
    {
        return $this->store->marks($prefix, $now);
    }
}
