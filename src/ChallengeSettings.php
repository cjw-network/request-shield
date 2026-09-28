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
     */
    private function __construct(
        public readonly ?string $secret,
        public readonly int $passTtl,
        public readonly int $solutionTtl,
        public readonly int $difficultyMin,
        public readonly int $difficultyMax,
        public readonly string $cookie,
        public readonly string $solutionCookie,
        public readonly bool $bindUserAgent,
        public readonly ?array $searchEngines,
        public readonly array $exemptPaths,
        public readonly array $texts,
    ) {
    }

    /** @param array<mixed> $c */
    public static function from(array $c): self
    {
        $difficulty = Settings::map($c, 'difficulty', 'challenge.difficulty');
        $min = max(1000, Settings::int($difficulty, 'min', 'challenge.difficulty.min', 50000));
        $max = max($min, Settings::int($difficulty, 'max', 'challenge.difficulty.max', 500000));

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
            max(60, Settings::int($c, 'passTtl', 'challenge.passTtl', 3600)),
            max(30, Settings::int($c, 'solutionTtl', 'challenge.solutionTtl', 300)),
            $min,
            $max,
            self::cookieName($c, 'cookie', 'rs_pass'),
            self::cookieName($c, 'solutionCookie', 'rs_solution'),
            Settings::bool($c, 'bindUserAgent', 'challenge.bindUserAgent', true),
            $engines,
            Settings::strings($c, 'exemptPaths', 'challenge.exemptPaths'),
            $texts,
        );
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
        /** @phpstan-ignore argument.type */
        return new self(...$e);
    }
}
