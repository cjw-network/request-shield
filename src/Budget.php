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
    ) {
    }

    /**
     * @param array<mixed> $b
     * @return self|null null for a budget that is switched off (limit 0)
     */
    public static function from(string $name, array $b): ?self
    {
        $limit = Settings::int($b, 'limit', "budgets.$name.limit");
        if ($limit <= 0) {
            return null;
        }
        $challengeAt = Settings::intOrNull($b, 'challengeAt', "budgets.$name.challengeAt");
        return new self(
            $name,
            $limit,
            max(1, Settings::int($b, 'window', "budgets.$name.window", 60)),
            $challengeAt !== null && $challengeAt > 0 ? $challengeAt : null,
            Settings::bool($b, 'onDemand', "budgets.$name.onDemand"),
        );
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
