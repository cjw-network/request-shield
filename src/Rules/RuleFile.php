<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

use CjwNetwork\RequestShield\Config;
use CjwNetwork\RequestShield\Texts;

/**
 * Rule files: the settings written one rule per line, for people rather than
 * PHP (docs/features/rule-files.md).
 *
 *   host        www.example.org example.org
 *   block       /wp-admin/**  *.sql
 *   limit       requests 600/min challenge-at 300
 *   challenge   /login
 *   include     rules.d/*.rules
 *
 * They are read, never executed, and turned into the same settings array a
 * PHP settings file returns -- Settings::from() checks it as always. Only
 * when they changed: Settings::load() keeps the result compiled.
 */
final class RuleFile
{
    /** The kinds of crawler (rules/crawlers.rules) and what a site may do with them. */
    public const KINDS = ['search', 'ai-search', 'ai-user', 'ai-training'];

    public const POLICIES = ['allow', 'check', 'block'];

    /** The sets "block @name" adds and "unblock @name" takes away. */
    private const SETS = ['scanners', 'wordpress', 'tracking'];

    /** "set" keys => [setting path, type]. */
    private const SET = [
        'store' => ['store', 'store'],
        'store-dir' => ['storeDir', 'string'],
        'ipv6-prefix' => ['ipv6Prefix', 'int'],
        'debug-header' => ['debugHeader', 'bool'],
        'app-challenge' => ['appChallenge', 'bool'],
        'strip-untrusted-forwarded' => ['stripUntrustedForwarded', 'bool'],
        'max-uri' => ['limits.uri', 'int'],
        'max-query-parameters' => ['limits.queryParameters', 'int'],
        'max-header-bytes' => ['limits.headerBytes', 'int'],
        'secret' => ['challenge.secret', 'string'],
        'pass-ttl' => ['challenge.passTtl', 'seconds'],
        'solution-ttl' => ['challenge.solutionTtl', 'seconds'],
        'difficulty-min' => ['challenge.difficulty.min', 'int'],
        'difficulty-max' => ['challenge.difficulty.max', 'int'],
        'cookie' => ['challenge.cookie', 'string'],
        'solution-cookie' => ['challenge.solutionCookie', 'string'],
        'bind-user-agent' => ['challenge.bindUserAgent', 'bool'],
        'search-engines' => ['challenge.searchEngines', 'bool'],
        'dns-lookups' => ['challenge.dnsLookups', 'int'],
        'recheck' => ['recheck', 'seconds'],
        'site-from' => ['siteFrom', 'sitefrom'],
        'lists-dir' => ['listsDir', 'string'],
        'ban-growth' => ['banGrowth', 'int'],
        'ban-max' => ['banMax', 'seconds'],
        'language' => ['challenge.language', 'language'],
        'home' => ['challenge.home', 'string'],
        'widget-path' => ['challenge.widgetPath', 'string'],
        'widget-difficulty' => ['challenge.widgetDifficulty', 'int'],
        'challenge-logo' => ['challenge.logo', 'logo'],
        'mode' => ['mode', 'mode'],
        'crawler-verify' => ['crawlerVerify', 'verify'],
        'log' => ['log.file', 'path'],
        'stats' => ['stats', 'stats'],
        'stats-flush' => ['stats.flush', 'seconds'],
        'dashboard-path' => ['dashboardPath', 'string'],
        'stats-months' => ['stats.months', 'int'],
        'stats-depth' => ['stats.depth', 'int'],
        'stats-hours' => ['stats.hours', 'int'],
        'stats-days' => ['stats.days', 'int'],
        'crawler-log' => ['crawlerLog.dir', 'path'],
        'crawler-log-kinds' => ['crawlerLog.kinds', 'kinds'],
        'crawler-log-days' => ['crawlerLog.days', 'int'],
        'crawler-log-query' => ['crawlerLog.query', 'bool'],
        'log-level' => ['log.level', 'loglevel'],
        'live' => ['live.enabled', 'bool'],
        'live-keep' => ['live.keep', 'seconds'],
        'ban-keep' => ['banKeep', 'string'],
        'log-ip' => ['log.ip', 'logip'],
        'log-max-size' => ['log.maxSize', 'bytes'],
    ];

    /** @var array<string, mixed> */
    private array $c;

    /** @var array<string, array{0: int, 1: int}> every file and include directory read, with mtime and size */
    private array $seen = [];

    /** @var array<string, array<string, string>> setting => pattern or budget => where it was set ("site.rules:12") */
    private array $origins = [];

    private string $base = '';

    /** @var array<string, string|null> the environment variables used, with their values */
    private array $env = [];

    /** @var list<string> the files being read, for include loops */
    private array $stack = [];

    /** The rule being read: its own ID ("SITE-10") or, without one, where it is ("site.rules:12"). */
    private string $rid = '';

    /** @var array<string, array{0: string, 1: bool}> file => its namespace ("SITE") and whether IDs are required */
    private array $ns = [];

    /** @var array<string, string> ID => where it was given, for duplicates */
    private array $ids = [];

    /** @var array<string, string> file => its "version" */
    private array $versions = [];

    /** @var list<array{file: string, at: string, glob: ?string, regex: ?string, written: string}> the open match blocks */
    private array $blocks = [];

    /** @var list<string> reviewed revisions that differ from the rules' own: for check and the rules page */
    private array $warnings = [];

    /** Whether rules marked "monitor" are read as rules (the second reading) or only noted (the first). */
    private bool $monitoring = false;

    /** Whether a rule marked "monitor" was seen. */
    private bool $monitored = false;

    /** The website whose site block is read as rules (null: the base -- every site block skipped). */
    private ?string $want = null;

    /** @var array{id: string, file: string, at: string, skip: bool, depth: int, blocks: int}|null the open site block */
    private ?array $siteOpen = null;

    /** @var array<string, array{0: string, 1: string}> website name => [its block's ID (the block's first name), where] */
    private array $siteNames = [];

    /** Whether a site block was seen: rules for every website go above the first one. */
    private bool $sawSite = false;

    /** "set" keys that are about the server, not a website: not inside a site block. */
    private const SERVER_WIDE = ['store', 'store-dir', 'secret', 'recheck', 'dns-lookups', 'ipv6-prefix', 'site-from', 'lists-dir', 'ban-growth', 'ban-max', 'live', 'live-keep', 'ban-keep'];

    /** Reading a list file (allow.rules, deny.rules in lists-dir): only list lines there. */
    private bool $listing = false;

    /** The shipped rule files: rules/<name>.rules ("@scanners", "@wordpress"). */
    public static function shipped(string $name): ?string
    {
        $file = dirname(__DIR__, 2) . '/rules/' . $name . '.rules';
        return preg_match('/^[a-z0-9-]+$/', $name) && is_file($file) ? $file : null;
    }

    private function __construct()
    {
        $this->c = Config::defaults();
        $this->c['recheck'] = 10;
        // The built-in blocks come from rules/scanners.rules, read first, so
        // they have IDs and descriptions like every other rule.
        $this->c['blockedPaths'] = [];
        $this->origins['budgets']['requests'] = 'default';
    }

    /**
     * Reads $files in order (a later one adds to and overrides an earlier
     * one), each with its includes.
     *
     * @param list<string> $files paths or globs; a glob may match nothing
     * @param ?string $site the website whose "site" block is read too (its block's first name);
     *   null: the base -- the rules for every website, each site block skipped
     * @return array{config: array<string, mixed>, seen: array<string, array{0: int, 1: int}>, env: array<string, string|null>, recheck: int}
     *   config: the settings array, with 'origins' (setting => pattern or budget => "file:line"),
     *   'sites' (website name => its block's first name) and 'site'
     * @throws RuleFileException naming file and line
     */
    public static function read(array $files, ?string $site = null): array
    {
        $r = self::reading($files, false, $site);
        if ($r['monitored']) {
            // Rules marked "monitor": read once more with them, for the log.
            $r['config']['monitorRules'] = self::reading($files, true, $site)['config'];
        }
        unset($r['monitored']);
        return $r;
    }

    /**
     * @param list<string> $files
     * @return array{config: array<string, mixed>, seen: array<string, array{0: int, 1: int}>, env: array<string, string|null>, recheck: int, monitored: bool}
     */
    private static function reading(array $files, bool $monitoring, ?string $site = null): array
    {
        $r = new self();
        $r->monitoring = $monitoring;
        $r->want = $site;
        // Origins are named relative to the main file's directory (the last one).
        $main = $files === [] ? false : realpath(dirname($files[count($files) - 1]));
        $r->base = $main === false ? '' : $main . '/';
        $r->file((string) self::shipped('scanners'), null, null);
        $r->file((string) self::shipped('crawlers'), null, null);
        foreach ($files as $file) {
            $r->source($file, null, null);
        }
        // The list files (allow.rules, deny.rules in lists-dir): list lines only,
        // for every website; the directory recorded, so a new file is noticed.
        $listsDir = $r->c['listsDir'] ?? null;
        $listsDir = is_string($listsDir) && $listsDir !== '' ? $listsDir : (is_string($r->c['storeDir'] ?? null) ? $r->c['storeDir'] . '/lists' : null);
        if ($listsDir !== null) {
            $r->c['listsDir'] = $listsDir;
            $stat = self::stat($listsDir);
            if ($stat !== null) {
                $r->seen[$listsDir] = $stat;
            }
            $r->listing = true;
            foreach (['allow.rules', 'deny.rules'] as $name) {
                if (is_file("$listsDir/$name")) {
                    $r->listFile("$listsDir/$name");
                }
            }
            $r->listing = false;
        }
        $r->resolveLists();
        $recheck = $r->c['recheck'];
        unset($r->c['recheck']);
        foreach ($r->warnings as $i => $w) {
            $r->origins['warnings']['w' . $i] = $w;      // not numeric: PHP would make it an int key
        }
        if ($site !== null && !in_array($site, array_column($r->siteNames, 0), true)) {
            throw new RuleFileException("request-shield: no site block named $site");
        }
        $r->c['origins'] = $r->origins;
        $r->c['sites'] = array_map(static fn (array $n): string => $n[0], $r->siteNames);
        $r->c['site'] = $site;
        return ['config' => $r->c, 'seen' => $r->seen, 'env' => $r->env, 'recheck' => is_int($recheck) ? $recheck : 10, 'monitored' => $r->monitored];
    }

