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
 * across websites (the counter's name has no website in it). A ban written
 * inside a match block (0050) is the area's: looked up only for a request
 * there, under "ban:<client>@<rule>".
 */
final class BanRule implements Rule
{
    /** The area's ban that refused this request, for explain(); null: the site's. */
    private ?string $hit = null;

    /**
     * @param list<string> $exempt never banned
     * @param list<array{0: string, 1: list<string>}> $areas the areas' bans: rule, paths
     */
    public function __construct(private Store $store, private array $exempt, private int $ipv6Prefix = 64, private bool $site = true, private array $areas = [])
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        $this->hit = null;
        $bucket = IpAddress::bucket($request->clientIp, $this->ipv6Prefix);
        $until = $this->site ? $this->store->marked('ban:' . $bucket, $now) : 0;
        if ($until === 0 && $this->areas !== []) {
            $path = $request->matchPath();
            foreach ($this->areas as [$rule, $paths]) {
                foreach ($paths as $p) {
                    if (preg_match($p, $path) === 1) {
                        $until = $this->store->marked('ban:' . $bucket . '@' . $rule, $now);
                        break;
                    }
                }
                if ($until !== 0) {
                    $this->hit = $rule;
                    break;
                }
            }
        }
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
        if ($this->hit !== null) {
            return preg_replace('/[^\x21-\x7e ]/', '?', $this->hit) ?? $this->hit;
        }
        // A ban's mark keeps no rule (the log names it when it is set); with one ban rule, that one.
        $bans = [];
        foreach ($s->bans as $b) {
            if (!isset($b['paths'])) {
                $bans[] = $b['rule'];
            }
        }
        $bans = array_values(array_unique($bans));
        return count($bans) === 1 ? (preg_replace('/[^\x21-\x7e ]/', '?', $bans[0]) ?? $bans[0]) : null;
    }
}
