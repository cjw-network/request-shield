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
        );
    }

    // ── Compiled: checked once, then loaded from OPcache ──────────────────

    /** Bumped when the export's shape changes, so old compiled files are rebuilt. */
    private const FORMAT = 2;       // 2: challenge.alwaysPaths

    /**
     * The settings of a configuration file, checked only when the file
     * changed. The checked values are kept as a PHP file in $cacheDir, which
     * OPcache serves from memory; a request then costs two stat() calls and
     * an include instead of checking every setting again (about 10 µs).
     *
     * @throws \InvalidArgumentException for a setting of the wrong type
     * @throws \RuntimeException when the file cannot be read
     */
    public static function load(string $file, ?string $cacheDir = null): self
    {
        // PHP keeps the last stat() for the rest of the process: in a
        // long-running server (and a second load() in one request) the file's
        // old mtime and size would be compared, and a change never seen.
        // This clears only that one entry, not the realpath cache.
        clearstatcache();
        $mtime = @filemtime($file);
        $size = @filesize($file);
        if ($mtime === false || $size === false) {
            throw new \RuntimeException("request-shield: cannot read the settings file $file");
        }
        $cacheDir ??= rtrim(sys_get_temp_dir(), '/') . '/request-shield';
        $compiled = $cacheDir . '/settings-' . hash(PHP_VERSION_ID >= 80100 ? 'xxh128' : 'md5', $file) . '.php';
        // No is_file() first: a stat costs more than everything else here, and
        // an include of a missing file just returns false.
        $e = @include $compiled;
        if (is_array($e) && ($e['format'] ?? 0) === self::FORMAT && ($e['file'] ?? '') === $file
            && ($e['mtime'] ?? -1) === $mtime && ($e['size'] ?? -1) === $size && is_array($e['settings'] ?? null)) {
            /** @var array<string, mixed> $exported */
            $exported = $e['settings'];
            return self::import($exported);
        }
        // Rebuilding: the file changed. OPcache judges a file by its mtime,
        // and a file rewritten within the same second keeps it -- the old
        // settings would be compiled under the new size and stay for good.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new \RuntimeException("request-shield: $file must return an array");
        }
        $settings = self::from($config);
        self::write($compiled, "<?php\n// Compiled by cjw-network/request-shield from $file; rebuilt when it changes.\nreturn "
            . var_export(['format' => self::FORMAT, 'file' => $file, 'mtime' => $mtime, 'size' => $size, 'settings' => $settings->export()], true) . ";\n");
        return $settings;
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
