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
 * Known query parameters and their types (proposal 0009): the cheapest check
 * of a query string, before the attack patterns.
 *
 *   query page int   sort word                 everywhere
 *   query SearchText text at /content/search   one path (or inside match)
 *   query strict                               anything else: 404
 *   query drop [at <paths>]                    anything else: dropped, the request goes on
 *
 * Every parameter is looked up (the path's own rules first, then those for
 * every path) and its value checked against its type. With "strict", an
 * unknown parameter or a value not of its type is refused at once. Without,
 * the request goes on -- and the attack patterns look only at the values that
 * could hold an attack: text, unknown, not of their type -- the whole pair,
 * name included. Typed values (a number, a word) are not scanned at all.
 */
final class QueryRule implements Rule
{
    /** @var array<string, string> name => type, for every path */
    private array $exact;

    /** @var array<string, string> name pattern => type, for every path */
    private array $globs;

    /** @var list<array{paths: list<string>, exact: array<string, string>, globs: array<string, string>}> the rules of some paths */
    private array $local;

    /**
     * @param array{exact: array<string, string>, globs: array<string, string>, local: list<array{paths: list<string>, exact: array<string, string>, globs: array<string, string>}>} $index from index()
     * @param ?string $own the shield's own addresses (widget-path and "/"): widget.js?v=…, its task -- answered by the shield, never by the site
     * @param list<string> $drop path patterns where an unknown parameter, or one not of its type, is dropped instead of refused
     */
    public function __construct(array $index, private bool $strict = false, private ?string $own = null, private array $drop = [])
    {
        ['exact' => $this->exact, 'globs' => $this->globs, 'local' => $this->local] = $index;
    }

    /**
     * The rules as one lookup instead of a walk through every line (built once,
     * with the settings): the rules for every path merged -- the first line
     * naming a parameter wins -- and those of some paths on their own.
     *
     * @param list<array{paths: list<string>|null, exact: array<string, string>, globs: array<string, string>}> $rules
     * @return array{exact: array<string, string>, globs: array<string, string>, local: list<array{paths: list<string>, exact: array<string, string>, globs: array<string, string>}>}
     */
    public static function index(array $rules): array
    {
        $index = ['exact' => [], 'globs' => [], 'local' => []];
        foreach ($rules as $r) {
            if ($r['paths'] === null) {
                $index['exact'] += $r['exact'];
                $index['globs'] += $r['globs'];
            } else {
                $index['local'][] = ['paths' => $r['paths'], 'exact' => $r['exact'], 'globs' => $r['globs']];
            }
        }
        return $index;
    }

    public function check(Request $request, float $now): ?Decision
    {
        if ($request->query === '') {
            return null;
        }
        // The shield's own script and task carry its own parameters (widget.js?v=<version>): not the site's to declare.
        if ($this->own !== null && strncmp($request->path, $this->own, strlen($this->own)) === 0) {
            return null;
        }
        $path = $this->local === [] && $this->drop === [] ? '' : $request->matchPath();
        // Left out only where the shield takes it out of the request (GET and HEAD); another method gets strict, as before.
        $drop = $this->drop !== [] && ($request->method === 'GET' || $request->method === 'HEAD') && self::onPath($this->drop, $path);
        $scan = [];
        foreach ($request->queryPairs() as [$name, $value, $raw]) {
            $type = $this->type($name, $path);
            if ($type === 'any') {
                continue;
            }
            if ($type === null || !self::fits($type, $value)) {
                if ($drop) {
                    // Left out once decided (Shield::queryForCaches()) -- scanned all the same: an attack in it is refused.
                    $request->drop($name);
                } elseif ($this->strict) {
                    return Decision::reject(404, 'unknown parameter');
                }
                $scan[] = $raw;             // unknown, or not of its type: scanned, name and all
                continue;
            }
            if ($type === 'text') {
                $scan[] = $raw;
            }
        }
        // What the attack patterns get to see of the query: only the pairs that
        // could hold an attack, as they stand in it (decoded there as always).
        $request->scanQuery(implode('&', $scan));
        return null;
    }

    /**
     * The type a parameter is declared with on this path, or null: the
     * path's own rules before those for every path; within them an exact
     * name before a name with "*".
     */
    public function type(string $name, string $path): ?string
    {
        foreach ($this->local as $r) {
            if (!self::onPath($r['paths'], $path)) {
                continue;
            }
            if (isset($r['exact'][$name])) {
                return $r['exact'][$name];
            }
            foreach ($r['globs'] as $glob => $type) {
                if (preg_match($glob, $name) === 1) {
                    return $type;
                }
            }
        }
        if (isset($this->exact[$name])) {
            return $this->exact[$name];
        }
        foreach ($this->globs as $glob => $type) {
            if (preg_match($glob, $name) === 1) {
                return $type;
            }
        }
        return null;
    }

    /**
     * The type a parameter is declared with here, or null (see type()).
     *
     * @param list<array{paths: list<string>|null, exact: array<string, string>, globs: array<string, string>}> $rules
     */
    public static function typeOf(array $rules, string $name, string $path): ?string
    {
        return (new self(self::index($rules)))->type($name, $path);
    }

    /** @param list<string> $patterns */
    private static function onPath(array $patterns, string $path): bool
    {
        foreach ($patterns as $p) {
            if (preg_match($p, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a (decoded) value is of its type. An empty value is of every
     * type: forms send their empty fields (?page=), and it holds nothing.
     */
    public static function fits(string $type, string $value): bool
    {
        if ($value === '') {
            return true;
        }
        switch ($type) {
            case 'int':
                return preg_match('/^-?\d{1,18}$/', $value) === 1;
            case 'number':
                return preg_match('/^-?\d{1,18}(?:\.\d{1,18})?$/', $value) === 1;
            case 'word':
                return preg_match('/^[\p{L}\p{N}._-]{1,64}$/u', $value) === 1;
            case 'id':
                return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value) === 1;
            case 'list':
                return preg_match('/^[\p{L}\p{N}._-]{1,64}(?:,[\p{L}\p{N}._-]{1,64}){0,63}$/u', $value) === 1;
            case 'text':
            case 'any':
                return true;
        }
        return strncmp($type, '#', 1) === 0 && @preg_match($type, $value) === 1;
    }

    public function explain(Decision $d, Request $request, Settings $s): ?string
    {
        return $d->reason === 'unknown parameter' ? $s->ruleName('query', 'strict', 'queryStrict') : null;
    }
}