    /**
     * The shipped crawlers as rules/crawlers.php holds them (for settings
     * from a PHP array): rules/crawlers.rules with its shipped address lists.
     *
     * @return array<string, array{kind: string, ua: string, dns: list<string>, lists: array<string, mixed>, ranges: list<string>, nets: array<string, list<array{0: string, 1: int}>>}>
     */
    public static function shippedCrawlers(): array
    {
        $r = new self();
        $r->c['storeDir'] = null;
        $r->file((string) self::shipped('crawlers'), null, null);
        $r->resolveLists();
        $crawlers = $r->crawlers();
        foreach ($crawlers as $id => $x) {
            $crawlers[$id]['nets'] = \CjwNetwork\RequestShield\IpAddress::index($x['ranges']);    // the lookup, ready (PHP settings build nothing per request)
        }
        return $crawlers;
    }

    /**
     * The contents rules/crawlers.php must have: the shipped crawlers ready
     * for Settings (policy allow, the address lookup, the expression).
     */
    public static function shippedCrawlersPhp(): string
    {
        $crawlers = self::shippedCrawlers();
        foreach ($crawlers as $id => $x) {
            $crawlers[$id]['policy'] = 'allow';
        }
        [$index, $ids] = \CjwNetwork\RequestShield\Settings::crawlerIndex($crawlers);
        // The descriptions, for reports of settings from a PHP array (which have no origins).
        $r = new self();
        $r->file((string) self::shipped('crawlers'), null, null);
        $names = array_intersect_key($r->origins['text'] ?? [], $crawlers);
        return "<?php\n// Generated from rules/crawlers.rules and rules/crawlers/*.json by bin/update-crawler-lists -- do not edit.\n"
            . 'return ' . var_export(['crawlers' => $crawlers, 'index' => $index, 'ids' => $ids, 'names' => $names], true) . ";\n";
    }

    /**
     * The address lists the crawlers of these rules read, name => file (for
     * "crawlers update").
     *
     * @param array<string, mixed> $config what read() returned as config
     * @return array<string, string>
     */
    public static function crawlerListFiles(array $config): array
    {
        $out = [];
        foreach ((array) ($config['crawlers'] ?? []) as $x) {
            foreach (is_array($x) ? (array) ($x['lists'] ?? []) : [] as $name => $about) {
                if (strpos((string) $name, '/') === false) {
                    $out[(string) $name] = dirname(__DIR__, 2) . "/rules/crawlers/$name.json";
                }
            }
        }
        return $out;
    }

    /**
     * The mtime and size of a file or directory, as read() records them.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function stat(string $path): ?array
    {
        $mtime = @filemtime($path);
        $size = @filesize($path);
        return $mtime === false || $size === false ? null : [$mtime, $size];
    }

    /**
     * A path or glob from the top level (null $below) or an include.
     */
    private function source(string $pattern, ?string $below, ?string $where): void
    {
        if (strpbrk($pattern, '*?[') === false) {
            $this->file($pattern, $below, $where);
            return;
        }
        // A glob: the files in alphabetical order, and its directory recorded,
        // so a file added there later (an extension installed) is noticed.
        $dir = dirname($pattern);
        if (strpbrk($dir, '*?[') === false) {
            $stat = self::stat($dir);
            if ($stat !== null) {
                $this->seen[$dir] = $stat;
            }
        }
        $matches = glob($pattern) ?: [];
        sort($matches, SORT_STRING);
        foreach ($matches as $file) {
            if (is_file($file)) {
                $this->file($file, $below, $where);
            }
        }
    }

