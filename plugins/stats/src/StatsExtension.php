<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Vocabulary;

/**
 * The statistics' own settings, as an extension (0031 B.3, ADR 0008): the
 * `stats` words and `set` keys of the rule file, checked when the rules are
 * compiled into `ext.stats`, which StatsPlugin and the statistics' pages
 * read. The bootstrap names it (REQUEST_SHIELD_EXTENSIONS) and the registry
 * offers it on its first lookup; without plugins/stats (a smaller build)
 * `set stats on` is an unknown setting.
 *
 * What stays in the core until 0031 B.5/B.7: stats-group, stats-access,
 * stats-session and stats-path -- Access and the dashboard's frame read them,
 * and the core never reads an extension's slot.
 */
final class StatsExtension implements Extension
{
    /** What the statistics can count (set stats <parts>). */
    public const PARTS = ['requests', 'crawlers', 'not-found', 'bots', 'pages', 'forms'];

    public static function id(): string
    {
        return 'stats';
    }

    /**
     * The compiled slot: every key there, the statistics off. What a reader
     * sees when the extension did not compile (no slot at all).
     *
     * @return array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, hosts: list<string>, skip: list<string>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}}
     */
    public static function defaults(): array
    {
        return ['enabled' => false, 'parts' => self::PARTS, 'hours' => 7, 'days' => 400, 'months' => 0, 'flush' => 60, 'depth' => 2, 'hosts' => [], 'skip' => [],
            'crawlerLog' => ['dir' => null, 'kinds' => [], 'days' => 30, 'query' => true]];
    }

    /**
     * The statistics' settings of $s: its compiled slot, or the defaults when
     * there is none -- a reader never sees a missing key.
     *
     * @return array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, hosts: list<string>, skip: list<string>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}}
     */
    public static function of(Settings $s): array
    {
        /** @var array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, hosts: list<string>, skip: list<string>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}} */
        return $s->ext['stats'] ?? self::defaults();
    }

    public static function vocabulary(Vocabulary $v): void
    {
        // set stats on|off|<parts>: on, off, or what to count -- enabled and parts at once.
        $v->set('stats', 'words', 'on, off or what to count: ' . implode(', ', self::PARTS), static function ($words, string $at): array {
            $words = array_map(static fn ($w): string => is_scalar($w) ? strtolower((string) $w) : '', is_array($words) ? $words : []);
            if ($words === ['on'] || $words === ['off']) {
                return ['enabled' => $words === ['on']];
            }
            foreach ($words as $w) {
                if (!in_array($w, self::PARTS, true)) {
                    throw new RuleFileException("$at: stats is on, off or what to count: " . implode(', ', self::PARTS) . " -- not \"$w\"");
                }
            }
            return ['enabled' => true, 'parts' => $words];
        }, many: true);
        $v->set('stats-flush', 'seconds', 'with APCu, seconds between writes of the counts to disk (0: only hourly)', null, 'flush');
        $v->set('stats-months', 'int', 'months the month totals are kept (0: for good)', null, 'months');
        $v->set('stats-depth', 'int', 'folder levels a section\'s views are counted for exactly: 1 to 4', null, 'depth');
        $v->set('stats-hours', 'int', 'days the hourly counters are kept', null, 'hours');
        $v->set('stats-days', 'int', 'days the daily counters are kept', null, 'days');
        $v->set('stats-hosts', 'words', 'the websites with statistics of their own: names, *.domain, host, sites',
            static fn ($value, string $at): array => self::hostnames('stats-hosts', $value, $at), 'hosts', serverWide: true);
        $v->set('crawler-log', 'path', 'one log per known crawler and day in this directory', null, 'crawlerLog.dir');
        $v->set('crawler-log-kinds', 'words', 'the kinds whose crawlers are logged', static function ($value, string $at): array {
            $kinds = array_map(static fn ($k): string => is_scalar($k) ? (string) $k : '', is_array($value) ? $value : []);
            foreach ($kinds as $k) {
                if (!in_array($k, RuleFile::KINDS, true)) {
                    throw new RuleFileException("$at: crawler-log-kinds takes kinds of crawler (" . implode(', ', RuleFile::KINDS) . "), not \"$k\"");
                }
            }
            return $kinds;
        }, 'crawlerLog.kinds');
        $v->set('crawler-log-days', 'int', 'days a crawler\'s log is kept', null, 'crawlerLog.days');
        $v->set('crawler-log-query', 'bool', 'whether the crawler logs keep the query string', null, 'crawlerLog.query');
        // stats-skip <paths>: not in the statistics when they pass (a map proxy's tiles); protected all the same.
        $v->word('stats-skip', static function (array $args, array $values, string $at, string $rid): array {
            if ($args === []) {
                throw new RuleFileException("$at: stats-skip needs at least one value");
            }
            /** @var list<string> $skip */
            $skip = is_array($values['skip'] ?? null) ? array_values($values['skip']) : [];
            if ($args[0] === 'none') {
                $skip = [];
                array_shift($args);
            }
            foreach ($args as $pattern) {
                if (!in_array($pattern, $skip, true)) {
                    $skip[] = $pattern;
                }
            }
            $values['skip'] = $skip;
            return $values;
        }, 'stats-skip <paths>: not counted when they pass', paths: true);
    }

