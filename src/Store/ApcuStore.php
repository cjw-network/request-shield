<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Store;

/**
 * Counters in APCu: one atomic apcu_inc() per request. Shared by the processes
 * of one PHP pool (FPM, LSAPI, a forking server), not across servers.
 */
final class ApcuStore implements Store
{
    public function __construct(private string $prefix = 'rshield:')
    {
    }

    public static function usable(): bool
    {
        return \CjwNetwork\RequestShield\Capability::apcu();
    }

    public function hit(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        $k = $this->prefix . $window . ':' . $key . ':';
        $ok = false;
        $current = apcu_inc($k . $slot, 1, $ok, $window * 2 + 1);
        if (!$ok || $current === false) {
            $current = 1;
        }
        return SlidingWindow::estimate($current, self::count($k . ($slot - 1)), $weight);
    }

    public function reset(string $key, int $window, float $now): void
    {
        [$slot] = SlidingWindow::position($window, $now);
        $k = $this->prefix . $window . ':' . $key . ':';
        apcu_delete([$k . $slot, $k . ($slot - 1)]);
    }

    public function mark(string $key, int $until, float $now): void
    {
        if ($until <= $now) {
            apcu_delete($this->prefix . 'mark:' . $key);
            return;
        }
        apcu_store($this->prefix . 'mark:' . $key, $until, max(1, $until - (int) $now));
    }

    public function marked(string $key, float $now): int
    {
        $until = apcu_fetch($this->prefix . 'mark:' . $key);
        return is_int($until) && $until > $now ? $until : 0;
    }

    public function marks(string $prefix, float $now): array
    {
        $out = [];
        $start = $this->prefix . 'mark:';
        if (!class_exists(\APCUIterator::class)) {
            return $out;
        }
        foreach (new \APCUIterator('/^' . preg_quote($start . $prefix, '/') . '/', APC_ITER_KEY | APC_ITER_VALUE) as $item) {
            /** @var array{key: string, value: mixed} $item */
            if (is_int($item['value']) && $item['value'] > $now) {
                $out[substr($item['key'], strlen($start))] = $item['value'];
            }
        }
        return $out;
    }

    public function peek(string $key, int $window, float $now): float
    {
        [$slot, $weight] = SlidingWindow::position($window, $now);
        $k = $this->prefix . $window . ':' . $key . ':';
        return SlidingWindow::estimate(self::count($k . $slot), self::count($k . ($slot - 1)), $weight);
    }

    private static function count(string $key): int
    {
        $v = apcu_fetch($key);
        return is_int($v) ? $v : 0;
    }
}
