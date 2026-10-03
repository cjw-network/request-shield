<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

/**
 * What the library ships in rules/ -- the rule sets (@scanners, @wordpress
 * …), the feed catalog, the crawlers' address lists and the crawlers ready
 * for settings from a PHP array -- read through this one class (0031 E.1,
 * proposal 0002). No other class names the directory, so the single-file
 * build can embed the data: in the repository the constants are empty and
 * the methods read rules/; the build fills RULES, FEEDS and CRAWLER_LISTS
 * with nowdoc heredocs, and then nothing here touches rules/ at all.
 *
 * A source to watch (rulesFile(), crawlerListFile()) is the file in rules/,
 * or -- embedded -- the file this class lives in: an update of the single
 * file changes its mtime, so the compiled settings are rebuilt, at the cost
 * of the one stat the main file costs already.
 */
final class Shipped
{
    /** @var array<string, string> name => the contents of rules/<name>.rules; empty in the repository (read from rules/) */
    public const RULES = [];

    /** The contents of rules/feeds.json; '' in the repository. */
    public const FEEDS = '';

    /** @var array<string, string> name => the contents of rules/crawlers/<name>.json; empty in the repository */
    public const CRAWLER_LISTS = [];

    /** @var array<string, string> app => the contents of rules/starter/<app>.rules (`init --app=`); empty in the repository */
    public const STARTERS = [];

    /**
     * The release key (minisign, Ed25519; the public key's base64 line): what
     * `verify` and `self-update` check a download's signature with. Empty until
     * the owner makes the key (0031 step H.2a) -- then verify checks the
     * checksum only and says so, and self-update refuses.
     */
    public const PUBKEY = '';

    /** @var array{crawlers: array<string, array{kind: string, ua: string, dns: list<string>, ranges: list<string>, lists: array<string, array<string, ?string>>, policy: string, nets: array<string, list<array{0: string, 1: int}>>}>, index: string, ids: list<string>, names: array<string, string>}|null */
    private static ?array $crawlers = null;

    /** Whether the data is embedded (the single file), not read from rules/. */
    public static function embedded(): bool
    {
        return self::embeddedRules() !== [];
    }

    /** The contents of a shipped rule set ("scanners" or "@scanners"), null when there is none of that name. */
    public static function rules(string $name): ?string
    {
        $name = ltrim($name, '@');
        if (!self::valid($name)) {
            return null;
        }
        if (self::embedded()) {
            return self::embeddedRules()[$name] ?? null;
        }
        $text = @file_get_contents(self::dir() . "/$name.rules");
        return $text === false ? null : $text;
    }

    /** The file whose change means the set changed: rules/<name>.rules, or this file when embedded; null when there is no such set. */
    public static function rulesFile(string $name): ?string
    {
        $name = ltrim($name, '@');
        if (!self::valid($name)) {
            return null;
        }
        if (self::embedded()) {
            return isset(self::embeddedRules()[$name]) ? __FILE__ : null;
        }
        $file = self::dir() . "/$name.rules";
        return is_file($file) ? $file : null;
    }