    /**
     * Website names (*.domain: one label), or "host" (the host rule's), "sites"
     * (the site blocks'), as `set stats-hosts` takes them.
     *
     * @param mixed $value
     * @return list<string>
     */
    private static function hostnames(string $key, $value, string $at): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $name) {
            $name = rtrim(strtolower(is_scalar($name) ? (string) $name : ''), '.');
            if ($name !== 'host' && $name !== 'sites' && preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $name) !== 1) {
                throw new RuleFileException("$at: $key takes website names (www.example.org, *.example.org), host or sites -- not \"$name\"");
            }
            $out[] = $name;
        }
        return $out;
    }

    public static function compile(array $raw, Settings $base): array
    {
        $log = Settings::map($raw, 'crawlerLog', 'ext.stats.crawlerLog');
        $dir = $log['dir'] ?? null;
        if ($dir !== null && (!is_string($dir) || $dir === '')) {
            throw Settings::wrong('ext.stats.crawlerLog.dir', 'null or a directory');
        }
        $kinds = Settings::strings($log, 'kinds', 'ext.stats.crawlerLog.kinds');
        foreach ($kinds as $k) {
            if (!in_array($k, RuleFile::KINDS, true)) {
                throw Settings::wrong('ext.stats.crawlerLog.kinds', 'kinds of crawler: ' . implode(', ', RuleFile::KINDS));
            }
        }
        $depth = Settings::int($raw, 'depth', 'ext.stats.depth', 2);
        if ($depth < 1 || $depth > 4) {
            throw Settings::wrong('ext.stats.depth', '1 to 4 folder levels');
        }
        $parts = array_key_exists('parts', $raw) ? Settings::strings($raw, 'parts', 'ext.stats.parts') : self::PARTS;
        foreach ($parts as $p) {
            if (!in_array($p, self::PARTS, true)) {
                throw Settings::wrong('ext.stats.parts', implode(', ', self::PARTS));
            }
        }
        $skip = [];
        foreach (is_array($raw['skip'] ?? null) ? $raw['skip'] : [] as $i => $p) {
            if (!is_string($p) || @preg_match($p, '') === false) {
                throw Settings::wrong("ext.stats.skip[$i]", 'a path pattern (stats-skip **/osm-proxy/** in a rule file)');
            }
            $skip[] = $p;
        }
        return [
            'enabled' => Settings::bool($raw, 'enabled', 'ext.stats.enabled'),
            'parts' => $parts,
            'hours' => max(1, Settings::int($raw, 'hours', 'ext.stats.hours', 7)),
            'days' => max(1, Settings::int($raw, 'days', 'ext.stats.days', 400)),
            'months' => max(0, Settings::int($raw, 'months', 'ext.stats.months', 0)),
            'flush' => max(0, Settings::int($raw, 'flush', 'ext.stats.flush', 60)),
            'depth' => $depth,
            'hosts' => self::hosts($raw, $base),
            'skip' => $skip,
            'crawlerLog' => ['dir' => $dir, 'kinds' => $kinds, 'days' => max(1, Settings::int($log, 'days', 'ext.stats.crawlerLog.days', 30)),
                'query' => Settings::bool($log, 'query', 'ext.stats.crawlerLog.query', true)],
        ];
    }

    /**
     * The websites with statistics of their own: the names given, "host" for
     * the host rule's, "sites" for the site blocks' (not "default") -- and a
     * group's websites (stats-group), counted apart too without naming them twice.
     *
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    private static function hosts(array $raw, Settings $base): array
    {
        $out = [];
        $named = (array) ($raw['hosts'] ?? []);
        foreach ($base->statsGroups as $g) {
            foreach ($g['sites'] as $site) {
                $named[] = $site;
            }
        }
        foreach ($named as $name) {
            if (!is_string($name)) {
                throw Settings::wrong('ext.stats.hosts', 'website names');
            }
            $names = match ($name) {
                'host' => $base->hosts,
                'sites' => array_keys($base->sites),
                default => [$name],
            };
            foreach ($names as $n) {
                $n = rtrim(strtolower((string) $n), '.');
                if ($n !== '' && $n !== 'default' && preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $n) === 1) {
                    $out[$n] = true;
                }
            }
        }
        return array_map('strval', array_keys($out));
    }

    /** StatsPlugin runs when something is counted or logged. */
    public static function plugins(array $compiled): array
    {
        $log = $compiled['crawlerLog'] ?? null;
        return ($compiled['enabled'] ?? false) === true || (is_array($log) && ($log['dir'] ?? null) !== null) ? [StatsPlugin::class] : [];
    }

    public static function routes(): array
    {
        return [];
    }

    public static function commands(): array
    {
        return [];
    }

    public static function check(Settings $s): array
    {
        $o = self::of($s);
        if (($o['enabled'] || $o['crawlerLog']['dir'] !== null) && !class_exists(StatsPlugin::class)) {
            return ['set stats (or crawler-log) is on, but the statistics plugin is not installed (plugins/stats) -- nothing is counted'];
        }
        return [];
    }
}
