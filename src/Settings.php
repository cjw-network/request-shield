<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

/**
 * The configuration array, checked once and turned into typed values. A
 * value of the wrong type is an error at once -- with the key named -- not
 * a check that quietly does something else on a live site.
 */
final class Settings
{
    /**
     * @param list<string> $trustedProxies
     * @param list<string> $methods
     * @param list<string> $hosts
     * @param list<string> $blockedPaths
     * @param list<string>|null $cacheablePaths
     * @param list<string>|null $cacheableQuery
     * @param array<string, Budget> $budgets
     * @param list<string> $exemptIps
     * @param array<string, array<string, string>> $origins setting => pattern or budget => where it was set (rule files)
     * @param list<array{paths: list<string>, ips: list<string>}> $restricted
     * @param array<string, list<string>> $methodPaths
     * @param list<array{paths: list<string>, patterns: list<string>|null, ips: list<string>}> $blockExceptions
     */
    private function __construct(
        /** @readonly */
        public array $trustedProxies,
        /** @readonly */
        public bool $stripUntrustedForwarded,
        /** @readonly */
        public array $methods,
        /** @readonly */
        public array $hosts,
        /** @readonly */
        public int $maxUri,
        /** @readonly */
        public int $maxQueryParameters,
        /** @readonly */
        public int $maxHeaderBytes,
        /** @readonly */
        public array $blockedPaths,
        /** @readonly */
        public ?array $cacheablePaths,
        /** @readonly */
        public ?array $cacheableQuery,
        /** @readonly */
        public array $budgets,
        /** @readonly */
        public array $exemptIps,
        /** @readonly */
        public int $ipv6Prefix,
        /** @readonly */
        public string $store,
        /** @readonly */
        public string $storeDir,
        /** @readonly */
        public bool $debugHeader,
        /** @readonly */
        public ChallengeSettings $challenge,
        /** @readonly */
        public array $origins = [],
        /** @readonly */
        public array $restricted = [],
        /** @readonly */
        public array $methodPaths = [],
        /** @readonly */
        public ?string $logFile = null,
        /** @readonly */
        public string $logLevel = 'stop',
        /** @readonly */
        public string $logIp = 'masked',
        /** @readonly */
        public int $logMaxSize = 10485760,
        /** @readonly */
        public array $blockExceptions = [],
    ) {
    }

    /**
     * @param array<mixed> $config see Config::defaults()
     * @throws \InvalidArgumentException naming the key that is wrong
     */
    public static function from(array $config): self
    {
        $c = Config::merge($config);
        $limits = self::map($c, 'limits');
        $cacheable = self::map($c, 'cacheable');
        $exempt = self::map($c, 'exempt');
        $log = self::map($c, 'log');

        $restricted = [];
        foreach (self::map($c, 'restricted') as $i => $r) {
            if (!is_array($r)) {
                throw self::wrong("restricted.$i", "an array of 'paths' and 'ips'");
            }
            $restricted[] = ['paths' => self::strings($r, 'paths', "restricted.$i.paths"), 'ips' => self::strings($r, 'ips', "restricted.$i.ips")];
        }
        $exceptions = [];
        foreach (self::map($c, 'blockExceptions') as $i => $x) {
            if (!is_array($x)) {
                throw self::wrong("blockExceptions.$i", "an array of 'paths', 'patterns' and 'ips'");
            }
            $exceptions[] = ['paths' => self::strings($x, 'paths', "blockExceptions.$i.paths"),
                'patterns' => self::stringsOrNull($x, 'patterns', "blockExceptions.$i.patterns"), 'ips' => self::strings($x, 'ips', "blockExceptions.$i.ips")];
        }
        $methodPaths = [];
        foreach (self::map($c, 'methodPaths') as $method => $paths) {
            $methodPaths[strtoupper((string) $method)] = self::strings(['p' => $paths], 'p', "methodPaths.$method");
        }
        $level = self::string($log, 'level', 'log.level', 'stop');
        if (!in_array($level, Log::LEVELS, true)) {
            throw self::wrong('log.level', implode(', ', Log::LEVELS));
        }
        $ip = self::string($log, 'ip', 'log.ip', 'masked');
        if ($ip !== 'masked' && $ip !== 'full') {
            throw self::wrong('log.ip', 'masked or full');
        }
        $logFile = $log['file'] ?? null;
        if ($logFile !== null && (!is_string($logFile) || $logFile === '')) {
            throw self::wrong('log.file', 'null or a path');
        }

        $budgets = [];
        foreach (self::map($c, 'budgets') as $name => $budget) {
            if (!is_array($budget)) {
                throw self::wrong("budgets.$name", 'an array');
            }
            $b = Budget::from((string) $name, $budget);
            if ($b !== null) {
                $budgets[(string) $name] = $b;
            }
        }

        return new self(
            self::strings($c, 'trustedProxies'),
            self::bool($c, 'stripUntrustedForwarded'),
            array_map(static fn (string $m): string => strtoupper($m), self::strings($c, 'methods')),
            array_map(static fn (string $h): string => strtolower($h), self::strings($c, 'hosts')),
            self::int($limits, 'uri', 'limits.uri'),
            self::int($limits, 'queryParameters', 'limits.queryParameters'),
            self::int($limits, 'headerBytes', 'limits.headerBytes'),
            self::strings($c, 'blockedPaths'),
            self::stringsOrNull($cacheable, 'paths', 'cacheable.paths'),
            self::stringsOrNull($cacheable, 'query', 'cacheable.query'),
            $budgets,
            self::strings($exempt, 'ips', 'exempt.ips'),
            max(0, min(128, self::int($c, 'ipv6Prefix'))),
            self::string($c, 'store'),
            self::string($c, 'storeDir'),
            self::bool($c, 'debugHeader'),
            ChallengeSettings::from(self::map($c, 'challenge')),
            self::origins(self::map($c, 'origins')),
            $restricted,
            $methodPaths,
            $level === 'off' ? null : $logFile,
            $level,
            $ip,
            max(4096, self::int($log, 'maxSize', 'log.maxSize', 10485760)),
            $exceptions,
        );
    }

