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
 * The configuration array, checked once and turned into typed values. A
 * value of the wrong type is an error at once -- with the key named -- not
 * a check that quietly does something else on a live site.
 */
final class Settings
{
    /**
     * @param list<string> $trustedProxies
     * @param list<string> $methods
     * @param list<string> $hosts
     * @param list<string> $blockedPaths
     * @param list<string>|null $cacheablePaths
     * @param list<string>|null $cacheableQuery
     * @param array<string, Budget> $budgets
     * @param list<string> $exemptIps
     * @param array<string, array<string, string>> $origins setting => pattern or budget => where it was set (rule files)
     * @param list<array{paths: list<string>, ips: list<string>}> $restricted
     * @param array<string, list<string>> $methodPaths
     * @param list<array{paths: list<string>, patterns: list<string>|null, ips: list<string>}> $blockExceptions
     * @param list<array{target: string, patterns: list<string>}> $contentRules
     * @param array<string, string> $contentIndex
     * @param array<string, list<string>> $contentHints
     */
    private function __construct(
        /** @readonly */
        public array $trustedProxies,
        /** @readonly */
        public bool $stripUntrustedForwarded,
        /** @readonly */
        public array $methods,
        /** @readonly */
        public array $hosts,
        /** @readonly */
        public int $maxUri,
        /** @readonly */
        public int $maxQueryParameters,
        /** @readonly */
        public int $maxHeaderBytes,
        /** @readonly */
        public array $blockedPaths,
        /** @readonly */
        public ?array $cacheablePaths,
        /** @readonly */
        public ?array $cacheableQuery,
        /** @readonly */
        public array $budgets,
        /** @readonly */
        public array $exemptIps,
        /** @readonly */
        public int $ipv6Prefix,
        /** @readonly */
        public string $store,
        /** @readonly */
        public string $storeDir,
        /** @readonly */
        public bool $debugHeader,
        /** @readonly */
        public ChallengeSettings $challenge,
        /** @readonly */
        public array $origins = [],
        /** @readonly */
        public array $restricted = [],
        /** @readonly */
        public array $methodPaths = [],
        /** @readonly */
        public ?string $logFile = null,
        /** @readonly */
        public string $logLevel = 'stop',
        /** @readonly */
        public string $logIp = 'masked',
        /** @readonly */
        public int $logMaxSize = 10485760,
        /** @readonly */
        public array $blockExceptions = [],
        /** @readonly */
        public bool $appChallenge = false,
        /** @readonly */
        public array $contentRules = [],
        /** @readonly target => all of its patterns in one expression */
        public array $contentIndex = [],
        /** @readonly all blocked paths in one expression; '' when they cannot be combined */
        public string $blockedIndex = '',
        /**
         * @readonly target => texts one of which every pattern needs; a target without is always matched
         * @var array<string, list<string>>
         */
        public array $contentHints = [],
        /**
         * @readonly the known query parameters (QueryRule)
         * @var list<array{paths: list<string>|null, exact: array<string, string>, globs: array<string, string>}>
         */
        public array $queryParams = [],
        /** @readonly anything but a known parameter of its type: 404 */
        public bool $queryStrict = false,
        /**
         * @readonly queryParams as one lookup (QueryRule::index()), built once
         * @var array{exact: array<string, string>, globs: array<string, string>, local: list<array{paths: list<string>, exact: array<string, string>, globs: array<string, string>}>}
         */
        public array $queryIndex = ['exact' => [], 'globs' => [], 'local' => []],
        /** @readonly off, monitor, enforce or strict */
        public string $mode = 'enforce',
        /** @readonly how often a request a cache must not keep counts against the budgets (strict: 2) */
        public int $uncachedWeight = 1,
        /** @readonly the rules with those marked "monitor": decided alongside, only logged; null: none */
        public ?Settings $monitor = null,
        /**
         * @readonly the known crawlers, each with what the site does with it (policy)
         * @var array<string, array{kind: string, ua: string, dns: list<string>, ranges: list<string>, lists: array<string, array<string, ?string>>, policy: string, nets: array<string, list<array{0: string, 1: int}>>}>
         */
        public array $crawlers = [],
        /** @readonly every crawler's User-Agent pattern in one expression, (*MARK:<n>) naming it; '' for none */
        public string $crawlerIndex = '',
        /** @var list<string> @readonly crawler IDs in the order of the MARKs */
        public array $crawlerIds = [],
        /** @readonly both, ranges or dns */
        public string $crawlerVerify = 'both',
        /** @var array<string, string> @readonly the policies as written: kind or crawler ID => allow, check, block */
        public array $crawlerPolicy = [],
        /** @readonly counters for the dashboard (Stats) */
        public bool $statsEnabled = false,
        /** @readonly days the hourly counters are kept */
        public int $statsHours = 7,
        /** @readonly days the daily counters are kept */
        public int $statsDays = 400,
        /** @readonly one log per known crawler and day in this directory; null: none */
        public ?string $crawlerLogDir = null,
        /** @var list<string> @readonly the kinds whose crawlers are logged ([]: all) */
        public array $crawlerLogKinds = [],
        /** @readonly days a crawler's log is kept */
        public int $crawlerLogDays = 30,
        /** @readonly whether the crawler logs keep the query string */
        public bool $crawlerLogQuery = true,
        /** @var list<string> @readonly what is counted: requests, crawlers, not-found, bots, pages */
        public array $statsParts = ['requests', 'crawlers', 'not-found', 'bots', 'pages'],
        /** @readonly with APCu, seconds between writes of the counts to disk (0: only hourly) */
        public int $statsFlush = 60,
        /** @readonly months the month totals are kept (0: for good) */
        public int $statsMonths = 0,
        /** @readonly folder levels a section's views are counted for exactly: 1 to 4 (2: /news/, /news/2026/) */
        public int $statsDepth = 2,
        /** @readonly where the statistics pages live: <path>/dashboard, /stats, /shield */
        public string $dashboardPath = '/rs',
        /** @var list<class-string> @readonly the plugins the rules name (the statistics come with "set stats on" on their own) */
        public array $plugins = [],
        /** @var array<string, string> @readonly website name (a.de, *.b.de, default) => its site block (the block's first name); [] without site blocks */
        public array $sites = [],
        /** @readonly the site block these settings are (null: the base, for every website) */
        public ?string $site = null,
        /** @readonly which name picks the site: server-name (the web server's, the default) or host (the Host header) */
        public string $siteFrom = 'server-name',
        /** @var list<array{ips: list<string>, until: ?int, rule: string}> @readonly addresses kept out (deny), the ones in force -- the first DENY_SHOWN, for the pages */
        public array $deny = [],
        /** @var array{4: string, 6: string, ids: string, dir?: string}|array{} @readonly all of them, as a sorted table (IpTable); [] none */
        public array $denyTable = [],
        /** @readonly how many entries are in force */
        public int $denyCount = 0,
        /** @readonly when the next list entry ends (a Unix time; 0: none) -- the settings are built again then */
        public int $listsUntil = 0,
        /** @readonly where the list files are (allow.rules, deny.rules); null: none */
        public ?string $listsDir = null,
        /** @var list<array{after: int, signal: string, in: int, for: int, rule: string}> @readonly automatic, temporary bans */
        public array $bans = [],
        /** @readonly a ban within a day of the last lasts this many times longer */
        public int $banGrowth = 2,
        /** @readonly the longest ban, in seconds */
        public int $banMax = 86400,
    ) {
    }

