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

/**
 * Paths only some addresses may open -- an admin area, an internal API:
 * everyone else gets 403. Matched against Request::matchPath(), so neither
 * "//admin" nor "/%61dmin" gets past it; the client address is the one a
 * trusted proxy vouches for, never a header anyone could send.
 */
final class RestrictedPathRule implements Rule
{
    /** @param list<array{paths: list<string>, ips: list<string>}> $restricted */
    public function __construct(private array $restricted)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        $path = $request->matchPath();
        foreach ($this->restricted as $r) {
            foreach ($r['paths'] as $pattern) {
                if (@preg_match($pattern, $path) === 1) {
                    if (!IpAddress::inRanges($request->clientIp, $r['ips'])) {
                        return Decision::reject(403, 'restricted');
                    }
                    break;
                }
            }
        }
        return null;
    }
}
