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
 * A capability on a Plugin (0031 B.8): it can say how often the rules decided
 * and what the known crawlers did -- the numbers the rules and setup page
 * shows next to each rule. Recorded into the compiled settings at compile
 * time (`$s->hooks['ruleCounts']`, by instanceof), read only when that page
 * is drawn: a request pays nothing. The statistics plugin is the first.
 */
interface RuleCounts
{
    /**
     * How often each rule decided in the last $days days: rule id (as the log
     * names it) => times. [] when nothing is counted.
     *
     * @return array<string, int>
     */
    public function ruleCounts(int $days, float $now): array;

    /**
     * What each known crawler did in the last $days days, by its rule id
     * (CRAWL-GOOGLE): the counts the rules page puts into words. null when
     * nothing is counted.
     *
     * @return array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}>|null
     */
    public function crawlerCounts(int $days, float $now): ?array;
}
