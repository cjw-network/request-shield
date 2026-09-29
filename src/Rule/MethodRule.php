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

/** Only the listed methods reach the application; anything else is 405. */
final class MethodRule implements Rule
{
    /** @param list<string> $methods */
    public function __construct(private array $methods)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        return in_array($request->method, $this->methods, true) ? null : Decision::reject(405, 'method');
    }
}
