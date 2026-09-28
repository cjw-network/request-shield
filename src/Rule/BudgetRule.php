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
        private readonly Store $store,
        private readonly string $name,
        private readonly int $limit,
        private readonly int $window,
        private readonly ?int $challengeAt = null,
        private readonly array $exempt = [],
        private readonly int $ipv6Prefix = 64,
    ) {
    }

    public function check(Request $request, float $now): ?Decision
    {
        if ($this->limit <= 0 || ($this->exempt !== [] && IpAddress::inRanges($request->clientIp, $this->exempt))) {
            return null;
        }
        $count = $this->store->hit($this->name . ':' . IpAddress::bucket($request->clientIp, $this->ipv6Prefix), $this->window, $now);
        if ($count > $this->limit) {
            return Decision::throttle($this->name, (int) ceil($this->window * ($count - $this->limit) / $this->limit));
        }
        if ($this->challengeAt !== null && $this->challengeAt > 0 && $count > $this->challengeAt) {
            return Decision::challenge($this->name);
        }
        return null;
    }
}
