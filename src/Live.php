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
 * The live view's memory (set live on): the last requests the shield stopped
 * -- refused, banned, told to wait, checked, and what watched rules would have
 * done -- with the full address, so one can be kept out exactly. A ring of
 * SIZE entries in APCu, each kept live-keep seconds (an hour by default),
 * never written to disk; shared by every worker of a server, gone with a
 * restart.
 *
 * Two APCu calls for a request that was stopped anyway (a counter and its
 * entry); nothing for one that passes.
 */
final class Live
{
    /** Entries in the ring: the newest SIZE, older ones overwritten. */
    public const SIZE = 2000;

    public static function usable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    /** Whether a decision belongs in the live view: anything that stopped or checked a request. */
    public static function wants(Decision $d): bool
    {
        return $d->action !== Decision::ALLOW && $d->action !== Decision::ALLOW_UNCACHED;
    }

    public static function push(Settings $s, Request $request, Decision $d, ?string $rule, float $now, bool $monitor = false): void
    {
        if (!$s->liveEnabled || !self::wants($d) || !self::usable()) {
            return;
        }
        $p = self::prefix($s);
        $seq = apcu_inc($p . 'n', 1, $ok, 0);
        if ($seq === false) {
            return;
        }
        // The same fields as a log line (LogStats::parse()), so the page reads either.
        apcu_store($p . ($seq % self::SIZE), [$seq, [
            'time' => (int) $now, 'client' => $request->clientIp, 'action' => ($monitor ? 'monitor-' : '') . $d->action, 'status' => $d->status,
            'reason' => substr($d->reason, 0, 60), 'rule' => $rule === null ? null : substr($rule, 0, 120), 'claimed' => $d->claimed,
            'method' => substr($request->method, 0, 10), 'url' => substr($request->scheme . '://' . $request->host . $request->rawUri, 0, 300),
            'agent' => substr((string) $request->header('user-agent'), 0, 150),
        ]], $s->liveKeep);
    }

    /**
     * The entries after $cursor ("m:<n>"; null: the newest), oldest first.
     *
     * @return array{rows: list<array{time: int, client: string, action: string, status: int, reason: string, rule: ?string, claimed: ?string, method: string, url: string, agent: string}>, cursor: string, skipped: int}
     */
    public static function read(Settings $s, ?string $cursor, int $max = 500): array
    {
        $p = self::prefix($s);
        $last = self::usable() ? apcu_fetch($p . 'n') : false;
        $last = is_int($last) ? $last : 0;
        $from = $cursor !== null && preg_match('/^m:(\d+)$/', $cursor, $m) === 1 ? (int) $m[1] + 1 : $last - $max + 1;
        if ($from > $last + 1) {
            $from = max(1, $last - $max + 1);              // the counter started again (a restart): the newest
        }
        $skipped = 0;
        if ($last - $from + 1 > $max) {
            $skipped = $last - $from + 1 - $max;
            $from = $last - $max + 1;
        }
        $from = max(1, $from);
        $keys = [];
        for ($n = $from; $n <= $last; $n++) {
            $keys[] = $p . ($n % self::SIZE);
        }
        $rows = [];
        $got = $keys === [] ? [] : apcu_fetch($keys);
        foreach (is_array($got) ? $got : [] as $v) {
            if (is_array($v) && is_int($v[0] ?? null) && $v[0] >= $from && $v[0] <= $last && is_array($v[1] ?? null)) {
                $rows[$v[0]] = $v[1];                       // only this round's: an overwritten slot is skipped
            }
        }
        ksort($rows);
        /** @var list<array{time: int, client: string, action: string, status: int, reason: string, rule: ?string, claimed: ?string, method: string, url: string, agent: string}> $list */
        $list = array_values($rows);
        return ['rows' => $list, 'cursor' => 'm:' . $last, 'skipped' => $skipped];
    }

    /** Per store-dir: several sites on one server keep their own. */
    private static function prefix(Settings $s): string
    {
        return 'rshield-live:' . hash('crc32b', $s->storeDir) . ':';
    }
}