    /**
     * The shipped rule sets' names, sorted (for `version`).
     *
     * @return list<string>
     */
    public static function sets(): array
    {
        if (self::embedded()) {
            $names = array_keys(self::embeddedRules());
        } else {
            $names = array_map(static fn (string $f): string => basename($f, '.rules'), glob(self::dir() . '/*.rules') ?: []);
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** The feed catalog (rules/feeds.json), '' when there is none. */
    public static function feeds(): string
    {
        if (self::embedded()) {
            return self::FEEDS;
        }
        return (string) @file_get_contents(self::dir() . '/feeds.json');
    }

    /** The starter rule file for an application (rules/starter/<app>.rules), null when there is none. */
    public static function starter(string $app): ?string
    {
        if (!self::valid($app)) {
            return null;
        }
        if (self::embedded()) {
            return self::embeddedStarters()[$app] ?? null;
        }
        $text = @file_get_contents(self::dir() . "/starter/$app.rules");
        return $text === false ? null : $text;
    }

    /**
     * The applications there is a starter for, sorted.
     *
     * @return list<string>
     */
    public static function starters(): array
    {
        $names = self::embedded() ? array_keys(self::embeddedStarters())
            : array_map(static fn (string $f): string => basename($f, '.rules'), glob(self::dir() . '/starter/*.rules') ?: []);
        sort($names, SORT_STRING);
        return $names;
    }

    /** A shipped address list (rules/crawlers/<name>.json) as JSON, null when there is none of that name. */
    public static function crawlerList(string $name): ?string
    {
        if (!self::valid($name)) {
            return null;
        }
        if (self::embedded()) {
            return self::embeddedLists()[$name] ?? null;
        }
        $json = @file_get_contents(self::dir() . "/crawlers/$name.json");
        return $json === false ? null : $json;
    }

    /** The file whose change means the list changed (rules/crawlers/<name>.json, or this file when embedded); null when there is none. */
    public static function crawlerListFile(string $name): ?string
    {
        if (!self::valid($name)) {
            return null;
        }
        if (self::embedded()) {
            return isset(self::embeddedLists()[$name]) ? __FILE__ : null;
        }
        $file = self::dir() . "/crawlers/$name.json";
        return is_file($file) ? $file : null;
    }

    /**
     * The shipped crawlers ready for Settings (rules/crawlers.php, made by
     * bin/update-crawler-lists): what settings from a PHP array use without
     * a compile. Not embedded (330 KB that only serves Shield::protect(array));
     * the single file builds the same from its rule set and lists, once per
     * process.
     *
     * @return array{crawlers: array<string, array{kind: string, ua: string, dns: list<string>, ranges: list<string>, lists: array<string, array<string, ?string>>, policy: string, nets: array<string, list<array{0: string, 1: int}>>}>, index: string, ids: list<string>, names: array<string, string>}
     */
    public static function crawlers(): array
    {
        if (self::$crawlers === null) {
            /** @var array{crawlers: array<string, array{kind: string, ua: string, dns: list<string>, ranges: list<string>, lists: array<string, array<string, ?string>>, policy: string, nets: array<string, list<array{0: string, 1: int}>>}>, index: string, ids: list<string>, names: array<string, string>} $ready */
            $ready = self::embedded() ? RuleFile::shippedReady() : require self::dir() . '/crawlers.php';
            self::$crawlers = $ready;
        }
        return self::$crawlers;
    }

    /** Whether a path is one of the shipped files -- a shipped set, not a site's file (for `check`). */
    public static function isShipped(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        if (self::embedded()) {
            return $real === realpath(__FILE__);
        }
        $dir = realpath(self::dir());
        return $dir !== false && strncmp($real, $dir . '/', strlen($dir) + 1) === 0;
    }

    /** The name a source is shown with: "built-in scanners.rules" for a shipped file, null for any other. */
    public static function label(string $path): ?string
    {
        if (self::embedded() || !self::isShipped($path)) {
            return null;
        }
        $dir = (string) realpath(self::dir());
        return 'built-in ' . substr((string) realpath($path), strlen($dir) + 1);
    }

    /** @return array<string, string> RULES, typed (empty in the repository) */
    private static function embeddedRules(): array
    {
        return self::RULES;
    }

    /** @return array<string, string> STARTERS, typed (empty in the repository) */
    private static function embeddedStarters(): array
    {
        return self::STARTERS;
    }

    /** @return array<string, string> CRAWLER_LISTS, typed (empty in the repository) */
    private static function embeddedLists(): array
    {
        return self::CRAWLER_LISTS;
    }

    /** A shipped name: letters, digits and "-" only, so it never leaves the directory. */
    private static function valid(string $name): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $name) === 1;
    }

    /** rules/ in the repository; the build never calls it (the data is embedded). */
    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/rules';
    }
}
