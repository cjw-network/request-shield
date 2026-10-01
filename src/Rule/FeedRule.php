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
use CjwNetwork\RequestShield\Request;

/**
 * Addresses on a public blocklist named "deny" (feed …, proposal 0025): 403
 * right after the deny list -- unless the address is let in, a trusted proxy,
 * or a crawler that proved who it is ($lookup decides all of that).
 */
final class FeedRule implements Rule
{
    /** @param \Closure(Request, string): ?string $lookup the rule of the list that holds the client for an action, or null */
    public function __construct(private \Closure $lookup)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        return ($this->lookup)($request, 'deny') !== null ? Decision::reject(403, 'feed') : null;
    }
}
