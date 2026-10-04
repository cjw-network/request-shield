<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api;

use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\LogTail;
use CjwNetwork\RequestShield\Rules\Lists;
use CjwNetwork\RequestShield\Settings;

/**
 * The live view's rows (proposal 0026, GET /live): what the shield stopped,
 * one row per request, newest first -- the website, the address, the
 * request, what happened, why in plain words and where the decision came
 * from. Read from the log's new lines (LogTail) or the live memory, so a
 * request pays nothing for it. The dashboard's live page (plugins/waf) shows
 * the same rows.
 */
final class LiveRows
{
    /** Rows one answer carries at most: the newest. */
    public const MAX_ROWS = 300;

    /** The words of a row: what happened, where it came from. */
    public const T = [
        'en' => [
            'w.refused' => 'refused',
            'w.banned' => 'banned',
            'w.paused' => 'told to wait',
            'w.checked' => 'checked',
            'w.uncached' => 'not cached',
            'w.passed' => 'let through',
            'w.watched' => 'watched: would be ',
            's.list' => 'list',
            's.ban' => 'ban',
            's.feed' => 'feed',
            's.own' => 'own rule',
            's.builtin' => 'built-in rule',
            's.pace' => 'pace',
            's.crawler' => 'crawler policy',
            's.shield' => 'basic check',
            'onList' => 'on the deny list',
            'onFeed' => 'on the public list %s',
        ],
        'de' => [
            'w.refused' => 'abgewiesen',
            'w.banned' => 'gesperrt',
            'w.paused' => 'zum Warten geschickt',
            'w.checked' => 'geprüft',
            'w.uncached' => 'nicht gecacht',
            'w.passed' => 'durchgelassen',
            'w.watched' => 'beobachtet: wäre ',
            's.list' => 'Liste',
            's.ban' => 'Sperre',
            's.feed' => 'Feed',
            's.own' => 'eigene Regel',
            's.builtin' => 'eingebaute Regel',
            's.pace' => 'Tempo',
            's.crawler' => 'Crawler-Regel',
            's.shield' => 'Grundprüfung',
            'onList' => 'auf der Sperrliste',
            'onFeed' => 'auf der öffentlichen Liste %s',
        ],
    ];

    /**
     * The new rows since $cursor (null: the end of the log), as the page shows them.
     *
     * @param array<string, mixed> $o lang (en, de), ip (the viewer's address: no "keep out" for a range that holds it),
     *                                links (the dashboard's pages, Frame::links(): a rule's ID links to its line on "rules", a list entry to "lists")
     * @return array{cursor: string, rows: list<array<string, mixed>>, skipped: int, log: bool, memory?: bool}
     */
    public static function json(Settings $s, ?string $cursor, array $o = []): array
    {
        $lang = ($o['lang'] ?? 'en') === 'de' ? 'de' : 'en';
        $memory = self::fromMemory($s);
        if (!$memory && $s->logFile === null) {
            return ['cursor' => '', 'rows' => [], 'skipped' => 0, 'log' => false];
        }
        // The memory (set live on: APCu, or live.log in store-dir): full addresses, the last hour; else the log's new lines.
        $tail = $memory ? \CjwNetwork\RequestShield\Live::read($s, $cursor, self::MAX_ROWS)
            : LogTail::read((string) $s->logFile, $cursor);
        // Not the dashboard's own requests that went through (its pages, this
        // feed every few seconds): the view would mostly show itself.
        $raw = [];
        foreach ($tail['rows'] as $r) {
            if (($r['action'] === 'allow-uncached' || $r['action'] === 'allow') && Frame::isPage($s, (string) parse_url($r['url'], PHP_URL_PATH))) {
                continue;
            }
            $raw[] = $r;
        }
        $raw = array_slice($raw, -self::MAX_ROWS);
        // The comments of the list entries named, read once for all rows.
        $ids = [];
        foreach ($raw as $r) {
            if ($r['reason'] === 'denied' && $r['rule'] !== null && strncmp($r['rule'], 'LIST-', 5) === 0) {
                $ids[$r['rule']] = true;
            }
        }
        $notes = $ids !== [] && $s->listsDir !== null ? Lists::notes($s->listsDir, array_keys($ids)) : [];
        $viewer = is_string($o['ip'] ?? null) ? $o['ip'] : null;
        $links = [];
        foreach ((array) ($o['links'] ?? []) as $k => $v) {
            if (is_string($v)) {
                $links[(string) $k] = $v;
            }
        }
        $rows = [];
        foreach ($raw as $r) {
            $row = self::row($s, $r, $lang, $notes, $links);
            if ($viewer !== null && is_string($row['keep']) && \CjwNetwork\RequestShield\IpAddress::inRanges($viewer, [$row['keep']])) {
                $row['keep'] = null;                        // never offered: it would lock out the person looking
            }
            $rows[] = $row;
        }
        return ['cursor' => $tail['cursor'], 'rows' => $rows, 'skipped' => $memory ? $tail['skipped'] : (int) round($tail['skipped'] / 1024), 'log' => true, 'memory' => $memory];
    }

    /** Whether the rows come from the live memory (set live on: the APCu ring, or live.log in store-dir) rather than the log. */
    public static function fromMemory(Settings $s): bool
    {
        return \CjwNetwork\RequestShield\Live::keeps($s);
    }

