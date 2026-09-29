<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

use CjwNetwork\RequestShield\Config;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Settings;

/**
 * Settings and decisions in plain words, for people who do not read regular
 * expressions: the rules page and the command line use it.
 */
final class Describe
{
    /** A built-in pattern, as a site owner would say it. */
    public static function builtIn(string $pattern): ?string
    {
        return Config::builtIns()[$pattern][1] ?? null;
    }

    /**
     * A rule for people: its description (the comment after it in the rule
     * file), else the pattern as written.
     */
    public static function rule(Settings $s, string $setting, string $pattern): string
    {
        $id = $s->origin($setting, $pattern);
        $text = $id !== null ? $s->origin('text', $id) : null;
        return $text ?? self::pattern($s, $pattern);
    }

    /** Where a rule is written: "site.rules:12", "built-in scanners.rules:10". */
    public static function where(Settings $s, ?string $id): ?string
    {
        return $id === null ? null : $s->origin('at', $id);
    }

    /** A path pattern as it was written in a rule file, the built-in name, or the expression. */
    public static function pattern(Settings $s, string $pattern): string
    {
        $written = $s->origin('written', $pattern);
        if ($written !== null) {
            return $written;
        }
        return self::builtIn($pattern) ?? 'regex ' . trim((string) preg_replace('/^#|#i?$/', '', $pattern));
    }

    public static function duration(int $seconds): string
    {
        $units = [86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second'];
        foreach ($units as $size => $unit) {
            if ($seconds % $size === 0) {
                $n = intdiv($seconds, $size);
                return $n === 1 ? $unit : "$n {$unit}s";
            }
        }
        return "$seconds seconds";
    }

    /** "1 minute", "2 hours": a length of time rather than a unit. */
    public static function span(int $seconds): string
    {
        $d = self::duration($seconds);
        return ctype_digit($d[0]) ? $d : "1 $d";
    }

    /** What a visitor gets, in one sentence. */
    public static function verdict(Decision $d): string
    {
        switch ($d->action) {
            case Decision::ALLOW:
                return 'sees the page — a cache may keep it';
            case Decision::ALLOW_UNCACHED:
                return 'sees the page — answered by the site, but a cache must not keep it';
            case Decision::CHALLENGE:
                return 'gets the browser check first: an invisible moment, then the page (not with a valid pass)';
            case Decision::THROTTLE:
                return "has to wait {$d->retryAfter} seconds (429 Too Many Requests)";
        }
        $what = [
            400 => 'a broken request (400) — the site never sees it',
            403 => 'no access (403) — the site never sees it',
            404 => '"not found" (404) — the site never sees it',
            405 => '"not allowed here" (405) — this kind of request is not accepted at this address',
            414 => 'an address that is too long (414)',
            431 => 'too much header data (431)',
        ];
        return 'gets ' . ($what[$d->status] ?? "an error ($d->status)");
    }

    /** Why, in words: a decision's reason. */
    public static function reason(string $reason): string
    {
        $words = [
            'blocked path' => 'an address only attackers ask for',
            'restricted' => 'an area only for certain addresses',
            'method' => 'this kind of request is not accepted',
            'method not allowed here' => 'a form sent where there is none',
            'host' => 'an unknown website name',
            'uri length' => 'the address is too long',
            'query parameters' => 'too many parameters',
            'header size' => 'too much header data',
            'path encoding' => 'a disguised address',
            'path traversal' => 'an attempt to leave the website\'s folder',
            'query parameter' => 'a parameter a cache must not keep',
            'path not cacheable' => 'an address a cache must not keep',
            'unknown url' => 'an address the site does not know',
            'always' => 'a page where every visitor is checked',
            'challenge solved' => 'the browser check was just passed',
        ];
        return $words[$reason] ?? "the budget \"$reason\"";
    }
}
