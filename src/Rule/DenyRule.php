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
 * Addresses kept out (deny, the deny list): 403 before every other check --
 * the cheapest refusal there is. A lookup by the first two bytes, so a list
 * of thousands costs about what one of a few does.
 */
final class DenyRule implements Rule
{
    /** @param array<string, list<array{0: string, 1: int}>> $index IpAddress::index() of the addresses */
    public function __construct(private array $index)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        return IpAddress::inIndex($request->clientIp, $this->index) ? Decision::reject(403, 'denied') : null;
    }
}
