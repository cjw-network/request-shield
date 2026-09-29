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
 * Paths that are only ever asked for by scanners (repository metadata,
 * environment files, backups, other applications' login pages): answered
 * with 404 before the application starts. Regular expressions on the
 * decoded, lower-case path.
 */
final class BlockedPathRule implements Rule
{
    /**
     * @param list<string> $patterns
     * @param list<array{paths: list<string>, patterns: list<string>|null, ips: list<string>}> $exceptions
     *   where blocked paths are let through anyway -- looked at only once a pattern matched
     */
    public function __construct(private array $patterns, private array $exceptions = [])
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        if ($this->patterns === []) {
            return null;
        }
        $path = strtolower(rawurldecode($request->path));
        foreach ($this->patterns as $pattern) {
            if (@preg_match($pattern, $path) === 1 && ($this->exceptions === [] || self::excepted($this->exceptions, $pattern, $request) === null)) {
                return Decision::reject(404, 'blocked path');
            }
        }
        return null;
    }

    /**
     * Whether an exception lets a blocked pattern through for this request:
     * its path, its patterns (null: every one), its addresses ([]: everyone).
     * Matched on the path as the application routes it.
     *
     * @param list<array{paths: list<string>, patterns: list<string>|null, ips: list<string>}> $exceptions
     */
    public static function excepted(array $exceptions, string $pattern, Request $request): ?int
    {
        foreach ($exceptions as $i => $x) {
            if (($x['patterns'] !== null && !in_array($pattern, $x['patterns'], true))
                || ($x['ips'] !== [] && !IpAddress::inRanges($request->clientIp, $x['ips']))) {
                continue;
            }
            foreach ($x['paths'] as $p) {
                if (@preg_match($p, $request->matchPath()) === 1) {
                    return $i;
                }
            }
        }
        return null;
    }
}