    /**
     * @param array<mixed> $o
     * @return array<string, array<string, string>>
     */
    private static function origins(array $o): array
    {
        $out = [];
        foreach ($o as $setting => $list) {
            if (!is_array($list)) {
                throw self::wrong("origins.$setting", 'an array');
            }
            foreach ($list as $what => $where) {
                if (!is_string($where)) {
                    throw self::wrong("origins.$setting", 'an array of strings');
                }
                $out[(string) $setting][(string) $what] = $where;
            }
        }
        return $out;
    }

    /**
     * Where the rule behind a pattern or budget was written ("site.rules:12",
     * "default @scanners.backups"); null for settings from a PHP array.
     */
    public function origin(string $setting, string $what): ?string
    {
        return $this->origins[$setting][$what] ?? null;
    }

    // ── Compiled: checked once, then loaded from OPcache ──────────────────

    /** Bumped when the export's shape changes, so old compiled files are rebuilt. */
    private const FORMAT = 5;       // 3: rule files, several sources, origins; 4: restricted, methodPaths, log; 5: blockExceptions

    /**
     * The settings of a file, checked only when it changed. A ".rules" file
     * is a rule file (Rules\RuleFile), anything else a PHP file returning
     * the settings array; $sources are further rule files or globs read
     * before it (an adapter's extensions), each with its includes.
     *
     * The checked values are kept as a PHP file in $cacheDir, which OPcache
     * serves from memory. Whether the sources changed:
     *   - one file: its mtime and size, on every call (one stat());
     *   - several, with APCu: all of them, at most every "recheck" seconds
     *     (default 10) -- in between not a single stat();
     *   - several, without APCu: the main file on every call; the others
     *     when it changes (bin/request-shield reload touches it).
     * "set recheck 0" checks every source on every call.
     *
     * @param list<string> $sources
     * @throws \InvalidArgumentException for a setting of the wrong type (Rules\RuleFileException: with file and line)
     * @throws \RuntimeException when the file cannot be read
     */
    public static function load(string $file, ?string $cacheDir = null, array $sources = []): self
    {
        $cacheDir ??= rtrim(sys_get_temp_dir(), '/') . '/request-shield';
        $key = hash(PHP_VERSION_ID >= 80100 ? 'xxh128' : 'md5', $file . "\0" . implode("\0", $sources));
        $compiled = $cacheDir . '/settings-' . $key . '.php';
        // No is_file() first: a stat costs more than everything else here, and
        // an include of a missing file just returns false.
        $e = @include $compiled;
        // Written by write() below, so trusted beyond its format and file.
        if (is_array($e) && ($e['format'] ?? 0) === self::FORMAT && ($e['file'] ?? '') === $file) {
            /** @var array{seen: array<string, array{0: int, 1: int}>, env: array<string, string|null>, recheck: int, settings: array<string, mixed>} $e */
            if (($e['env'] === [] || self::sameEnv($e['env'])) && self::fresh($e['seen'], $e['recheck'], $key)) {
                return self::import($e['settings']);
            }
        }

        if (substr($file, -6) === '.rules') {
            $files = $sources;
            $files[] = $file;
            $read = Rules\RuleFile::read($files);
            // The main file first: without APCu it is the one checked.
            $seen = [$file => $read['seen'][$file] ?? [0, 0]] + $read['seen'];
            $recheck = $read['recheck'];
            $config = $read['config'];
            $env = $read['env'];
        } else {
            clearstatcache();
            $stat = Rules\RuleFile::stat($file);
            if ($stat === null) {
                throw new \RuntimeException("request-shield: cannot read the settings file $file");
            }
            // OPcache judges a file by its mtime, and a file rewritten within
            // the same second keeps it -- the old settings would be compiled
            // under the new size and stay for good.
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            $config = require $file;
            if (!is_array($config)) {
                throw new \RuntimeException("request-shield: $file must return an array");
            }
            $seen = [$file => $stat];
            $recheck = 0;
            $env = [];
            if ($sources !== []) {
                throw new \RuntimeException('request-shield: further sources need a .rules main file');
            }
        }
        $settings = self::from($config);
        self::write($compiled, "<?php\n// Compiled by cjw-network/request-shield from $file; rebuilt when it changes.\nreturn "
            . var_export(['format' => self::FORMAT, 'file' => $file, 'seen' => $seen, 'env' => $env, 'recheck' => $recheck, 'settings' => $settings->export()], true) . ";\n");
        if ($recheck > 0 && function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_store('rshield:fresh:' . $key, true, $recheck);
        }
        return $settings;
    }

