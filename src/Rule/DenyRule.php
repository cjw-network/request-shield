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
use CjwNetwork\RequestShield\IpTable;
use CjwNetwork\RequestShield\Request;

/**
 * Addresses kept out (deny, the deny list): 403 before every other check --
 * the cheapest refusal there is. A binary search in a sorted table, so a
 * list of a hundred thousand costs about what one of a few does.
 */
final class DenyRule implements Rule
{
    /** @param array{4: string, 6: string, ids: string, dir?: string} $table IpTable::build() of the addresses */
    public function __construct(private array $table)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        return IpTable::find($request->clientIp, $this->table) !== null ? Decision::reject(403, 'denied') : null;
    }
}
