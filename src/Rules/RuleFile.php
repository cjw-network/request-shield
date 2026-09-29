<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

use CjwNetwork\RequestShield\Config;
use CjwNetwork\RequestShield\Texts;

/**
 * Rule files: the settings written one rule per line, for people rather than
 * PHP (docs/features/rule-files.md).
 *
 *   host        www.example.org example.org
 *   block       /wp-admin/**  *.sql
 *   limit       requests 600/min challenge-at 300
 *   challenge   /login
 *   include     rules.d/*.rules
 *
 * They are read, never executed, and turned into the same settings array a
 * PHP settings file returns -- Settings::from() checks it as always. Only
 * when they changed: Settings::load() keeps the result compiled.
 */
final class RuleFile
{
    /** The sets "block @name" adds and "unblock @name" takes away. */
    private const SETS = ['scanners', 'wordpress'];

    /** "set" keys => [setting path, type]. */
    private const SET = [
        'store' => ['store', 'store'],
        'store-dir' => ['storeDir', 'string'],
        'ipv6-prefix' => ['ipv6Prefix', 'int'],
        'debug-header' => ['debugHeader', 'bool'],
        'app-challenge' => ['appChallenge', 'bool'],
        'strip-untrusted-forwarded' => ['stripUntrustedForwarded', 'bool'],
        'max-uri' => ['limits.uri', 'int'],
        'max-query-parameters' => ['limits.queryParameters', 'int'],
        'max-header-bytes' => ['limits.headerBytes', 'int'],
        'secret' => ['challenge.secret', 'string'],
        'pass-ttl' => ['challenge.passTtl', 'seconds'],
        'solution-ttl' => ['challenge.solutionTtl', 'seconds'],
        'difficulty-min' => ['challenge.difficulty.min', 'int'],
        'difficulty-max' => ['challenge.difficulty.max', 'int'],
        'cookie' => ['challenge.cookie', 'string'],
        'solution-cookie' => ['challenge.solutionCookie', 'string'],
        'bind-user-agent' => ['challenge.bindUserAgent', 'bool'],
        'search-engines' => ['challenge.searchEngines', 'bool'],
        'recheck' => ['recheck', 'seconds'],
        'language' => ['challenge.language', 'language'],
        'home' => ['challenge.home', 'string'],
        'log' => ['log.file', 'path'],
        'log-level' => ['log.level', 'loglevel'],
        'log-ip' => ['log.ip', 'logip'],
        'log-max-size' => ['log.maxSize', 'bytes'],
    ];

    /** @var array<string, mixed> */
    private array $c;

    /** @var array<string, array{0: int, 1: int}> every file and include directory read, with mtime and size */
    private array $seen = [];

    /** @var array<string, array<string, string>> setting => pattern or budget => where it was set ("site.rules:12") */
    private array $origins = [];

    private string $base = '';

    /** @var array<string, string|null> the environment variables used, with their values */
    private array $env = [];

    /** @var list<string> the files being read, for include loops */
    private array $stack = [];

    /** The rule being read: its own ID ("SITE-10") or, without one, where it is ("site.rules:12"). */
    private string $rid = '';

    /** @var array<string, array{0: string, 1: bool}> file => its namespace ("SITE") and whether IDs are required */
    private array $ns = [];

    /** @var array<string, string> ID => where it was given, for duplicates */
    private array $ids = [];

    /** @var array<string, string> file => its "version" */
    private array $versions = [];

    /** @var list<string> reviewed revisions that differ from the rules' own: for check and the rules page */
    private array $warnings = [];

    /** The shipped rule files: rules/<name>.rules ("@scanners", "@wordpress"). */
    public static function shipped(string $name): ?string
    {
        $file = dirname(__DIR__, 2) . '/rules/' . $name . '.rules';
        return preg_match('/^[a-z0-9-]+$/', $name) && is_file($file) ? $file : null;
    }

    private function __construct()
    {
        $this->c = Config::defaults();
        $this->c['recheck'] = 10;
        // The built-in blocks come from rules/scanners.rules, read first, so
        // they have IDs and descriptions like every other rule.
        $this->c['blockedPaths'] = [];
        $this->origins['budgets']['requests'] = 'default';
    }

    /**
     * Reads $files in order (a later one adds to and overrides an earlier
     * one), each with its includes.
     *
     * @param list<string> $files paths or globs; a glob may match nothing
     * @return array{config: array<string, mixed>, seen: array<string, array{0: int, 1: int}>, env: array<string, string|null>, recheck: int}
     *   config: the settings array, with 'origins' (setting => pattern or budget => "file:line")
     * @throws RuleFileException naming file and line
     */
    public static function read(array $files): array
    {
        $r = new self();
        // Origins are named relative to the main file's directory (the last one).
        $main = $files === [] ? false : realpath(dirname($files[count($files) - 1]));
        $r->base = $main === false ? '' : $main . '/';
        $r->file((string) self::shipped('scanners'), null, null);
        foreach ($files as $file) {
            $r->source($file, null, null);
        }
        $recheck = $r->c['recheck'];
        unset($r->c['recheck']);
        foreach ($r->warnings as $i => $w) {
            $r->origins['warnings']['w' . $i] = $w;      // not numeric: PHP would make it an int key
        }
        $r->c['origins'] = $r->origins;
        return ['config' => $r->c, 'seen' => $r->seen, 'env' => $r->env, 'recheck' => is_int($recheck) ? $recheck : 10];
    }

