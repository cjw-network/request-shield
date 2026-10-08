<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

use CjwNetwork\RequestShield\Learn;
use CjwNetwork\RequestShield\Rule\QueryRule;
use CjwNetwork\RequestShield\Settings;

/**
 * The rule advisor's first part (proposal 0016, step 2): suggestions from a
 * learning run -- what the site's own clicks show it takes. The query
 * parameters and their types, `query strict` (watched first), the methods a
 * form is sent with and where (`allow`, watched first), forms only from the
 * site's own pages (`post-origin same`), the addresses its scripts call
 * (`api-path`). Only what the rules do not say yet; each a sentence and a
 * rule line built from a fixed template -- never text from the recording but
 * names and paths that passed a strict check.
 *
 * Never on a request: `request-shield advise` reads the recording, and the
 * command line checks the lines with the rule parser and replays the run
 * through the rules with them.
 */
final class Advise
{
    /** Parameters on one `query` line, at most. */
    private const PER_LINE = 8;

    /** A name or a path that may stand in a rule line as it is (no space, no quote, no "#", no "*": a pattern is the advisor's to make). */
    private const SAFE_NAME = '/^[A-Za-z0-9_.\-]{1,64}$/';

    private const SAFE_PATH = '#^/[A-Za-z0-9_.~\-/%]{0,200}$#';

    /** A folder a script's address names that is an API by its name (navigation strings in scripts are no API). */
    private const API_FOLDER = '/^(api|ajax|json|rest|graphql|wp-json|rpc|services?)$/i';

    /** Words a `query` line reads as its own. */
    private const RESERVED = ['at', 'strict', 'none'];

