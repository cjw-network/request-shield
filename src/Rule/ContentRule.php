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
 * Attack patterns in the query string and the headers -- SQL injection, XSS,
 * code and shell injection, file inclusion, attack tools (rules/attacks.rules,
 * after the OWASP Core Rule Set's first level). A short value is matched
 * against one expression of all the target's patterns (one call); a longer
 * one pattern by pattern -- PCRE finds a pattern's fixed text (union,
 * <script) quickly on its own and loses that in the one expression, which
 * was twice as slow on a clean 1 KB query. The first pattern that matches,
 * and that no exception lets through, refuses.
 */
final class ContentRule implements Rule
{
    /** From this length on, pattern by pattern (measured: equal at about 100 bytes) */
    private const LONG = 128;

    /**
     * @param array<string, string> $index target => the combined expression
     * @param list<array{target: string, patterns: list<string>}> $rules
     * @param list<array{paths: list<string>, patterns: list<string>|null, ips: list<string>}> $exceptions
     * @param array<string, list<string>> $hints
     */
    public function __construct(private array $index, private array $rules, private array $exceptions = [], private array $hints = [])
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        foreach ($this->index as $target => $all) {
            // A text every pattern of the target needs (Settings::hints()):
            // not in the raw value, and nothing encoded -- nothing to find.
            if (isset($this->hints[$target]) && !$request->mayHold($target, $this->hints[$target])) {
                continue;
            }
            $content = $request->content($target);
            if ($content === '' || (!isset($content[self::LONG - 1]) && @preg_match($all, $content) !== 1)) {
                continue;
            }
            if (self::matched($this->rules, $this->exceptions, $target, $request) !== null) {
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

    public function explain(Decision $d, Request $request, Settings $s): ?string
    {
        if ($d->reason !== 'attack') {
            return null;
        }
        $p = self::matched($s->contentRules, $s->blockExceptions, null, $request);
        return $p === null ? null : $s->ruleName('contentRules', $p, 'contentRules');
    }
}
