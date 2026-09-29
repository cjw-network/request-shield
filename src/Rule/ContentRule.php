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
 * Attack patterns in the query string and the headers -- SQL injection, XSS,
 * code and shell injection, file inclusion, attack tools (rules/attacks.rules,
 * after the OWASP Core Rule Set's first level). Every target is matched
 * against one expression of all its patterns, so a clean request costs one
 * preg_match per target; only a hit looks for the pattern (for the rule's ID
 * and the exceptions).
 */
final class ContentRule implements Rule
{
    /**
     * @param array<string, string> $index target => the combined expression
     * @param list<array{target: string, patterns: list<string>}> $rules
     * @param list<array{paths: list<string>, patterns: list<string>|null, ips: list<string>}> $exceptions
     */
    public function __construct(private array $index, private array $rules, private array $exceptions = [])
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        foreach ($this->index as $target => $all) {
            $content = $request->content($target);
            if ($content !== '' && @preg_match($all, $content) === 1 && self::matched($this->rules, $this->exceptions, $target, $request) !== null) {
                return Decision::reject(403, 'attack');
            }
        }
        return null;
    }

    /**
     * The pattern that matched, unless an exception lets it through.
     *
     * @param list<array{target: string, patterns: list<string>}> $rules
     * @param list<array{paths: list<string>, patterns: list<string>|null, ips: list<string>}> $exceptions
     */
    public static function matched(array $rules, array $exceptions, ?string $target, Request $request): ?string
    {
        foreach ($rules as $r) {
            if ($target !== null && $r['target'] !== $target) {
                continue;
            }
            $content = $request->content($r['target']);
            foreach ($r['patterns'] as $p) {
                if (@preg_match($p, $content) === 1 && ($exceptions === [] || BlockedPathRule::excepted($exceptions, $p, $request) === null)) {
                    return $p;
                }
            }
        }
        return null;
    }
}