    /**
     * The recording's lines (learned.jsonl): one shape each.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $r = $line === '' ? null : json_decode($line, true);
            if (is_array($r) && is_string($r['method'] ?? null) && is_string($r['path'] ?? null)) {
                /** @var array<string, mixed> $r */
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * The suggestions, most useful first.
     *
     * @param list<array<string, mixed>> $records
     * @return list<array{id: string, text: string, rule: string, count: int}>
     */
    public static function suggest(array $records, Settings $s): array
    {
        $served = array_values(array_filter($records, static fn (array $r): bool => in_array($r['decided'] ?? 'allow', ['allow', 'allow-uncached'], true)
            && (!is_int($r['status'] ?? null) || $r['status'] < 400)));
        $out = [];

        // The query parameters: each name with the type all its values fit, and where it was seen; a
        // name only offered on a page (a link's or a script's "?page&sort", a GET form's field) as text --
        // still scanned. "name[]" is "name", as PHP reads it.
        $types = [];
        $where = [];
        $see = static function (string $name, string $type, string $path) use (&$types, &$where): void {
            if ($path === '') {
                return;                         // another host's address: none of this site's parameters
            }
            $name = (string) preg_replace('/\[[^\]]*\]$/', '', $name);
            $types[$name] = $type === '' ? ($types[$name] ?? '') : Learn::wider($types[$name] ?? null, $type);
            $where[$name][$path] = true;
        };
        foreach ($served as $r) {
            $path = is_string($r['path'] ?? null) ? $r['path'] : '/';
            foreach (is_array($r['query'] ?? null) ? $r['query'] : [] as $name => $type) {
                $see((string) $name, is_string($type) ? $type : 'text', $path);
            }
            foreach (array_merge(self::offered($r, 'links'), self::offered($r, 'scripts')) as $link) {
                foreach (self::linkNames($link) as $name) {
                    $see($name, '', self::resolve(is_string($link) ? $link : '', $path));
                }
            }
            foreach (self::offered($r, 'forms') as $form) {
                if (is_array($form) && strtoupper(is_string($form['method'] ?? null) ? $form['method'] : 'GET') === 'GET' && is_array($form['fields'] ?? null)) {
                    foreach (array_keys($form['fields']) as $name) {
                        $see((string) $name, '', self::resolve(is_string($form['action'] ?? null) ? $form['action'] : '', $path));
                    }
                }
            }
        }
        // Not declared yet -- where it was seen. Declared for some paths only (query … at …): the others
        // get a line of their own with "at", never one for every path.
        $params = [];
        $atLines = [];
        foreach ($types as $name => $type) {
            $name = (string) $name;
            $type = $type === '' ? 'text' : $type;
            if (preg_match(self::SAFE_NAME, $name) !== 1 || in_array(strtolower($name), self::RESERVED, true)) {
                continue;
            }
            $paths = array_map('strval', array_keys($where[$name] ?? ['/' => true]));
            $open = array_values(array_filter($paths, static fn (string $p): bool => QueryRule::typeOf($s->queryParams, $name, $p) === null));
            if ($open === []) {
                continue;
            }
            if (count($open) === count($paths) && !self::declaredLocally($s->queryParams, $name)) {
                $params[$name] = $type;
                continue;
            }
            $at = array_values(array_unique(array_filter(array_map([self::class, 'general'], array_filter($open, static fn (string $p): bool => preg_match(self::SAFE_PATH, $p) === 1)))));
            if ($at !== []) {
                sort($at);
                $atLines[] = "query $name $type at " . implode(' ', $at);
            }
        }
        ksort($params);
        $chunks = array_chunk($params, self::PER_LINE, true);
        foreach ($chunks as $i => $chunk) {
            $pairs = implode('  ', array_map(static fn (string $n, string $t): string => "$n $t", array_map('strval', array_keys($chunk)), $chunk));
            $out[] = ['id' => 'ADV-PARAMS' . ($i > 0 ? '-' . ($i + 1) : ''), 'count' => count($chunk),
                'text' => $i > 0 ? 'More of the query parameters your pages took.'
                    : count($params) . ' query parameter' . (count($params) === 1 ? '' : 's') . ' your pages took, each with the type all its values fit -- typed values are no longer scanned for attacks; nothing is refused for this line.',
                'rule' => "query $pairs"];
        }
        foreach ($atLines as $i => $line) {
            $out[] = ['id' => 'ADV-PARAMS-AT' . ($i > 0 ? '-' . ($i + 1) : ''), 'count' => 1, 'rule' => $line,
                'text' => 'A parameter your rules declare for other paths only, where your pages took it too.'];
        }
        // What the rules say already -- enforced, or watched (monitor …).
        $watched = $s->monitor instanceof Settings ? $s->monitor : null;
        $known = $params !== [] || $atLines !== [] || $s->queryParams !== [];
        $strict = $s->queryStrict || ($watched !== null && $watched->queryStrict);
        if (($known || $strict) && QueryRule::typeOf($s->queryParams, 'utm_source', '/') === null) {
            $out[] = ['id' => 'ADV-TRACKING', 'count' => 0, 'rule' => 'include @tracking',
                'text' => 'The marketing tags (utm_*, gclid, fbclid …) as known parameters, so links from newsletters and ads keep working with query strict.'];
        }
        if ($known && !$strict) {
            $out[] = ['id' => 'ADV-STRICT', 'count' => 0, 'rule' => 'monitor query strict',
                'text' => 'Anything else in the query answered 404 -- watched first (monitor): the log shows what it would refuse; take "monitor" off when that is only scanners.'];
        }

        // The methods a form is sent with, and where: what the run sent, and the forms its pages offered.
        $byMethod = [];
        foreach ($served as $r) {
            $method = strtoupper(is_string($r['method'] ?? null) ? $r['method'] : 'GET');
            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && is_string($r['path'] ?? null) && preg_match(self::SAFE_PATH, $r['path']) === 1) {
                $byMethod[$method][self::general($r['path'])] = true;
            }
            foreach (self::offered($r, 'forms') as $form) {
                if (!is_array($form)) {
                    continue;
                }
                $method = strtoupper(is_string($form['method'] ?? null) ? $form['method'] : 'GET');
                $action = is_string($form['action'] ?? null) ? (string) strtok($form['action'], '?') : '';
                if ($method !== 'GET' && preg_match(self::SAFE_PATH, $action) === 1) {
                    $byMethod[$method][self::general($action)] = true;   // a page's form says "/**"? never a pattern
                }
            }
        }
        ksort($byMethod);
        $forms = 0;
        foreach ($byMethod as $method => $paths) {
            $method = (string) $method;
            $paths = array_map('strval', array_keys($paths));
            if (preg_match('/^[A-Z]{3,10}$/', $method) !== 1) {
                continue;
            }
            sort($paths);
            $forms += count($paths);
            if (isset($s->methodPaths[$method]) || ($watched !== null && isset($watched->methodPaths[$method]))) {
                continue;                       // the rules say where already
            }
            $out[] = ['id' => 'ADV-' . $method, 'count' => count($paths), 'rule' => "monitor allow $method " . implode(' ', $paths),
                'text' => "$method only where your pages sent it (" . count($paths) . ' address' . (count($paths) === 1 ? '' : 'es') . ') -- anywhere else 405; watched first (monitor).'];
        }
        if ($forms > 0 && $s->postOrigin === null) {
            $out[] = ['id' => 'ADV-ORIGIN', 'count' => $forms, 'rule' => 'post-origin same',
                'text' => 'Forms only from your own pages: one sent from another website is refused, one without Origin or Referer gets the browser check. If another website posts to you on purpose (a payment provider\'s callback): post-origin except <its path>.'];
        }

