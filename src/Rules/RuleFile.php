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
        'log' => ['log.file', 'path'],
        'log-level' => ['log.level', 'loglevel'],
        'log-ip' => ['log.ip', 'logip'],
        'log-max-size' => ['log.maxSize', 'bytes'],
    ];

    private const TEXTS = ['lang', 'title', 'text', 'noscript', 'nocookies', 'failed'];

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

    private function __construct()
    {
        $this->c = Config::defaults();
        $this->c['recheck'] = 10;
        foreach (Config::scannerPaths() as $p) {
            $this->origins['blockedPaths'][$p] = 'default @scanners';
        }
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
        foreach ($files as $file) {
            $r->source($file, null, null);
        }
        $recheck = $r->c['recheck'];
        unset($r->c['recheck']);
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
        $name = $this->base !== '' && strncmp($real, $this->base, strlen($this->base)) === 0 ? substr($real, strlen($this->base)) : $real;
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $i => $line) {
            $this->line($line, "$name:" . ($i + 1), $file);
        }
        array_pop($this->stack);
    }

    private function line(string $line, string $at, string $file): void
    {
        // "#" starts a comment at the start of a line or after a space; "\#"
        // is a literal "#" (in a regex, say).
        $line = preg_replace('/(^|\s)#.*$/', '', $line) ?? $line;
        $line = trim(str_replace('\\#', '#', $line));
        if ($line === '') {
            return;
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        $keyword = strtolower((string) array_shift($parts));
        $args = array_map(fn (string $a): string => $this->env($a, $at), $parts);

        switch ($keyword) {
            case 'host':
                $this->list('hosts', $args, $at, static fn (string $h): string => strtolower($h));
                $this->origins['hosts']['*'] = $at;
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
                $this->origins['methods']['*'] = $at;
                return;
            case 'block':
                $this->patterns('blockedPaths', $args, $at, true);
                return;
            case 'unblock':
                $this->unblock($args, $at);
                return;
            case 'cache-path':
                $this->patterns('cacheable.paths', $args, $at, false);
                $this->origins['cacheable.paths']['*'] = $at;
                return;
            case 'cache-query':
                $this->list('cacheable.query', $args, $at, static fn (string $q): string => $q);
                $this->origins['cacheable.query']['*'] = $at;
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
        $list = $this->get($key);
        $list = is_array($list) ? $list : [];
        if ($args[0] === 'none') {
            $list = [];
            unset($this->origins[$key]);
        }
        foreach ($this->compile($args, $at, $sets) as $pattern => $origin) {
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
            if ($a[0] === '@') {
                if (!$sets) {
                    throw new RuleFileException("$at: a set ($a) only works with block and unblock");
                }
                foreach (self::set_($a, $at) as $p) {
                    $out[$p] = "$at $a";
                }
                continue;
            }
            $pattern = $regex ? Pattern::fromRegex($a) : Pattern::fromGlob($a);
            if (!Pattern::valid($pattern)) {
                throw new RuleFileException("$at: \"$a\" is not a valid regular expression");
            }
            $out[$pattern] = $at;
        }
        if ($regex && $out === []) {
            throw new RuleFileException("$at: regex of what?");
        }
        return $out;
    }

    /** @return list<string> */
    private static function set_(string $name, string $at): array
    {
        switch (substr($name, 1)) {
            case 'scanners':
                return Config::scannerPaths();
            case 'wordpress':
                return Config::wordpressPaths();
        }
        throw new RuleFileException("$at: unknown set \"$name\" (there are @" . implode(', @', self::SETS) . ')');
    }

    /** @param list<string> $args */
    private function unblock(array $args, string $at): void
    {
        if ($args === []) {
            throw new RuleFileException("$at: unblock what?");
        }
        $list = [];
        foreach ((array) $this->get('blockedPaths') as $p) {
            $list[] = is_string($p) ? $p : '';
        }
        $remove = array_keys($this->compile($args, $at, true));
        foreach ($remove as $pattern) {
            if (!in_array($pattern, $list, true)) {
                throw new RuleFileException("$at: nothing to unblock -- no earlier block matches " . implode(' ', $args) . ' exactly');
            }
        }
        $this->put('blockedPaths', array_values(array_diff($list, $remove)));
        foreach ($remove as $pattern) {
            unset($this->origins['blockedPaths'][$pattern]);
        }
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
            $this->origins['methodPaths'][$m] = $at;
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
        $this->origins['budgets'][$name] = $at;
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
        if (strncmp($key, 'text.', 5) === 0) {
            $text = substr($key, 5);
            if (!in_array($text, self::TEXTS, true)) {
                throw new RuleFileException("$at: unknown text \"$text\" (there are " . implode(', ', self::TEXTS) . ')');
            }
            $this->put("challenge.texts.$text", $value);
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
