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
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Store\Store;

/**
 * A client banned for a while (ban after …): nothing but 429 with the time to
 * wait, until the ban ends by itself. One store lookup per request; kept
 * across websites (the counter's name has no website in it).
 */
final class BanRule implements Rule
{
    /** @param list<string> $exempt never banned */
    public function __construct(private Store $store, private array $exempt, private int $ipv6Prefix = 64)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        $until = $this->store->marked('ban:' . IpAddress::bucket($request->clientIp, $this->ipv6Prefix), $now);
        if ($until === 0 || ($this->exempt !== [] && IpAddress::inRanges($request->clientIp, $this->exempt))) {
            return null;
        }
        return Decision::throttle('banned', max(1, $until - (int) $now));
    }

    public function explain(Decision $d, Request $request, Settings $s): ?string
    {
        if ($d->reason !== 'banned') {
            return null;
        }
        // A ban's mark keeps no rule (the log names it when it is set); with one ban rule, that one.
        $bans = array_values(array_unique(array_column($s->bans, 'rule')));
        return count($bans) === 1 ? (preg_replace('/[^\x21-\x7e ]/', '?', $bans[0]) ?? $bans[0]) : null;
    }
}
