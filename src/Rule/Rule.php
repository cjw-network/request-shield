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
use CjwNetwork\RequestShield\Settings;

/**
 * One check. Returns null when it has nothing against the request, or the
 * decision it wants; the shield keeps the strictest and stops at a rejection.
 */
interface Rule
{
    public function check(Request $request, float $now): ?Decision;

    /**
     * The rule behind a decision of its own (0031 C.2): the ID or file:line
     * the setting was written at, "built-in" for a fixed check -- or null for
     * a decision that is not this rule's. What the log and the pages name.
     */
    public function explain(Decision $d, Request $request, Settings $s): ?string;
}