    private function file(string $file, ?string $below, ?string $where): void
    {
        $real = realpath($file);
        if ($real === false || !is_file($real)) {
            throw new RuleFileException(($where ?? 'request-shield') . ": cannot read the rule file $file");
        }
        if ($below !== null && strncmp($real, $below . '/', strlen($below) + 1) !== 0) {
            throw new RuleFileException("$where: $file is outside " . $below . ' (an include stays below the including file, unless its path is absolute)');
        }
        if (in_array($real, $this->stack, true)) {
            throw new RuleFileException("$where: $file includes itself");
        }
        // Recorded before it is read: a change while reading is noticed next time.
        $stat = self::stat($file);
        $text = @file_get_contents($file);
        if ($stat === null || $text === false) {
            throw new RuleFileException(($where ?? 'request-shield') . ": cannot read the rule file $file");
        }
        $this->seen[$file] = $stat;
        $this->stack[] = $real;
        $shipped = dirname(__DIR__, 2) . '/rules/';
        $name = strncmp($real, $shipped, strlen($shipped)) === 0 ? 'built-in ' . substr($real, strlen($shipped))
            : ($this->base !== '' && strncmp($real, $this->base, strlen($this->base)) === 0 ? substr($real, strlen($this->base)) : $real);
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $i => $line) {
            $this->line($line, "$name:" . ($i + 1), $file);
        }
        $open = end($this->blocks);
        if ($open !== false && $open['file'] === $file) {
            throw new RuleFileException("{$open['at']}: match without its } -- the block opened here is not closed");
        }
        if ($this->siteOpen !== null && $this->siteOpen['file'] === $file) {
            throw new RuleFileException("{$this->siteOpen['at']}: site without its } -- the block opened here is not closed");
        }
        if (isset($this->versions[$file])) {
            // Named by the file's namespace, else by the file.
            $this->origins['versions'][$this->ns[$file][0] ?? $name] = $this->versions[$file];
        }
        array_pop($this->stack);
    }

    /**
     * A list file: a deny line as the command line writes it is read on a
     * fast path (a list may hold hundreds of thousands); everything else --
     * exempt, comments, a line written by hand, a mistake -- the usual way.
     * Kept for the rest of the build (forgetLists()), which reads the files
     * again for each website and the monitor rules.
     */
    private function listFile(string $file): void
    {
        $stat = self::stat($file);
        $memo = self::$listMemo[$file] ?? null;
        if ($memo === null || $stat === null || $memo[0] !== $stat) {
            $text = @file_get_contents($file);
            if ($stat === null || $text === false) {
                throw new RuleFileException("request-shield: cannot read the list file $file");
            }
            $name = $this->base !== '' && strncmp($file, $this->base, strlen($this->base)) === 0 ? substr($file, strlen($this->base)) : $file;
            $deny = [];
            $other = [];
            foreach (explode("\n", $text) as $i => $line) {
                if (preg_match('/^\[([A-Za-z0-9-]+)\][ \t]+deny[ \t]+([^#\\\\]*?)(?:[ \t]+#.*)?\r?$/', $line, $m) === 1) {
                    [$ips, $until] = $this->addressesUntil(preg_split('/[ \t]+/', trim($m[2])) ?: [], "$name:" . ($i + 1), 'deny');
                    $deny[] = ['ips' => $ips, 'until' => $until, 'rule' => $m[1]];
                } elseif (trim($line) !== '') {
                    $other[] = [$line, "$name:" . ($i + 1)];
                }
            }
            self::$listMemo[$file] = $memo = [$stat, $deny, $other];
        }
        $this->seen[$file] = $stat;
        foreach ($memo[1] as $e) {
            $this->deny($e);
        }
        foreach ($memo[2] as [$line, $at]) {
            $this->line($line, $at, $file);
        }
    }

    /** @param array{ips: list<string>, until: ?int, rule: string} $e appended in place */
    private function deny(array $e): void
    {
        $list = &$this->c['deny'];
        if (!is_array($list)) {
            $list = [];
        }
        $list[] = $e;
    }

    /** @var array<string, array{0: array{0: int, 1: int}, 1: list<array{ips: list<string>, until: ?int, rule: string}>, 2: list<array{0: string, 1: string}>}> */
    private static array $listMemo = [];

    /** Drops the list files read during a build -- a worker must not keep them. */
    public static function forgetLists(): void
    {
        self::$listMemo = [];
    }

    private function line(string $line, string $at, string $file): void
    {
        // "#" starts a comment at the start of a line or after a space; "\#"
        // is a literal "#" (in a regex, say). A comment after a rule is its
        // description, for people (the active rules page).
        $text = null;
        if (preg_match('/(?:^|\s)#\s*(.*)$/', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $text = trim($m[1][0]);
            $line = substr($line, 0, $m[0][1]);
        }
        $line = trim(str_replace('\\#', '#', $line));
        if ($line === '') {
            return;
        }
        // [SITE-10] before a rule: its own ID, used everywhere instead of file:line.
        // [SCAN-BACKUP@3]: revision 3 of that rule, raised when it changes its meaning.
        $id = null;
        $rev = null;
        if (preg_match('/^\[([^\]]*)\]\s*(.*)$/', $line, $m) === 1) {
            [$id, $rev] = self::ref($m[1], $at);
            $line = $m[2];
            if ($line === '') {
                throw new RuleFileException("$at: [$id] before what? The rule follows the ID on the same line");
            }
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        $keyword = strtolower((string) array_shift($parts));
        // A list file holds list lines only: the dashboard and the command line
        // write it, so it must never become a way to write rules.
        if ($this->listing && !in_array($keyword, ['deny', 'exempt'], true)) {
            throw new RuleFileException("$at: a list file holds only deny and exempt lines (written by bin/request-shield deny, allow, unlist) -- not \"$keyword\"");
        }
        // Another website's block: skipped to its }, the blocks inside counted
        // (a site inside a site is an error when that website's block is read).
        if ($this->siteOpen !== null && $this->siteOpen['skip']) {
            if (end($parts) === '{') {
                $this->siteOpen['depth']++;
            } elseif ($keyword === '}') {
                if ($this->siteOpen['depth'] > 0) {
                    $this->siteOpen['depth']--;
                } elseif ($file === $this->siteOpen['file']) {
                    $this->siteOpen = null;
                }
            }
            return;
        }
        // site <names> { ... }: the rules of some websites, added to the base.
        if ($keyword === 'site') {
            if ($id !== null) {
                throw new RuleFileException("$at: [$id] site -- IDs go on the rules inside a block, not on the block");
            }
            $this->openSite(array_map(fn (string $a): string => $this->env($a, $at), $parts), $at, $file);
            return;
        }
        if ($keyword === '}' && $this->siteOpen !== null && $file === $this->siteOpen['file'] && count($this->blocks) === $this->siteOpen['blocks']) {
            if ($parts !== []) {
                throw new RuleFileException("$at: } stands on a line of its own");
            }
            $this->siteOpen = null;
            return;
        }
        if ($this->siteOpen !== null && ($keyword === 'trust' || ($keyword === 'set' && in_array(strtolower($parts[0] ?? ''), self::SERVER_WIDE, true)))) {
            throw new RuleFileException("$at: " . ($keyword === 'trust' ? 'trust' : 'set ' . strtolower($parts[0] ?? '')) . ' is about the server, not a website -- put it above the site blocks');
        }
        if ($this->siteOpen !== null && ($keyword === 'ban' || ($keyword === 'monitor' && strtolower($parts[0] ?? '') === 'ban'))) {
            // A ban keeps an address off the whole server, whichever website it offended.
            throw new RuleFileException("$at: ban is about the server, not a website -- put it above the site blocks (a budget the website counts can still be its signal)");
        }
        if ($this->sawSite && $this->siteOpen === null && !$this->listing && !in_array($keyword, ['include', 'ids', 'version', '}'], true)) {
            throw new RuleFileException("$at: a rule for every website after a site block -- the rules for every website go above the first site block");
        }
        // monitor <rule>: logged as it would decide, never enforced.
        $monitor = false;
        if ($keyword === 'monitor') {
            $monitor = true;
            $keyword = strtolower((string) array_shift($parts));
            $line = (string) preg_replace('/^\S+\s*/', '', $line);
            // The rules that refuse or check someone; the others refuse nobody.
            $watchable = in_array($keyword, ['block', 'restrict', 'allow', 'limit', 'challenge', 'ban'], true)
                || ($keyword === 'query' && $parts === ['strict']);
            if (!$watchable) {
                throw new RuleFileException("$at: monitor <rule> -- for block, restrict, allow, limit, challenge, ban and query strict"
                    . ($keyword === '' ? '' : ", not \"$keyword\" (use set mode monitor to watch everything)"));
            }
        }
        $args = array_map(fn (string $a): string => $this->env($a, $at), $parts);

        // match <path> { ... }: the rules of an area, their paths the block's.
        if ($keyword === 'match' || $keyword === '}') {
            if ($id !== null) {
                throw new RuleFileException("$at: [$id] $keyword -- IDs go on the rules inside a block, not on the block");
            }
            $keyword === 'match' ? $this->openBlock($args, $at, $file) : $this->closeBlock($args, $at, $file);
            return;
        }
        $inBlock = $this->blocks !== [] && end($this->blocks)['file'] === $file;
        if ($inBlock && in_array($keyword, ['ids', 'version', 'include', 'set'], true)) {
            throw new RuleFileException("$at: $keyword does not go inside a match block -- put it before the block");
        }

        if ($keyword === 'ids') {
            $this->namespace($args, $at, $file, $id);
            return;
        }
        if ($keyword === 'version') {
            if ($id !== null || count($args) !== 1 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,39}$/', $args[0])) {
                throw new RuleFileException("$at: version <version> -- one word: 2026-09-29.2, 1.4.0");
            }
            if (isset($this->versions[$file])) {
                throw new RuleFileException("$at: a file has one version -- already {$this->versions[$file]}");
            }
            $this->versions[$file] = $args[0];
            return;
        }
        if ($keyword === 'replace') {
            if ($id !== null) {
                throw new RuleFileException("$at: replace [<ID>] <rule> -- the ID follows replace");
            }
            $this->replace($parts, $line, $at, $file, $text);
            return;
        }
        [$ns, $required] = $this->ns[$file] ?? ['', false];
        if ($id !== null) {
            if ($keyword === 'set' || $keyword === 'include') {
                throw new RuleFileException("$at: $keyword takes no ID -- it is not a rule");
            }
            if ($ns !== '' && strncmp($id, $ns . '-', strlen($ns) + 1) !== 0) {
                throw new RuleFileException("$at: [$id] is not in this file's namespace -- its IDs start with $ns- ([$ns-10])");
            }
            if (isset($this->ids[$id]) && $this->ids[$id] !== $at) {
                throw new RuleFileException("$at: [$id] is used twice -- already at {$this->ids[$id]}");
            }
            $this->ids[$id] = $at;
            if (!$this->listing) {
                // Not for the list files: tens of thousands of lines, each the
                // ID's line in the compiled settings; their IDs are in the table.
                $this->origins['at'][$id] = $at;
            }
            if ($rev !== null) {
                $this->origins['rev'][$id] = $rev;
            }
        } elseif ($required && $keyword !== 'set' && $keyword !== 'include') {
            throw new RuleFileException("$at: every rule in this file needs an ID ([$ns-...] before it: ids $ns required)");
        }
        $this->rid = $id ?? $at;
        if ($text !== null && $text !== '' && $keyword !== 'set' && $keyword !== 'include') {
            $this->origins['text'][$this->rid] = $text;
        }
        if ($monitor) {
            // The rule as written, for the rules page and show; enforced only
            // in the second reading, which the log compares with.
            $this->origins['monitor'][$this->rid] = $line;
            $this->monitored = true;
            if (!$this->monitoring) {
                return;
            }
        }

        $this->dispatch($keyword, $args, $line, $at, $file);
    }

    /**
     * One rule, its keyword and values; $line for "set" (texts have spaces).
     *
     * @param list<string> $args
     */
    private function dispatch(string $keyword, array $args, string $line, string $at, string $file): void
    {
        // challenge <paths> max-age 5m: a pass issued in the last five minutes there.
        $maxAge = null;
        if ($keyword === 'challenge' && ($i = array_search('max-age', $args, true)) !== false) {
            if ($i !== count($args) - 2) {
                throw new RuleFileException("$at: challenge <paths> max-age <duration> -- max-age and its duration come last");
            }
            $maxAge = self::seconds($args[$i + 1], 'max-age', $at);
            if ($maxAge < 1) {
                throw new RuleFileException("$at: max-age is at least one second");
            }
            array_splice($args, (int) $i, 2);
        }
        $args = $this->inBlock($keyword, $args, $at, $file);
        switch ($keyword) {
            case 'host':
                $this->list('hosts', $args, $at, static fn (string $h): string => strtolower($h));
                $this->origins['hosts']['*'] = $this->rid;
                return;
            case 'restrict':
                $this->restrict($args, $at);
                return;
            case 'plugin':
                // plugin Vendor\Package\MyPlugin: told what was decided (proposal 0023).
                if (count($args) !== 1 || !preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $args[0])) {
                    throw new RuleFileException("$at: plugin takes one class name, such as Vendor\\Package\\MyPlugin");
                }
                $list = (array) $this->get('plugins');
                $list[] = ltrim($args[0], '\\');
                $this->put('plugins', $list);
                $this->origins['plugins'][ltrim($args[0], '\\')] = $this->rid;
                return;
            case 'allow':
                $this->allow($args, $at);
                return;
            case 'trust':
            case 'exempt':
                if ($keyword === 'exempt' && in_array('until', $args, true)) {
                    // exempt <addresses> until <when>: let in for a while (the allow list).
                    [$ips, $until] = $this->addressesUntil($args, $at, 'exempt');
                    $list = (array) $this->get('exemptUntil');
                    $list[] = ['ips' => $ips, 'until' => $until];
                    $this->put('exemptUntil', $list);
                    $this->origins['exempt'][$ips[0]] = $this->rid;
                    return;
                }
                $key = $keyword === 'trust' ? 'trustedProxies' : 'exempt.ips';
                $this->list($key, $args, $at, static fn (string $ip): string => self::address($ip, $at));
                if ($keyword === 'exempt') {
                    foreach ($args as $a) {
                        if ($a !== 'none') {
                            $this->origins['exempt'][self::address($a, $at)] = $this->rid;
                        }
                    }
                }
                return;
            case 'deny':
                // deny <addresses> [until <when>]: kept out, 403 before every other check.
                [$ips, $until] = $this->addressesUntil($args, $at, 'deny');
                // Appended in place: a list file may hold tens of thousands of
                // lines, and a copy of the list per line made reading quadratic.
                $this->deny(['ips' => $ips, 'until' => $until, 'rule' => $this->rid]);
                return;
            case 'ban':
                $this->ban($args, $at);
                return;
            case 'method':
                $this->list('methods', $args, $at, static function (string $m) use ($at): string {
                    if (!preg_match('/^[A-Za-z]+$/', $m)) {
                        throw new RuleFileException("$at: \"$m\" is not a method");
                    }
                    return strtoupper($m);
                });
                $this->origins['methods']['*'] = $this->rid;
                return;
            case 'block':
                if (in_array($args[0] ?? '', ['query', 'header', 'headers', 'anywhere'], true)) {
                    $this->contentBlock($args, $at);
                    return;
                }
                $this->patterns('blockedPaths', $args, $at, true);
                return;
            case 'unblock':
                $this->unblock($args, $at);
                return;
            case 'cache-path':
                $this->patterns('cacheable.paths', $args, $at, false);
                $this->origins['cacheable.paths']['*'] = $this->rid;
                return;
            case 'query':
                $this->queryRule($args, $at);
                return;
            case 'cache-query':
                $this->list('cacheable.query', $args, $at, static fn (string $q): string => $q);
                $this->origins['cacheable.query']['*'] = $this->rid;
                return;
            case 'challenge':
                foreach ($this->patterns('challenge.alwaysPaths', $args, $at, false) as $p) {
                    $ages = (array) $this->get('challenge.alwaysMaxAge');
                    if ($maxAge !== null) {
                        $ages[$p] = $maxAge;
                    } else {
                        unset($ages[$p]);
                    }
                    $this->put('challenge.alwaysMaxAge', $ages);
                }
                return;
            case 'challenge-exempt':
                $this->patterns('challenge.exemptPaths', $args, $at, false);
                return;
            case 'api-path':
                $this->patterns('challenge.apiPaths', $args, $at, false);
                return;
            case 'limit':
                $this->limit($args, $at);
                return;
            case 'no-limit':
                if (count($args) !== 1) {
                    throw new RuleFileException("$at: no-limit takes the name of one budget");
                }
                // Limit 0 switches a budget off, a default one too.
                $this->put("budgets.$args[0]", ['limit' => 0]);
                unset($this->origins['budgets'][$args[0]]);
                return;
            case 'crawler':
                $this->crawler($args, $at, $file);
                return;
            case 'crawlers':
                if (count($args) !== 2 || !in_array($args[0], self::KINDS, true) || !in_array($args[1], self::POLICIES, true)) {
                    throw new RuleFileException("$at: crawlers <kind> <policy> -- kinds " . implode(', ', self::KINDS) . '; policies ' . implode(', ', self::POLICIES));
                }
                $this->crawlerPolicy($args[0], $args[1]);
                return;
            case 'set':
                $this->set($line, $at, $file);
                return;
            case 'include':
                if ($args === []) {
                    throw new RuleFileException("$at: include of what?");
                }
                foreach ($args as $path) {
                    if ($path[0] === '@') {
                        $shipped = self::shipped(substr($path, 1));
                        if ($shipped === null) {
                            throw new RuleFileException("$at: unknown set \"$path\" (there are @" . implode(', @', self::SETS) . ')');
                        }
                        if (!isset($this->seen[$shipped])) {
                            $this->file($shipped, null, $at);
                        }
                        continue;
                    }
                    $absolute = $path[0] === '/';
                    if (!$absolute && preg_match('#(^|/)\.\.(/|$)#', $path)) {
                        throw new RuleFileException("$at: $path is outside " . dirname($file) . ' (an include stays below the including file, unless its path is absolute)');
                    }
                    $dir = dirname($file);
                    $real = realpath($dir);
                    $this->source($absolute ? $path : $dir . '/' . $path, $absolute ? null : ($real === false ? $dir : $real), $at);
                }
                return;
        }
        throw new RuleFileException("$at: unknown rule \"$keyword\"" . self::suggest($keyword,
            ['host', 'trust', 'exempt', 'method', 'allow', 'restrict', 'block', 'unblock', 'query', 'cache-path', 'cache-query', 'challenge', 'challenge-exempt', 'api-path', 'limit', 'no-limit', 'crawler', 'crawlers', 'plugin', 'site', 'deny', 'ban', 'set', 'include']));
    }

    /**
     * "SCAN-BACKUP" or "SCAN-BACKUP@3".
     *
     * @return array{0: string, 1: ?string} ID and revision
     */
    private static function ref(string $ref, string $at): array
    {
        if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]{0,47})(?:@([1-9][0-9]{0,5}))?$/', $ref, $m)) {
            throw new RuleFileException("$at: \"[$ref]\" is not an ID -- letters, digits, \"-\", \"_\" and \".\", a revision after @ ([SITE-10], [SCAN-BACKUP@3])");
        }
        return [$m[1], isset($m[2]) ? $m[2] : null];
    }

    /**
     * A rule that takes back, replaces or opens another names the revision it
     * was written for; when that rule has changed since (a library update),
     * say so -- the rule still applies, only the deviation needs a look.
     */
    private function pin(string $id, string $rev, string $at): void
    {
        $now = $this->origins['rev'][$id] ?? '1';
        if ($now !== $rev) {
            $this->warnings[] = "$this->rid ($at) was written for $id revision $rev; $id is now revision $now ("
                . ($this->origins['at'][$id] ?? $id) . ') -- please check what changed';
        }
    }

    /**
     * replace [<ID>@<rev>] <rule>: the rule with that ID is taken back
     * everywhere it applies, and this one takes its place -- same ID, so the
     * log and the rules page go on counting it.
     *
     * @param list<string> $parts
     */
    private function replace(array $parts, string $line, string $at, string $file, ?string $text): void
    {
        $ref = (string) array_shift($parts);
        if (!preg_match('/^\[(.+)\]$/', $ref, $m) || $parts === []) {
            throw new RuleFileException("$at: replace [<ID>] <rule> -- replace [SCAN-BACKUP@1] block *.sql *.bak");
        }
        [$id, $rev] = self::ref($m[1], $at);
        $was = $this->origins['at'][$id] ?? null;
        if ($was === null && !$this->forget($id, true)) {
            throw new RuleFileException("$at: no earlier rule has the ID [$id]");
        }
        $this->rid = $id;
        if ($rev !== null) {
            $this->pin($id, $rev, $at);
        }
        $this->forget($id, false);
        $keyword = strtolower((string) array_shift($parts));
        if (in_array($keyword, ['set', 'include', 'ids', 'version', 'replace'], true)) {
            throw new RuleFileException("$at: replace takes a rule, not \"$keyword\"");
        }
        $this->ids[$id] = $at;
        $this->origins['at'][$id] = "$at (replaces " . ($was ?? $id) . ')';
        if ($text !== null && $text !== '') {
            $this->origins['text'][$id] = $text;
        }
        $args = array_map(fn (string $a): string => $this->env($a, $at), $parts);
        $this->dispatch($keyword, $args, (string) preg_replace('/^\s*replace\s+\S+\s+/i', '', $line), $at, $file);
    }

    /**
     * Takes back what the rule with $id set, wherever that is. With $probe
     * only says whether there is anything.
     */
    private function forget(string $id, bool $probe): bool
    {
        $found = false;
        foreach (['blockedPaths', 'cacheable.paths', 'challenge.alwaysPaths', 'challenge.exemptPaths'] as $key) {
            $list = $this->get($key);
            if (!is_array($list)) {
                continue;
            }
            $keep = [];
            foreach ($list as $p) {
                if (is_string($p) && ($this->origins[$key][$p] ?? null) === $id) {
                    $found = true;
                    unset($this->origins[$key][$p]);
                    continue;
                }
                $keep[] = $p;
            }
            if (!$probe) {
                $this->put($key, $keep);
            }
        }
        $mine = [];
        foreach ($this->origins['contentRules'] ?? [] as $p => $origin) {
            if ($origin === $id) {
                $mine[] = (string) $p;
            }
        }
        if ($mine !== []) {
            $found = true;
            if (!$probe) {
                $this->dropContent($mine);
                foreach ($mine as $p) {
                    unset($this->origins['contentRules'][$p]);
                }
            }
        }
        foreach (['restricted', 'blockExceptions'] as $key) {
            $keep = [];
            foreach ((array) $this->get($key) as $entry) {
                $paths = is_array($entry) && is_array($entry['paths'] ?? null) ? $entry['paths'] : [];
                $first = $paths[0] ?? null;
                if (is_string($first) && ($this->origins[$key][$first] ?? null) === $id) {
                    $found = true;
                    continue;
                }
                $keep[] = $entry;
            }
            if (!$probe) {
                $this->put($key, $keep);
            }
        }
        foreach ($this->origins['budgets'] ?? [] as $name => $origin) {
            if ($origin === $id) {
                $found = true;
                if (!$probe) {
                    $this->put("budgets.$name", ['limit' => 0]);
                    unset($this->origins['budgets'][$name]);
                }
            }
        }
        foreach ($this->origins['methodPaths'] ?? [] as $method => $origin) {
            if ($origin === $id) {
                $found = true;
                if (!$probe) {
                    $all = (array) $this->get('methodPaths');
                    unset($all[$method]);
                    $this->put('methodPaths', $all);
                    unset($this->origins['methodPaths'][$method]);
                }
            }
        }
        foreach (['hosts' => [], 'methods' => Config::defaults()['methods'], 'cacheable.query' => null] as $key => $reset) {
            if (($this->origins[$key]['*'] ?? null) === $id) {
                $found = true;
                if (!$probe) {
                    $this->put($key, $reset);
                    unset($this->origins[$key]['*']);
                }
            }
        }
        return $found;
    }

    /**
     * <addresses> [until <when>]: the addresses (checked) and the end, a Unix
     * time (null: for good). <when> is a day (2026-10-07: to its end) or a
     * moment (2026-10-07T15:30), in the server's time zone.
     *
     * @param list<string> $args
     * @return array{0: list<string>, 1: ?int}
     */
    private function addressesUntil(array $args, string $at, string $keyword): array
    {
        $until = null;
        $i = array_search('until', $args, true);
        if ($i !== false) {
            if ($i !== count($args) - 2) {
                throw new RuleFileException("$at: $keyword <addresses> until <day or moment> -- until and its time come last");
            }
            $until = self::until($args[$i + 1], $at);
            array_splice($args, (int) $i, 2);
        }
        if ($args === []) {
            throw new RuleFileException("$at: $keyword needs at least one address or range");
        }
        return [array_map(static fn (string $ip): string => self::address($ip, $at), $args), $until];
    }

    /** "2026-10-07" (to its end) or "2026-10-07T15:30": a Unix time in the server's time zone. */
    private static function until(string $v, string $at): int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (isset($m[5]) && ((int) $m[4] > 23 || (int) $m[5] > 59))) {
            throw new RuleFileException("$at: until takes a day (2026-10-07) or a moment (2026-10-07T15:30), not \"$v\"");
        }
        return isset($m[5]) ? (int) mktime((int) $m[4], (int) $m[5], 0, (int) $m[2], (int) $m[3], (int) $m[1])
            : (int) mktime(23, 59, 59, (int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * ban after <n> <signal> in <time> for <time>: a client that sends <n> of
     * these signals within <time> gets nothing but 429 for a while. Signals:
     * limits (past a limit), refusals (blocked paths, attack patterns), checks
     * (a check page shown again and again), or the name of a budget the site
     * counts itself (consume('logins')).
     *
     * @param list<string> $args
     */
    private function ban(array $args, string $at): void
    {
        $usage = 'ban after <n> <limits|refusals|checks|budget> in <time> for <time>';
        if (count($args) !== 7 || $args[0] !== 'after' || $args[3] !== 'in' || $args[5] !== 'for' || !preg_match('/^[1-9]\d*$/', $args[1])
            || !preg_match('/^[A-Za-z0-9_-]+$/', $args[2])) {
            throw new RuleFileException("$at: $usage");
        }
        $window = self::seconds($args[4], 'in', $at);
        $for = self::seconds($args[6], 'for', $at);
        if ($window < 1 || $for < 1) {
            throw new RuleFileException("$at: $usage -- both times at least a second");
        }
        $list = (array) $this->get('bans');
        $list[] = ['after' => (int) $args[1], 'signal' => strtolower($args[2]), 'in' => $window, 'for' => $for, 'rule' => $this->rid];
        $this->put('bans', $list);
    }

    /**
     * site <names> {: names are exact (a.de) or *.domain (one label more), or
     * "default" (a name no block lists). Its block is read as rules only when
     * that website is wanted; otherwise skipped to its }.
     *
     * @param list<string> $args
     */
    private function openSite(array $args, string $at, string $file): void
    {
        $usage = 'site <names> {  (the rules, then } on a line of its own)';
        if (array_pop($args) !== '{' || $args === []) {
            throw new RuleFileException("$at: $usage");
        }
        if ($this->siteOpen !== null) {
            throw new RuleFileException("$at: a site block holds no site blocks -- the one at {$this->siteOpen['at']} is still open");
        }
        if ($this->blocks !== []) {
            throw new RuleFileException("$at: site goes outside match blocks (a match block may go inside a site block)");
        }
        $names = [];
        foreach ($args as $a) {
            $n = rtrim(strtolower($a), '.');
            if ($n !== 'default' && preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $n) !== 1) {
                throw new RuleFileException("$at: \"$a\" is not a website's name -- a.de, shop.a.de, *.a.de (one label more) or default");
            }
            $names[] = $n;
        }
        $siteId = $names[0];
        foreach ($names as $n) {
            if (isset($this->siteNames[$n]) && $this->siteNames[$n][1] !== $at) {
                throw new RuleFileException("$at: $n is in two site blocks -- already at {$this->siteNames[$n][1]}");
            }
            $this->siteNames[$n] = [$siteId, $at];
        }
        $this->sawSite = true;
        $this->siteOpen = ['id' => $siteId, 'file' => $file, 'at' => $at, 'skip' => $this->want !== $siteId, 'depth' => 0, 'blocks' => count($this->blocks)];
    }

    /**
     * match <glob> {  or  match regex <expression> {  -- the rules up to "}"
     * are about that area. An inner block adds its path to the outer one
     * (match /shop { match /checkout/** { ... } }); "**" only at the end of
     * the innermost; a regex block holds no blocks.
     *
     * @param list<string> $args
     */
    private function openBlock(array $args, string $at, string $file): void
    {
        $usage = 'match <path> {  (the rules, then } on a line of its own)';
        if (array_pop($args) !== '{' || $args === []) {
            throw new RuleFileException("$at: $usage");
        }
        $regex = null;
        $glob = null;
        if ($args[0] === 'regex') {
            if (count($args) !== 2) {
                throw new RuleFileException("$at: match regex <expression> {");
            }
            $regex = $args[1];
        } elseif (count($args) === 1) {
            $glob = $args[0];
        } else {
            throw new RuleFileException("$at: $usage -- one path per block");
        }
        $outer = end($this->blocks);
        if ($outer !== false && $outer['file'] === $file) {
            if ($outer['regex'] !== null || $regex !== null) {
                throw new RuleFileException("$at: a block by regex holds no blocks, and is in none -- write the whole path");
            }
            if (substr((string) $outer['glob'], -2) === '**') {
                throw new RuleFileException("$at: the outer block ends in ** -- an inner path cannot follow it");
            }
            if ((string) $glob === '' || ((string) $glob)[0] !== '/') {
                throw new RuleFileException("$at: an inner block's path starts with / -- it is added to the outer one");
            }
            $glob = rtrim((string) $outer['glob'], '/') . $glob;
        }
        // A broken path is an error here, not at the first rule inside.
        if (!Pattern::valid($regex !== null ? Pattern::fromRegex($regex) : Pattern::fromGlob((string) $glob))) {
            throw new RuleFileException("$at: \"" . ($regex ?? $glob) . "\" is not a valid regular expression");
        }
        $this->blocks[] = ['file' => $file, 'at' => $at, 'glob' => $glob, 'regex' => $regex, 'written' => $regex !== null ? "regex $regex" : (string) $glob];
    }

    /** @param list<string> $args */
    private function closeBlock(array $args, string $at, string $file): void
    {
        $open = end($this->blocks);
        if ($args !== [] || $open === false || $open['file'] !== $file) {
            throw new RuleFileException("$at: } without a match block" . ($args !== [] ? ' -- } stands on a line of its own' : ''));
        }
        array_pop($this->blocks);
    }

    /**
     * A rule inside a match block: its paths are the block's. Rules that take
     * paths leave them out; those without paths do not go inside.
     *
     * @param list<string> $args
     * @return list<string>
     */
    private function inBlock(string $keyword, array $args, string $at, string $file): array
    {
        $b = end($this->blocks);
        if ($b === false || $b['file'] !== $file) {
            return $args;
        }
        $path = $b['regex'] !== null ? ['regex', $b['regex']] : [(string) $b['glob']];
        $this->origins['area'][$this->rid] = $b['written'];
        $noPaths = static function () use ($args, $at, $keyword): void {
            if ($args !== []) {
                throw new RuleFileException("$at: inside match, $keyword takes no paths -- they are the block's");
            }
        };
        switch ($keyword) {
            case 'restrict':
                if (($args[0] ?? '') !== 'to') {
                    throw new RuleFileException("$at: inside match: restrict to <addresses> -- the paths are the block's");
                }
                return array_merge($path, $args);
            case 'allow':
                foreach ($args as $a) {
                    if (!preg_match('/^[A-Z]+$/', $a)) {
                        throw new RuleFileException("$at: inside match: allow <METHODS> -- the paths are the block's");
                    }
                }
                return array_merge($args, $path);
            case 'challenge':
            case 'challenge-exempt':
            case 'cache-path':
            case 'api-path':
                $noPaths();
                return $path;
            case 'block':
                $noPaths();
                return $path;                 // the whole area
            case 'unblock':
                if (in_array('at', $args, true)) {
                    throw new RuleFileException("$at: inside match: unblock [<ID>] [for <addresses>] -- the block is where");
                }
                $for = array_search('for', $args, true);
                $what = $for === false ? $args : array_slice($args, 0, (int) $for);
                $rest = $for === false ? [] : array_slice($args, (int) $for);
                return array_merge($what, ['at'], $path, $rest);
            case 'query':
                if ($args === ['strict']) {
                    throw new RuleFileException("$at: query strict is for the whole site -- put it outside the block");
                }
                if (in_array('at', $args, true)) {
                    throw new RuleFileException("$at: inside match: query <name> <type> ... -- the block is where");
                }
                return array_merge($args, ['at'], $path);
            case 'limit':
            case 'cache-query':
                throw new RuleFileException("$at: $keyword per area is not there yet (proposal 0008, a second step) -- put it outside the block");
        }
        throw new RuleFileException("$at: $keyword does not go inside a match block -- it is not about paths; put it outside");
    }

    /**
     * ids <NS> [required]: the IDs in this file start with "<NS>-"; with
     * "required", every rule needs one. For number blocks per file: the site
     * SITE, an extension SHOP, the built-ins SCAN and WP.
     *
     * @param list<string> $args
     */
    private function namespace(array $args, string $at, string $file, ?string $id): void
    {
        if ($id !== null || $args === [] || count($args) > 2 || (isset($args[1]) && $args[1] !== 'required')
            || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,23}$/', $args[0])) {
            throw new RuleFileException("$at: ids <NAMESPACE> [required] -- letters and digits, e.g. ids SHOP");
        }
        if (isset($this->ns[$file])) {
            throw new RuleFileException("$at: a file has one namespace -- already ids {$this->ns[$file][0]}");
        }
        $this->ns[$file] = [$args[0], isset($args[1])];
    }

    /**
     * Adds to a list. "none" first empties it -- the defaults too; for
     * cache-path and cache-query "any" means no restriction at all.
     *
     * @param list<string> $args
     * @param callable(string): string $each
     */
    private function list(string $key, array $args, string $at, callable $each): void
    {
        if ($args === []) {
            throw new RuleFileException("$at: " . strtok($key, '.') . ' needs at least one value');
        }
        $list = $this->get($key);
        if ($args === ['any'] && strncmp($key, 'cacheable.', 10) === 0) {
            $this->put($key, null);
            return;
        }
        if ($args[0] === 'none') {
            $list = [];
            array_shift($args);
        }
        $list = is_array($list) ? $list : [];
        foreach ($args as $a) {
            $v = $each($a);
            if (!in_array($v, $list, true)) {
                $list[] = $v;
            }
        }
        $this->put($key, $list);
    }

    /**
     * Globs, or "regex <expression>...". For block: "@scanners", "@wordpress".
     * "none" first empties the list, the defaults too. Each pattern's origin
     * (file:line) is kept, so a decision can name the rule behind it.
     *
     * @param list<string> $args
     * @return list<string> the patterns of this rule
     */
    private function patterns(string $key, array $args, string $at, bool $sets): array
    {
        if ($args === []) {
            throw new RuleFileException("$at: " . strtok($key, '.') . ' needs at least one value');
        }
        if ($args === ['any'] && $key === 'cacheable.paths') {
            $this->put($key, null);
            unset($this->origins[$key]);
            return [];
        }
        if ($args[0] === 'none') {
            $this->put($key, []);
            unset($this->origins[$key]);
        }
        // Compiled first: a set ("@wordpress") reads its file now, which adds
        // its blocks with their own IDs.
        $compiled = $this->compile($args, $at, $sets);
        $list = $this->get($key);
        $list = is_array($list) ? $list : [];
        foreach ($compiled as $pattern => $origin) {
            if (!in_array($pattern, $list, true)) {
                $list[] = $pattern;
                $this->origins[$key][$pattern] = $origin;
            }
        }
        $this->put($key, $list);
        return array_map('strval', array_keys($compiled));
    }

    /**
     * @param list<string> $args
     * @return array<string, string> pattern => origin
     */
    private function compile(array $args, string $at, bool $sets): array
    {
        $out = [];
        $regex = false;
        foreach ($args as $a) {
            if ($a === 'none') {
                continue;
            }
            if ($a === 'regex') {
                $regex = true;
                continue;
            }
            if (!$regex && ($a[0] === '@' || $a[0] === '[')) {
                if (!$sets) {
                    throw new RuleFileException("$at: $a only works with block and unblock");
                }
                foreach ($this->refer($a, $at) as $p => $origin) {
                    $out[$p] = $origin;
                }
                continue;
            }
            $pattern = $regex ? Pattern::fromRegex($a) : Pattern::fromGlob($a);
            if (!Pattern::valid($pattern)) {
                throw new RuleFileException("$at: \"$a\" is not a valid regular expression");
            }
            $out[$pattern] = $this->rid;
            // As written, for people (the rules page): "/wp-admin/**", not the expression.
            $this->origins['written'][$pattern] = $this->origins['written'][$pattern . 'i'] = ($regex ? 'regex ' : '') . $a;
        }
        if ($regex && $out === []) {
            throw new RuleFileException("$at: regex of what?");
        }
        return $out;
    }

    /**
     * The blocks a reference stands for: "[SCAN-BACKUP]" one rule by its ID,
     * "@wordpress" every block of a shipped file (read now if it was not).
     *
     * @return array<string, string> pattern => its rule's ID
     */
    private function refer(string $ref, string $at): array
    {
        $out = [];
        if ($ref[0] === '[') {
            [$id, $rev] = self::ref(trim($ref, '[]'), $at);
            if ($rev !== null) {
                $this->pin($id, $rev, $at);
            }
            foreach (($this->origins['blockedPaths'] ?? []) + ($this->origins['contentRules'] ?? []) as $p => $origin) {
                if ($origin === $id) {
                    $out[$p] = $origin;
                }
            }
            if ($out === []) {
                throw new RuleFileException("$at: no earlier block has the ID $ref");
            }
            return $out;
        }
        $file = self::shipped(substr($ref, 1));
        if ($file === null) {
            throw new RuleFileException("$at: unknown set \"$ref\" (there are @" . implode(', @', self::SETS) . ')');
        }
        $real = (string) realpath($file);
        if (!isset($this->seen[$file])) {
            $saved = $this->rid;
            $this->file($file, null, $at);
            $this->rid = $saved;
        }
        foreach (($this->origins['blockedPaths'] ?? []) + ($this->origins['contentRules'] ?? []) as $p => $origin) {
            if (strncmp((string) ($this->origins['at'][$origin] ?? ''), 'built-in ' . basename($real) . ':', strlen(basename($real)) + 10) === 0) {
                $out[$p] = $origin;
            }
        }
        return $out;
    }

    /**
     * unblock <patterns or sets>: take back an earlier block.
     * unblock [<patterns or sets>] at <paths> [for <addresses>]: let blocked
     * paths through at some paths only (an admin's file reader), for some
     * addresses only; without patterns every block, without "for" everyone.
     *
     * @param list<string> $args
     */
    private function unblock(array $args, string $at): void
    {
        if ($args === []) {
            throw new RuleFileException("$at: unblock what?");
        }
        $where = array_search('at', $args, true);
        if ($where !== false) {
            $this->blockException($args, (int) $where, $at);
            return;
        }
        $list = [];
        foreach ((array) $this->get('blockedPaths') as $p) {
            $list[] = is_string($p) ? $p : '';
        }
        $remove = array_keys($this->compile($args, $at, true));
        $content = $this->contentPatterns();
        foreach ($remove as $i => $pattern) {
            // A written content pattern is kept with its "i" flag: "unblock
            // regex x" takes back what "block query x" set.
            if (!in_array($pattern, $list, true) && !in_array($pattern, $content, true) && in_array($pattern . 'i', $content, true)) {
                $remove[$i] = $pattern . 'i';
            }
            if (!in_array($remove[$i], $list, true) && !in_array($remove[$i], $content, true)) {
                throw new RuleFileException("$at: nothing to unblock -- no earlier block matches " . implode(' ', $args) . ' exactly');
            }
        }
        $this->put('blockedPaths', array_values(array_diff($list, $remove)));
        $this->dropContent($remove);
        foreach ($remove as $pattern) {
            unset($this->origins['blockedPaths'][$pattern], $this->origins['contentRules'][$pattern]);
        }
    }

    /**
     * block query|headers|anywhere <regex>..., block header <Name> <regex>...:
     * attack patterns in the query string and the headers, matched after
     * Request::content() normalised them (decoded, lower case). Regular
     * expressions always; "regex" may be written, it changes nothing.
     *
     * @param list<string> $args
     */
    private function contentBlock(array $args, string $at): void
    {
        $where = (string) array_shift($args);
        if ($where === 'header') {
            $name = strtolower((string) array_shift($args));
            if (!preg_match('/^[a-z0-9-]+$/', $name)) {
                throw new RuleFileException("$at: block header <Name> <regex> -- block header User-Agent sqlmap");
            }
            $where = "header:$name";
        }
        if (($args[0] ?? '') === 'regex') {
            array_shift($args);
        }
        if ($args === []) {
            throw new RuleFileException("$at: block $where <regex> -- what to look for");
        }
        foreach ($args as $a) {
            // Expressions only: "@set" would silently match its own name and
            // "[X-1]" become a character class; references belong to unblock.
            if ($a !== '' && ($a[0] === '@' || preg_match('/^\[[A-Za-z0-9][A-Za-z0-9_.-]{0,47}(?:@[1-9][0-9]{0,5})?\]$/', $a) === 1)) {
                throw new RuleFileException("$at: block $where takes expressions, not references -- unblock [ID] takes one back");
            }
        }
        $patterns = [];
        foreach ($this->compile(array_merge(['regex'], $args), $at, false) as $p => $origin) {
            $p .= 'i';
            $patterns[] = $p;
            $this->origins['contentRules'][$p] = $origin;
            $this->origins['written'][$p] = $where . ' ' . ($this->origins['written'][substr($p, 0, -1)] ?? $p);
        }
        $rules = (array) $this->get('contentRules');
        $rules[] = ['target' => $where, 'patterns' => $patterns];
        $this->put('contentRules', $rules);
    }

    /** @return list<string> every attack pattern so far */
    private function contentPatterns(): array
    {
        $out = [];
        foreach ((array) $this->get('contentRules') as $r) {
            foreach (is_array($r) && is_array($r['patterns'] ?? null) ? $r['patterns'] : [] as $p) {
                if (is_string($p)) {
                    $out[] = $p;
                }
            }
        }
        return $out;
    }

    /** @param list<string> $patterns attack patterns to take out */
    private function dropContent(array $patterns): void
    {
        $rules = [];
        foreach ((array) $this->get('contentRules') as $r) {
            if (!is_array($r)) {
                continue;
            }
            $keep = [];
            foreach ((array) ($r['patterns'] ?? []) as $p) {
                if (is_string($p) && !in_array($p, $patterns, true)) {
                    $keep[] = $p;
                }
            }
            $r['patterns'] = $keep;
            if ($r['patterns'] !== []) {
                $rules[] = $r;
            }
        }
        $this->put('contentRules', $rules);
    }

    /** @param list<string> $args */
    private function blockException(array $args, int $where, string $at): void
    {
        $usage = 'unblock [<what>] at <paths> [for <addresses>]';
        $for = array_search('for', $args, true);
        $pathArgs = array_slice($args, $where + 1, $for === false ? null : (int) $for - $where - 1);
        if ($pathArgs === [] || ($for !== false && ($for < $where || $for === count($args) - 1))) {
            throw new RuleFileException("$at: $usage");
        }
        $patterns = null;
        if ($where > 0) {
            $patterns = array_keys($this->compile(array_slice($args, 0, $where), $at, true));
            $content = $this->contentPatterns();
            $blocked = array_merge((array) $this->get('blockedPaths'), $content);
            foreach ($patterns as $i => $p) {
                if (!in_array($p, $blocked, true) && in_array($p . 'i', $content, true)) {
                    $patterns[$i] = $p . 'i';   // a written content pattern, kept with its "i" flag
                }
                if (!in_array($patterns[$i], $blocked, true)) {
                    throw new RuleFileException("$at: nothing to unblock -- no earlier block matches " . implode(' ', array_slice($args, 0, $where)) . ' exactly');
                }
            }
        }
        $ips = [];
        foreach ($for === false ? [] : array_slice($args, (int) $for + 1) as $ip) {
            $ips[] = self::address($ip, $at);
        }
        $paths = [];
        foreach ($this->compile($pathArgs, $at, false) as $pattern => $_) {
            $pattern .= 'i';
            $paths[] = $pattern;
            $this->origins['blockExceptions'][$pattern] = $this->rid;
        }
        $list = $this->get('blockExceptions');
        $list = is_array($list) ? $list : [];
        $list[] = ['paths' => $paths, 'patterns' => $patterns, 'ips' => $ips];
        $this->put('blockExceptions', $list);
    }

    /**
     * query <name> <type> [<name> <type> ...] [at <paths>]  -- known parameters
     * query strict                                          -- anything else: 404
     * Types: int, number, word, id, list, text, any, or /regex/. A name may use
     * * (utm_*).
     *
     * @param list<string> $args
     */
    private function queryRule(array $args, string $at): void
    {
        if ($args === ['strict']) {
            $this->put('queryStrict', true);
            $this->origins['query']['strict'] = $this->rid;
            return;
        }
        $usage = 'query <name> <type> ... [at <paths>]  (types: int, number, word, id, list, text, any, /regex/) -- or query strict';
        $where = array_search('at', $args, true);
        $pairs = $where === false ? $args : array_slice($args, 0, (int) $where);
        if ($pairs === [] || count($pairs) % 2 !== 0) {
            throw new RuleFileException("$at: $usage");
        }
        $exact = [];
        $globs = [];
        for ($i = 0; $i < count($pairs); $i += 2) {
            [$name, $type] = [$pairs[$i], $pairs[$i + 1]];
            if (!preg_match('/^[A-Za-z0-9_.*-]{1,64}$/', $name)) {
                throw new RuleFileException("$at: \"$name\" is not a parameter name");
            }
            if (preg_match('#^/(.+)/$#', $type, $m)) {
                $type = Pattern::fromRegex('^(?:' . $m[1] . ')$');
                if (!Pattern::valid($type)) {
                    throw new RuleFileException("$at: \"{$pairs[$i + 1]}\" is not a valid regular expression");
                }
            } elseif (!in_array($type, ['int', 'number', 'word', 'id', 'list', 'text', 'any'], true)) {
                throw new RuleFileException("$at: \"$type\" is not a type -- int, number, word, id, list, text, any, /regex/");
            }
            if (strpos($name, '*') !== false) {
                $globs['#^' . str_replace('\\*', '.*', preg_quote($name, '#')) . '$#'] = $type;
            } else {
                $exact[$name] = $type;
            }
        }
        $paths = null;
        if ($where !== false) {
            $paths = [];
            foreach ($this->compile(array_slice($args, (int) $where + 1), $at, false) as $p => $_) {
                $paths[] = $p;
            }
            if ($paths === []) {
                throw new RuleFileException("$at: $usage");
            }
        }
        $list = (array) $this->get('queryParams');
        $list[] = ['paths' => $paths, 'exact' => $exact, 'globs' => $globs];
        $this->put('queryParams', $list);
        foreach (array_merge(array_keys($exact), array_keys($globs)) as $n) {
            $this->origins['query'][(string) $n] = $this->rid;
        }
        // The line too: two query lines may name the same parameter (at different paths).
        $this->origins['queryParams']['#' . (count($list) - 1)] = $this->rid;       // '#0': a number would become an int key
    }

    /**
     * restrict <path patterns> to <addresses or ranges>: everyone else 403.
     * Case does not matter, and the path is matched as the application routes
     * it (Request::matchPath()).
     *
     * @param list<string> $args
     */
    private function restrict(array $args, string $at): void
    {
        $to = array_search('to', $args, true);
        if ($to === false || $to === 0 || $to === count($args) - 1) {
            throw new RuleFileException("$at: restrict <paths> to <addresses or ranges>");
        }
        $ips = [];
        foreach (array_slice($args, $to + 1) as $ip) {
            $ips[] = self::address($ip, $at);
        }
        $paths = [];
        foreach ($this->compile(array_slice($args, 0, $to), $at, false) as $pattern => $origin) {
            $pattern .= 'i';
            $paths[] = $pattern;
            $this->origins['restricted'][$pattern] = $origin;
        }
        $list = $this->get('restricted');
        $list = is_array($list) ? $list : [];
        $list[] = ['paths' => $paths, 'ips' => $ips];
        $this->put('restricted', $list);
    }

    /**
     * allow <METHODS> <path patterns>: those methods only there (a POST where
     * the forms are); elsewhere 405. The methods are allowed at all, too.
     *
     * @param list<string> $args
     */
    private function allow(array $args, string $at): void
    {
        $methods = [];
        while ($args !== [] && preg_match('/^[A-Z]+$/', $args[0])) {
            $methods[] = (string) array_shift($args);
        }
        if ($methods === [] || $args === []) {
            throw new RuleFileException("$at: allow <METHODS> <paths> (allow POST /contact /edit/**)");
        }
        $patterns = $this->compile($args, $at, false);
        foreach ($methods as $m) {
            $list = $this->get("methodPaths.$m");
            $list = is_array($list) ? $list : [];
            foreach ($patterns as $pattern => $origin) {
                $pattern .= 'i';
                if (!in_array($pattern, $list, true)) {
                    $list[] = $pattern;
                }
            }
            $this->put("methodPaths.$m", $list);
            $this->origins['methodPaths'][$m] = $this->rid;
            $all = (array) $this->get('methods');
            if (!in_array($m, $all, true)) {
                $all[] = $m;
                $this->put('methods', $all);
            }
        }
    }

    private static function address(string $ip, string $at): string
    {
        $addr = explode('/', $ip, 2);
        if (@inet_pton($addr[0]) === false || (isset($addr[1]) && !ctype_digit($addr[1]))) {
            throw new RuleFileException("$at: \"$ip\" is not an address or a range (192.0.2.0/24, 2001:db8::/32)");
        }
        return $ip;
    }

    /** @param list<string> $args  <name> <n>/<sec|min|hour|day> [challenge-at <n>] [on-demand] */
    private function limit(array $args, string $at): void
    {
        $usage = 'limit <name> <n>/<sec|min|hour|day> [challenge-at <n>] [on-demand] [on-exceeded challenge|throttle]';
        $name = array_shift($args);
        $rate = array_shift($args);
        if ($name === null || $rate === null || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new RuleFileException("$at: $usage");
        }
        if (!preg_match('~^(\d+)/(\d*)(s|sec|second|m|min|minute|h|hour|d|day)s?$~', $rate, $m)) {
            throw new RuleFileException("$at: \"$rate\" is not a rate like 600/min or 20/10s");
        }
        $unit = ['s' => 1, 'sec' => 1, 'second' => 1, 'm' => 60, 'min' => 60, 'minute' => 60,
            'h' => 3600, 'hour' => 3600, 'd' => 86400, 'day' => 86400][$m[3]];
        $budget = ['limit' => (int) $m[1], 'window' => max(1, (int) ($m[2] === '' ? 1 : $m[2])) * $unit, 'challengeAt' => null, 'onDemand' => false];
        while ($args !== []) {
            $a = array_shift($args);
            if ($a === 'on-demand') {
                $budget['onDemand'] = true;
            } elseif ($a === 'on-exceeded' && in_array($args[0] ?? '', ['challenge', 'throttle'], true)) {
                $budget['onExceeded'] = (string) array_shift($args);
            } elseif ($a === 'challenge-at' && isset($args[0]) && ctype_digit($args[0])) {
                $budget['challengeAt'] = (int) array_shift($args);
            } else {
                throw new RuleFileException("$at: \"$a\" -- $usage");
            }
        }
        if ($this->siteOpen !== null) {
            // Written in a site block: counted on that website only (base budgets count across all).
            $budget['site'] = $this->siteOpen['id'];
        }
        $this->put("budgets.$name", $budget);
        $this->origins['budgets'][$name] = $this->rid;
    }

    private function set(string $line, string $at, string $file): void
    {
        // The value is the rest of the line: texts have spaces.
        $parts = preg_split('/\s+/', $line, 3) ?: [];
        $key = strtolower($parts[1] ?? '');
        $value = $this->env(trim($parts[2] ?? ''), $at);
        if ($key === '' || $value === '') {
            throw new RuleFileException("$at: set <key> <value>");
        }
        // text.<key> for every language, text.<lang>.<key> for one.
        if (strncmp($key, 'text.', 5) === 0) {
            $rest = substr($key, 5);
            $dot = strrpos($rest, '.');
            $lang = $dot === false ? null : substr($rest, 0, $dot);
            $text = $dot === false ? $rest : substr($rest, $dot + 1);
            if ($text === 'lang') {
                return;                 // an old setting: the language is chosen now
            }
            if (!in_array($text, Texts::KEYS, true)) {
                throw new RuleFileException("$at: unknown text \"$text\" (there are " . implode(', ', Texts::KEYS) . ')');
            }
            if ($lang !== null && !preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $lang)) {
                throw new RuleFileException("$at: \"$lang\" is not a language code (de, en, fr, de-at)");
            }
            // Stored flat ("de.title"): the settings keep one list of texts.
            $texts = (array) $this->get('challenge.texts');
            $texts[($lang !== null ? "$lang." : '') . $text] = $value;
            $this->put('challenge.texts', $texts);
            return;
        }
        if (!isset(self::SET[$key])) {
            throw new RuleFileException("$at: unknown setting \"$key\"" . self::suggest($key, array_merge(array_keys(self::SET), ['text.title'])));
        }
        [$path, $type] = self::SET[$key];
        switch ($type) {
            case 'bool':
                $v = ['on' => true, 'yes' => true, 'true' => true, 'off' => false, 'no' => false, 'false' => false][strtolower($value)] ?? null;
                if ($v === null) {
                    throw new RuleFileException("$at: $key is on or off, not \"$value\"");
                }
                break;
            case 'int':
                if (!preg_match('/^\d+$/', $value)) {
                    throw new RuleFileException("$at: $key is a number, not \"$value\"");
                }
                $v = (int) $value;
                break;
            case 'seconds':
                $v = self::seconds($value, $key, $at);
                break;
            case 'language':
                $v = strtolower($value);
                if ($v !== 'auto' && !preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $v)) {
                    throw new RuleFileException("$at: language is auto or a code (de, en, fr, de-at), not \"$value\"");
                }
                break;
            case 'loglevel':
                if (!in_array($value, \CjwNetwork\RequestShield\Log::LEVELS, true)) {
                    throw new RuleFileException("$at: log-level is " . implode(', ', \CjwNetwork\RequestShield\Log::LEVELS) . ", not \"$value\"");
                }
                $v = $value;
                break;
            case 'verify':
                if (!in_array($value, ['both', 'ranges', 'dns'], true)) {
                    throw new RuleFileException("$at: crawler-verify is both, ranges (the published address lists only: no DNS, for a DMZ) or dns, not \"$value\"");
                }
                $v = $value;
                break;
            case 'sitefrom':
                if (!in_array($value, ['server-name', 'host'], true)) {
                    throw new RuleFileException("$at: site-from is server-name (the name the web server answers as -- the safe default) or host (the Host header), not \"$value\"");
                }
                $v = $value;
                break;
            case 'mode':
                if (!in_array($value, \CjwNetwork\RequestShield\Settings::MODES, true)) {
                    throw new RuleFileException("$at: mode is " . implode(', ', \CjwNetwork\RequestShield\Settings::MODES) . ", not \"$value\"");
                }
                $v = $value;
                break;
            case 'logip':
                if ($value !== 'masked' && $value !== 'full') {
                    throw new RuleFileException("$at: log-ip is masked or full, not \"$value\"");
                }
                $v = $value;
                break;
            case 'bytes':
                if (!preg_match('/^(\d+)([kmg])?b?$/i', $value, $m)) {
                    throw new RuleFileException("$at: $key is a size (10M, 500K), not \"$value\"");
                }
                $v = (int) $m[1] * ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2] ?? '')];
                break;
            case 'stats':
                // on, off, or the parts: set stats requests crawlers
                $words = preg_split('/\s+/', strtolower($value)) ?: [];
                if ($words === ['on'] || $words === ['off']) {
                    $this->put('stats.enabled', $words === ['on']);
                    return;
                }
                foreach ($words as $w) {
                    if (!in_array($w, \CjwNetwork\RequestShield\Settings::STATS_PARTS, true)) {
                        throw new RuleFileException("$at: stats is on, off or what to count: " . implode(', ', \CjwNetwork\RequestShield\Settings::STATS_PARTS) . " -- not \"$w\"");
                    }
                }
                $this->put('stats.enabled', true);
                $this->put('stats.parts', $words);
                return;
            case 'kinds':
                $v = preg_split('/\s+/', $value) ?: [];
                foreach ($v as $k) {
                    if (!in_array($k, self::KINDS, true)) {
                        throw new RuleFileException("$at: $key takes kinds of crawler (" . implode(', ', self::KINDS) . "), not \"$k\"");
                    }
                }
                break;
            case 'path':
                // Relative to the rule file it is written in.
                $v = $value[0] === '/' ? $value : dirname($file) . '/' . $value;
                break;
            case 'store':
                if (!in_array($value, ['auto', 'apcu', 'file', 'memory'], true)) {
                    throw new RuleFileException("$at: store is auto, apcu, file or memory, not \"$value\"");
                }
                $v = $value;
                break;
            case 'logo':
                // Relative to the rule file; checked now, so a refused logo names its line.
                $v = $value[0] === '/' ? $value : dirname($file) . '/' . $value;
                try {
                    \CjwNetwork\RequestShield\Challenge\ChallengeLogo::load($v, $key);
                } catch (\InvalidArgumentException $e) {
                    throw new RuleFileException("$at: " . $e->getMessage());
                }
                $stat = self::stat($v);
                if ($stat !== null) {
                    $this->seen[$v] = $stat;        // a changed logo is noticed like a changed rule file
                }
                break;
            default:
                $v = $value;
        }
        $this->put($path, $v);
    }

    /**
     * ${NAME}: an environment variable, so a secret need not be in the file;
     * ${NAME:-default} when it may be unset. The values used are recorded:
     * the compiled settings are rebuilt when one changes.
     */
    private function env(string $value, string $at): string
    {
        if (strpos($value, '${') === false) {
            return $value;
        }
        return (string) preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}/', function (array $m) use ($at): string {
            $v = getenv($m[1]);
            $this->env[$m[1]] = is_string($v) ? $v : null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
            if (isset($m[2])) {
                return $m[2];
            }
            throw new RuleFileException("$at: the environment variable $m[1] is not set");
        }, $value);
    }

    /**
     * crawler <kind> ua /<pattern>/ [dns <suffixes>] [ranges <lists>]: a
     * crawler that behaves, verified by where it comes from -- or
     * crawler <ID> <policy>: what the site does with one.
     *
     * @param list<string> $args
     */
    private function crawler(array $args, string $at, string $file): void
    {
        if (count($args) === 2 && in_array($args[1], self::POLICIES, true)) {
            $id = trim($args[0], '[]');
            if (!isset($this->crawlers()[$id])) {
                throw new RuleFileException("$at: crawler $id -- no crawler has that ID (they are in rules/crawlers.rules: CRAWL-GOOGLE, CRAWL-GPTBOT, …)");
            }
            $this->crawlerPolicy($id, $args[1]);
            return;
        }
        $usage = 'crawler <kind> ua /<pattern>/ [dns <host suffixes>] [ranges <lists>] -- or crawler <ID> allow|check|block';
        $kind = array_shift($args);
        if ($kind === null || !in_array($kind, self::KINDS, true)) {
            throw new RuleFileException("$at: $usage (kinds: " . implode(', ', self::KINDS) . ')');
        }
        $ua = null;
        $dns = [];
        $lists = [];
        $part = null;
        foreach ($args as $a) {
            if (in_array($a, ['ua', 'dns', 'ranges'], true)) {
                $part = $a;
                continue;
            }
            if ($part === 'ua' && $ua === null && preg_match('#^/(.+)/$#', $a, $m)) {
                $ua = Pattern::fromRegex($m[1]) . 'i';
                if (!Pattern::valid($ua)) {
                    throw new RuleFileException("$at: \"$a\" is not a valid regular expression");
                }
            } elseif ($part === 'dns' && preg_match('/^\.[a-z0-9.-]+[a-z]$/', strtolower($a))) {
                $dns[] = strtolower($a);
            } elseif ($part === 'ranges' && preg_match('/^[a-z0-9._-]+$|\//', $a)) {
                // A shipped list's name (rules/crawlers/<name>.json), or a file of the site's own (….json).
                $own = strpos($a, '/') !== false || substr($a, -5) === '.json';
                $path = !$own ? dirname(__DIR__, 2) . "/rules/crawlers/$a.json" : ($a[0] === '/' ? $a : dirname($file) . '/' . $a);
                if (!is_file($path)) {
                    throw new RuleFileException("$at: no address list \"$a\" (" . (!$own ? 'rules/crawlers/' . $a . '.json' : $path) . ')');
                }
                $lists[$own ? $path : $a] = $path;
            } else {
                throw new RuleFileException("$at: $usage -- \"$a\" does not fit" . ($part === 'dns' ? ' (a suffix starts with a dot: .googlebot.com)' : ''));
            }
        }
        if ($ua === null || ($dns === [] && $lists === [])) {
            throw new RuleFileException("$at: $usage -- a User-Agent pattern and a way to verify it (dns or ranges): the name alone proves nothing");
        }
        $crawlers = $this->crawlers();
        $crawlers[$this->rid] = ['kind' => $kind, 'ua' => $ua, 'dns' => $dns, 'lists' => $lists, 'ranges' => []];
        $this->c['crawlers'] = $crawlers;
        $this->origins['crawlers'][$this->rid] = $this->rid;
    }

    /** @return array<string, array{kind: string, ua: string, dns: list<string>, lists: array<string, mixed>, ranges: list<string>}> */
    private function crawlers(): array
    {
        /** @var array<string, array{kind: string, ua: string, dns: list<string>, lists: array<string, mixed>, ranges: list<string>}> */
        return is_array($this->c['crawlers'] ?? null) ? $this->c['crawlers'] : [];
    }

    private function crawlerPolicy(string $key, string $policy): void
    {
        $policies = is_array($this->c['crawlerPolicy'] ?? null) ? $this->c['crawlerPolicy'] : [];
        $policies[$key] = $policy;
        $this->c['crawlerPolicy'] = $policies;
        $this->origins['crawlerPolicy'][$key] = $this->rid;
    }

    /**
     * The address lists of the crawlers, read now (compiled with the
     * settings): the shipped ones, or newer ones "crawlers update" put into
     * store-dir.
     */
    private function resolveLists(): void
    {
        $dir = is_string($this->c['storeDir'] ?? null) ? $this->c['storeDir'] . '/crawlers' : null;
        if ($dir !== null) {
            $stat = self::stat($dir);
            if ($stat !== null) {
                $this->seen[$dir] = $stat;          // a list updated there is noticed
            }
        }
        $read = [];
        $crawlers = $this->crawlers();
        foreach ($crawlers as $id => $crawler) {
            $ranges = [];
            $about = [];
            foreach ($crawler['lists'] as $name => $path) {
                $name = (string) $name;
                $read[$name] ??= CrawlerLists::read(is_string($path) ? $path : '', $dir !== null && strpos($name, '/') === false ? "$dir/$name.json" : null);
                foreach ($read[$name]['files'] as $f) {
                    $stat = self::stat($f);
                    if ($stat !== null) {
                        $this->seen[$f] = $stat;
                    }
                }
                array_push($ranges, ...$read[$name]['prefixes']);
                $about[$name] = ['source' => $read[$name]['source'], 'created' => $read[$name]['created'], 'fetched' => $read[$name]['fetched'], 'from' => $read[$name]['from']];
            }
            $crawlers[$id]['ranges'] = array_values(array_unique($ranges));
            $crawlers[$id]['lists'] = $about;
        }
        if ($crawlers !== []) {
            $this->c['crawlers'] = $crawlers;
        }
    }

    /** 300, 30s, 5m, 1h, 1d as seconds. */
    private static function seconds(string $value, string $key, string $at): int
    {
        if (!preg_match('/^(\d+)(s|m|h|d)?$/', $value, $m)) {
            throw new RuleFileException("$at: $key is a duration (300, 30s, 5m, 1h, 1d), not \"$value\"");
        }
        return (int) $m[1] * ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2] ?? ''];
    }

    /** @return mixed */
    private function get(string $path)
    {
        $v = $this->c;
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return null;
            }
            $v = $v[$k];
        }
        return $v;
    }

    /** @param mixed $value */
    private function put(string $path, $value): void
    {
        $ref = &$this->c;
        foreach (explode('.', $path) as $k) {
            if (!is_array($ref)) {
                $ref = [];
            }
            $ref = &$ref[$k];
        }
        $ref = $value;
    }

    /** @param list<string> $known */
    private static function suggest(string $word, array $known): string
    {
        $best = null;
        $distance = 3;
        foreach ($known as $k) {
            $d = levenshtein($word, $k);
            if ($d < $distance) {
                [$best, $distance] = [$k, $d];
            }
        }
        return $best === null ? '' : " (did you mean \"$best\"?)";
    }
}
