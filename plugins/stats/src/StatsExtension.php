<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Stats;

use CjwNetwork\RequestShield\ApiProvider;
use CjwNetwork\RequestShield\Endpoint;
use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

/**
 * The statistics' own settings, as an extension (0031 B.3, ADR 0008): the
 * `stats` words and `set` keys of the rule file, checked when the rules are
 * compiled into `ext.stats`, which StatsPlugin and the statistics' pages
 * read. The bootstrap names it (REQUEST_SHIELD_EXTENSIONS) and the registry
 * offers it on its first lookup; without plugins/stats (a smaller build)
 * `set stats on` is an unknown setting.
 *
 * Its pages (routes(), 0031 B.5) live below `set stats-path` (default
 * <dashboard-path>/stats): the registry in the compiled settings knows them,
 * the frame's tabs and the pace's exemption follow.
 */
final class StatsExtension implements Extension, ApiProvider
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
     * @return array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, path: string, hosts: list<string>, skip: list<string>, groups: array<string, array{name: string, sites: list<string>, rule: string}>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}}
     */
    public static function defaults(): array
    {
        return ['enabled' => false, 'parts' => self::PARTS, 'hours' => 7, 'days' => 400, 'months' => 0, 'flush' => 60, 'depth' => 2, 'path' => '/rs/stats', 'hosts' => [], 'skip' => [], 'groups' => [],
            'crawlerLog' => ['dir' => null, 'kinds' => [], 'days' => 30, 'query' => true]];
    }

    /**
     * The statistics' settings of $s: its compiled slot, or the defaults when
     * there is none -- a reader never sees a missing key.
     *
     * @return array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, path: string, hosts: list<string>, skip: list<string>, groups: array<string, array{name: string, sites: list<string>, rule: string}>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}}
     */
    public static function of(Settings $s): array
    {
        /** @var array{enabled: bool, parts: list<string>, hours: int, days: int, months: int, flush: int, depth: int, path: string, hosts: list<string>, skip: list<string>, groups: array<string, array{name: string, sites: list<string>, rule: string}>, crawlerLog: array{dir: ?string, kinds: list<string>, days: int, query: bool}} */
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
        $v->set('stats-path', 'string', 'where the statistics pages live (default <dashboard-path>/stats): /sites, /overview, /visitors, /protection below it', null, 'path');
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
        // stats-group "<name>" <websites>: a customer's websites, read together and each on its own;
        // the group's id (customer-a) is the principal dashboard-access names.
        $v->word('stats-group', static function (array $args, array $values, string $at, string $rid): array {
            $usage = 'stats-group "<name>" <websites> (stats-group "Customer A" a.de www.a.de)';
            $line = implode(' ', $args);
            if (preg_match('/^(?:"([^"]{1,60})"|([^"\s]{1,60}))\s+(\S.*)$/', trim($line), $m) !== 1) {
                throw new RuleFileException("$at: $usage");
            }
            $name = trim($m[1] !== '' ? $m[1] : $m[2]);
            $sites = [];
            foreach (preg_split('/\s+/', strtolower(trim($m[3]))) ?: [] as $site) {
                $site = rtrim($site, '.');
                if (preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $site) !== 1) {
                    throw new RuleFileException("$at: stats-group takes website names (www.example.org, *.example.org) after its name, not \"$site\"");
                }
                $sites[] = $site;
            }
            if ($name === '' || $sites === []) {
                throw new RuleFileException("$at: $usage");
            }
            $groups = is_array($values['groups'] ?? null) ? $values['groups'] : [];
            $groups[] = ['name' => $name, 'sites' => array_values(array_unique($sites)), 'rule' => $rid];
            $values['groups'] = $groups;
            return $values;
        }, 'stats-group "<name>" <websites>: a customer\'s websites, read together and each on its own', serverWide: true);
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
            'path' => self::path($raw, $base),
            'hosts' => self::hosts($raw, $base),
            'skip' => $skip,
            'groups' => self::groups($raw),
            'crawlerLog' => ['dir' => $dir, 'kinds' => $kinds, 'days' => max(1, Settings::int($log, 'days', 'ext.stats.crawlerLog.days', 30)),
                'query' => Settings::bool($log, 'query', 'ext.stats.crawlerLog.query', true)],
        ];
    }

    /**
     * Where the pages live: set stats-path, else <dashboard-path>/stats. The
     * plugin owns it; the core's pages stay at <dashboard-path>/waf/.
     *
     * @param array<string, mixed> $raw
     */
    private static function path(array $raw, Settings $base): string
    {
        $p = $raw['path'] ?? null;
        if ($p === null) {
            return $base->dashboardPath . '/stats';
        }
        // Names of letters, digits and . _ ~ -, but no "." or ".." of their own (as dashboard-path).
        if (!is_string($p) || !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $p) || preg_match('#/\.+(/|$)#', $p)) {
            throw Settings::wrong('ext.stats.path', 'a path such as /rs/stats or /admin/statistics');
        }
        return $p;
    }

    /**
     * The groups (stats-group): id (customer-a) => its name, its websites, the
     * rule -- the principal dashboard-access names is the id.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array{name: string, sites: list<string>, rule: string}>
     */
    private static function groups(array $raw): array
    {
        $out = [];
        foreach ((array) ($raw['groups'] ?? []) as $i => $g) {
            if (!is_array($g) || !is_string($g['name'] ?? null) || !is_array($g['sites'] ?? null) || $g['sites'] === []) {
                throw Settings::wrong("ext.stats.groups[$i]", "['name' => a name, 'sites' => [websites]]");
            }
            $id = Settings::principal($g['name']);
            if ($id === '' || isset($out[$id])) {
                throw Settings::wrong("ext.stats.groups[$i]", "a name of its own -- \"{$g['name']}\" is " . ($id === '' ? 'empty' : 'used twice (or names the same as another: ' . $id . ')'));
            }
            $sites = [];
            foreach ($g['sites'] as $site) {
                if (is_string($site) && $site !== '') {
                    $sites[] = rtrim(strtolower($site), '.');
                }
            }
            $out[$id] = ['name' => $g['name'], 'sites' => $sites, 'rule' => is_string($g['rule'] ?? null) ? $g['rule'] : "ext.stats.groups[$i]"];
        }
        return $out;
    }

    /**
     * What a reader may see: the website switch's choice for a principal (its
     * own group, or one of the group's websites; else the whole group), null
     * for the administrator ('*'). A principal without a group sees nothing.
     */
    public static function siteFor(Settings $s, string $who, ?string $asked): ?string
    {
        if ($who === '*') {
            return $asked;
        }
        $sites = self::of($s)['groups'][$who]['sites'] ?? [];
        return $asked !== null && ($asked === 'group:' . $who || in_array($asked, $sites, true)) ? $asked : 'group:' . $who;
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
        foreach (self::groups($raw) as $g) {
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

    /**
     * The statistics' pages below their path: the start (all websites with
     * stats-hosts, else the overview), all websites (with stats-hosts; a
     * reader's), the overview (the admin's), visitors and pages and the
     * protection (a reader's). The core's Rules & setup, Live and Lists come
     * after them in the tabs.
     */
    public static function routes(array $compiled): array
    {
        $path = is_string($compiled['path'] ?? null) ? $compiled['path'] : '/rs/stats';
        $sites = ($compiled['hosts'] ?? []) !== [];
        return [$path => ['key' => $sites ? 'sites' : 'all', 'tab' => null, 'role' => $sites ? 'reader' : 'admin', 'order' => 5, 'page' => Report\StatsPage::class]]
            + ($sites ? [$path . '/sites' => ['key' => 'sites', 'tab' => ['All websites', 'Alle Websites'], 'role' => 'reader', 'order' => 10, 'page' => Report\StatsPage::class]] : [])
            + [
                $path . '/overview' => ['key' => 'all', 'tab' => ['Dashboard', 'Dashboard'], 'role' => 'admin', 'order' => 20, 'page' => Report\StatsPage::class],
                $path . '/visitors' => ['key' => 'site', 'tab' => ['Visitors & pages', 'Besucher & Seiten'], 'role' => 'reader', 'order' => 30, 'page' => Report\StatsPage::class],
                $path . '/protection' => ['key' => 'shield', 'tab' => ['Protection', 'Schutz'], 'role' => 'reader', 'order' => 40, 'page' => Report\StatsPage::class],
            ];
    }

    /** The statistics in the API (RSF06-05): the report of a period, and all websites at a glance -- a customer gets its group. */
    public static function api(): array
    {
        return [
            new Endpoint('GET', '/stats/report', 'reader', false, Api\Report::class, Api\Report::SCHEMA, 'RSF06-03',
                'What the counters say about a period: requests, what the shield did, pages, not found, crawlers, bots, forms, the sentences.', Api\Report::PARAMS),
            new Endpoint('GET', '/stats/sites', 'reader', false, Api\Sites::class, Api\Sites::SCHEMA, 'RSF06-03',
                'All websites at a glance: page views, people, crawlers, bots, stopped, not found, and the period before.', Api\Sites::PARAMS),
        ];
    }

    /** `request-shield stats <main.rules>`: the counters in words, or as JSON (0031 D.1). */
    public static function commands(): array
    {
        return ['stats' => Cli\StatsCommand::class];
    }

    public static function check(Settings $s): array
    {
        $o = self::of($s);
        $warnings = [];
        // A principal the dashboard lets in, but no group of that id: it sees no statistics.
        foreach ($s->dashboardAccess as $a) {
            if ($a['who'] !== '*' && !isset($o['groups'][$a['who']])) {
                $warnings[] = "{$a['rule']}: dashboard-access \"{$a['who']}\" -- no stats-group has that id" . ($o['groups'] === [] ? '' : ' (' . implode(', ', array_keys($o['groups'])) . ')')
                    . '; the token opens the dashboard, but shows no statistics';
            }
        }
        return $warnings;
    }
}
