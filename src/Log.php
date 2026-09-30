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
 * The shield's log: one line per request it did something about, so a site
 * owner sees what was turned away, by which rule -- and what a new rule would
 * hit. Nothing is written for a request that simply passes (unless the level
 * is "all"), so the normal path costs nothing.
 *
 *   2026-09-29T08:41:03+02:00 198.51.100.0/24 reject 404 "blocked path" rule=site.rules:5 "GET https://www.example.org/wp-login.php" "Mozilla/5.0 ..."
 *
 * The client address first, as grep and log tools expect it. By default it is
 * anonymised to its network (IPv4 /24, IPv6 /48), written as such so nobody
 * mistakes it for a client: enough to see a pattern, not a person. "set
 * log-ip full" when the log feeds a ban list (fail2ban). Then the request
 * with its full URL, as the shield saw it (scheme and host from a trusted
 * proxy).
 */
final class Log
{
    public const LEVELS = ['off', 'stop', 'flag', 'all'];

    /** Whether a decision is written at a level. */
    public static function wants(string $level, Decision $d): bool
    {
        switch ($level) {
            case 'all':
                return true;
            case 'flag':
                return $d->action !== Decision::ALLOW;
            case 'stop':
                return !$d->passes();
        }
        return false;
    }

    /** @param bool $monitor what a rule in monitor would have decided: written as "monitor-<action>" */
    public static function write(Settings $s, Request $request, Decision $d, ?string $rule, ?float $now = null, bool $monitor = false): void
    {
        $file = $s->logFile;
        if ($file === null) {
            return;
        }
        $line = date('c', (int) ($now ?? time())) . ' '
            . ($s->logIp === 'full' ? $request->clientIp : self::mask($request->clientIp)) . ' '
            . ($monitor ? 'monitor-' : '') . $d->action . ' ' . $d->status . ' "' . self::clean($d->reason, 60) . '"'
            . ($rule !== null ? ' rule=' . self::clean($rule, 120) : '')
            . ($d->claimed !== null ? ' claimed=' . self::clean($d->claimed, 60) : '')
            . ' "' . self::clean($request->method, 10) . ' ' . self::clean($request->scheme . '://' . $request->host . $request->rawUri, 300) . '"'
            . ' "' . self::clean((string) $request->header('user-agent'), 150) . "\"\n";

        // One rotation when it gets large: file.log -> file.log.1. Only
        // checked when there is something to write -- and past PHP's stat
        // cache, which a long-running process would otherwise keep.
        clearstatcache(true, $file);
        $size = @filesize($file);
        if ($size !== false && $size > $s->logMaxSize) {
            @rename($file, $file . '.1');
        }
        $new = $size === false;
        if ($new) {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
        }
        // O_APPEND: lines from parallel requests do not overwrite each other.
        if (@file_put_contents($file, $line, FILE_APPEND) !== false && $new) {
            @chmod($file, 0640);
        }
    }

    /** 198.51.100.7 -> 198.51.100.0/24, 2001:db8:1:2::5 -> 2001:db8:1::/48 */
    public static function mask(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return '-';
        }
        if (strlen($bin) === 4) {
            return inet_ntop(substr($bin, 0, 3) . "\0") . '/24';
        }
        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** Printable, no quotes or line breaks (a forged log line), shortened. */
    private static function clean(string $s, int $max): string
    {
        $s = preg_replace('/[^\x20-\x7e]/', '?', $s) ?? '';
        $s = str_replace(['"', '\\'], ["'", '/'], $s);
        return strlen($s) > $max ? substr($s, 0, $max - 3) . '...' : $s;
    }
}
