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
 * Paths that are only ever asked for by scanners (repository metadata,
 * environment files, backups, other applications' login pages): answered
 * with 404 before the application starts. Regular expressions on the
 * decoded, lower-case path.
 */
final class BlockedPathRule implements Rule
{
    /** @param list<string> $patterns */
    public function __construct(private readonly array $patterns)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        if ($this->patterns === []) {
            return null;
        }
        $path = strtolower(rawurldecode($request->path));
        foreach ($this->patterns as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return Decision::reject(404, 'blocked path');
            }
        }
        return null;
    }
}
