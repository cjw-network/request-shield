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
 * What the log says: how often each rule decided, and when last; the latest
 * lines. Reads only the end of the file (and the rotated one if that is too
 * short), so a large log stays quick to look at.
 */
final class LogStats
{
    /**
     * @return array{rules: array<string, array{count: int, last: int}>, actions: array<string, int>, claims: array<string, int>, recent: list<array{time: int, client: string, action: string, status: int, reason: string, rule: ?string, method: string, url: string, agent: string}>, since: int, lines: int}
     */
    public static function read(?string $file, int $since, int $recent = 20, int $maxBytes = 1048576): array
    {
        $out = ['rules' => [], 'actions' => [], 'claims' => [], 'recent' => [], 'since' => $since, 'lines' => 0];
        if ($file === null) {
            return $out;
        }
        $text = self::tail($file . '.1', $maxBytes) . self::tail($file, $maxBytes);
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $entry = self::parse($line);
            if ($entry === null || $entry['time'] < $since) {
                continue;
            }
            $claimed = $entry['claimed'];
            unset($entry['claimed']);
            if ($claimed !== null) {
                // Named a known crawler without coming from it.
                $out['claims'][$claimed] = ($out['claims'][$claimed] ?? 0) + 1;
            }
            $lines[] = $entry;
            $out['actions'][$entry['action']] = ($out['actions'][$entry['action']] ?? 0) + 1;
            if ($entry['rule'] !== null) {
                $r = $out['rules'][$entry['rule']] ?? ['count' => 0, 'last' => 0];
                $out['rules'][$entry['rule']] = ['count' => $r['count'] + 1, 'last' => max($r['last'], $entry['time'])];
            }
        }
        $out['lines'] = count($lines);
        $out['recent'] = array_reverse(array_slice($lines, -$recent));
        return $out;
    }

    /**
     * One line of the log, or null for anything else.
     *
     * @return array{time: int, client: string, action: string, status: int, reason: string, rule: ?string, claimed: ?string, method: string, url: string, agent: string, ref: ?string}|null
     */
    public static function parse(string $line): ?array
    {
        if (!preg_match('/^(\S+) (\S+) (\S+) (\d+) "([^"]*)"(?: rule=(.*?))?(?: claimed=(\S+))?(?: ref=([0-9A-Z]{4}-[0-9A-Z]{4}))? "(\S+) ([^"]*)" "([^"]*)"$/', $line, $m)) {
            return null;
        }
        $time = strtotime($m[1]);
        if ($time === false) {
            return null;
        }
        return ['time' => $time, 'client' => $m[2], 'action' => $m[3], 'status' => (int) $m[4], 'reason' => $m[5],
            'rule' => $m[6] !== '' ? $m[6] : null, 'claimed' => $m[7] !== '' ? $m[7] : null, 'method' => $m[9], 'url' => $m[10], 'agent' => $m[11], 'ref' => $m[8] !== '' ? $m[8] : null];
    }

    private static function tail(string $file, int $maxBytes): string
    {
        $size = @filesize($file);
        if ($size === false || $size === 0) {
            return '';
        }
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return '';
        }
        if ($size > $maxBytes) {
            fseek($h, -$maxBytes, SEEK_END);
            fgets($h);          // the first line is cut: skip it
        }
        $text = stream_get_contents($h);
        fclose($h);
        return is_string($text) ? rtrim($text, "\n") . "\n" : '';
    }
}
