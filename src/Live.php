<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Report\LogTail;

/**
 * The live view's memory (set live on): the last requests the shield stopped
 * -- refused, banned, told to wait, checked, and what watched rules would have
 * done -- with the full address, so one can be kept out exactly. Each entry
 * is kept live-keep seconds (an hour by default).
 *
 * With APCu: a ring of SIZE entries in memory, shared by every worker of a
 * server, gone with a restart; two APCu calls for a request that was stopped
 * anyway. Without (the file store, ADR 0013): the same lines appended to
 * store-dir/live.log, rotated once past FILE_MAX bytes -- as the log is, but
 * with the full address and only for live-keep. Nothing for a request that
 * passes.
 */
final class Live implements Sink
{
    /** The first sink (0031 B.9): the live view hears what the log hears, when live is on. */
    public function __construct(private Settings $settings)
    {
    }

    public function note(Request $request, Decision $decision, ?string $rule, float $now, bool $monitor): void
    {
        self::push($this->settings, $request, $decision, $rule, $now, $monitor);
    }

    /** Entries in the APCu ring: the newest SIZE, older ones overwritten. */
    public const SIZE = 2000;

    /** Bytes of live.log before it rotates to live.log.1 (about SIZE lines). */
    public const FILE_MAX = 500000;

    /** Whether the APCu ring is available here. */
    public static function usable(): bool
    {
        return Capability::apcu();
    }

    /**
     * Where the live view keeps its rows: "apcu" (the ring), "file"
     * (store-dir/live.log) or "none" (set store memory: nothing outlasts a
     * request).
     */
    public static function where(Settings $s): string
    {
        if ($s->store === 'memory') {
            return 'none';
        }
        return $s->store !== 'file' && Capability::apcu() ? 'apcu' : 'file';
    }

    /** Whether the live view has rows of its own to show (set live on, and somewhere to keep them). */
    public static function keeps(Settings $s): bool
    {
        return $s->liveEnabled && self::where($s) !== 'none';
    }

    /** Whether a decision belongs in the live view: anything that stopped or checked a request. */
    public static function wants(Decision $d): bool
    {
        return $d->action !== Decision::ALLOW && $d->action !== Decision::ALLOW_UNCACHED;
    }

    public static function push(Settings $s, Request $request, Decision $d, ?string $rule, float $now, bool $monitor = false): void
    {
        if (!$s->liveEnabled || !self::wants($d)) {
            return;
        }
        $where = self::where($s);
        if ($where === 'none') {
            return;
        }
        if ($where === 'file') {
            // The log's line, with the full address: LogTail reads it back.
            Log::append(self::file($s), Log::line($s, $request, $d, $rule, $now, $monitor, $request->clientIp), self::FILE_MAX);
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
     * The entries after $cursor (null: the newest), oldest first. The cursor
     * is the ring's "m:<n>", or the file's "<inode>:<offset>" (LogTail).
     *
     * @return array{rows: list<array{time: int, client: string, action: string, status: int, reason: string, rule: ?string, claimed: ?string, method: string, url: string, agent: string}>, cursor: string, skipped: int}
     */
    public static function read(Settings $s, ?string $cursor, int $max = 500): array
    {
        if (self::where($s) !== 'apcu') {
            return self::readFile($s, $cursor, $max);
        }
        $p = self::prefix($s);
        $last = apcu_fetch($p . 'n');
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

    /**
     * The file's new lines since the cursor, without those older than
     * live-keep; at most $max rows (about 250 bytes each).
     *
     * @return array{rows: list<array{time: int, client: string, action: string, status: int, reason: string, rule: ?string, claimed: ?string, method: string, url: string, agent: string}>, cursor: string, skipped: int}
     */
    private static function readFile(Settings $s, ?string $cursor, int $max): array
    {
        $t = LogTail::read(self::file($s), $cursor, $max * 250);
        $since = time() - $s->liveKeep;
        $rows = [];
        foreach ($t['rows'] as $row) {
            if ($row['time'] >= $since) {
                $rows[] = $row;
            }
        }
        return ['rows' => $rows, 'cursor' => $t['cursor'], 'skipped' => intdiv($t['skipped'], 250)];
    }

    /** The file of the live view without APCu. */
    public static function file(Settings $s): string
    {
        return $s->storeDir . '/live.log';
    }

    /** Per store-dir: several sites on one server keep their own. */
    private static function prefix(Settings $s): string
    {
        return 'rshield-live:' . hash('crc32b', $s->storeDir) . ':';
    }
}
