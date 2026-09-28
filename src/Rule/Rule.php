<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rule;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;

/**
 * One check. Returns null when it has nothing against the request, or the
 * decision it wants; the shield keeps the strictest and stops at a rejection.
 */
interface Rule
{
    public function check(Request $request, float $now): ?Decision;
}
