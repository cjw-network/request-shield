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
 * Methods allowed only on some paths: a POST where the site has forms, a PUT
 * only on its API. Any other path answers 405 for that method, so a bot
 * cannot post to every URL it finds. Methods not listed are left to MethodRule.
 */
final class MethodPathRule implements Rule
{
    /** @param array<string, list<string>> $methodPaths method => patterns */
    public function __construct(private array $methodPaths)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        $patterns = $this->methodPaths[$request->method] ?? null;
        if ($patterns === null) {
            return null;
        }
        $path = $request->matchPath();
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return null;
            }
        }
        return Decision::reject(405, 'method not allowed here');
    }

    public function explain(Decision $d, Request $request, Settings $s): ?string
    {
        if ($d->reason !== 'method not allowed here') {
            return null;
        }
        return $s->origin('methodPathsFirst', $request->method) !== null
            ? $s->ruleName('methodPathsFirst', $request->method, "methodPaths.$request->method")
            : $s->ruleName('methodPaths', $request->method, "methodPaths.$request->method");
    }
}
