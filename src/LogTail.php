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
 * The log's new lines since the last look, for the live view: a cursor
 * ("<inode>:<offset>") says where the last read ended. The first look reads
 * the end of the file; a rotated log (another inode) or a shorter one starts
 * from its beginning; a burst bigger than one read skips to the newest bytes
 * and says how many were skipped. Only whole lines are read -- a line being
 * written is read next time.
 */
final class LogTail
{
    /**
     * @return array{rows: list<array{time: int, client: string, action: string, status: int, reason: string, rule: ?string, claimed: ?string, method: string, url: string, agent: string, ref?: ?string}>, cursor: string, skipped: int}
     */
    public static function read(string $file, ?string $cursor, int $maxBytes = 65536): array
    {
        $out = ['rows' => [], 'cursor' => '0:0', 'skipped' => 0];
        clearstatcache(true, $file);
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return $out;
        }
        $stat = fstat($h);
        $size = is_array($stat) ? (int) $stat['size'] : 0;
        $inode = is_array($stat) ? (string) $stat['ino'] : '0';
        $offset = null;
        if ($cursor !== null && preg_match('/^(\d+):(\d+)$/', $cursor, $m) === 1 && $m[1] === $inode && (int) $m[2] <= $size) {
            $offset = (int) $m[2];
        } elseif ($cursor !== null && $cursor !== '') {
            $offset = 0;                                    // rotated or cut: the new file from its start
        }
        $cut = false;
        if ($offset === null || $size - $offset > $maxBytes) {
            $start = max(0, $size - $maxBytes);
            $out['skipped'] = $offset === null ? 0 : $start - $offset;
            $cut = $start > 0;
            $offset = $start;
        }
        fseek($h, $offset);
        $text = (string) stream_get_contents($h, $size - $offset);
        fclose($h);
        $end = strrpos($text, "\n");
        if ($end === false) {
            $out['cursor'] = $inode . ':' . $offset;
            return $out;
        }
        $text = substr($text, 0, $end + 1);
        $out['cursor'] = $inode . ':' . ($offset + strlen($text));
        $lines = explode("\n", rtrim($text, "\n"));
        if ($cut) {
            array_shift($lines);                            // started inside a line
        }
        foreach ($lines as $line) {
            $row = LogStats::parse($line);
            if ($row !== null) {
                $out['rows'][] = $row;
            }
        }
        return $out;
    }
}
