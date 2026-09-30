<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

/** One budget: requests (or events) per client and window. */
final class Budget
{
    private function __construct(
        /** @readonly */
        public string $name,
        /** @readonly */
        public int $limit,
        /** @readonly */
        public int $window,
        /** @readonly */
        public ?int $challengeAt,
        /** @readonly */
        public bool $onDemand,
        /** @readonly past the limit: false a pause (429), true the browser check that frees the counter */
        public bool $earnBack = false,
    ) {
    }

    /**
     * @param array<mixed> $b
     * @return self|null null for a budget that is switched off (limit 0)
     */
    public static function from(string $name, array $b, bool $strict = false): ?self
    {
        $limit = Settings::int($b, 'limit', "budgets.$name.limit");
        if ($limit <= 0) {
            return null;
        }
        $challengeAt = Settings::intOrNull($b, 'challengeAt', "budgets.$name.challengeAt");
        // strict: the check from a quarter of the limit, unless the budget says when.
        if ($strict && ($challengeAt === null || $challengeAt <= 0)) {
            $challengeAt = max(1, intdiv($limit, 4));
        }
        return new self(
            $name,
            $limit,
            max(1, Settings::int($b, 'window', "budgets.$name.window", 60)),
            $challengeAt !== null && $challengeAt > 0 ? $challengeAt : null,
            Settings::bool($b, 'onDemand', "budgets.$name.onDemand"),
            self::onExceeded($b, $name),
        );
    }

    /** @param array<mixed> $b */
    private static function onExceeded(array $b, string $name): bool
    {
        $v = Settings::string($b, 'onExceeded', "budgets.$name.onExceeded", 'throttle');
        if ($v !== 'throttle' && $v !== 'challenge') {
            throw Settings::wrong("budgets.$name.onExceeded", 'throttle (a pause, the default) or challenge (the check that frees the counter)');
        }
        return $v === 'challenge';
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
