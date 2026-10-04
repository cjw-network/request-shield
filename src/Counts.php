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
 * The numbers next to the rules: asked from the plugins that have the
 * RuleCounts capability (`$s->hooks['ruleCounts']`, recorded when the rules
 * were compiled), added up. The pages know no plugin by name; a plugin that
 * fails is left out (the page is drawn without its numbers).
 */
final class Counts
{
    /**
     * How often each rule decided in the last $days days, all plugins added up.
     *
     * @return array<string, int>
     */
    public static function rules(Settings $s, int $days, float $now): array
    {
        $sum = [];
        foreach (self::providers($s) as $plugin) {
            try {
                foreach ($plugin->ruleCounts($days, $now) as $rule => $n) {
                    $sum[(string) $rule] = ($sum[(string) $rule] ?? 0) + $n;
                }
            } catch (\Throwable $e) {
                // report only: the page comes without these numbers
            }
        }
        return $sum;
    }

    /**
     * What the known crawlers did in the last $days days: the first plugin
     * that counts them; null when none does.
     *
     * @return array<string, array{kind: string, policy: string, name: string, seen: int, verified: int, claimed: int, allowed: int, checked: int, refused: int, throttled: int, robots: int, pages: array<string, int>, last: array{0: int, 1: string}|null}>|null
     */
    public static function crawlers(Settings $s, int $days, float $now): ?array
    {
        foreach (self::providers($s) as $plugin) {
            try {
                $c = $plugin->crawlerCounts($days, $now);
                if ($c !== null) {
                    return $c;
                }
            } catch (\Throwable $e) {
                // report only
            }
        }
        return null;
    }

    /**
     * The plugins with the capability, made as the shield makes them (new $class($settings)).
     *
     * @return list<RuleCounts>
     */
    private static function providers(Settings $s): array
    {
        $out = [];
        foreach ($s->hooks['ruleCounts'] ?? [] as $class) {
            try {
                if (class_exists($class) && is_subclass_of($class, RuleCounts::class)) {
                    $out[] = new $class($s);
                }
            } catch (\Throwable $e) {
                // left out
            }
        }
        return $out;
    }
}
