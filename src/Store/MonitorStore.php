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
 * The store of the rules marked "monitor": a budget only they have ("monitor
 * limit searches 10/min") is counted; one the enforced rules count already is
 * only looked at -- counted once, whichever rules ask. Never forgets anything:
 * a watched rule starts no counter again.
 */
final class MonitorStore implements Store
{
    /** @param array<string, true> $own the budgets only the monitored rules have */
    public function __construct(private Store $store, private array $own)
    {
    }

    public function hit(string $key, int $window, float $now): float
    {
        $budget = strstr($key, ':', true);
        return $budget !== false && isset($this->own[$budget])
            ? $this->store->hit('monitor:' . $key, $window, $now)
            // Counted by the enforced rules a moment ago (this request included).
            : $this->store->peek($key, $window, $now);
    }

    public function peek(string $key, int $window, float $now): float
    {
        $budget = strstr($key, ':', true);
        return $this->store->peek($budget !== false && isset($this->own[$budget]) ? 'monitor:' . $key : $key, $window, $now);
    }

    public function reset(string $key, int $window, float $now): void
    {
    }

    /** A watched rule bans nobody. */
    public function mark(string $key, int $until, float $now): void
    {
    }

    public function marked(string $key, float $now): int
    {
        return $this->store->marked($key, $now);
    }
}