    /**
     * @param array<mixed> $config see Config::defaults()
     * @throws \InvalidArgumentException naming the key that is wrong
     */
    public static function from(array $config): self
    {
        $c = Config::merge($config);
        $verify = self::string($c, 'crawlerVerify', 'crawlerVerify', 'both');
        if (!in_array($verify, ['both', 'ranges', 'dns'], true)) {
            throw self::wrong('crawlerVerify', 'both, ranges or dns');
        }
        $mode = self::string($c, 'mode', 'mode', 'enforce');
        if (!in_array($mode, self::MODES, true)) {
            throw self::wrong('mode', implode(', ', self::MODES));
        }
        $monitorRules = $c['monitorRules'] ?? null;
        if ($monitorRules !== null && !is_array($monitorRules)) {
            throw self::wrong('monitorRules', 'null or the settings with the monitored rules');
        }
        if ($mode === 'monitor' && $monitorRules !== null) {
            // Everything is only logged anyway: the monitored rules count like the others.
            return self::from(['mode' => 'monitor', 'monitorRules' => null] + $monitorRules);
        }
        $strict = $mode === 'strict';
        $limits = self::map($c, 'limits');
        $cacheable = self::map($c, 'cacheable');
        $exempt = self::map($c, 'exempt');
        $log = self::map($c, 'log');

        $restricted = [];
        foreach (self::map($c, 'restricted') as $i => $r) {
            if (!is_array($r)) {
                throw self::wrong("restricted.$i", "an array of 'paths' and 'ips'");
            }
            $restricted[] = ['paths' => self::strings($r, 'paths', "restricted.$i.paths"), 'ips' => self::strings($r, 'ips', "restricted.$i.ips")];
        }
        $exceptions = [];
        foreach (self::map($c, 'blockExceptions') as $i => $x) {
            if (!is_array($x)) {
                throw self::wrong("blockExceptions.$i", "an array of 'paths', 'patterns' and 'ips'");
            }
            $exceptions[] = ['paths' => self::strings($x, 'paths', "blockExceptions.$i.paths"),
                'patterns' => self::stringsOrNull($x, 'patterns', "blockExceptions.$i.patterns"), 'ips' => self::strings($x, 'ips', "blockExceptions.$i.ips")];
        }
        [$contentRules, $contentIndex] = self::contentRules(self::map($c, 'contentRules'));
        $blocked = self::strings($c, 'blockedPaths');
        $methodPaths = [];
        foreach (self::map($c, 'methodPaths') as $method => $paths) {
            $methodPaths[strtoupper((string) $method)] = self::strings(['p' => $paths], 'p', "methodPaths.$method");
        }
        $level = self::string($log, 'level', 'log.level', 'stop');
        if (!in_array($level, Log::LEVELS, true)) {
            throw self::wrong('log.level', implode(', ', Log::LEVELS));
        }
        $ip = self::string($log, 'ip', 'log.ip', 'masked');
        if ($ip !== 'masked' && $ip !== 'full') {
            throw self::wrong('log.ip', 'masked or full');
        }
        $logFile = $log['file'] ?? null;
        if ($logFile !== null && (!is_string($logFile) || $logFile === '')) {
            throw self::wrong('log.file', 'null or a path');
        }

        $budgets = [];
        foreach (self::map($c, 'budgets') as $name => $budget) {
            if (!is_array($budget)) {
                throw self::wrong("budgets.$name", 'an array');
            }
            $b = Budget::from((string) $name, $budget, $strict);
            if ($b !== null) {
                $budgets[(string) $name] = $b;
            }
        }

        return new self(
            self::strings($c, 'trustedProxies'),
            self::bool($c, 'stripUntrustedForwarded'),
            array_map(static fn (string $m): string => strtoupper($m), self::strings($c, 'methods')),
            array_map(static fn (string $h): string => strtolower($h), self::strings($c, 'hosts')),
            self::int($limits, 'uri', 'limits.uri'),
            self::int($limits, 'queryParameters', 'limits.queryParameters'),
            self::int($limits, 'headerBytes', 'limits.headerBytes'),
            $blocked,
            self::stringsOrNull($cacheable, 'paths', 'cacheable.paths'),
            self::stringsOrNull($cacheable, 'query', 'cacheable.query'),
            $budgets,
            array_values(array_unique(array_merge(self::strings($exempt, 'ips', 'exempt.ips'), self::exemptForNow($c)))),
            max(0, min(128, self::int($c, 'ipv6Prefix'))),
            self::string($c, 'store'),
            self::string($c, 'storeDir'),
            self::bool($c, 'debugHeader'),
            ChallengeSettings::from(self::map($c, 'challenge'), $strict),
            self::origins(self::map($c, 'origins')),
            $restricted,
            $methodPaths,
            $level === 'off' ? null : $logFile,
            $level,
            $ip,
            max(4096, self::int($log, 'maxSize', 'log.maxSize', 10485760)),
            $exceptions,
            self::bool($c, 'appChallenge'),
            $contentRules,
            $contentIndex,
            self::combine($blocked),
            self::hints($contentRules),
            $query = self::queryParams(self::map($c, 'queryParams')),
            self::bool($c, 'queryStrict'),
            \CjwNetwork\RequestShield\Rule\QueryRule::index($query),
            $mode,
            $strict ? 2 : 1,
            $monitorRules === null ? null : self::from(['mode' => $mode, 'monitorRules' => null] + $monitorRules),
            ...self::knownCrawlers($c, $verify),
            ...self::stats($c),
            ...[self::dashboardPath($c), self::plugins($c)],
            ...self::sites($c),
            ...self::lists($c, $budgets),
        );
    }