    /**
     * One log line as a row: website, what happened, why, where from.
     *
     * @param array{time: int, client: string, action: string, status: int, reason: string, rule: ?string, claimed: ?string, method: string, url: string, agent: string, ref?: ?string} $r
     * @param array<string, string> $notes list entry ID => its comment
     * @param array<string, string> $links the dashboard's pages: rules (a rule's line), lists (a list entry)
     * @return array<string, mixed>
     */
    public static function row(Settings $s, array $r, string $lang, array $notes = [], array $links = []): array
    {
        $t = self::T[$lang];
        $watched = strncmp($r['action'], 'monitor-', 8) === 0;
        $action = $watched ? substr($r['action'], 8) : $r['action'];
        $what = match ($action) {
            'reject' => 'refused',
            'throttle' => $r['reason'] === 'banned' ? 'banned' : 'paused',
            'challenge' => 'checked',
            'allow-uncached' => 'uncached',
            default => 'passed',
        };
        $parts = parse_url($r['url']);
        $host = is_array($parts) && isset($parts['host']) ? strtolower($parts['host']) : '';
        $request = (is_array($parts) ? ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '') : $r['url']);
        $source = self::source($s, $r);
        $reason = Describe::reason($r['reason'], $lang);
        $why = $reason;
        if ($source === 'list' && $r['rule'] !== null) {
            $note = $notes[$r['rule']] ?? ($s->origins['text'][$r['rule']] ?? '');
            $why = $t['onList'] . ($note !== '' ? ': ' . $note : '');
        } elseif ($source === 'feed' && $r['rule'] !== null && !isset($s->origins['text'][$r['rule']])) {
            foreach (array_merge($s->feeds, $s->monitor !== null ? $s->monitor->feeds : []) as $f) {
                if ($f['rule'] === $r['rule']) {
                    $why = sprintf($t['onFeed'], $f['title']);
                    break;
                }
            }
        } elseif ($r['rule'] !== null && isset($s->origins['text'][$r['rule']]) && $s->origins['text'][$r['rule']] !== '') {
            $why = (string) $s->origins['text'][$r['rule']];
        }
        $site = $s->sites === [] || $host === '' ? null : $s->siteFor(['SERVER_NAME' => $host, 'HTTP_HOST' => $host]);
        return [
            't' => $r['time'], 'time' => date('H:i:s', $r['time']), 'day' => date($lang === 'de' ? 'd.m.Y' : 'Y-m-d', $r['time']),
            'host' => $host, 'site' => $site, 'client' => $r['client'], 'method' => $r['method'], 'request' => $request,
            'what' => $what, 'watched' => $watched, 'status' => $r['status'],
            'label' => ($watched ? $t['w.watched'] : '') . $t['w.' . $what] . ($what !== 'passed' && $what !== 'uncached' ? ' ' . $r['status'] : ''),
            'why' => $why, 'reason' => $reason, 'source' => $source, 'sourceLabel' => $t['s.' . $source], 'rule' => $r['rule'],
            'keep' => $r['client'] !== '-' && $source !== 'list' ? $r['client'] : null, 'agent' => $r['agent'],
            'ruleHref' => self::ruleHref($s, $r['rule'], $links),
        ];
    }

    /**
     * Where a rule is written, as a link: a list entry on the lists page
     * (searched for its ID), a basic check on the way of a request, any other
     * rule on its line of the rules page (#rule-<ID>, its file opened there).
     *
     * @param array<string, string> $links
     */
    private static function ruleHref(Settings $s, ?string $rule, array $links): ?string
    {
        if ($rule === null || $rule === '') {
            return null;
        }
        if (strncmp($rule, 'LIST-', 5) === 0 && isset($links['lists'])) {
            return $links['lists'] . '?' . http_build_query(['q' => "[$rule]"]);
        }
        if (!isset($links['rules'])) {
            return null;
        }
        $id = Describe::ruleInfo($s, str_replace(' ', '_', $rule))['id'];
        return $links['rules'] . ($id === 'built-in' || $id === 'application' ? '#way' : '#rule-' . Describe::anchor($id));
    }

    /**
     * Where a decision came from: list, ban, feed, own (the site's rule files),
     * builtin (the shipped rules), pace (a budget), crawler (the crawler
     * policy), shield (a basic check without a rule).
     *
     * @param array{reason: string, rule: ?string, action: string} $r
     */
    public static function source(Settings $s, array $r): string
    {
        $rule = $r['rule'];
        if ($r['reason'] === 'banned') {
            return 'ban';
        }
        if ($r['reason'] === 'denied') {
            return 'list';
        }
        if ($r['reason'] === 'feed' || ($rule !== null && strncmp($rule, 'FEED-', 5) === 0)) {
            return 'feed';
        }
        if ($r['reason'] === 'crawler') {
            return 'crawler';
        }
        if (Describe::isBudget($r['reason'])) {
            return 'pace';
        }
        if ($rule === null) {
            return 'shield';
        }
        $at = (string) ($s->origins['at'][$rule] ?? $rule);
        return strncmp($at, 'built-in ', 9) === 0 || strncmp($rule, 'built-in ', 9) === 0 ? 'builtin' : 'own';
    }
}
