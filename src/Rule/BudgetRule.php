<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rule;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Store\Store;

/**
 * Requests per client and window. Above $challengeAt the client is asked to
 * prove it is a browser; above $limit it waits (429 with Retry-After). Exempt
 * addresses (monitoring, the site's own servers) are never counted.
 */
final class BudgetRule implements Rule
{
    /** @param list<string> $exempt */
    public function __construct(
        private Store $store,
        private string $name,
        private int $limit,
        private int $window,
        private ?int $challengeAt = null,
        private array $exempt = [],
        private int $ipv6Prefix = 64,
        private bool $earnBack = false,
        private ?string $counter = null,
    ) {
    }

    /** @param int $times how often this request counts (strict: twice for one a cache must not keep) */
    public function check(Request $request, float $now, int $times = 1): ?Decision
    {
        if ($this->limit <= 0 || ($this->exempt !== [] && IpAddress::inRanges($request->clientIp, $this->exempt))) {
            return null;
        }
        $key = ($this->counter ?? $this->name) . ':' . IpAddress::bucket($request->clientIp, $this->ipv6Prefix);
        $count = $this->store->hit($key, $this->window, $now);
        for ($i = 1; $i < $times; $i++) {
            $count = $this->store->hit($key, $this->window, $now);
        }
        if ($count > $this->limit) {
            $wait = (int) ceil($this->window * ($count - $this->limit) / $this->limit);
            // Earn it back (onExceeded: challenge): the check, and solved, the
            // counter starts again; otherwise a pause.
            return $this->earnBack ? Decision::spent($this->name, $wait) : Decision::throttle($this->name, $wait);
        }
        if ($this->challengeAt !== null && $this->challengeAt > 0 && $count > $this->challengeAt) {
            $span = max(1, $this->limit - $this->challengeAt);
            return Decision::challenge($this->name, ($count - $this->challengeAt) / $span);
        }
        return null;
    }
}