    /**
     * @param array<mixed> $c
     * @return array{0: bool, 1: int, 2: int, 3: ?string, 4: list<string>, 5: int, 6: bool, 7: list<string>, 8: int, 9: int, 10: int}
     */
    private static function stats(array $c): array
    {
        $stats = self::map($c, 'stats');
        $log = self::map($c, 'crawlerLog');
        $dir = $log['dir'] ?? null;
        if ($dir !== null && (!is_string($dir) || $dir === '')) {
            throw self::wrong('crawlerLog.dir', 'null or a directory');
        }
        $kinds = self::strings($log, 'kinds', 'crawlerLog.kinds');
        foreach ($kinds as $k) {
            if (!in_array($k, Rules\RuleFile::KINDS, true)) {
                throw self::wrong('crawlerLog.kinds', 'kinds of crawler: ' . implode(', ', Rules\RuleFile::KINDS));
            }
        }
        $depth = self::int($stats, 'depth', 'stats.depth', 2);
        if ($depth < 1 || $depth > 4) {
            throw self::wrong('stats.depth', '1 to 4 folder levels');
        }
        $parts = array_key_exists('parts', $stats) ? self::strings($stats, 'parts', 'stats.parts') : self::STATS_PARTS;
        foreach ($parts as $p) {
            if (!in_array($p, self::STATS_PARTS, true)) {
                throw self::wrong('stats.parts', implode(', ', self::STATS_PARTS));
            }
        }
        return [self::bool($stats, 'enabled', 'stats.enabled'), max(1, self::int($stats, 'hours', 'stats.hours', 7)), max(1, self::int($stats, 'days', 'stats.days', 400)),
            $dir, $kinds, max(1, self::int($log, 'days', 'crawlerLog.days', 30)), self::bool($log, 'query', 'crawlerLog.query', true),
            $parts, max(0, self::int($stats, 'flush', 'stats.flush', 60)), max(0, self::int($stats, 'months', 'stats.months', 0)), $depth];
    }

    /**
     * The known crawlers with the site's policies, their expression and IDs,
     * crawler-verify and the policies as written. The shipped list comes
     * ready (rules/crawlers.php): settings from a PHP array only apply the
     * site's policies to it.
     *
     * @param array<mixed> $c
     * @return array{0: array<string, array{kind: string, ua: string, dns: list<string>, ranges: list<string>, lists: array<string, array<string, ?string>>, policy: string, nets: array<string, list<array{0: string, 1: int}>>}>, 1: string, 2: list<string>, 3: string, 4: array<string, string>}
     */
    private static function knownCrawlers(array $c, string $verify): array
    {
        $policies = self::crawlerPolicy($c);
        $engines = is_array($c['challenge'] ?? null) ? ($c['challenge']['searchEngines'] ?? true) : true;
        if ($engines === true && ($c['crawlers'] ?? null) === null) {
            /** @var array{crawlers: array<string, array{kind: string, ua: string, dns: list<string>, ranges: list<string>, lists: array<string, array<string, ?string>>, policy: string, nets: array<string, list<array{0: string, 1: int}>>}>, index: string, ids: list<string>} $ready */
            $ready = require dirname(__DIR__) . '/rules/crawlers.php';
            $crawlers = $ready['crawlers'];
            foreach ($policies as $key => $policy) {
                if (!in_array($policy, Rules\RuleFile::POLICIES, true) || (!isset($crawlers[$key]) && !in_array($key, Rules\RuleFile::KINDS, true))) {
                    throw self::wrong("crawlerPolicy.$key", 'allow, check or block, for a kind (' . implode(', ', Rules\RuleFile::KINDS) . ') or a crawler\'s ID');
                }
            }
            if ($policies !== []) {
                foreach ($crawlers as $id => $x) {
                    $crawlers[$id]['policy'] = $policies[$id] ?? $policies[$x['kind']] ?? 'allow';
                }
            }
            return [$crawlers, $ready['index'], $ready['ids'], $verify, $policies];
        }
        $crawlers = self::crawlers($c);
        return [$crawlers, ...self::crawlerIndex($crawlers), ...[$verify, $policies]];
    }

    /**
     * The known crawlers: the shipped list, a site's own, the old
     * challenge.searchEngines map (pattern => DNS suffixes), or none.
     *
     * @param array<mixed> $c
     * @return array<string, array{kind: string, ua: string, dns: list<string>, ranges: list<string>, lists: array<string, array<string, ?string>>, policy: string, nets: array<string, list<array{0: string, 1: int}>>}>
     */
    private static function crawlers(array $c): array
    {
        $engines = is_array($c['challenge'] ?? null) ? ($c['challenge']['searchEngines'] ?? true) : true;
        if ($engines === false) {
            return [];
        }
        if (is_array($engines)) {
            $list = [];
            $i = 0;
            foreach ($engines as $pattern => $suffixes) {
                $list['searchEngines[' . $i++ . ']'] = ['kind' => 'search', 'ua' => (string) $pattern, 'dns' => $suffixes, 'ranges' => []];
            }
        } else {
            $list = $c['crawlers'] ?? [];
        }
        if (!is_array($list)) {
            throw self::wrong('crawlers', 'null or an array of ID => crawler');
        }
        $policies = self::map($c, 'crawlerPolicy');
        $out = [];
        foreach ($list as $id => $x) {
            $id = (string) $id;
            if (!is_array($x) || !in_array($x['kind'] ?? null, Rules\RuleFile::KINDS, true)
                || !is_string($x['ua'] ?? null) || @preg_match($x['ua'], '') === false) {
                throw self::wrong("crawlers.$id", "an array of 'kind' (" . implode(', ', Rules\RuleFile::KINDS) . "), 'ua' (a regex), 'dns' and 'ranges'");
            }
            $ranges = self::strings($x, 'ranges', "crawlers.$id.ranges");
            foreach ($ranges as $r) {
                if (!Rules\CrawlerLists::isRange($r)) {
                    throw self::wrong("crawlers.$id.ranges", 'addresses or ranges (192.0.2.0/24)');
                }
            }
            $policy = $policies[$id] ?? $policies[$x['kind']] ?? 'allow';
            if (!in_array($policy, Rules\RuleFile::POLICIES, true)) {
                throw self::wrong("crawlerPolicy.$id", implode(', ', Rules\RuleFile::POLICIES));
            }
            /** @var array<string, array<string, ?string>> $lists */
            $lists = is_array($x['lists'] ?? null) ? $x['lists'] : [];
            // The ranges as a lookup, built once (compiled with the settings).
            $nets = IpAddress::index($ranges);
            $out[$id] = ['kind' => $x['kind'], 'ua' => $x['ua'], 'dns' => self::strings($x, 'dns', "crawlers.$id.dns"), 'ranges' => $ranges, 'lists' => $lists, 'policy' => (string) $policy, 'nets' => $nets];
        }
        foreach ($policies as $key => $policy) {
            if (!isset($out[$key]) && !in_array($key, Rules\RuleFile::KINDS, true)) {
                throw self::wrong("crawlerPolicy.$key", 'a kind (' . implode(', ', Rules\RuleFile::KINDS) . ') or a crawler\'s ID');
            }
        }
        return $out;
    }

