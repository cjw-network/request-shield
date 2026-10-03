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
use CjwNetwork\RequestShield\Shield;

/**
 * A provided rule (RuleProvider, 0031 C.3) behind a guard: when it throws, it
 * has nothing to say about the request -- the chain goes on as if it had
 * returned null -- and PHP's error log hears it once a minute. The site stays
 * up whatever a plugin's rule does.
 */
final class Guarded implements Rule
{
    public function __construct(private Rule $rule, private string $key)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        try {
            return $this->rule->check($request, $now);
        } catch (\Throwable $e) {
            // The cause first (the throttle keys on the message's first 200 characters), the class last.
            Shield::failed('rules', "the rule $this->key failed and said nothing: " . $e->getMessage() . ' (' . get_class($this->rule) . ')');
            return null;
        }
    }

    public function explain(Decision $d, Request $request, Settings $s): ?string
    {
        try {
            return $this->rule->explain($d, $request, $s);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The rule behind the guard. */
    public function inner(): Rule
    {
        return $this->rule;
    }
}