    /** @param array<string, string|null> $env the environment variables a rule file used, with their values then */
    private static function sameEnv(array $env): bool
    {
        foreach ($env as $name => $value) {
            $now = getenv($name);
            if ((is_string($now) ? $now : null) !== $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the sources are unchanged. Kept small and free of other
     * classes: it runs on every request.
     *
     * @param array<string, array{0: int, 1: int}> $seen path => [mtime, size], the main file first
     */
    private static function fresh(array $seen, int $recheck, string $key): bool
    {
        $apcu = $recheck > 0 && function_exists('apcu_enabled') && apcu_enabled();
        if ($apcu && apcu_fetch('rshield:fresh:' . $key) === true) {
            return true;
        }
        // PHP keeps the last stat() for the rest of the process: in a
        // long-running server (and a second load() in one request) the old
        // mtime and size would be compared, and a change never seen.
        clearstatcache();
        foreach ($seen as $path => $stat) {
            if (@filemtime($path) !== $stat[0] || @filesize($path) !== $stat[1]) {
                return false;
            }
            if ($recheck > 0 && !$apcu) {
                break;          // without APCu: only the main file
            }
        }
        if ($apcu) {
            apcu_store('rshield:fresh:' . $key, true, $recheck);
        }
        return true;
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        /** @var array<string, mixed> $e */
        $e = get_object_vars($this);
        $e['budgets'] = array_map(static fn (Budget $b): array => $b->export(), $this->budgets);
        $e['challenge'] = $this->challenge->export();
        return $e;
    }

    /** @param array<string, mixed> $e what export() returned (trusted: no checks) */
    public static function import(array $e): self
    {
        $budgets = [];
        foreach ((array) $e['budgets'] as $name => $b) {
            /** @var array<string, mixed> $b */
            $budgets[(string) $name] = Budget::import($b);
        }
        $e['budgets'] = $budgets;
        /** @var array<string, mixed> $challenge */
        $challenge = $e['challenge'];
        $e['challenge'] = ChallengeSettings::import($challenge);
        // Positional, in declaration order (what export() returns): unpacking
        // string keys into named arguments needs PHP 8.1.
        /** @phpstan-ignore argument.type */
        return new self(...array_values($e));
    }

    private static function write(string $file, string $php): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $php) === false) {
            return;             // not cacheable here: checked again next time
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        } elseif (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    // ── Typed reading, shared with Budget and ChallengeSettings ────────────

    /**
     * @param array<mixed> $a
     * @return array<mixed>
     */
    public static function map(array $a, string $key, ?string $name = null): array
    {
        $v = $a[$key] ?? [];
        if (!is_array($v)) {
            throw self::wrong($name ?? $key, 'an array');
        }
        return $v;
    }

    /**
     * @param array<mixed> $a
     * @return list<string>
     */
    public static function strings(array $a, string $key, ?string $name = null): array
    {
        $v = $a[$key] ?? [];
        if (!is_array($v)) {
            throw self::wrong($name ?? $key, 'a list of strings');
        }
        $out = [];
        foreach ($v as $item) {
            if (!is_string($item)) {
                throw self::wrong($name ?? $key, 'a list of strings');
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<mixed> $a
     * @return list<string>|null
     */
    public static function stringsOrNull(array $a, string $key, ?string $name = null): ?array
    {
        return ($a[$key] ?? null) === null ? null : self::strings($a, $key, $name);
    }

    /** @param array<mixed> $a */
    public static function int(array $a, string $key, ?string $name = null, int $default = 0): int
    {
        $v = $a[$key] ?? $default;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            return (int) $v;
        }
        throw self::wrong($name ?? $key, 'an integer');
    }

    /** @param array<mixed> $a */
    public static function intOrNull(array $a, string $key, ?string $name = null): ?int
    {
        return ($a[$key] ?? null) === null ? null : self::int($a, $key, $name);
    }

    /** @param array<mixed> $a */
    public static function bool(array $a, string $key, ?string $name = null, bool $default = false): bool
    {
        $v = $a[$key] ?? $default;
        if (!is_bool($v)) {
            throw self::wrong($name ?? $key, 'true or false');
        }
        return $v;
    }

    /** @param array<mixed> $a */
    public static function string(array $a, string $key, ?string $name = null, string $default = ''): string
    {
        $v = $a[$key] ?? $default;
        if (!is_string($v)) {
            throw self::wrong($name ?? $key, 'a string');
        }
        return $v;
    }

    public static function wrong(string $key, string $what): \InvalidArgumentException
    {
        return new \InvalidArgumentException("request-shield: '$key' must be $what");
    }
}
