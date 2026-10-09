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
 * The definition of what may be cached. A request outside it is not refused
 * -- the definition can lag behind the site (a page published a second ago)
 * -- but answered without being stored, so random paths and parameters never
 * fill a cache. An adapter can put its own URL index behind the $known hook.
 */
final class CacheableRule implements Rule
{
    /** @var array{0: int, 1: string}|null max_input_vars, and the separators PHP splits a query on but "&" -- read once a process */
    private static ?array $limits = null;

    /**
     * @param list<string>|null $paths regular expressions a cacheable path matches; null: any path
     * @param list<string>|null $query query parameter names a cacheable URL may carry; null: any
     * @param (callable(Request): ?bool)|null $known an adapter's own answer (null: no opinion)
     * @param list<string> $ignore names no cache key holds (cache-ignore, 0048): they never make a request uncacheable
     */
    public function __construct(
        private ?array $paths,
        private ?array $query,
        private $known = null,
        private array $ignore = [],
    ) {
    }

    /**
     * Whether a parameter is one no cache key holds (cache-ignore, 0048): its
     * name as PHP names it matches one of the globs.
     *
     * @param list<string> $ignore
     */
    public static function ignored(string $name, array $ignore): bool
    {
        if ($ignore === []) {
            return false;
        }
        $php = Request::phpName($name);
        foreach ($ignore as $glob) {
            if ($glob === $php || (strpos($glob, '*') !== false && preg_match('/^' . str_replace('\\*', '.*', preg_quote($glob, '/')) . '$/', $php) === 1)) {
                return true;
            }
        }
        return false;
    }

    public function check(Request $request, float $now): ?Decision
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return Decision::allowUncached('method');
        }
        // More parameters than PHP reads (max_input_vars): $_GET holds fewer than the query -- never kept.
        // (Counted only past 20 "&": a usual query pays one substr_count.)
        if ($request->query !== '') {
            $limits = self::$limits ??= [(int) ini_get('max_input_vars'), str_replace('&', '', (string) ini_get('arg_separator.input'))];
            if (substr_count($request->query, '&') >= 20 && $limits[0] > 0 && count($request->queryPairs()) > $limits[0]) {
                return Decision::allowUncached('too many parameters');
            }
            // PHP splitting on another character too (arg_separator.input "&;"): the shield's pairs are not PHP's.
            if ($limits[1] !== '' && strpbrk($request->query, $limits[1]) !== false) {
                return Decision::allowUncached('query separator');
            }
        }
        if ($this->query !== null) {
            foreach ($request->queryNames() as $name) {
                if (!in_array($name, $this->query, true) && !self::ignored($name, $this->ignore) && !isset($request->dropped()[$name])) {
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

    public function explain(Decision $d, Request $request, Settings $s): ?string
    {
        switch ($d->reason) {
            case 'query parameter':
                return $s->ruleName('cacheable.query', '*', 'cacheable.query');
            case 'path not cacheable':
                return $s->ruleName('cacheable.paths', '*', 'cacheable.paths');
            case 'method':
                return $d->action === Decision::REJECT ? null : 'built-in';     // a POST is never cached (the refusal is MethodRule's)
        }
        return null;
    }
}
