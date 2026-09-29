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
 * The definition of what may be cached. A request outside it is not refused
 * -- the definition can lag behind the site (a page published a second ago)
 * -- but answered without being stored, so random paths and parameters never
 * fill a cache. An adapter can put its own URL index behind the $known hook.
 */
final class CacheableRule implements Rule
{
    /**
     * @param list<string>|null $paths regular expressions a cacheable path matches; null: any path
     * @param list<string>|null $query query parameter names a cacheable URL may carry; null: any
     * @param (callable(Request): ?bool)|null $known an adapter's own answer (null: no opinion)
     */
    public function __construct(
        private ?array $paths,
        private ?array $query,
        private $known = null,
    ) {
    }

    public function check(Request $request, float $now): ?Decision
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return Decision::allowUncached('method');
        }
        if ($this->query !== null) {
            foreach ($request->queryNames() as $name) {
                if (!in_array($name, $this->query, true)) {
                    return Decision::allowUncached('query parameter');
                }
            }
        }
        if ($this->known !== null) {
            $answer = ($this->known)($request);
            if ($answer === false) {
                return Decision::allowUncached('unknown url');
            }
            if ($answer === true) {
                return null;
            }
        }
        if ($this->paths !== null) {
            foreach ($this->paths as $pattern) {
                if (@preg_match($pattern, $request->path) === 1) {
                    return null;
                }
            }
            return Decision::allowUncached('path not cacheable');
        }
        return null;
    }
}
