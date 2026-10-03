<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Challenge\SearchEngines;

/** Config's 'challenge' section, typed. */
final class ChallengeSettings
{
    /**
     * @param array<string, list<string>>|null $searchEngines null: no crawler is exempt
     * @param list<string> $exemptPaths
     * @param array<string, string> $texts
     * @param list<string> $alwaysPaths
     * @param list<string> $apiPaths
     */
    private function __construct(
        /** @readonly */
        public ?string $secret,
        /** @readonly */
        public int $passTtl,
        /** @readonly */
        public int $solutionTtl,
        /** @readonly */
        public int $difficultyMin,
        /** @readonly */
        public int $difficultyMax,
        /** @readonly */
        public string $cookie,
        /** @readonly */
        public string $solutionCookie,
        /** @readonly */
        public bool $bindUserAgent,
        /** @readonly */
        public ?array $searchEngines,
        /** @readonly */
        public array $exemptPaths,
        /** @readonly */
        public array $texts,
        /** @readonly */
        public array $alwaysPaths = [],
        /** @readonly */
        public string $language = 'auto',
        /** @readonly the address the shield's own pages link to ("To the home page"); null: none */
        public ?string $home = null,
        /** @readonly where the check-in-the-form endpoint answers (/request-shield); null: off */
        public ?string $widgetPath = null,
        /** @readonly the widget's difficulty (maxnumber): the visitor is still typing */
        public int $widgetDifficulty = 25000,
        /** @readonly paths of the site's API: a check there is sent as a header, not as a page */
        public array $apiPaths = [],
        /** @readonly new DNS lookups a minute to verify search engines, for all requests together; 0: none */
        public int $dnsLookups = 30,
        /**
         * @readonly alwaysPaths entry => seconds: a pass issued at most that long ago (challenge … max-age 5m)
         * @var array<string, int>
         */
        public array $alwaysMaxAge = [],
        /** @readonly the site's logo for the check page, checked (Challenge\ChallengeLogo), as markup; null: a plain shield */
        public ?string $logo = null,
    ) {
    }

    /**
     * @param array<mixed> $c
     * @param bool $strict mode strict: a pass for at most 15 minutes, the difficulty from twice its minimum
     */
    public static function from(array $c, bool $strict = false): self
    {
        $difficulty = Settings::map($c, 'difficulty', 'challenge.difficulty');
        $min = max(1000, Settings::int($difficulty, 'min', 'challenge.difficulty.min', 50000));
        $max = max($min, Settings::int($difficulty, 'max', 'challenge.difficulty.max', 500000));
        $passTtl = max(60, Settings::int($c, 'passTtl', 'challenge.passTtl', 3600));
        if ($strict) {
            $min = min($max, $min * 2);
            $passTtl = min($passTtl, 900);
        }
        $always = Settings::strings($c, 'alwaysPaths', 'challenge.alwaysPaths');
        $ages = [];
        foreach (Settings::map($c, 'alwaysMaxAge', 'challenge.alwaysMaxAge') as $pattern => $age) {
            if (!is_int($age) || $age < 1 || !in_array((string) $pattern, $always, true)) {
                throw Settings::wrong("challenge.alwaysMaxAge.$pattern", 'seconds (at least 1) for one of alwaysPaths');
            }
            $ages[(string) $pattern] = $age;
        }

        $secret = $c['secret'] ?? null;
        if ($secret !== null && (!is_string($secret) || strlen($secret) < 32)) {
            throw Settings::wrong('challenge.secret', 'null or a string of at least 32 characters');
        }

        $engines = $c['searchEngines'] ?? true;
        if ($engines === true) {
            $engines = SearchEngines::defaults();
        } elseif ($engines === false) {
            $engines = null;
        } elseif (is_array($engines)) {
            $list = [];
            foreach ($engines as $pattern => $suffixes) {
                $list[(string) $pattern] = Settings::strings(['s' => $suffixes], 's', 'challenge.searchEngines');
            }
            $engines = $list;
        } else {
            throw Settings::wrong('challenge.searchEngines', 'true, false or an array of pattern => host suffixes');
        }

        $texts = [];
        foreach (Settings::map($c, 'texts', 'challenge.texts') as $key => $text) {
            if (!is_string($text)) {
                throw Settings::wrong("challenge.texts.$key", 'a string');
            }
            $texts[(string) $key] = $text;
        }

        return new self(
            $secret,
            $passTtl,
            max(30, Settings::int($c, 'solutionTtl', 'challenge.solutionTtl', 300)),
            $min,
            $max,
            self::cookieName($c, 'cookie', 'rsp'),
            self::cookieName($c, 'solutionCookie', 'rss'),
            Settings::bool($c, 'bindUserAgent', 'challenge.bindUserAgent', true),
            $engines,
            Settings::strings($c, 'exemptPaths', 'challenge.exemptPaths'),
            $texts,
            $always,
            self::language($c),
            self::home($c),
            self::widgetPath($c),
            max(1000, Settings::int($c, 'widgetDifficulty', 'challenge.widgetDifficulty', 25000)),
            Settings::strings($c, 'apiPaths', 'challenge.apiPaths'),
            max(0, Settings::int($c, 'dnsLookups', 'challenge.dnsLookups', 30)),
            $ages,
            self::logo($c),
        );
    }

    /** @param array<mixed> $c */
    private static function logo(array $c): ?string
    {
        $file = $c['logo'] ?? null;
        if ($file === null || $file === '') {
            return null;
        }
        if (!is_string($file)) {
            throw Settings::wrong('challenge.logo', 'null or the path of an SVG file');
        }
        return \CjwNetwork\RequestShield\Challenge\ChallengeLogo::load($file, 'challenge.logo');
    }

    /** @param array<mixed> $c */
    private static function widgetPath(array $c): ?string
    {
        $p = $c['widgetPath'] ?? null;
        if ($p === null || $p === '') {
            return null;
        }
        if (!is_string($p) || !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $p) || preg_match('#/\.+(/|$)#', $p)) {
            throw Settings::wrong('challenge.widgetPath', 'null or a path such as /request-shield');
        }
        return $p;
    }

    /** @param array<mixed> $c */
    private static function home(array $c): ?string
    {
        $home = $c['home'] ?? null;
        if ($home === null || $home === '') {
            return null;
        }
        if (!is_string($home) || !preg_match('#^(/|https?://)[^\s"<>]*$#i', $home)) {
            throw Settings::wrong('challenge.home', 'null, a path (/) or an http(s) address');
        }
        return $home;
    }

    /** @param array<mixed> $c */
    private static function language(array $c): string
    {
        $lang = strtolower(Settings::string($c, 'language', 'challenge.language', 'auto'));
        if ($lang !== 'auto' && !preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $lang)) {
            throw Settings::wrong('challenge.language', '"auto" or a language code such as de, en, fr, de-at');
        }
        return $lang;
    }

    /** @param array<mixed> $c */
    private static function cookieName(array $c, string $key, string $default): string
    {
        $name = Settings::string($c, $key, "challenge.$key", $default);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            throw Settings::wrong("challenge.$key", 'a cookie name of letters, digits, "_" and "-"');
        }
        return $name;
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        /** @var array<string, mixed> */
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $e what export() returned (trusted: no checks) */
    public static function import(array $e): self
    {
        // Positional, in declaration order (what export() returns): unpacking
        // string keys into named arguments needs PHP 8.1.
        /** @phpstan-ignore argument.type */
        return new self(...array_values($e));
    }
}