        // What the site's scripts call: its API -- a check there answers as JSON, not as a page. Only a
        // folder that is one by its name (/api/, /ajax/ …) or where the run sent JSON: a script names
        // navigation too ("/en/products/"), and an API path takes a page's browser check away.
        $api = [];
        foreach ($served as $r) {
            foreach (self::offered($r, 'scripts') as $target) {
                $folder = is_string($target) ? self::folder((string) strtok($target, '?')) : null;
                if ($folder !== null && preg_match(self::API_FOLDER, substr($folder, 1, -3)) === 1) {
                    $api[$folder] = true;
                }
            }
            if (is_string($r['type'] ?? null) && strpos($r['type'], 'json') !== false && is_string($r['path'] ?? null)
                && preg_match(self::SAFE_PATH, $r['path']) === 1) {
                // Where the run sent JSON: that address, not its folder -- /de/cart/add is no reason to take
                // every page under /de/ for an API.
                $api[self::general($r['path'])] = true;
            }
        }
        $api = array_map('strval', array_keys($api));
        sort($api);
        if ($api !== [] && $s->challenge->apiPaths === []) {
            $out[] = ['id' => 'ADV-API', 'count' => count($api), 'rule' => 'api-path ' . implode(' ', $api),
                'text' => 'Addresses your pages\' scripts call: a check there answers as JSON with a header, which a script can read, not as a page.'];
        }
        return $out;
    }

    /**
     * The suggestions as a rule file: a comment and the line each.
     *
     * @param list<array{id: string, text: string, rule: string, count: int}> $suggestions
     */
    public static function file(array $suggestions, string $source, int $now): string
    {
        $out = "# Written by request-shield advise from $source, " . gmdate('Y-m-d H:i', $now) . " UTC.\n"
            . "# Suggestions from a learning run: read each, keep what fits. A line with \"monitor\" is\n"
            . "# watched only -- take the word off when the log shows it refuses nothing of yours.\n";
        foreach ($suggestions as $a) {
            $out .= "\n# " . str_replace("\n", ' ', $a['text']) . "\n" . (strncmp($a['rule'], 'include ', 8) === 0 ? '' : '[' . $a['id'] . '] ') . $a['rule'] . "\n";
        }
        return $out;
    }

    /**
     * A path as a rule pattern: a segment that is a number, a long hex key or a
     * UUID becomes "*" (/node/123/edit -> /node/*\/edit).
     */
    public static function general(string $path): string
    {
        $parts = explode('/', $path);
        foreach ($parts as $i => $p) {
            if (preg_match('/^(\d+|[0-9a-f]{16,}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $p) === 1) {
                $parts[$i] = '*';
            }
        }
        return implode('/', $parts);
    }

    /**
     * Whether a name is declared for some paths only (query … at …, a match block): then a
     * suggestion for it is never one for every path.
     *
     * @param list<array{paths: list<string>|null, exact: array<string, string>, globs: array<string, string>}> $rules
     */
    private static function declaredLocally(array $rules, string $name): bool
    {
        foreach ($rules as $r) {
            if ($r['paths'] === null) {
                continue;
            }
            if (isset($r['exact'][$name])) {
                return true;
            }
            foreach (array_keys($r['globs']) as $glob) {
                if (@preg_match((string) $glob, $name) === 1) {        // kept as an expression (#^utm_.*$#), as QueryRule reads it
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * An offered address as a path: "?q=1" and "" are the page's own, "page?x" the page's
     * folder's; "/x?y" itself.
     */
    private static function resolve(string $target, string $page): string
    {
        $path = (string) explode('?', $target, 2)[0];
        if ($path === '') {
            return $page;
        }
        if (strncmp($path, '//', 2) === 0) {
            return '';                          // another host's address: no path of this site
        }
        if ($path[0] !== '/') {
            $path = substr($page, 0, (int) strrpos($page, '/') + 1) . $path;
        }
        // "." and ".." as a browser reads them: /a/b/../y is /a/y.
        $out = [];
        foreach (explode('/', $path) as $i => $seg) {
            if ($seg === '..') {
                array_pop($out);
            } elseif ($seg !== '.' && ($seg !== '' || $i === 0)) {
                $out[] = $seg;
            }
        }
        return implode('/', $out) . (substr($path, -1) === '/' && count($out) > 1 ? '/' : '');
    }

    /**
     * The first folder of an address, as "<folder>/**" (/api/v1/messages -> /api/**); null for a page
     * at the top, or a folder that is no plain name (a script's placeholder: /${lang}/api -> "*").
     */
    private static function folder(string $path): ?string
    {
        if (preg_match(self::SAFE_PATH, $path) !== 1 || strpos($path, '/', 1) === false) {
            return null;
        }
        $seg = explode('/', trim($path, '/'))[0];
        return preg_match('/^[A-Za-z0-9_.~\-]+$/', $seg) === 1 ? "/$seg/**" : null;
    }

    /**
     * What a page offered (Learn::found()): links, scripts or forms.
     *
     * @param array<string, mixed> $r
     * @return list<mixed>
     */
    private static function offered(array $r, string $kind): array
    {
        $f = is_array($r['found'] ?? null) ? $r['found'] : [];
        return is_array($f[$kind] ?? null) ? array_values($f[$kind]) : [];
    }

    /**
     * The parameter names of an offered link ("/news/?page&sort").
     *
     * @return list<string>
     */
    private static function linkNames(mixed $link): array
    {
        if (!is_string($link) || strpos($link, '?') === false) {
            return [];
        }
        $names = [];
        foreach (explode('&', (string) substr($link, (int) strpos($link, '?') + 1)) as $pair) {
            $name = rawurldecode(explode('=', $pair, 2)[0]);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }
}