    /**
     * The mtime and size of a file or directory, as read() records them.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function stat(string $path): ?array
    {
        $mtime = @filemtime($path);
        $size = @filesize($path);
        return $mtime === false || $size === false ? null : [$mtime, $size];
    }

    /**
     * A path or glob from the top level (null $below) or an include.
     */
    private function source(string $pattern, ?string $below, ?string $where): void
    {
        if (strpbrk($pattern, '*?[') === false) {
            $this->file($pattern, $below, $where);
            return;
        }
        // A glob: the files in alphabetical order, and its directory recorded,
        // so a file added there later (an extension installed) is noticed.
        $dir = dirname($pattern);
        if (strpbrk($dir, '*?[') === false) {
            $stat = self::stat($dir);
            if ($stat !== null) {
                $this->seen[$dir] = $stat;
            }
        }
        $matches = glob($pattern) ?: [];
        sort($matches, SORT_STRING);
        foreach ($matches as $file) {
            if (is_file($file)) {
                $this->file($file, $below, $where);
            }
        }
    }

    private function file(string $file, ?string $below, ?string $where): void
    {
        $real = realpath($file);
        if ($real === false || !is_file($real)) {
            throw new RuleFileException(($where ?? 'request-shield') . ": cannot read the rule file $file");
        }
        if ($below !== null && strncmp($real, $below . '/', strlen($below) + 1) !== 0) {
            throw new RuleFileException("$where: $file is outside " . $below . ' (an include stays below the including file, unless its path is absolute)');
        }
        if (in_array($real, $this->stack, true)) {
            throw new RuleFileException("$where: $file includes itself");
        }
        // Recorded before it is read: a change while reading is noticed next time.
        $stat = self::stat($file);
        $text = @file_get_contents($file);
        if ($stat === null || $text === false) {
            throw new RuleFileException(($where ?? 'request-shield') . ": cannot read the rule file $file");
        }
        $this->seen[$file] = $stat;
        $this->stack[] = $real;
        $shipped = dirname(__DIR__, 2) . '/rules/';
        $name = strncmp($real, $shipped, strlen($shipped)) === 0 ? 'built-in ' . substr($real, strlen($shipped))
            : ($this->base !== '' && strncmp($real, $this->base, strlen($this->base)) === 0 ? substr($real, strlen($this->base)) : $real);
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $i => $line) {
            $this->line($line, "$name:" . ($i + 1), $file);
        }
        if (isset($this->versions[$file])) {
            // Named by the file's namespace, else by the file.
            $this->origins['versions'][$this->ns[$file][0] ?? $name] = $this->versions[$file];
        }
        array_pop($this->stack);
    }

    private function line(string $line, string $at, string $file): void
    {
        // "#" starts a comment at the start of a line or after a space; "\#"
        // is a literal "#" (in a regex, say). A comment after a rule is its
        // description, for people (the active rules page).
        $text = null;
        if (preg_match('/(?:^|\s)#\s*(.*)$/', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $text = trim($m[1][0]);
            $line = substr($line, 0, $m[0][1]);
        }
        $line = trim(str_replace('\\#', '#', $line));
        if ($line === '') {
            return;
        }
        // [SITE-10] before a rule: its own ID, used everywhere instead of file:line.
        // [SCAN-BACKUP@3]: revision 3 of that rule, raised when it changes its meaning.
        $id = null;
        $rev = null;
        if (preg_match('/^\[([^\]]*)\]\s*(.*)$/', $line, $m) === 1) {
            [$id, $rev] = self::ref($m[1], $at);
            $line = $m[2];
            if ($line === '') {
                throw new RuleFileException("$at: [$id] before what? The rule follows the ID on the same line");
            }
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        $keyword = strtolower((string) array_shift($parts));
        $args = array_map(fn (string $a): string => $this->env($a, $at), $parts);

        if ($keyword === 'ids') {
            $this->namespace($args, $at, $file, $id);
            return;
        }
        if ($keyword === 'version') {
            if ($id !== null || count($args) !== 1 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,39}$/', $args[0])) {
                throw new RuleFileException("$at: version <version> -- one word: 2026-09-29.2, 1.4.0");
            }
            if (isset($this->versions[$file])) {
                throw new RuleFileException("$at: a file has one version -- already {$this->versions[$file]}");
            }
            $this->versions[$file] = $args[0];
            return;
        }
        if ($keyword === 'replace') {
            if ($id !== null) {
                throw new RuleFileException("$at: replace [<ID>] <rule> -- the ID follows replace");
            }
            $this->replace($parts, $line, $at, $file, $text);
            return;
        }
        [$ns, $required] = $this->ns[$file] ?? ['', false];
        if ($id !== null) {
            if ($keyword === 'set' || $keyword === 'include') {
                throw new RuleFileException("$at: $keyword takes no ID -- it is not a rule");
            }
            if ($ns !== '' && strncmp($id, $ns . '-', strlen($ns) + 1) !== 0) {
                throw new RuleFileException("$at: [$id] is not in this file's namespace -- its IDs start with $ns- ([$ns-10])");
            }
            if (isset($this->ids[$id]) && $this->ids[$id] !== $at) {
                throw new RuleFileException("$at: [$id] is used twice -- already at {$this->ids[$id]}");
            }
            $this->ids[$id] = $at;
            $this->origins['at'][$id] = $at;
            if ($rev !== null) {
                $this->origins['rev'][$id] = $rev;
            }
        } elseif ($required && $keyword !== 'set' && $keyword !== 'include') {
            throw new RuleFileException("$at: every rule in this file needs an ID ([$ns-...] before it: ids $ns required)");
        }
        $this->rid = $id ?? $at;
        if ($text !== null && $text !== '' && $keyword !== 'set' && $keyword !== 'include') {
            $this->origins['text'][$this->rid] = $text;
        }

        $this->dispatch($keyword, $args, $line, $at, $file);
    }

    /**
     * One rule, its keyword and values; $line for "set" (texts have spaces).
     *
     * @param list<string> $args
     */
    private function dispatch(string $keyword, array $args, string $line, string $at, string $file): void
    {
        switch ($keyword) {
            case 'host':
                $this->list('hosts', $args, $at, static fn (string $h): string => strtolower($h));
                $this->origins['hosts']['*'] = $this->rid;
                return;
            case 'restrict':
                $this->restrict($args, $at);
                return;
            case 'allow':
                $this->allow($args, $at);
                return;
            case 'trust':
            case 'exempt':
                $key = $keyword === 'trust' ? 'trustedProxies' : 'exempt.ips';
                $this->list($key, $args, $at, static fn (string $ip): string => self::address($ip, $at));
                return;
            case 'method':
                $this->list('methods', $args, $at, static function (string $m) use ($at): string {
                    if (!preg_match('/^[A-Za-z]+$/', $m)) {
                        throw new RuleFileException("$at: \"$m\" is not a method");
                    }
                    return strtoupper($m);
                });
                $this->origins['methods']['*'] = $this->rid;
                return;
            case 'block':
                if (in_array($args[0] ?? '', ['query', 'header', 'headers', 'anywhere'], true)) {
                    $this->contentBlock($args, $at);
                    return;
                }
                $this->patterns('blockedPaths', $args, $at, true);
                return;
            case 'unblock':
                $this->unblock($args, $at);
                return;
            case 'cache-path':
                $this->patterns('cacheable.paths', $args, $at, false);
                $this->origins['cacheable.paths']['*'] = $this->rid;
                return;
            case 'cache-query':
                $this->list('cacheable.query', $args, $at, static fn (string $q): string => $q);
                $this->origins['cacheable.query']['*'] = $this->rid;
                return;
            case 'challenge':
                $this->patterns('challenge.alwaysPaths', $args, $at, false);
                return;
            case 'challenge-exempt':
                $this->patterns('challenge.exemptPaths', $args, $at, false);
                return;
            case 'limit':
                $this->limit($args, $at);
                return;
            case 'no-limit':
                if (count($args) !== 1) {
                    throw new RuleFileException("$at: no-limit takes the name of one budget");
                }
                // Limit 0 switches a budget off, a default one too.
                $this->put("budgets.$args[0]", ['limit' => 0]);
                unset($this->origins['budgets'][$args[0]]);
                return;
            case 'set':
                $this->set($line, $at, $file);
                return;
            case 'include':
                if ($args === []) {
                    throw new RuleFileException("$at: include of what?");
                }
                foreach ($args as $path) {
                    if ($path[0] === '@') {
                        $shipped = self::shipped(substr($path, 1));
                        if ($shipped === null) {
                            throw new RuleFileException("$at: unknown set \"$path\" (there are @" . implode(', @', self::SETS) . ')');
                        }
                        if (!isset($this->seen[$shipped])) {
                            $this->file($shipped, null, $at);
                        }
                        continue;
                    }
                    $absolute = $path[0] === '/';
                    if (!$absolute && preg_match('#(^|/)\.\.(/|$)#', $path)) {
                        throw new RuleFileException("$at: $path is outside " . dirname($file) . ' (an include stays below the including file, unless its path is absolute)');
                    }
                    $dir = dirname($file);
                    $real = realpath($dir);
                    $this->source($absolute ? $path : $dir . '/' . $path, $absolute ? null : ($real === false ? $dir : $real), $at);
                }
                return;
        }
        throw new RuleFileException("$at: unknown rule \"$keyword\"" . self::suggest($keyword,
            ['host', 'trust', 'exempt', 'method', 'allow', 'restrict', 'block', 'unblock', 'cache-path', 'cache-query', 'challenge', 'challenge-exempt', 'limit', 'no-limit', 'set', 'include']));
    }

    /**
     * "SCAN-BACKUP" or "SCAN-BACKUP@3".
     *
     * @return array{0: string, 1: ?string} ID and revision
     */
    private static function ref(string $ref, string $at): array
    {
        if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]{0,47})(?:@([1-9][0-9]{0,5}))?$/', $ref, $m)) {
            throw new RuleFileException("$at: \"[$ref]\" is not an ID -- letters, digits, \"-\", \"_\" and \".\", a revision after @ ([SITE-10], [SCAN-BACKUP@3])");
        }
        return [$m[1], isset($m[2]) ? $m[2] : null];
    }

    /**
     * A rule that takes back, replaces or opens another names the revision it
     * was written for; when that rule has changed since (a library update),
     * say so -- the rule still applies, only the deviation needs a look.
     */
    private function pin(string $id, string $rev, string $at): void
    {
        $now = $this->origins['rev'][$id] ?? '1';
        if ($now !== $rev) {
            $this->warnings[] = "$this->rid ($at) was written for $id revision $rev; $id is now revision $now ("
                . ($this->origins['at'][$id] ?? $id) . ') -- please check what changed';
        }
    }

    /**
     * replace [<ID>@<rev>] <rule>: the rule with that ID is taken back
     * everywhere it applies, and this one takes its place -- same ID, so the
     * log and the rules page go on counting it.
     *
     * @param list<string> $parts
     */
    private function replace(array $parts, string $line, string $at, string $file, ?string $text): void
    {
        $ref = (string) array_shift($parts);
        if (!preg_match('/^\[(.+)\]$/', $ref, $m) || $parts === []) {
            throw new RuleFileException("$at: replace [<ID>] <rule> -- replace [SCAN-BACKUP@1] block *.sql *.bak");
        }
        [$id, $rev] = self::ref($m[1], $at);
        $was = $this->origins['at'][$id] ?? null;
        if ($was === null && !$this->forget($id, true)) {
            throw new RuleFileException("$at: no earlier rule has the ID [$id]");
        }
        $this->rid = $id;
        if ($rev !== null) {
            $this->pin($id, $rev, $at);
        }
        $this->forget($id, false);
        $keyword = strtolower((string) array_shift($parts));
        if (in_array($keyword, ['set', 'include', 'ids', 'version', 'replace'], true)) {
            throw new RuleFileException("$at: replace takes a rule, not \"$keyword\"");
        }
        $this->ids[$id] = $at;
        $this->origins['at'][$id] = "$at (replaces " . ($was ?? $id) . ')';
        if ($text !== null && $text !== '') {
            $this->origins['text'][$id] = $text;
        }
        $args = array_map(fn (string $a): string => $this->env($a, $at), $parts);
        $this->dispatch($keyword, $args, (string) preg_replace('/^\s*replace\s+\S+\s+/i', '', $line), $at, $file);
    }

    /**
     * Takes back what the rule with $id set, wherever that is. With $probe
     * only says whether there is anything.
     */
    private function forget(string $id, bool $probe): bool
    {
        $found = false;
        foreach (['blockedPaths', 'cacheable.paths', 'challenge.alwaysPaths', 'challenge.exemptPaths'] as $key) {
            $list = $this->get($key);
            if (!is_array($list)) {
                continue;
            }
            $keep = [];
            foreach ($list as $p) {
                if (is_string($p) && ($this->origins[$key][$p] ?? null) === $id) {
                    $found = true;
                    unset($this->origins[$key][$p]);
                    continue;
                }
                $keep[] = $p;
            }
            if (!$probe) {
                $this->put($key, $keep);
            }
        }
        $mine = [];
        foreach ($this->origins['contentRules'] ?? [] as $p => $origin) {
            if ($origin === $id) {
                $mine[] = (string) $p;
            }
        }
        if ($mine !== []) {
            $found = true;
            if (!$probe) {
                $this->dropContent($mine);
                foreach ($mine as $p) {
                    unset($this->origins['contentRules'][$p]);
                }
            }
        }
        foreach (['restricted', 'blockExceptions'] as $key) {
            $keep = [];
            foreach ((array) $this->get($key) as $entry) {
                $paths = is_array($entry) && is_array($entry['paths'] ?? null) ? $entry['paths'] : [];
                $first = $paths[0] ?? null;
                if (is_string($first) && ($this->origins[$key][$first] ?? null) === $id) {
                    $found = true;
                    continue;
                }
                $keep[] = $entry;
            }
            if (!$probe) {
                $this->put($key, $keep);
            }
        }
        foreach ($this->origins['budgets'] ?? [] as $name => $origin) {
            if ($origin === $id) {
                $found = true;
                if (!$probe) {
                    $this->put("budgets.$name", ['limit' => 0]);
                    unset($this->origins['budgets'][$name]);
                }
            }
        }
        foreach ($this->origins['methodPaths'] ?? [] as $method => $origin) {
            if ($origin === $id) {
                $found = true;
                if (!$probe) {
                    $all = (array) $this->get('methodPaths');
                    unset($all[$method]);
                    $this->put('methodPaths', $all);
                    unset($this->origins['methodPaths'][$method]);
                }
            }
        }
        foreach (['hosts' => [], 'methods' => Config::defaults()['methods'], 'cacheable.query' => null] as $key => $reset) {
            if (($this->origins[$key]['*'] ?? null) === $id) {
                $found = true;
                if (!$probe) {
                    $this->put($key, $reset);
                    unset($this->origins[$key]['*']);
                }
            }
        }
        return $found;
    }

    /**
     * ids <NS> [required]: the IDs in this file start with "<NS>-"; with
     * "required", every rule needs one. For number blocks per file: the site
     * SITE, an extension SHOP, the built-ins SCAN and WP.
     *
     * @param list<string> $args
     */
    private function namespace(array $args, string $at, string $file, ?string $id): void
    {
        if ($id !== null || $args === [] || count($args) > 2 || (isset($args[1]) && $args[1] !== 'required')
            || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,23}$/', $args[0])) {
            throw new RuleFileException("$at: ids <NAMESPACE> [required] -- letters and digits, e.g. ids SHOP");
        }
        if (isset($this->ns[$file])) {
            throw new RuleFileException("$at: a file has one namespace -- already ids {$this->ns[$file][0]}");
        }
        $this->ns[$file] = [$args[0], isset($args[1])];
    }

    /**
     * Adds to a list. "none" first empties it -- the defaults too; for
     * cache-path and cache-query "any" means no restriction at all.
     *
     * @param list<string> $args
     * @param callable(string): string $each
     */
    private function list(string $key, array $args, string $at, callable $each): void
    {
        if ($args === []) {
            throw new RuleFileException("$at: " . strtok($key, '.') . ' needs at least one value');
        }
        $list = $this->get($key);
        if ($args === ['any'] && strncmp($key, 'cacheable.', 10) === 0) {
            $this->put($key, null);
            return;
        }
        if ($args[0] === 'none') {
            $list = [];
            array_shift($args);
        }
        $list = is_array($list) ? $list : [];
        foreach ($args as $a) {
            $v = $each($a);
            if (!in_array($v, $list, true)) {
                $list[] = $v;
            }
        }
        $this->put($key, $list);
    }

    /**
     * Globs, or "regex <expression>...". For block: "@scanners", "@wordpress".
     * "none" first empties the list, the defaults too. Each pattern's origin
     * (file:line) is kept, so a decision can name the rule behind it.
     *
     * @param list<string> $args
     */
    private function patterns(string $key, array $args, string $at, bool $sets): void
    {
        if ($args === []) {
            throw new RuleFileException("$at: " . strtok($key, '.') . ' needs at least one value');
        }
        if ($args === ['any'] && $key === 'cacheable.paths') {
            $this->put($key, null);
            unset($this->origins[$key]);
            return;
        }
        if ($args[0] === 'none') {
            $this->put($key, []);
            unset($this->origins[$key]);
        }
        // Compiled first: a set ("@wordpress") reads its file now, which adds
        // its blocks with their own IDs.
        $compiled = $this->compile($args, $at, $sets);
        $list = $this->get($key);
        $list = is_array($list) ? $list : [];
        foreach ($compiled as $pattern => $origin) {
            if (!in_array($pattern, $list, true)) {
                $list[] = $pattern;
                $this->origins[$key][$pattern] = $origin;
            }
        }
        $this->put($key, $list);
    }

    /**
     * @param list<string> $args
     * @return array<string, string> pattern => origin
     */
    private function compile(array $args, string $at, bool $sets): array
    {
        $out = [];
        $regex = false;
        foreach ($args as $a) {
            if ($a === 'none') {
                continue;
            }
            if ($a === 'regex') {
                $regex = true;
                continue;
            }
            if (!$regex && ($a[0] === '@' || $a[0] === '[')) {
                if (!$sets) {
                    throw new RuleFileException("$at: $a only works with block and unblock");
                }
                foreach ($this->refer($a, $at) as $p => $origin) {
                    $out[$p] = $origin;
                }
                continue;
            }
            $pattern = $regex ? Pattern::fromRegex($a) : Pattern::fromGlob($a);
            if (!Pattern::valid($pattern)) {
                throw new RuleFileException("$at: \"$a\" is not a valid regular expression");
            }
            $out[$pattern] = $this->rid;
            // As written, for people (the rules page): "/wp-admin/**", not the expression.
            $this->origins['written'][$pattern] = $this->origins['written'][$pattern . 'i'] = ($regex ? 'regex ' : '') . $a;
        }
        if ($regex && $out === []) {
            throw new RuleFileException("$at: regex of what?");
        }
        return $out;
    }

    /**
     * The blocks a reference stands for: "[SCAN-BACKUP]" one rule by its ID,
     * "@wordpress" every block of a shipped file (read now if it was not).
     *
     * @return array<string, string> pattern => its rule's ID
     */
    private function refer(string $ref, string $at): array
    {
        $out = [];
        if ($ref[0] === '[') {
            [$id, $rev] = self::ref(trim($ref, '[]'), $at);
            if ($rev !== null) {
                $this->pin($id, $rev, $at);
            }
            foreach (($this->origins['blockedPaths'] ?? []) + ($this->origins['contentRules'] ?? []) as $p => $origin) {
                if ($origin === $id) {
                    $out[$p] = $origin;
                }
            }
            if ($out === []) {
                throw new RuleFileException("$at: no earlier block has the ID $ref");
            }
            return $out;
        }
        $file = self::shipped(substr($ref, 1));
        if ($file === null) {
            throw new RuleFileException("$at: unknown set \"$ref\" (there are @" . implode(', @', self::SETS) . ')');
        }
        $real = (string) realpath($file);
        if (!isset($this->seen[$file])) {
            $saved = $this->rid;
            $this->file($file, null, $at);
            $this->rid = $saved;
        }
        foreach (($this->origins['blockedPaths'] ?? []) + ($this->origins['contentRules'] ?? []) as $p => $origin) {
            if (strncmp((string) ($this->origins['at'][$origin] ?? ''), 'built-in ' . basename($real) . ':', strlen(basename($real)) + 10) === 0) {
                $out[$p] = $origin;
            }
        }
        return $out;
    }

    /**
     * unblock <patterns or sets>: take back an earlier block.
     * unblock [<patterns or sets>] at <paths> [for <addresses>]: let blocked
     * paths through at some paths only (an admin's file reader), for some
     * addresses only; without patterns every block, without "for" everyone.
     *
     * @param list<string> $args
     */
    private function unblock(array $args, string $at): void
    {
        if ($args === []) {
            throw new RuleFileException("$at: unblock what?");
        }
        $where = array_search('at', $args, true);
        if ($where !== false) {
            $this->blockException($args, (int) $where, $at);
            return;
        }
        $list = [];
        foreach ((array) $this->get('blockedPaths') as $p) {
            $list[] = is_string($p) ? $p : '';
        }
        $remove = array_keys($this->compile($args, $at, true));
        $content = $this->contentPatterns();
        foreach ($remove as $pattern) {
            if (!in_array($pattern, $list, true) && !in_array($pattern, $content, true)) {
                throw new RuleFileException("$at: nothing to unblock -- no earlier block matches " . implode(' ', $args) . ' exactly');
            }
        }
        $this->put('blockedPaths', array_values(array_diff($list, $remove)));
        $this->dropContent($remove);
        foreach ($remove as $pattern) {
            unset($this->origins['blockedPaths'][$pattern], $this->origins['contentRules'][$pattern]);
        }
    }

    /**
     * block query|headers|anywhere <regex>..., block header <Name> <regex>...:
     * attack patterns in the query string and the headers, matched after
     * Request::content() normalised them (decoded, lower case). Regular
     * expressions always; "regex" may be written, it changes nothing.
     *
     * @param list<string> $args
     */
    private function contentBlock(array $args, string $at): void
    {
        $where = (string) array_shift($args);
        if ($where === 'header') {
            $name = strtolower((string) array_shift($args));
            if (!preg_match('/^[a-z0-9-]+$/', $name)) {
                throw new RuleFileException("$at: block header <Name> <regex> -- block header User-Agent sqlmap");
            }
            $where = "header:$name";
        }
        if (($args[0] ?? '') === 'regex') {
            array_shift($args);
        }
        if ($args === []) {
            throw new RuleFileException("$at: block $where <regex> -- what to look for");
        }
        $patterns = [];
        foreach ($this->compile(array_merge(['regex'], $args), $at, false) as $p => $origin) {
            $p .= 'i';
            $patterns[] = $p;
            $this->origins['contentRules'][$p] = $origin;
            $this->origins['written'][$p] = $where . ' ' . ($this->origins['written'][substr($p, 0, -1)] ?? $p);
        }
        $rules = (array) $this->get('contentRules');
        $rules[] = ['target' => $where, 'patterns' => $patterns];
        $this->put('contentRules', $rules);
    }

    /** @return list<string> every attack pattern so far */
    private function contentPatterns(): array
    {
        $out = [];
        foreach ((array) $this->get('contentRules') as $r) {
            foreach (is_array($r) && is_array($r['patterns'] ?? null) ? $r['patterns'] : [] as $p) {
                if (is_string($p)) {
                    $out[] = $p;
                }
            }
        }
        return $out;
    }

    /** @param list<string> $patterns attack patterns to take out */
    private function dropContent(array $patterns): void
    {
        $rules = [];
        foreach ((array) $this->get('contentRules') as $r) {
            if (!is_array($r)) {
                continue;
            }
            $keep = [];
            foreach ((array) ($r['patterns'] ?? []) as $p) {
                if (is_string($p) && !in_array($p, $patterns, true)) {
                    $keep[] = $p;
                }
            }
            $r['patterns'] = $keep;
            if ($r['patterns'] !== []) {
                $rules[] = $r;
            }
        }
        $this->put('contentRules', $rules);
    }

    /** @param list<string> $args */
    private function blockException(array $args, int $where, string $at): void
    {
        $usage = 'unblock [<what>] at <paths> [for <addresses>]';
        $for = array_search('for', $args, true);
        $pathArgs = array_slice($args, $where + 1, $for === false ? null : (int) $for - $where - 1);
        if ($pathArgs === [] || ($for !== false && ($for < $where || $for === count($args) - 1))) {
            throw new RuleFileException("$at: $usage");
        }
        $patterns = null;
        if ($where > 0) {
            $patterns = array_keys($this->compile(array_slice($args, 0, $where), $at, true));
            $blocked = array_merge((array) $this->get('blockedPaths'), $this->contentPatterns());
            foreach ($patterns as $p) {
                if (!in_array($p, $blocked, true)) {
                    throw new RuleFileException("$at: nothing to unblock -- no earlier block matches " . implode(' ', array_slice($args, 0, $where)) . ' exactly');
                }
            }
        }
        $ips = [];
        foreach ($for === false ? [] : array_slice($args, (int) $for + 1) as $ip) {
            $ips[] = self::address($ip, $at);
        }
        $paths = [];
        foreach ($this->compile($pathArgs, $at, false) as $pattern => $_) {
            $pattern .= 'i';
            $paths[] = $pattern;
            $this->origins['blockExceptions'][$pattern] = $this->rid;
        }
        $list = $this->get('blockExceptions');
        $list = is_array($list) ? $list : [];
        $list[] = ['paths' => $paths, 'patterns' => $patterns, 'ips' => $ips];
        $this->put('blockExceptions', $list);
    }

    /**
     * restrict <path patterns> to <addresses or ranges>: everyone else 403.
     * Case does not matter, and the path is matched as the application routes
     * it (Request::matchPath()).
     *
     * @param list<string> $args
     */
    private function restrict(array $args, string $at): void
    {
        $to = array_search('to', $args, true);
        if ($to === false || $to === 0 || $to === count($args) - 1) {
            throw new RuleFileException("$at: restrict <paths> to <addresses or ranges>");
        }
        $ips = [];
        foreach (array_slice($args, $to + 1) as $ip) {
            $ips[] = self::address($ip, $at);
        }
        $paths = [];
        foreach ($this->compile(array_slice($args, 0, $to), $at, false) as $pattern => $origin) {
            $pattern .= 'i';
            $paths[] = $pattern;
            $this->origins['restricted'][$pattern] = $origin;
        }
        $list = $this->get('restricted');
        $list = is_array($list) ? $list : [];
        $list[] = ['paths' => $paths, 'ips' => $ips];
        $this->put('restricted', $list);
    }

    /**
     * allow <METHODS> <path patterns>: those methods only there (a POST where
     * the forms are); elsewhere 405. The methods are allowed at all, too.
     *
     * @param list<string> $args
     */
    private function allow(array $args, string $at): void
    {
        $methods = [];
        while ($args !== [] && preg_match('/^[A-Z]+$/', $args[0])) {
            $methods[] = (string) array_shift($args);
        }
        if ($methods === [] || $args === []) {
            throw new RuleFileException("$at: allow <METHODS> <paths> (allow POST /contact /edit/**)");
        }
        $patterns = $this->compile($args, $at, false);
        foreach ($methods as $m) {
            $list = $this->get("methodPaths.$m");
            $list = is_array($list) ? $list : [];
            foreach ($patterns as $pattern => $origin) {
                $pattern .= 'i';
                if (!in_array($pattern, $list, true)) {
                    $list[] = $pattern;
                }
            }
            $this->put("methodPaths.$m", $list);
            $this->origins['methodPaths'][$m] = $this->rid;
            $all = (array) $this->get('methods');
            if (!in_array($m, $all, true)) {
                $all[] = $m;
                $this->put('methods', $all);
            }
        }
    }

    private static function address(string $ip, string $at): string
    {
        $addr = explode('/', $ip, 2);
        if (@inet_pton($addr[0]) === false || (isset($addr[1]) && !ctype_digit($addr[1]))) {
            throw new RuleFileException("$at: \"$ip\" is not an address or a range (192.0.2.0/24, 2001:db8::/32)");
        }
        return $ip;
    }

    /** @param list<string> $args  <name> <n>/<sec|min|hour|day> [challenge-at <n>] [on-demand] */
    private function limit(array $args, string $at): void
    {
        $usage = 'limit <name> <n>/<sec|min|hour|day> [challenge-at <n>] [on-demand]';
        $name = array_shift($args);
        $rate = array_shift($args);
        if ($name === null || $rate === null || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new RuleFileException("$at: $usage");
        }
        if (!preg_match('~^(\d+)/(\d*)(s|sec|second|m|min|minute|h|hour|d|day)s?$~', $rate, $m)) {
            throw new RuleFileException("$at: \"$rate\" is not a rate like 600/min or 20/10s");
        }
        $unit = ['s' => 1, 'sec' => 1, 'second' => 1, 'm' => 60, 'min' => 60, 'minute' => 60,
            'h' => 3600, 'hour' => 3600, 'd' => 86400, 'day' => 86400][$m[3]];
        $budget = ['limit' => (int) $m[1], 'window' => max(1, (int) ($m[2] === '' ? 1 : $m[2])) * $unit, 'challengeAt' => null, 'onDemand' => false];
        while ($args !== []) {
            $a = array_shift($args);
            if ($a === 'on-demand') {
                $budget['onDemand'] = true;
            } elseif ($a === 'challenge-at' && isset($args[0]) && ctype_digit($args[0])) {
                $budget['challengeAt'] = (int) array_shift($args);
            } else {
                throw new RuleFileException("$at: \"$a\" -- $usage");
            }
        }
        $this->put("budgets.$name", $budget);
        $this->origins['budgets'][$name] = $this->rid;
    }

    private function set(string $line, string $at, string $file): void
    {
        // The value is the rest of the line: texts have spaces.
        $parts = preg_split('/\s+/', $line, 3) ?: [];
        $key = strtolower($parts[1] ?? '');
        $value = $this->env(trim($parts[2] ?? ''), $at);
        if ($key === '' || $value === '') {
            throw new RuleFileException("$at: set <key> <value>");
        }
        // text.<key> for every language, text.<lang>.<key> for one.
        if (strncmp($key, 'text.', 5) === 0) {
            $rest = substr($key, 5);
            $dot = strrpos($rest, '.');
            $lang = $dot === false ? null : substr($rest, 0, $dot);
            $text = $dot === false ? $rest : substr($rest, $dot + 1);
            if ($text === 'lang') {
                return;                 // an old setting: the language is chosen now
            }
            if (!in_array($text, Texts::KEYS, true)) {
                throw new RuleFileException("$at: unknown text \"$text\" (there are " . implode(', ', Texts::KEYS) . ')');
            }
            if ($lang !== null && !preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $lang)) {
                throw new RuleFileException("$at: \"$lang\" is not a language code (de, en, fr, de-at)");
            }
            // Stored flat ("de.title"): the settings keep one list of texts.
            $texts = (array) $this->get('challenge.texts');
            $texts[($lang !== null ? "$lang." : '') . $text] = $value;
            $this->put('challenge.texts', $texts);
            return;
        }
        if (!isset(self::SET[$key])) {
            throw new RuleFileException("$at: unknown setting \"$key\"" . self::suggest($key, array_merge(array_keys(self::SET), ['text.title'])));
        }
        [$path, $type] = self::SET[$key];
        switch ($type) {
            case 'bool':
                $v = ['on' => true, 'yes' => true, 'true' => true, 'off' => false, 'no' => false, 'false' => false][strtolower($value)] ?? null;
                if ($v === null) {
                    throw new RuleFileException("$at: $key is on or off, not \"$value\"");
                }
                break;
            case 'int':
                if (!preg_match('/^\d+$/', $value)) {
                    throw new RuleFileException("$at: $key is a number, not \"$value\"");
                }
                $v = (int) $value;
                break;
            case 'seconds':
                if (!preg_match('/^(\d+)(s|m|h|d)?$/', $value, $m)) {
                    throw new RuleFileException("$at: $key is a duration (300, 30s, 5m, 1h, 1d), not \"$value\"");
                }
                $v = (int) $m[1] * ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2] ?? ''];
                break;
            case 'language':
                $v = strtolower($value);
                if ($v !== 'auto' && !preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $v)) {
                    throw new RuleFileException("$at: language is auto or a code (de, en, fr, de-at), not \"$value\"");
                }
                break;
            case 'loglevel':
                if (!in_array($value, \CjwNetwork\RequestShield\Log::LEVELS, true)) {
                    throw new RuleFileException("$at: log-level is " . implode(', ', \CjwNetwork\RequestShield\Log::LEVELS) . ", not \"$value\"");
                }
                $v = $value;
                break;
            case 'logip':
                if ($value !== 'masked' && $value !== 'full') {
                    throw new RuleFileException("$at: log-ip is masked or full, not \"$value\"");
                }
                $v = $value;
                break;
            case 'bytes':
                if (!preg_match('/^(\d+)([kmg])?b?$/i', $value, $m)) {
                    throw new RuleFileException("$at: $key is a size (10M, 500K), not \"$value\"");
                }
                $v = (int) $m[1] * ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2] ?? '')];
                break;
            case 'path':
                // Relative to the rule file it is written in.
                $v = $value[0] === '/' ? $value : dirname($file) . '/' . $value;
                break;
            case 'store':
                if (!in_array($value, ['auto', 'apcu', 'file', 'memory'], true)) {
                    throw new RuleFileException("$at: store is auto, apcu, file or memory, not \"$value\"");
                }
                $v = $value;
                break;
            default:
                $v = $value;
        }
        $this->put($path, $v);
    }

    /**
     * ${NAME}: an environment variable, so a secret need not be in the file;
     * ${NAME:-default} when it may be unset. The values used are recorded:
     * the compiled settings are rebuilt when one changes.
     */
    private function env(string $value, string $at): string
    {
        if (strpos($value, '${') === false) {
            return $value;
        }
        return (string) preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}/', function (array $m) use ($at): string {
            $v = getenv($m[1]);
            $this->env[$m[1]] = is_string($v) ? $v : null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
            if (isset($m[2])) {
                return $m[2];
            }
            throw new RuleFileException("$at: the environment variable $m[1] is not set");
        }, $value);
    }

    /** @return mixed */
    private function get(string $path)
    {
        $v = $this->c;
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return null;
            }
            $v = $v[$k];
        }
        return $v;
    }

    /** @param mixed $value */
    private function put(string $path, $value): void
    {
        $ref = &$this->c;
        foreach (explode('.', $path) as $k) {
            if (!is_array($ref)) {
                $ref = [];
            }
            $ref = &$ref[$k];
        }
        $ref = $value;
    }

    /** @param list<string> $known */
    private static function suggest(string $word, array $known): string
    {
        $best = null;
        $distance = 3;
        foreach ($known as $k) {
            $d = levenshtein($word, $k);
            if ($d < $distance) {
                [$best, $distance] = [$k, $d];
            }
        }
        return $best === null ? '' : " (did you mean \"$best\"?)";
    }
}
