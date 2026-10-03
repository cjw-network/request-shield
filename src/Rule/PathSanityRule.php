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
 * Paths no browser sends: a NUL byte, a broken %-escape, a ".." segment
 * (also %-encoded, once or twice), a path that is not UTF-8 once decoded.
 */
final class PathSanityRule implements Rule
{
    public function check(Request $request, float $now): ?Decision
    {
        $path = $request->path;
        if (strpos($path, "\0") !== false || preg_match('/%(?![0-9A-Fa-f]{2})/', $path)) {
            return Decision::reject(400, 'path encoding');
        }
        $decoded = rawurldecode($path);
        if (strpos($decoded, "\0") !== false || !preg_match('//u', $decoded)) {
            return Decision::reject(400, 'path encoding');
        }
        // Decoded twice: %252e%252e is "..", which some servers decode once more.
        foreach ([$decoded, rawurldecode($decoded)] as $candidate) {
            $candidate = str_replace('\\', '/', $candidate);
            if (preg_match('#(^|/)\.\.(/|$)#', $candidate)) {
                return Decision::reject(400, 'path traversal');
            }
        }
        return null;
    }

    public function explain(Decision $d, Request $request, Settings $s): ?string
    {
        return $d->reason === 'path encoding' || $d->reason === 'path traversal' ? 'built-in' : null;
    }
}