    /**
     * Plugins by their class names (Vendor\Package\MyPlugin): checked for their
     * form here; whether the class is there and a Plugin, when the shield makes
     * them (and by "check").
     *
     * @param array<mixed> $c
     * @return list<class-string>
     */
    private static function plugins(array $c): array
    {
        $out = [];
        foreach ((array) ($c['plugins'] ?? []) as $class) {
            if (!is_string($class) || !preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class)) {
                throw self::wrong('plugins', 'class names such as Vendor\\Package\\MyPlugin');
            }
            /** @var class-string $name */
            $name = ltrim($class, '\\');
            $out[] = $name;
        }
        return array_values(array_unique($out));
    }

    /**
     * The addresses let in for a while (exempt … until), those still in force.
     *
     * @param array<mixed> $c
     * @return list<string>
     */
    private static function exemptForNow(array $c): array
    {
        $out = [];
        $now = time();
        foreach ((array) ($c['exemptUntil'] ?? []) as $e) {
            if (is_array($e) && (!is_int($e['until'] ?? null) || $e['until'] > $now)) {
                foreach ((array) ($e['ips'] ?? []) as $ip) {
                    if (is_string($ip)) {
                        $out[] = $ip;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The deny list (the entries in force, and as a lookup), when the next
     * entry ends (exempt … until included), the list files' directory, and
     * the automatic bans.
     *
     * @param array<mixed> $c
     * @param array<string, Budget> $budgets
     * @return array{0: list<array{ips: list<string>, until: ?int, rule: string}>, 1: array{4: string, 6: string, ids: string, dir?: string}|array{}, 2: int, 3: int, 4: ?string, 5: list<array{after: int, signal: string, in: int, for: int, rule: string}>, 6: int, 7: int}
     */
    private static function lists(array $c, array $budgets): array
    {
        $now = time();
        $next = 0;
        $deny = [];
        $entries = [];
        foreach ((array) ($c['deny'] ?? []) as $i => $e) {
            if (!is_array($e) || !is_array($e['ips'] ?? null)) {
                throw self::wrong("deny[$i]", "['ips' => [addresses], 'until' => a Unix time or null]");
            }
            $until = is_int($e['until'] ?? null) ? $e['until'] : null;
            if ($until !== null && $until <= $now) {
                continue;                       // over: left out
            }
            $list = [];
            foreach ($e['ips'] as $ip) {
                $slash = is_string($ip) ? strpos($ip, '/') : false;
                if (!is_string($ip) || @inet_pton($slash === false ? $ip : substr($ip, 0, $slash)) === false) {
                    throw self::wrong("deny[$i].ips", 'addresses or ranges (203.0.113.7, 198.51.100.0/24, 2001:db8::/32)');
                }
                $list[] = $ip;
            }
            $rule = is_string($e['rule'] ?? null) ? $e['rule'] : "deny[$i]";
            $entries[] = [$list, $rule];
            if (count($deny) < self::DENY_SHOWN) {
                $deny[] = ['ips' => $list, 'until' => $until, 'rule' => $rule];
            }
            if ($until !== null && ($next === 0 || $until < $next)) {
                $next = $until;
            }
        }
        foreach ((array) ($c['exemptUntil'] ?? []) as $e) {
            $until = is_array($e) && is_int($e['until'] ?? null) ? $e['until'] : 0;
            if ($until > $now && ($next === 0 || $until < $next)) {
                $next = $until;
            }
        }
        $bans = [];
        foreach ((array) ($c['bans'] ?? []) as $i => $b) {
            if (!is_array($b) || !is_int($b['after'] ?? null) || !is_string($b['signal'] ?? null) || !is_int($b['in'] ?? null) || !is_int($b['for'] ?? null)
                || $b['after'] < 1 || $b['in'] < 1 || $b['for'] < 1) {
                throw self::wrong("bans[$i]", "['after' => n, 'signal' => limits|refusals|checks|<budget>, 'in' => seconds, 'for' => seconds]");
            }
            if (!in_array($b['signal'], ['limits', 'refusals', 'checks'], true) && !isset($budgets[$b['signal']])) {
                throw self::wrong("bans[$i].signal", "limits, refusals, checks or a budget's name -- there is no budget \"{$b['signal']}\"");
            }
            $bans[] = ['after' => $b['after'], 'signal' => $b['signal'], 'in' => $b['in'], 'for' => $b['for'], 'rule' => is_string($b['rule'] ?? null) ? $b['rule'] : "bans[$i]"];
        }
        $growth = $c['banGrowth'] ?? 2;
        $max = $c['banMax'] ?? 86400;
        if (!is_int($growth) || $growth < 1 || !is_int($max) || $max < 1) {
            throw self::wrong('banGrowth/banMax', 'a whole number of at least 1');
        }
        $dir = $c['listsDir'] ?? null;
        return [$deny, $entries === [] ? [] : IpTable::build($entries), count($entries), $next, is_string($dir) && $dir !== '' ? $dir : null, $bans, $growth, $max];
    }

    /**
     * The site blocks (from a rule file): website name => block, this block,
     * and which name picks one.
     *
     * @param array<mixed> $c
     * @return array{0: array<string, string>, 1: ?string, 2: string}
     */
    private static function sites(array $c): array
    {
        $map = [];
        foreach ((array) ($c['sites'] ?? []) as $name => $id) {
            if (!is_string($id)) {
                throw self::wrong('sites', 'website name => site block');
            }
            $map[(string) $name] = $id;
        }
        $site = $c['site'] ?? null;
        $from = $c['siteFrom'] ?? 'server-name';
        if (!in_array($from, ['server-name', 'host'], true)) {
            throw self::wrong('siteFrom', 'server-name or host');
        }
        return [$map, is_string($site) ? $site : null, $from];
    }

    /**
     * The site block a request belongs to, or null (the base): its name --
     * the web server's (SERVER_NAME) or, with site-from host, the Host header
     * (from a trusted proxy: X-Forwarded-Host) -- in lower case, without its
     * port and a trailing dot; looked up as itself, as *.<the rest>, then
     * "default". Two isset(), no expression.
     *
     * @param array<mixed> $server $_SERVER
     */
    public function siteFor(array $server): ?string
    {
        return $this->sites === [] ? null : self::pick($this->sites, $this->siteFrom, $this->trustedProxies, $server);
    }

    /**
     * siteFor() on the plain values -- for loadFor(), which picks the website
     * before it makes any settings.
     *
     * @param array<string, string> $sites
     * @param list<string> $trusted
     * @param array<mixed> $server
     */
    private static function pick(array $sites, string $from, array $trusted, array $server): ?string
    {
        $name = $server['SERVER_NAME'] ?? '';
        if ($from === 'host') {
            $name = $server['HTTP_HOST'] ?? $name;
            $forwarded = $server['HTTP_X_FORWARDED_HOST'] ?? null;
            $peer = $server['REMOTE_ADDR'] ?? '';
            if (is_string($forwarded) && is_string($peer) && $peer !== '' && $trusted !== [] && IpAddress::inRanges($peer, $trusted)) {
                $name = trim(explode(',', $forwarded)[0]);
            }
        }
        $name = is_string($name) ? strtolower($name) : '';
        // Without its port and a trailing dot -- string functions, no expression.
        $colon = strrpos($name, ':');
        if ($colon !== false && ctype_digit(substr($name, $colon + 1))) {
            $name = substr($name, 0, $colon);
        }
        $name = rtrim($name, '.');
        if ($name !== '' && isset($sites[$name])) {
            return $sites[$name];
        }
        $dot = strpos($name, '.');
        if ($dot !== false && isset($sites['*' . substr($name, $dot)])) {
            return $sites['*' . substr($name, $dot)];
        }
        return $sites['default'] ?? null;
    }

    /** @param array<mixed> $c */
    private static function dashboardPath(array $c): string
    {
        $p = $c['dashboardPath'] ?? '/rs';
        // Names of letters, digits and . _ ~ -, but no "." or ".." of their own.
        if (!is_string($p) || !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $p) || preg_match('#/\.+(/|$)#', $p)) {
            throw self::wrong('dashboardPath', 'a path such as /rs or /admin/rs');
        }
        return $p;
    }

    /**
     * @param array<mixed> $c
     * @return array<string, string>
     */
    private static function crawlerPolicy(array $c): array
    {
        $out = [];
        foreach (self::map($c, 'crawlerPolicy') as $key => $policy) {
            if (!is_string($policy)) {
                throw self::wrong("crawlerPolicy.$key", implode(', ', Rules\RuleFile::POLICIES));
            }
            $out[(string) $key] = $policy;
        }
        return $out;
    }

    /**
     * Every crawler's User-Agent pattern in one expression: a request that
     * names none costs one match. (*MARK:n) says which one matched.
     *
     * @param array<string, array{ua: string}> $crawlers
     * @return array{0: string, 1: list<string>}
     */
    public static function crawlerIndex(array $crawlers): array
    {
        $parts = [];
        $ids = [];
        foreach ($crawlers as $id => $x) {
            // '#…#i' or '/…/i': the expression between the delimiters.
            $d = $x['ua'][0];
            $end = strrpos($x['ua'], $d);
            $inner = substr($x['ua'], 1, (int) $end - 1);
            // Into '#…#': a "#" of another delimiter's expression needs its backslash.
            $inner = $d === '#' ? $inner : str_replace('#', '\#', str_replace('\\' . $d, $d, $inner));
            $parts[] = '(?:' . $inner . ')(*MARK:' . count($ids) . ')';
            $ids[] = $id;
        }
        $index = $parts === [] ? '' : '#' . implode('|', $parts) . '#i';
        if ($index !== '' && @preg_match($index, '') === false) {
            throw self::wrong('crawlers', 'User-Agent patterns that go into one expression (no back references)');
        }
        return [$index, $ids];
    }

    /**
     * @param array<mixed> $list
     * @return list<array{paths: list<string>|null, exact: array<string, string>, globs: array<string, string>}>
     */
    private static function queryParams(array $list): array
    {
        $out = [];
        $type = static function ($t, string $where): string {
            if (!is_string($t) || !(in_array($t, ['int', 'number', 'word', 'id', 'list', 'text', 'any'], true)
                || (strncmp($t, '#', 1) === 0 && @preg_match($t, '') !== false))) {
                throw self::wrong($where, 'int, number, word, id, list, text, any or a regex');
            }
            return $t;
        };
        foreach ($list as $i => $r) {
            if (!is_array($r)) {
                throw self::wrong("queryParams.$i", "an array of 'paths', 'exact' and 'globs'");
            }
            $exact = [];
            foreach (self::map($r, 'exact', "queryParams.$i.exact") as $name => $t) {
                $exact[(string) $name] = $type($t, "queryParams.$i.exact.$name");
            }
            $globs = [];
            foreach (self::map($r, 'globs', "queryParams.$i.globs") as $glob => $t) {
                if (@preg_match((string) $glob, '') === false) {
                    throw self::wrong("queryParams.$i.globs", 'regular expressions as keys');
                }
                $globs[(string) $glob] = $type($t, "queryParams.$i.globs");
            }
            $out[] = ['paths' => self::stringsOrNull($r, 'paths', "queryParams.$i.paths"), 'exact' => $exact, 'globs' => $globs];
        }
        return $out;
    }

    /**
     * For each target, the texts every one of its patterns starts with --
     * "${" for "\$\{(jndi|...)" -- when there are such: the request is then
     * normalised and matched only if its raw value holds one of them, or a
     * "%" (an encoding). Normalising can decode, lower-case and merge white
     * space, none of which makes such a text appear. A target where any
     * pattern can start otherwise gets none, and is always matched.
     *
     * @param list<array{target: string, patterns: list<string>}> $rules
     * @return array<string, list<string>>
     */
    public static function hints(array $rules): array
    {
        $by = [];
        $none = [];
        foreach ($rules as $r) {
            foreach ($r['patterns'] as $p) {
                if (!preg_match('/^#(.*)#i?$/s', $p, $m)) {
                    $none[$r['target']] = true;
                    continue;
                }
                foreach (self::alternatives($m[1]) as $alt) {
                    $lit = self::leadingLiteral($alt);
                    if (strlen($lit) < 2) {
                        $none[$r['target']] = true;
                        continue 2;
                    }
                    $by[$r['target']][strtolower($lit)] = true;
                }
            }
        }
        $out = [];
        foreach ($by as $target => $lits) {
            if (isset($none[$target])) {
                continue;
            }
            // A text that holds a shorter one is found with it: only the shorter.
            $keep = [];
            foreach (array_keys($lits) as $l) {
                foreach (array_keys($lits) as $other) {
                    if ($other !== $l && strpos((string) $l, (string) $other) !== false) {
                        continue 2;
                    }
                }
                $keep[] = (string) $l;
            }
            $out[$target] = $keep;
        }
        return $out;
    }

    /** @return list<string> an expression's top-level alternatives */
    private static function alternatives(string $re): array
    {
        $out = [];
        $depth = 0;
        $class = false;
        $start = 0;
        for ($i = 0, $n = strlen($re); $i < $n; $i++) {
            $c = $re[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($class) {
                $class = $c !== ']';
            } elseif ($c === '[') {
                $class = true;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === '|' && $depth === 0) {
                $out[] = substr($re, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $out[] = substr($re, $start);
        return $out;
    }

    /** The plain text an alternative has to start with ("\$\{" -> "${"); '' if none. */
    private static function leadingLiteral(string $alt): string
    {
        $lit = '';
        for ($i = 0, $n = strlen($alt); $i < $n; $i++) {
            $c = $alt[$i];
            if ($c === '\\') {
                $next = $alt[$i + 1] ?? '';
                if ($next === '' || ctype_alnum($next)) {
                    break;                  // \s, \d, \b, \1 ...: not a plain character
                }
                $lit .= $next;
                $i++;
                $last = $i;
                continue;
            }
            if (strpos('.[](){}?*+|^$', $c) !== false) {
                break;
            }
            $lit .= $c;
        }
        // A quantifier that allows none takes the last character back.
        $after = $alt[$i] ?? '';
        if ($lit !== '' && ($after === '?' || $after === '*' || $after === '{')) {
            $lit = substr($lit, 0, -1);
        }
        return $lit;
    }

    /**
     * Several expressions as one: "#a#", "#b#i" -> "#(?:a)|(?i:b)#". Only
     * where that means the same -- delimiters "#" or "/", no flags but "i",
     * no back references or named groups (they would point elsewhere);
     * otherwise '' and the patterns are matched one by one, as before.
     *
     * @param list<string> $patterns
     */
    public static function combine(array $patterns): string
    {
        if (count($patterns) < 2) {
            return '';
        }
        $parts = [];
        foreach ($patterns as $p) {
            if (!preg_match('~^([#/])(.*)\1(i?)$~s', $p, $m) || preg_match('/\\\\[1-9]|\\\\g\{?-?\d|\(\?P?[<\'=]|\(\?\|/', $m[2])) {
                return '';
            }
            $body = $m[1] === '/' ? str_replace(['\\/', '#'], ['/', '\\#'], $m[2]) : $m[2];
            $parts[] = ($m[3] === 'i' ? '(?i:' : '(?:') . $body . ')';
        }
        $all = '#' . implode('|', $parts) . '#';
        return @preg_match($all, '') === false ? '' : $all;
    }

    /**
     * The attack rules, and per target one expression of all its patterns --
     * a clean request then costs one match per target.
     *
     * @param array<mixed> $list
     * @return array{0: list<array{target: string, patterns: list<string>}>, 1: array<string, string>}
     */
    private static function contentRules(array $list): array
    {
        $rules = [];
        $bodies = [];
        foreach ($list as $i => $r) {
            if (!is_array($r)) {
                throw self::wrong("contentRules.$i", "an array of 'target' and 'patterns'");
            }
            $target = strtolower(self::string($r, 'target', "contentRules.$i.target"));
            if (!in_array($target, ['query', 'headers', 'anywhere'], true) && !preg_match('/^header:[a-z0-9-]+$/', $target)) {
                throw self::wrong("contentRules.$i.target", 'query, headers, anywhere or header:<name>');
            }
            $patterns = self::strings($r, 'patterns', "contentRules.$i.patterns");
            foreach ($patterns as $p) {
                if (!preg_match('/^#(.*)#i?$/s', $p, $m) || @preg_match($p, '') === false) {
                    throw self::wrong("contentRules.$i.patterns", 'regular expressions in #...# (flag i at most)');
                }
                // All of a target's patterns become one expression: a back
                // reference would point at another pattern's group.
                if (preg_match('/\\\\[1-9]|\\\\g\{?-?\d|\(\?P?[<\'=]/', $m[1])) {
                    throw self::wrong("contentRules.$i.patterns", 'expressions without back references or named groups');
                }
                $bodies[$target][] = '(?:' . $m[1] . ')';
            }
            $rules[] = ['target' => $target, 'patterns' => $patterns];
        }
        $index = [];
        foreach ($bodies as $target => $b) {
            $index[$target] = '#' . implode('|', $b) . '#i';
            if (@preg_match($index[$target], '') === false) {
                throw self::wrong('contentRules', 'patterns that also work together (' . $target . ')');
            }
        }
        return [$rules, $index];
    }

    /**
     * @param array<mixed> $o
     * @return array<string, array<string, string>>
     */
    private static function origins(array $o): array
    {
        $out = [];
        foreach ($o as $setting => $list) {
            if (!is_array($list)) {
                throw self::wrong("origins.$setting", 'an array');
            }
            foreach ($list as $what => $where) {
                if (!is_string($where)) {
                    throw self::wrong("origins.$setting", 'an array of strings');
                }
                $out[(string) $setting][(string) $what] = $where;
            }
        }
        return $out;
    }

    /**
     * Where the rule behind a pattern or budget was written ("site.rules:12",
     * "default @scanners.backups"); null for settings from a PHP array.
     */
    public function origin(string $setting, string $what): ?string
    {
        return $this->origins[$setting][$what] ?? null;
    }

    // ── Compiled: checked once, then loaded from OPcache ──────────────────

    /** Bumped when the export's shape changes, so old compiled files are rebuilt. */
    /** Deny entries kept as they were written, for the pages; the rest only in the table. */
    public const DENY_SHOWN = 100;

    private const FORMAT = 30;       // 3: rule files, several sources, origins; 4: restricted, methodPaths, log; 5: blockExceptions; 6: challenge.language; 7: appChallenge; 8: challenge.home; 9: contentRules; 10: blockedIndex; 11: contentHints; 12: widget; 13: earnBack, apiPaths; 14: dnsLookups; 15: queryParams; 16: queryIndex; 17: mode, uncachedWeight, monitor, challenge.alwaysMaxAge; 18: crawlers; 19: stats, crawlerLog; 20: statsParts, statsFlush; 21: statsMonths; 22: challenge.logo; 23: dashboardPath; 24: statsDepth; 25: origins.queryParams; 26: plugins; 27: sites, site, siteFrom; 28: budget.site; 29: deny, lists, bans; 30: denyTable, denyCount

    public const MODES = ['off', 'monitor', 'enforce', 'strict'];

    /** The shipped rules and lists (rules/): for plugins, which may live elsewhere. */
    public const RULES_DIR = __DIR__ . '/../rules';

    /** What the statistics can count (set stats <parts>). */
    public const STATS_PARTS = ['requests', 'crawlers', 'not-found', 'bots', 'pages'];

    /**
     * The settings of a file, checked only when it changed. A ".rules" file
     * is a rule file (Rules\RuleFile), anything else a PHP file returning
     * the settings array; $sources are further rule files or globs read
     * before it (an adapter's extensions), each with its includes.
     *
     * The checked values are kept as a PHP file in $cacheDir, which OPcache
     * serves from memory. Whether the sources changed:
     *   - one file: its mtime and size, on every call (one stat());
     *   - several, with APCu: all of them, at most every "recheck" seconds
     *     (default 10) -- in between not a single stat();
     *   - several, without APCu: the main file on every call; the others
     *     when it changes (bin/request-shield reload touches it).
     * "set recheck 0" checks every source on every call.
     *
     * With site blocks (rules per website, 0024) the base is checked and, for
     * a website's block, $site loads its settings: the same sources, so no
     * check of its own -- one more include from OPcache.
     *
     * @param list<string> $sources
     * @param ?string $site a site block (siteFor() names it); null: the base
     * @throws \InvalidArgumentException for a setting of the wrong type (Rules\RuleFileException: with file and line)
     * @throws \RuntimeException when the file cannot be read
     */
    public static function load(string $file, ?string $cacheDir = null, array $sources = [], ?string $site = null): self
    {
        $cacheDir ??= rtrim(sys_get_temp_dir(), '/') . '/request-shield';
        $key = hash(PHP_VERSION_ID >= 80100 ? 'xxh128' : 'md5', $file . "\0" . implode("\0", $sources));
        if ($site !== null) {
            return self::loadSite($file, $cacheDir, $sources, $site, $key);
        }
        $compiled = $cacheDir . '/settings-' . $key . '.php';
        // No is_file() first: a stat costs more than everything else here, and
        // an include of a missing file just returns false.
        $e = @include $compiled;
        // Written by write() below, so trusted beyond its format and file.
        if (is_array($e) && ($e['format'] ?? 0) === self::FORMAT && ($e['file'] ?? '') === $file) {
            /** @var array{seen: array<string, array{0: int, 1: int}>, env: array<string, string|null>, recheck: int, settings: array<string, mixed>} $e */
            if (($e['env'] === [] || self::sameEnv($e['env'])) && self::inForce($e['settings']) && self::fresh($e['seen'], $e['recheck'], $key)) {
                self::$checked[$key] = $e['seen'];
                return self::import($e['settings']);
            }
            $lock = self::lock($cacheDir, $key);
            if ($lock === null) {
                return self::import($e['settings']);        // another request is building them: the last ones meanwhile
            }
            try {
                return self::build($file, $cacheDir, $sources, $key);
            } finally {
                self::unlock($lock);
            }
        }
        return self::build($file, $cacheDir, $sources, $key);
    }

    /**
     * The settings for this request: load(), and with site blocks the
     * website's (siteFor()) -- picked from the compiled base before any
     * settings are made, so a website's request builds only its own.
     *
     * @param array<mixed> $server $_SERVER
     * @param list<string> $sources
     */
    public static function loadFor(string $file, array $server, ?string $cacheDir = null, array $sources = []): self
    {
        $cacheDir ??= rtrim(sys_get_temp_dir(), '/') . '/request-shield';
        $key = hash(PHP_VERSION_ID >= 80100 ? 'xxh128' : 'md5', $file . "\0" . implode("\0", $sources));
        $e = @include $cacheDir . '/settings-' . $key . '.php';
        if (is_array($e) && ($e['format'] ?? 0) === self::FORMAT && ($e['file'] ?? '') === $file) {
            /** @var array{seen: array<string, array{0: int, 1: int}>, env: array<string, string|null>, recheck: int, settings: array{sites: array<string, string>, siteFrom: string, trustedProxies: list<string>}} $e */
            if (($e['env'] === [] || self::sameEnv($e['env'])) && self::inForce($e['settings']) && self::fresh($e['seen'], $e['recheck'], $key)) {
                self::$checked[$key] = $e['seen'];
                $raw = $e['settings'];
                $site = $raw['sites'] === [] ? null : self::pick($raw['sites'], $raw['siteFrom'], $raw['trustedProxies'], $server);
                return $site === null ? self::import($raw) : self::loadSite($file, $cacheDir, $sources, $site, $key);
            }
            $lock = self::lock($cacheDir, $key);
            if ($lock === null) {
                // Another request is building them: the last ones meanwhile, the website's too.
                $raw = $e['settings'];
                $site = $raw['sites'] === [] ? null : self::pick($raw['sites'], $raw['siteFrom'], $raw['trustedProxies'], $server);
                $one = $site === null ? null : @include $cacheDir . '/settings-' . $key . '-' . hash('crc32b', $site) . '.php';
                if (is_array($one) && ($one['format'] ?? 0) === self::FORMAT && ($one['file'] ?? '') === $file && ($one['site'] ?? null) === $site) {
                    /** @var array{settings: array<string, mixed>} $one */
                    return self::import($one['settings']);
                }
                /** @var array<string, mixed> $raw */
                return self::import($raw);
            }
            try {
                $base = self::build($file, $cacheDir, $sources, $key);
            } finally {
                self::unlock($lock);
            }
            $site = $base->siteFor($server);
            return $site === null ? $base : self::loadSite($file, $cacheDir, $sources, $site, $key);
        }
        $base = self::build($file, $cacheDir, $sources, $key);
        $site = $base->siteFor($server);
        return $site === null ? $base : self::loadSite($file, $cacheDir, $sources, $site, $key);
    }

    /** @var array<string, array<string, array{0: int, 1: int}>> base key => the sources its last check found unchanged, this request */
    private static array $checked = [];

    /**
     * A website's settings (its site block on the base): checked with the
     * base -- the base just found the same sources unchanged, so the site's
     * compiled file is taken as it is (one include from OPcache, no stat).
     *
     * @param list<string> $sources
     */
    private static function loadSite(string $file, string $cacheDir, array $sources, string $site, string $key): self
    {
        if (!isset(self::$checked[$key])) {
            self::load($file, $cacheDir, $sources);         // the base, checked: its sources are the site's
        }
        $compiled = $cacheDir . '/settings-' . $key . '-' . hash('crc32b', $site) . '.php';
        $e = @include $compiled;
        if (self::isSite($e, $file, $site, $key)) {
            /** @var array{settings: array<string, mixed>} $e */
            return self::import($e['settings']);
        }
        // Missing, or from other sources: every website built again with the base.
        $base = self::build($file, $cacheDir, $sources, $key);
        $e = @include $compiled;
        if (self::isSite($e, $file, $site, $key)) {
            /** @var array{settings: array<string, mixed>} $e */
            return self::import($e['settings']);
        }
        return $base;                       // a site block that is gone: the base
    }

    /** Whether an included file is the compiled settings of this site, from the sources the base just found unchanged. */
    private static function isSite(mixed $e, string $file, string $site, string $key): bool
    {
        return is_array($e) && ($e['format'] ?? 0) === self::FORMAT && ($e['file'] ?? '') === $file && ($e['site'] ?? null) === $site
            && ($e['seen'] ?? null) === (self::$checked[$key] ?? false);
    }

    /**
     * The right to build these settings: a handle, true when there is no lock
     * file to be had (built anyway, as before), or null while another request
     * holds it -- with a big list a build takes seconds, and every request
     * under load building at once would take the server down with it.
     *
     * @return resource|true|null
     */
    private static function lock(string $cacheDir, string $key)
    {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0700, true);
        }
        $h = @fopen($cacheDir . '/settings-' . $key . '.lock', 'c');
        if ($h === false) {
            return true;
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            return null;
        }
        return $h;
    }

    /** @param resource|true $lock */
    private static function unlock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Reads and checks the settings, writes them compiled -- and, for a rule
     * file with site blocks, each website's settings beside them (all checked
     * now: a mistake in any block is an error here, not at that website's
     * first visitor). Every source any of them read is in "seen".
     *
     * @param list<string> $sources
     */
    private static function build(string $file, string $cacheDir, array $sources, string $key): self
    {
        try {
            return self::compile($file, $cacheDir, $sources, $key);
        } finally {
            Rules\RuleFile::forgetLists();         // read once per build, kept by no worker
        }
    }

    /** @param list<string> $sources */
    private static function compile(string $file, string $cacheDir, array $sources, string $key): self
    {
        $sites = [];
        if (substr($file, -6) === '.rules') {
            $files = $sources;
            $files[] = $file;
            $read = Rules\RuleFile::read($files);
            // The main file first: without APCu it is the one checked.
            $seen = [$file => $read['seen'][$file] ?? [0, 0]] + $read['seen'];
            $recheck = $read['recheck'];
            $config = $read['config'];
            $env = $read['env'];
            foreach ((array) ($config['sites'] ?? []) as $id) {
                if (!is_string($id) || isset($sites[$id])) {
                    continue;
                }
                $one = Rules\RuleFile::read($files, $id);
                $seen += $one['seen'];
                $env += $one['env'];
                $sites[$id] = self::from($one['config']);
            }
        } else {
            clearstatcache();
            $stat = Rules\RuleFile::stat($file);
            if ($stat === null) {
                throw new \RuntimeException("request-shield: cannot read the settings file $file");
            }
            // OPcache judges a file by its mtime, and a file rewritten within
            // the same second keeps it -- the old settings would be compiled
            // under the new size and stay for good.
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            $config = require $file;
            if (!is_array($config)) {
                throw new \RuntimeException("request-shield: $file must return an array");
            }
            $seen = [$file => $stat];
            $recheck = 0;
            $env = [];
            if ($sources !== []) {
                throw new \RuntimeException('request-shield: further sources need a .rules main file');
            }
        }
        $settings = self::from($config);
        foreach ($sites as $id => $one) {
            self::write($cacheDir . '/settings-' . $key . '-' . hash('crc32b', $id) . '.php', "<?php\n// Compiled by cjw-network/request-shield from $file, site $id; rebuilt when it changes.\nreturn "
                . var_export(['format' => self::FORMAT, 'file' => $file, 'site' => $id, 'seen' => $seen, 'settings' => $one->export()], true) . ";\n");
        }
        self::write($cacheDir . '/settings-' . $key . '.php', "<?php\n// Compiled by cjw-network/request-shield from $file; rebuilt when it changes.\nreturn "
            . var_export(['format' => self::FORMAT, 'file' => $file, 'seen' => $seen, 'env' => $env, 'recheck' => $recheck, 'settings' => $settings->export()], true) . ";\n");
        if ($recheck > 0 && function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_store('rshield:fresh:' . $key, true, $recheck);
        }
        self::$checked[$key] = $seen;
        return $settings;
    }

    /**
     * Whether compiled settings still hold: no list entry (deny … until,
     * exempt … until) has ended since -- one comparison.
     *
     * @param array<mixed> $settings what export() wrote
     */
    private static function inForce(array $settings): bool
    {
        $until = $settings['listsUntil'] ?? 0;
        return !is_int($until) || $until === 0 || time() < $until;
    }

    /** @param array<string, string|null> $env the environment variables a rule file used, with their values then */
    private static function sameEnv(array $env): bool
    {
        foreach ($env as $name => $value) {
            $now = getenv($name);
            if ((is_string($now) ? $now : null) !== $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the sources are unchanged. Kept small and free of other
     * classes: it runs on every request.
     *
     * @param array<string, array{0: int, 1: int}> $seen path => [mtime, size], the main file first
     */
    private static function fresh(array $seen, int $recheck, string $key): bool
    {
        $apcu = $recheck > 0 && function_exists('apcu_enabled') && apcu_enabled();
        if ($apcu && apcu_fetch('rshield:fresh:' . $key) === true) {
            return true;
        }
        // PHP keeps the last stat() for the rest of the process: in a
        // long-running server (and a second load() in one request) the old
        // mtime and size would be compared, and a change never seen.
        clearstatcache();
        foreach ($seen as $path => $stat) {
            if (@filemtime($path) !== $stat[0] || @filesize($path) !== $stat[1]) {
                return false;
            }
            if ($recheck > 0 && !$apcu) {
                break;          // without APCu: only the main file
            }
        }
        if ($apcu) {
            apcu_store('rshield:fresh:' . $key, true, $recheck);
        }
        return true;
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        /** @var array<string, mixed> $e */
        $e = get_object_vars($this);
        $e['budgets'] = array_map(static fn (Budget $b): array => $b->export(), $this->budgets);
        $e['challenge'] = $this->challenge->export();
        $e['monitor'] = $this->monitor === null ? null : $this->monitor->export();
        return $e;
    }

    /** @param array<string, mixed> $e what export() returned (trusted: no checks) */
    public static function import(array $e): self
    {
        $budgets = [];
        foreach ((array) $e['budgets'] as $name => $b) {
            /** @var array<string, mixed> $b */
            $budgets[(string) $name] = Budget::import($b);
        }
        $e['budgets'] = $budgets;
        /** @var array<string, mixed> $challenge */
        $challenge = $e['challenge'];
        $e['challenge'] = ChallengeSettings::import($challenge);
        /** @var array<string, mixed>|null $monitor */
        $monitor = $e['monitor'];
        $e['monitor'] = $monitor === null ? null : self::import($monitor);
        // Positional, in declaration order (what export() returns): unpacking
        // string keys into named arguments needs PHP 8.1.
        /** @phpstan-ignore argument.type */
        return new self(...array_values($e));
    }

    private static function write(string $file, string $php): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $php) === false) {
            return;             // not cacheable here: checked again next time
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        } elseif (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    // ── Typed reading, shared with Budget and ChallengeSettings ────────────

    /**
     * @param array<mixed> $a
     * @return array<mixed>
     */
    public static function map(array $a, string $key, ?string $name = null): array
    {
        $v = $a[$key] ?? [];
        if (!is_array($v)) {
            throw self::wrong($name ?? $key, 'an array');
        }
        return $v;
    }

    /**
     * @param array<mixed> $a
     * @return list<string>
     */
    public static function strings(array $a, string $key, ?string $name = null): array
    {
        $v = $a[$key] ?? [];
        if (!is_array($v)) {
            throw self::wrong($name ?? $key, 'a list of strings');
        }
        $out = [];
        foreach ($v as $item) {
            if (!is_string($item)) {
                throw self::wrong($name ?? $key, 'a list of strings');
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<mixed> $a
     * @return list<string>|null
     */
    public static function stringsOrNull(array $a, string $key, ?string $name = null): ?array
    {
        return ($a[$key] ?? null) === null ? null : self::strings($a, $key, $name);
    }

    /** @param array<mixed> $a */
    public static function int(array $a, string $key, ?string $name = null, int $default = 0): int
    {
        $v = $a[$key] ?? $default;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            return (int) $v;
        }
        throw self::wrong($name ?? $key, 'an integer');
    }

    /** @param array<mixed> $a */
    public static function intOrNull(array $a, string $key, ?string $name = null): ?int
    {
        return ($a[$key] ?? null) === null ? null : self::int($a, $key, $name);
    }

    /** @param array<mixed> $a */
    public static function bool(array $a, string $key, ?string $name = null, bool $default = false): bool
    {
        $v = $a[$key] ?? $default;
        if (!is_bool($v)) {
            throw self::wrong($name ?? $key, 'true or false');
        }
        return $v;
    }

    /** @param array<mixed> $a */
    public static function string(array $a, string $key, ?string $name = null, string $default = ''): string
    {
        $v = $a[$key] ?? $default;
        if (!is_string($v)) {
            throw self::wrong($name ?? $key, 'a string');
        }
        return $v;
    }

    public static function wrong(string $key, string $what): \InvalidArgumentException
    {
        return new \InvalidArgumentException("request-shield: '$key' must be $what");
    }
}
