<?php

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Tests;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Plugin;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\RuleCounts;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;

/**
 * A plugin with the RuleCounts capability and fixed numbers, for the tests
 * (0031 B.8): named by `plugin`, it is recorded into $s->hooks['ruleCounts']
 * when the rules are compiled, and the rules page adds its numbers up with
 * the others'. `set fail-at` of the test extension makes it throw instead.
 */
final class CountingPlugin implements Plugin, RuleCounts
{
    /** @var array<int, int> what was asked for: days => times called (a probe for the tests) */
    public static array $asked = [];

    public function __construct(private Settings $settings)
    {
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
    }

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
    }

    public function ruleCounts(int $days, float $now): array
    {
        self::$asked[$days] = (self::$asked[$days] ?? 0) + 1;
        if (($this->settings->ext['rs-test']['failAt'] ?? null) === 'counts') {
            throw new \RuntimeException('the counting plugin failed, as asked');
        }
        return ['T-1' => 3 * $days, 'built-in' => 1];
    }

    public function crawlerCounts(int $days, float $now): ?array
    {
        return null;
    }
}
