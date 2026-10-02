<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Challenge\Crawlers;
use CjwNetwork\RequestShield\Challenge\Gate;
use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\Rule\BanRule;
use CjwNetwork\RequestShield\Rule\BlockedPathRule;
use CjwNetwork\RequestShield\Rule\BudgetRule;
use CjwNetwork\RequestShield\Rule\CacheableRule;
use CjwNetwork\RequestShield\Rule\ContentRule;
use CjwNetwork\RequestShield\Rule\CrawlerRule;
use CjwNetwork\RequestShield\Rule\DenyRule;
use CjwNetwork\RequestShield\Rule\FeedRule;
use CjwNetwork\RequestShield\Rule\HostRule;
use CjwNetwork\RequestShield\Rule\LimitsRule;
use CjwNetwork\RequestShield\Rule\MethodPathRule;
use CjwNetwork\RequestShield\Rule\MethodRule;
use CjwNetwork\RequestShield\Rule\PathSanityRule;
use CjwNetwork\RequestShield\Rule\QueryRule;
use CjwNetwork\RequestShield\Rule\RestrictedPathRule;
use CjwNetwork\RequestShield\Rule\Rule;
use CjwNetwork\RequestShield\Store\ApcuStore;
use CjwNetwork\RequestShield\Store\FileStore;
use CjwNetwork\RequestShield\Store\MemoryStore;
use CjwNetwork\RequestShield\Store\Store;

/**
 * The request shield: runs before the application and decides whether a
 * request reaches it, and whether its answer may be cached.
 *
 *   CjwNetwork\RequestShield\Shield::protect( require 'request-shield.php' );
 *
 * The checks run cheapest first -- method, sizes, path sanity, host, blocked
 * paths -- and stop at the first rejection, so a scanner's request costs a
 * few string comparisons. The cacheable definition and the budgets follow;
 * a request the budgets want challenged then goes through the gate.
 */
final class Shield
{
    /** @var list<Rule> */
    private array $rules = [];

    /** @readonly */

    public Settings $settings;

    private Store $store;

    private static ?Decision $current = null;

    private static ?string $rule = null;

    private static ?self $active = null;

    /** The request protect() decided about. */
    private ?Request $request = null;

    /** Whether this request has passed the browser check (a pass, or a solution just now). */
    private bool $passed = false;

    /** Forms larger than this are not carried through the check (the visitor sends them again). */
    private const RESEND_MAX_BYTES = 262144;

    /** What decide() found without the budgets: whether the answer may be cached. */
    private Decision $base;

    /** The max-age of the always-checked path this request is on (challenge … max-age), or null. */
    private ?int $alwaysAge = null;

    /** The rules with those marked "monitor", decided alongside for the log (built when needed). */
    private ?self $watcher = null;

    /**
     * @param array<mixed>|Settings $config see Config::defaults()
     * @param (callable(Request): ?bool)|null $known an adapter's URL index (see CacheableRule)
     * @throws \InvalidArgumentException for a setting of the wrong type
     */
    public function __construct(array|Settings $config = [], ?Store $store = null, ?callable $known = null)
    {
        $s = $this->settings = $config instanceof Settings ? $config : Settings::from($config);
        $this->store = $store ?? self::storeFor($s);
        $this->base = Decision::allow();
        if ($s->mode === 'off') {
            return;             // no checks at all
        }

        // Kept out (the deny list), then banned for a while: before anything else.
        if ($s->denyTable !== []) {
            $this->rules[] = new DenyRule($s->denyTable);
        }
        if (isset($s->feedTables['deny']) || self::hasAt($s, 'deny')) {
            $this->rules[] = new FeedRule(\Closure::fromCallable([$this, 'feedHit']));
        }
        if ($s->bans !== [] && $s->mode !== 'monitor') {
            if ($s->banKeep === 'file' && $this->store instanceof ApcuStore) {
                self::restoreBans($s, $this->store);
            }
            $this->rules[] = new BanRule($this->store, $s->exemptIps, $s->ipv6Prefix);
        }
        $this->rules[] = new MethodRule($s->methods);
        $this->rules[] = new LimitsRule($s->maxUri, $s->maxQueryParameters, $s->maxHeaderBytes);
        $this->rules[] = new PathSanityRule();
        if ($s->hosts !== []) {
            $this->rules[] = new HostRule($s->hosts);
        }
        $this->rules[] = new BlockedPathRule($s->blockedPaths, $s->blockExceptions, $s->blockedIndex);
        if ($s->methodPaths !== []) {
            $this->rules[] = new MethodPathRule($s->methodPaths);
        }
        if ($s->restricted !== []) {
            $this->rules[] = new RestrictedPathRule($s->restricted);
        }
        // Known crawlers the site refuses: only when there are such.
        foreach ($s->crawlers as $x) {
            if ($x['policy'] === 'block') {
                $this->rules[] = new CrawlerRule($this->crawlers());
                break;
            }
        }
        // Known parameters before the attack patterns: cheaper, and they say
        // which values the patterns need to look at.
        if ($s->queryParams !== [] || $s->queryStrict) {
            $this->rules[] = new QueryRule($s->queryIndex, $s->queryStrict);
        }
        if ($s->contentIndex !== []) {
            $this->rules[] = new ContentRule($s->contentIndex, $s->contentRules, $s->blockExceptions, $s->contentHints);
        }
        $this->rules[] = new CacheableRule($s->cacheablePaths, $s->cacheableQuery, $known);
        foreach ($s->budgets as $budget) {
            if (!$budget->onDemand) {
                $this->rules[] = $this->budgetRule($budget);
            }
        }
    }

    /**
     * protect() with the settings of a file, checked only when it changed
     * (see Settings::load()): the way to run the shield on every request.
     * A ".rules" file is a rule file; $sources are further rule files or
     * globs read before it -- an adapter's, for its extensions.
     *
     * @param (callable(Request): ?bool)|null $known
     * @param list<string> $sources
     */
    public static function protectFile(string $file, ?callable $known = null, ?string $cacheDir = null, array $sources = []): Decision
    {
        // With site blocks (rules per website): the settings of this request's website.
        return self::protect(Settings::loadFor($file, $_SERVER, $cacheDir, $sources), $known);
    }

    /**
     * Decides about the request in $_SERVER, answers it itself when the
     * application must not run (and ends the request), and returns the
     * decision otherwise. Also Shield::current() and
     * $_SERVER['REQUEST_SHIELD'] ("allow" or "allow-uncached") afterwards.
     *
     * An array is checked on every call; protectFile() checks it once.
     *
     * @param array<mixed>|Settings $config
     * @param (callable(Request): ?bool)|null $known
     */
    public static function protect(array|Settings $config = [], ?callable $known = null): Decision
    {
        $shield = new self($config, null, $known);
        $s = $shield->settings;
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        $request = Request::fromServer($server, $s->trustedProxies);
        $shield->request = $request;
        self::$active = $shield;
        if ($s->mode === 'off') {
            // Switched off: nothing checked, counted or logged; the include stays.
            self::$current = Decision::allow();
            self::$rule = null;
            $_SERVER['REQUEST_SHIELD'] = Decision::ALLOW;
            return self::$current;
        }
        $now = microtime(true);
        $decided = $shield->decide($request, $now);
        // The endpoint of the check inside the form: answered here, before the
        // application -- after the checks, so budgets count it and a refused
        // client stays refused; a challenged one gets its task (that is what
        // it asks for).
        $w = $s->challenge->widgetPath;
        if ($w !== null && strncmp($request->path, $w . '/', strlen($w) + 1) === 0
            && ($decided->passes() || $decided->action === Decision::CHALLENGE)) {
            $shield->serveWidget(substr($request->path, strlen($w) + 1), $request, $now);
            exit;
        }
        $settled = $shield->settle($decided, $request, $now);
        $decision = $settled['decision'];
        $shield->passed = $settled['passed'];
        if (!$decision->passes() && ($s->bans !== [] || ($s->monitor !== null && $s->monitor->bans !== []))) {
            $shield->signals($decision, $request, $now);         // what may lead to a ban: only when refused, slowed or checked
        }
        // Which rule: looked up only for a request that was stopped or flagged
        // (or when every request is logged) -- a passing one costs nothing.
        $rule = null;
        $watched = null;
        $would = null;
        if ($s->mode === 'monitor' && !$decision->passes()) {
            $would = $decision;
            // Monitor: decided and counted, logged as it would be -- and let through,
            // never cached (whatever it is, it is not what a cache should keep).
            $watched = $shield->watched($decision, $request, $now, $shield);
            $decision = Decision::allowUncached('monitor');
            $settled = ['decision' => $decision, 'cookies' => $settled['cookies'], 'page' => null, 'json' => null, 'passed' => false];
        } elseif ($decision->action !== Decision::ALLOW || $s->logLevel === 'all') {
            $rule = $shield->explain($decision, $request);
            Log::note($s, $request, $decision, $rule, $now);
        }
        if ($s->monitor !== null && $decision->passes() && $watched === null) {
            // Rules marked "monitor": what they would decide, for the log.
            $watched = $shield->watch($request, $now);
        }
        self::$current = $decision;
        self::$rule = $rule;
        if ($shield->plugins() !== []) {
            // The plugins (the statistics, …): told now, and -- for a request that
            // goes on to the site -- again when it has ended, with the site's status.
            $shield->record($request, $decision, $rule, $now, $would, $decision->passes());
        }
        if (!headers_sent()) {
            foreach ($settled['cookies'] as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }

        if (!$request->viaTrustedProxy && $s->stripUntrustedForwarded) {
            foreach (array_keys($_SERVER) as $name) {
                if (is_string($name) && strncmp($name, 'HTTP_X_FORWARDED_', 17) === 0) {
                    unset($_SERVER[$name]);
                }
            }
            unset($_SERVER['HTTP_FORWARDED']);
        }

        if (!$decision->passes() && ($settled['json'] ?? null) !== null) {
            echo (new Responder())->api($decision, $settled['json'], $s->debugHeader, $rule);
            exit;
        }
        if (!$decision->passes()) {
            $c = $s->challenge;
            (new Responder())->send($decision, $request, $s->debugHeader, $settled['page'], $rule,
                Texts::all(Texts::language($c->language, $request->header('accept-language'), $c->texts), $c->texts), $c->home);
            exit;
        }
        if ($s->appChallenge && ($request->method === 'GET' || $request->method === 'HEAD')) {
            $shield->watchForChallengeHeader();
        }
        $_SERVER['REQUEST_SHIELD'] = $decision->action;
        if ($rule !== null) {
            $_SERVER['REQUEST_SHIELD_RULE'] = $rule;
        }
        if ($s->debugHeader && !headers_sent()) {
            header('X-Request-Shield: ' . ($watched !== null && $s->mode === 'monitor' ? 'monitor ' . $watched
                : $decision->action . ($decision->reason !== '' ? ' ' . $decision->reason : '') . ($rule !== null ? '; rule=' . $rule : '')));
            if ($watched !== null && $s->mode !== 'monitor') {
                header('X-Request-Shield-Monitor: ' . $watched);
            }
        }
        return $decision;
    }

    /**
     * What the rules marked "monitor" would decide about a request the
     * enforced rules let through: logged, never acted on. Returns it in
     * words ("reject blocked path; rule=SITE-OLD") or null when they would
     * let it through too.
     */
    private function watch(Request $request, float $now): ?string
    {
        $w = $this->watcher($request);
        if ($w === null) {
            return null;
        }
        $d = $w->decide($request, $now);
        if ($d->passes()) {
            return null;
        }
        // A check the visitor has passed already (a pass cookie) is no refusal.
        $d = $w->settle($d, $request, $now)['decision'];
        return $d->passes() ? null : $this->watched($d, $request, $now, $w);
    }

    /**
     * Tells the plugins what was decided (the statistics: counted for the
     * dashboard, a crawler's log -- see StatsPlugin). protect() calls it; code
     * that runs decide() and settle() itself calls it after them. Without a
     * plugin it costs nothing.
     *
     * @param Decision|null $would what monitor mode would have done
     * @param bool $atEnd the request goes on to the site: the plugins hear
     *   again when it ends, with the status the site answered
     */
    public function record(Request $request, Decision $decision, ?string $rule, float $now, ?Decision $would = null, bool $atEnd = false): void
    {
        $plugins = $this->plugins();
        if ($plugins === []) {
            return;
        }
        $seen = new Seen($request, $this);
        foreach ($plugins as $plugin) {
            try {
                $plugin->decided($request, $decision, $rule, $seen, $now, $atEnd, $would);
            } catch (\Throwable $e) {
                self::pluginFailed($plugin, $e);
            }
        }
        if (!$atEnd) {
            return;
        }
        // The site answers: when it is done, the plugins hear its status.
        register_shutdown_function(static function () use ($plugins, $request, $seen, $now): void {
            $status = http_response_code();
            $headers = headers_list();
            foreach ($plugins as $plugin) {
                try {
                    $plugin->ended($request, is_int($status) && $status > 0 ? $status : 0, $headers, $seen, $now);
                } catch (\Throwable $e) {
                    self::pluginFailed($plugin, $e);
                }
            }
        });
    }

    /**
     * The signals a decision gives for the bans: past a limit (a pause, a spent
     * check), a refusal for what only attackers ask for, a check page shown.
     */
    private function signals(Decision $d, Request $request, float $now): void
    {
        if ($d->action === Decision::THROTTLE && $d->reason !== 'banned') {
            $this->signal('limits', $request, $now);
        } elseif ($d->action === Decision::CHALLENGE) {
            $this->signal('checks', $request, $now);
            if ($d->spent) {
                $this->signal('limits', $request, $now);
            }
        } elseif ($d->action === Decision::REJECT && ($d->reason === 'blocked path' || $d->reason === 'attack')) {
            $this->signal('refusals', $request, $now);
        }
    }

    /** One signal: counted for every ban that names it; past its number, the ban (or, watched, a line in the log). */
    private function signal(string $name, Request $request, float $now): void
    {
        $s = $this->settings;
        $enforced = $s->mode === 'monitor' ? [] : $s->bans;
        $rules = array_column($enforced, 'rule');
        // Watched: every ban in monitor mode, and those marked "monitor ban".
        $watched = $s->mode === 'monitor' ? $s->bans : [];
        foreach ($s->monitor !== null ? $s->monitor->bans : [] as $b) {
            if (!in_array($b['rule'], $rules, true) && !in_array($b, $watched, true)) {
                $watched[] = $b;
            }
        }
        foreach ([[$enforced, false], [$watched, true]] as [$bans, $watch]) {
            foreach ($bans as $b) {
                if ($b['signal'] === $name) {
                    $this->countBan($b, $request, $now, $watch);
                }
            }
        }
    }

    /**
     * A signal for one ban: past its number in its window, the client is
     * banned -- longer each time within a day (ban-growth), at most ban-max.
     * Never an address let in (exempt), a trusted proxy or a verified crawler.
     *
     * @param array{after: int, signal: string, in: int, for: int, rule: string} $b
     */
    private function countBan(array $b, Request $request, float $now, bool $watch): void
    {
        $s = $this->settings;
        $ip = $request->clientIp;
        if (IpAddress::inRanges($ip, $s->exemptIps) || IpAddress::inRanges($ip, $s->trustedProxies)) {
            return;
        }
        $bucket = IpAddress::bucket($ip, $s->ipv6Prefix);
        // On a public blocklist named "ban-signal <n>": one signal counts n times.
        $weight = 1;
        if ($s->feedWeights !== [] && ($feed = $this->feedHit($request, 'signal')) !== null) {
            $weight = $s->feedWeights[$feed] ?? 1;
        }
        $count = 0.0;
        for ($i = 0; $i < $weight; $i++) {
            $count = $this->store->hit(($watch ? 'monitor:' : '') . 'ban:' . $b['rule'] . ':' . $bucket, $b['in'], $now);
        }
        if ($count < $b['after']) {
            return;
        }
        $id = $s->crawlers === [] ? null : $this->crawlers()->claims((string) $request->header('user-agent'));
        if ($id !== null && $this->crawlers()->verified($ip, $id)) {
            return;                                         // a verified crawler gets the pause it understands, never a ban
        }
        if ($watch) {
            Log::note($s, $request, Decision::throttle('banned', $b['for']), $b['rule'], $now, true);
            return;
        }
        $offences = (int) round($this->store->hit('bans:' . $bucket, 86400, $now));
        $duration = (int) min($s->banMax, $b['for'] * $s->banGrowth ** max(0, min($offences - 1, 30)));
        $until = (int) $now + $duration;
        if ($this->store->marked('ban:' . $bucket, $now) >= $until) {
            return;                                         // banned longer already
        }
        $this->store->mark('ban:' . $bucket, $until, $now);
        if ($s->banKeep === 'file' && !$this->store instanceof FileStore) {
            (new FileStore($s->storeDir))->mark('ban:' . $bucket, $until, $now);     // survives a restart of APCu
        }
        Log::note($s, $request, Decision::throttle('banned', $duration), $b['rule'], $now);
    }

    /**
     * set ban-keep file: after a restart of APCu (empty), the first request
     * copies the bans still running from their files back -- once: apcu_add()
     * lets one process do it. Every other request: that one call.
     */
    private static function restoreBans(Settings $s, Store $store): void
    {
        if (!function_exists('apcu_add') || !apcu_add('rshield-bans-restored:' . hash('crc32b', $s->storeDir), 1)) {
            return;
        }
        $now = microtime(true);
        foreach ((new FileStore($s->storeDir))->marks('ban:', $now) as $key => $until) {
            if ($store->marked($key, $now) < $until) {
                $store->mark($key, $until, $now);
            }
        }
    }

    /**
     * Lifts a ban at once, wherever it is kept: the store and, with
     * ban-keep file, its file. False when the address is not banned.
     */
    public static function liftBan(Settings $s, Store $store, string $bucket, ?float $now = null): bool
    {
        $now ??= microtime(true);
        $key = 'ban:' . $bucket;
        $was = $store->marked($key, $now) > 0;
        $store->mark($key, 0, $now);
        if ($s->banKeep === 'file' && !$store instanceof FileStore) {
            $file = new FileStore($s->storeDir);
            $was = $was || $file->marked($key, $now) > 0;
            $file->mark($key, 0, $now);
        }
        return $was;
    }

    /** @var list<Plugin>|null */
    private ?array $plugins = null;

    /**
     * The plugins of these settings, made once: those the rules name ("plugin
     * …"), and the statistics when "set stats" or "set crawler-log" is on. A
     * class that is missing or no Plugin is left out (and logged).
     *
     * @return list<Plugin>
     */
    public function plugins(): array
    {
        if ($this->plugins !== null) {
            return $this->plugins;
        }
        $s = $this->settings;
        $classes = $s->plugins;
        if (($s->statsEnabled || $s->crawlerLogDir !== null) && !in_array(StatsPlugin::class, $classes, true)) {
            $classes[] = StatsPlugin::class;
        }
        $made = [];
        foreach ($classes as $class) {
            try {
                $plugin = class_exists($class) && is_subclass_of($class, Plugin::class) ? new $class($s) : null;
            } catch (\Throwable $e) {
                $plugin = null;
            }
            if ($plugin instanceof Plugin) {
                $made[] = $plugin;
            } else {
                self::pluginFailed($class, null);
            }
        }
        return $this->plugins = $made;
    }

    /** @var array<string, int> plugin => when its failure was last noted */
    private static array $failed = [];

    /** A plugin that failed: noted in PHP's error log, once a minute per plugin and process -- the visitor never sees it. */
    private static function pluginFailed(Plugin|string $plugin, ?\Throwable $e): void
    {
        $name = is_string($plugin) ? $plugin : get_class($plugin);
        if (time() - (self::$failed[$name] ?? 0) < 60) {
            return;
        }
        self::$failed[$name] = time();
        error_log('request-shield: plugin ' . (is_string($plugin) ? $plugin . ' is missing or no ' . Plugin::class : get_class($plugin) . ' failed: ' . ($e !== null ? $e->getMessage() : '?')));
    }

    /** The shield of the rules marked "monitor" (null: there are none), built once. */
    private function watcher(Request $request): ?self
    {
        $m = $this->settings->monitor;
        if ($m === null) {
            return null;
        }
        if ($this->watcher === null) {
            // Their own budgets are counted; those the enforced rules have, only looked at.
            $own = array_fill_keys(array_keys(array_diff_key($m->budgets, $this->settings->budgets)), true);
            $this->watcher = new self($m, new \CjwNetwork\RequestShield\Store\MonitorStore($this->store, $own));
            $this->watcher->request = $request;
        }
        return $this->watcher;
    }

    /** Logs what would have been decided ("monitor-reject …") and returns it in words for the header. */
    private function watched(Decision $d, Request $request, float $now, self $by): string
    {
        $s = $this->settings;
        $rule = $by->explain($d, $request);
        Log::note($s, $request, $d, $rule, $now, true);
        return $d->action . ($d->reason !== '' ? ' ' . $d->reason : '') . ($rule !== null ? '; rule=' . $rule : '');
    }

    /** The decision protect() made for this request, if it ran. */
    public static function current(): ?Decision
    {
        return self::$current;
    }

    /**
     * The shield protect() ran with, to count on-demand budgets against the
     * same settings and request: Shield::active()?->consume('searches').
     */
    public static function active(): ?self
    {
        return self::$active;
    }

    /** The rule behind current(), when it was not a plain "allow" (see explain()). */
    public static function currentRule(): ?string
    {
        return self::$rule;
    }

    /**
     * The rule behind a decision: where it was written ("site.rules:12",
     * "default @scanners.backups"), the setting for PHP array settings
     * ("blockedPaths[3]"), "built-in" for the checks every site has
     * (sizes, path encoding), or null when no single rule decided (allow).
     * Runs the matching again, so it is meant for decisions that stopped or
     * flagged a request, not for every one.
     */
    public function explain(Decision $d, Request $request): ?string
    {
        $s = $this->settings;
        $name = static function (string $setting, string $what, string $fallback) use ($s): string {
            $origin = $s->origin($setting, $what) ?? $fallback;
            return preg_replace('/[^\x21-\x7e ]/', '?', $origin) ?? $fallback;
        };
        $first = static function (array $patterns, string $path): ?int {
            foreach ($patterns as $i => $p) {
                if (is_string($p) && @preg_match($p, $path) === 1) {
                    return (int) $i;
                }
            }
            return null;
        };
        switch ($d->reason) {
            case 'feed':
                return $this->feedHit($request, $d->action === Decision::CHALLENGE ? 'check' : 'deny');
            case 'denied':
                // The entry of the deny list that holds the address.
                $id = $s->denyTable === [] ? null : IpTable::find($request->clientIp, $s->denyTable);
                return $id === null ? 'deny' : (preg_replace('/[^\x21-\x7e ]/', '?', $id) ?? 'deny');
            case 'blocked path':
                $i = null;
                foreach ($s->blockedPaths as $n => $p) {
                    if (@preg_match($p, strtolower(rawurldecode($request->path))) === 1 && BlockedPathRule::excepted($s->blockExceptions, $p, $request) === null) {
                        $i = $n;
                        break;
                    }
                }
                return $i === null ? null : $name('blockedPaths', $s->blockedPaths[$i], Config::setName($s->blockedPaths[$i]) ?? "blockedPaths[$i]");
            case 'restricted':
                foreach ($s->restricted as $n => $r) {
                    $i = $first($r['paths'], $request->matchPath());
                    if ($i !== null) {
                        return $name('restricted', $r['paths'][$i], "restricted[$n]");
                    }
                }
                return null;
            case 'method not allowed here':
                return $s->origin('methodPathsFirst', $request->method) !== null
                    ? $name('methodPathsFirst', $request->method, "methodPaths.$request->method")
                    : $name('methodPaths', $request->method, "methodPaths.$request->method");
            case 'method':
                // Refused: not in the methods; passed uncached: a POST is never cached.
                return $d->action === Decision::REJECT ? $name('methods', '*', 'methods') : 'built-in';
            case 'host':
                return $name('hosts', '*', 'hosts');
            case 'app':
                return 'application';
            case 'crawler':
                $id = $this->crawlers()->claims((string) $request->header('user-agent'));
                return $id === null ? null : ($s->origin('crawlerPolicy', $id) ?? $s->origin('crawlerPolicy', $this->crawlers()->kind($id)) ?? $id);
            case 'unknown parameter':
                return $name('query', 'strict', 'queryStrict');
            case 'attack':
                $p = ContentRule::matched($s->contentRules, $s->blockExceptions, null, $request);
                return $p === null ? null : $name('contentRules', $p, 'contentRules');
            case 'always':
                $i = $first($s->challenge->alwaysPaths, $request->path);
                return $i === null ? null : $name('challenge.alwaysPaths', $s->challenge->alwaysPaths[$i], "challenge.alwaysPaths[$i]");
            case 'query parameter':
                return $name('cacheable.query', '*', 'cacheable.query');
            case 'path not cacheable':
                return $name('cacheable.paths', '*', 'cacheable.paths');
            case 'uri length':
            case 'query parameters':
            case 'header size':
            case 'path encoding':
            case 'path traversal':
                return 'built-in';
        }
        if (isset($s->budgets[$d->reason])) {
            return $name('budgets', $d->reason, "budgets.$d->reason");
        }
        return null;
    }

    public function decide(Request $request, float $now): Decision
    {
        $base = Decision::allow();
        $budget = null;
        $this->alwaysAge = null;
        // The dashboard's own pages, opened from an address their restrict rule
        // allows: not counted against the pace (the live view asks every few seconds).
        $ownPage = $this->settings->restricted !== [] && $this->dashboardOnly($request);
        foreach ($this->rules as $rule) {
            if ($ownPage && $rule instanceof BudgetRule) {
                continue;
            }
            // strict: a request a cache must not keep counts twice -- the pattern
            // of floods that bust the cache with made-up addresses.
            $wants = $rule instanceof BudgetRule
                ? $rule->check($request, $now, $base->action === Decision::ALLOW_UNCACHED ? $this->settings->uncachedWeight : 1)
                : $rule->check($request, $now);
            if ($wants === null) {
                continue;
            }
            if ($wants->action === Decision::REJECT || $rule instanceof BanRule) {
                return $this->base = $wants;            // a banned client: one lookup, and the answer
            }
            if ($rule instanceof BudgetRule) {
                $budget = $budget === null ? $wants : $budget->stricter($wants);
            } else {
                $base = $base->stricter($wants);
            }
        }
        $this->base = $base;
        // Paths that are always checked (a login page): challenged whatever the
        // budgets say, every method -- a bot must not POST to a login without
        // having loaded it. The gate lets a client with a pass cookie through,
        // shows the page to a GET and answers any other method with 429.
        $always = $this->settings->challenge->alwaysPaths;
        // An address let in (exempt, the allow list) never meets the check.
        if ($always !== [] && !($this->settings->exemptIps !== [] && IpAddress::inRanges($request->clientIp, $this->settings->exemptIps))) {
            foreach ($always as $pattern) {
                if (@preg_match($pattern, $request->path) === 1) {
                    $budget = $budget === null ? Decision::challenge('always') : $budget->stricter(Decision::challenge('always'));
                    $this->alwaysAge = $this->settings->challenge->alwaysMaxAge[$pattern] ?? null;
                    break;
                }
            }
        }
        // On a public blocklist named "check": the browser check, as on an
        // always-checked page -- a pass lets the visitor through.
        if ((isset($this->settings->feedTables['check']) || self::hasAt($this->settings, 'check')) && $this->feedHit($request, 'check') !== null) {
            $budget = $budget === null ? Decision::challenge('feed') : $budget->stricter(Decision::challenge('feed'));
        }
        return $budget === null ? $base : $base->stricter($budget);
    }

    /**
     * Whether a request is for one of the dashboard's pages (dashboard-path:
     * /rs/live, /rs/stats/visitors … -- also below a prefix, /demo/rs/live) from an
     * address the restrict rule over that path allows. Without such a rule
     * the pages count like any other: an open dashboard keeps its flood guard.
     */
    private function dashboardOnly(Request $request): bool
    {
        $s = $this->settings;
        $path = $request->matchPath();
        if (stripos($path, $s->dashboardPath) === false && stripos($path, $s->statsPath) === false) {
            return false;                                   // the common case: a search or two
        }
        if (!Report\Frame::isPage($s, $path)) {
            return false;
        }
        foreach ($s->restricted as $r) {
            foreach ($r['paths'] as $pattern) {
                if (@preg_match($pattern, $path) === 1) {
                    return IpAddress::inRanges($request->clientIp, $r['ips']);
                }
            }
        }
        return false;
    }

    /** @var array<string, ?string> client|action => the rule found, this request */
    private array $feedHits = [];

    /**
     * The rule of the public blocklist that holds this client for an action
     * (deny, check, signal) -- null when none does, or when the client is let
     * in, a trusted proxy, or a crawler that proved who it is.
     */
    public function feedHit(Request $request, string $action): ?string
    {
        $key = $request->clientIp . '|' . $action . '|' . $request->path;
        if (array_key_exists($key, $this->feedHits)) {
            return $this->feedHits[$key];
        }
        $s = $this->settings;
        $rule = isset($s->feedTables[$action]) ? IpTable::find($request->clientIp, $s->feedTables[$action]) : null;
        foreach ($rule === null ? $s->feedsAt : [] as $f) {
            if ($f['action'] !== $action || ($id = IpTable::find($request->clientIp, $f['table'])) === null) {
                continue;
            }
            foreach ($f['paths'] as $p) {
                if (@preg_match($p, $request->path) === 1) {
                    $rule = $id;
                    break 2;
                }
            }
        }
        if ($rule !== null && (IpAddress::inRanges($request->clientIp, $s->exemptIps) || IpAddress::inRanges($request->clientIp, $s->trustedProxies))) {
            $rule = null;
        }
        if ($rule !== null && $s->crawlers !== []) {
            $id = $this->crawlers()->claims((string) $request->header('user-agent'));
            if ($id !== null && $this->crawlers()->verified($request->clientIp, $id)) {
                $rule = null;                               // a crawler that proved who it is: never by a list
            }
        }
        if (count($this->feedHits) > 64) {
            $this->feedHits = [];
        }
        return $this->feedHits[$key] = $rule;
    }

    private static function hasAt(Settings $s, string $action): bool
    {
        foreach ($s->feedsAt as $f) {
            if ($f['action'] === $action) {
                return true;
            }
        }
        return false;
    }

    /**
     * The request after the budgets: a challenged request goes through the
     * gate (pass cookie, solution, crawler, exempt path, or the page).
     *
     * @return array{decision: Decision, cookies: list<string>, page: ?string, json: ?array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}, passed: bool}
     */
    public function settle(Decision $decision, Request $request, float $now): array
    {
        if ($decision->action !== Decision::CHALLENGE) {
            return ['decision' => $decision, 'cookies' => [], 'page' => null, 'json' => null, 'passed' => false];
        }
        $r = $this->gate()->resolve($decision, $this->base, $request, $now, $this->gateOptions($decision, $request));
        return $r + ['passed' => $r['decision']->passes()];
    }

    /**
     * How the gate answers this request: an API gets the task as JSON; a
     * form comes back after the check (a pause would lose what was typed);
     * a spent budget's solution starts the budget's counter again.
     *
     * @return array{solution: ?string, fresh: ?int, api: bool, earn: array{window: int}|null, resend: array{action: string, fields: list<array{0: string, 1: string}>}|false|null}
     */
    private function gateOptions(Decision $d, Request $request): array
    {
        $api = $this->isApi($request);
        $budget = $d->spent ? ($this->settings->budgets[$d->reason] ?? null) : null;
        return [
            'solution' => $this->postedSolution(),
            // challenge … max-age: a pass issued at most that long ago.
            'fresh' => $d->reason === 'always' ? $this->alwaysAge : null,
            'api' => $api,
            'earn' => $budget !== null ? ['window' => $budget->window, 'counter' => $budget->counter()] : null,
            'resend' => !$api && $request->method !== 'GET' && $request->method !== 'HEAD' ? self::resendFields($request) : null,
        ];
    }

    /**
     * Whether a request is an API's: it asks for or sends JSON, or its path is
     * one the site named (api-path). A check is then a header, not a page.
     */
    public function isApi(Request $request): bool
    {
        $sent = $this->request === $request ? ($_SERVER['CONTENT_TYPE'] ?? '') : '';
        foreach ([(string) $request->header('accept'), is_string($sent) ? $sent : ''] as $type) {
            if ($type !== '' && preg_match('#application/(?:[\w.+-]+\+)?json#i', $type) === 1 && stripos($type, 'text/html') === false) {
                return true;
            }
        }
        foreach ($this->settings->challenge->apiPaths as $p) {
            if (@preg_match($p, $request->matchPath()) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * The answer the check inside the form sent with a form (hidden field),
     * taken out of $_POST once read -- the application never sees it.
     */
    private function postedSolution(): ?string
    {
        if ($this->settings->challenge->widgetPath === null) {
            return null;
        }
        $field = $this->settings->challenge->solutionCookie;
        $v = $_POST[$field] ?? null;
        unset($_POST[$field]);
        if (is_string($v) && $v !== '') {
            $this->posted = $v;
        }
        return $this->posted;
    }

    private ?string $posted = null;

    /**
     * The placeholder for the check inside a form, and (once per page) the
     * script: echo Shield::active()?->widget(); -- empty while the widget is
     * off (set widget-path ...).
     *
     * @param string $start "input" (the first input into the form), "load" or "submit"
     */
    public function widget(string $start = 'input'): string
    {
        $w = $this->settings->challenge->widgetPath;
        return $w === null || $this->settings->mode === 'off' ? '' : \CjwNetwork\RequestShield\Challenge\Widget::html($w, $start);
    }

    /** <widgetPath>/challenge (a task as JSON) and <widgetPath>/widget.js. */
    private function serveWidget(string $what, Request $request, float $now): void
    {
        $c = $this->settings->challenge;
        if ($what === 'widget.js') {
            $js = \CjwNetwork\RequestShield\Challenge\Widget::script();
            $etag = '"' . substr(hash('sha256', $js), 0, 16) . '"';
            header('Content-Type: text/javascript; charset=utf-8');
            header('Cache-Control: public, max-age=86400');
            header('ETag: ' . $etag);
            if (($request->header('if-none-match') ?? '') === $etag) {
                http_response_code(304);
                return;
            }
            echo $js;
            return;
        }
        if ($what !== 'challenge') {
            http_response_code(404);
            return;
        }
        $texts = Texts::all(Texts::language($c->language, $request->header('accept-language'), $c->texts), $c->texts);
        $task = $this->gate()->widgetTask($request, $now);
        $until = $task === null ? $this->gate()->passUntil($request, $now) : 0;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Vary: Accept-Language');
        header('X-Robots-Tag: noindex');
        echo json_encode([
            'passed' => $task === null,
            // With a pass: until when it holds; the widget fetches a task before then.
            'until' => $task === null ? $until : null,
            'challenge' => $task,
            'field' => $c->solutionCookie,
            'texts' => ['checking' => $texts['widget-checking'], 'checked' => $texts['widget-checked'], 'failed' => $texts['widget-failed']],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /**
     * For the application: go on only with a browser that passed the check.
     * With a valid pass (issued in the last $fresh seconds, when given) this
     * returns; otherwise the visitor gets the check page and the request
     * ends here -- for a form, with its fields, which are sent again once the
     * check is done, so nothing typed is lost:
     *
     *   Shield::active()?->requirePass();        // before saving a comment
     *   Shield::active()?->requirePass(300);     // a pass from the last 5 minutes
     *
     * Call it before acting on the request. Forms with files, or larger than
     * 256 KB, cannot be carried through: the visitor is asked to send them
     * again (better: require the pass on the form's page, before).
     */
    public function requirePass(?int $fresh = null): void
    {
        $request = $this->request;
        if ($request === null || $this->settings->mode === 'off' || ($this->passed && $fresh === null)) {
            return;
        }
        $now = microtime(true);
        // The answer out of the form first: a used one must not be sent again with it.
        $solution = $this->postedSolution();
        $resend = $request->method === 'GET' || $request->method === 'HEAD' ? null : self::resendFields($request);
        $r = $this->gate()->resolve(Decision::challenge('app'), Decision::allowUncached('app'), $request, $now,
            ['forced' => true, 'fresh' => $fresh, 'resend' => $resend, 'solution' => $solution]);
        if (!headers_sent()) {
            foreach ($r['cookies'] as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }
        if ($r['decision']->passes()) {
            $this->passed = true;
            return;
        }
        if ($this->settings->mode === 'monitor') {
            $this->watched($r['decision'], $request, $now, $this);
            return;
        }
        $this->stop($r['decision'], $request, $r['page'], $now);
        exit;
    }

    /** Answers with the check page (or a status page) instead of the application's. */
    /** @param array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}|null $json the task for an API */
    private function stop(Decision $d, Request $request, ?string $page, float $now, bool $echo = true, ?array $json = null, bool $log = true): string
    {
        $s = $this->settings;
        $rule = $this->explain($d, $request);
        if ($log) {
            Log::note($s, $request, $d, $rule, $now);
        }
        while ($echo && ob_get_level() > 0) {
            ob_end_clean();                 // nothing of the application's page
        }
        if ($json !== null) {
            $body = (new Responder())->api($d, $json, $s->debugHeader, $rule);
            if ($echo) {
                echo $body;
            }
            return $body;
        }
        $c = $s->challenge;
        $texts = Texts::all(Texts::language($c->language, $request->header('accept-language'), $c->texts), $c->texts);
        $responder = new Responder();
        $responder->headers($d, $s->debugHeader, $rule);
        $body = $request->method === 'HEAD' ? '' : $responder->body($d, $page, $texts, $c->home);
        if ($echo) {
            echo $body;
        }
        return $body;
    }

    /**
     * A form's fields as the application got them, to put in the check page;
     * false when they cannot be carried (files, too large, too many).
     *
     * @return array{action: string, fields: list<array{0: string, 1: string}>}|false
     */
    private static function resendFields(Request $request)
    {
        foreach ($_FILES as $file) {
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                return false;
            }
        }
        $fields = [];
        $bytes = 0;
        $walk = static function (array $values, string $prefix) use (&$walk, &$fields, &$bytes): bool {
            foreach ($values as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
                if (is_array($value)) {
                    if (!$walk($value, $name)) {
                        return false;
                    }
                    continue;
                }
                $value = is_scalar($value) ? (string) $value : '';
                $fields[] = [$name, $value];
                $bytes += strlen($name) + strlen($value);
                if ($bytes > self::RESEND_MAX_BYTES || count($fields) > 1000) {
                    return false;
                }
            }
            return true;
        };
        /** @var array<mixed> $post */
        $post = $_POST;
        return $walk($post, '') ? ['action' => $request->rawUri, 'fields' => $fields] : false;
    }

    /**
     * X-Request-Shield-Challenge: required[; fresh=<seconds>] from the
     * application, on a page (a form's): the page is kept back until it is
     * finished; without a pass the visitor gets the check page instead. The
     * header never reaches the browser.
     */
    private function watchForChallengeHeader(): void
    {
        $decided = null;
        ob_start(function (string $buffer, int $phase) use (&$decided): string {
            if ($decided === null) {
                $decided = '';
                foreach (headers_list() as $h) {
                    if (preg_match('/^X-Request-Shield-Challenge:\s*(.*)$/i', $h, $m)) {
                        header_remove('X-Request-Shield-Challenge');
                        $page = $this->challengeFor(trim($m[1]));
                        if ($page !== null) {
                            $decided = $page;
                        }
                        break;
                    }
                }
                if ($decided !== '') {
                    return $decided;
                }
                $decided = false;
            }
            return $decided === false ? $buffer : '';     // after the check page: nothing of the application's
        });
    }

    /** The check page for a page the application marked, or null when the visitor has a pass. */
    private function challengeFor(string $value): ?string
    {
        $request = $this->request;
        if ($request === null || stripos($value, 'required') !== 0) {
            return null;
        }
        $fresh = preg_match('/fresh=(\d+)/', $value, $m) ? (int) $m[1] : null;
        if ($this->passed && $fresh === null) {
            return null;
        }
        $now = microtime(true);
        $r = $this->gate()->resolve(Decision::challenge('app'), Decision::allowUncached('app'), $request, $now, ['forced' => true, 'fresh' => $fresh]);
        foreach ($r['cookies'] as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        if ($r['decision']->passes()) {
            $this->passed = true;
            return null;
        }
        if ($this->settings->mode === 'monitor') {
            $this->watched($r['decision'], $request, $now, $this);
            return null;
        }
        return $this->stop($r['decision'], $request, $r['page'], $now, false);
    }

    /**
     * Counts one event against a budget that no rule counts by itself -- a
     * cache miss, a failed sign-in -- and says what the client has earned.
     * Budgets marked 'onDemand' => true in the configuration are only counted here.
     */
    public function consume(string $budget, ?Request $request = null, ?float $now = null, bool $answer = false): Decision
    {
        $b = $this->settings->budgets[$budget] ?? null;
        $request ??= $this->request;
        if ($request === null || $this->settings->mode === 'off') {
            return Decision::allow();
        }
        $now ??= microtime(true);
        if ($b === null) {
            // Only a rule marked "monitor" has it: counted and logged, never acted on.
            $this->watchBudget($budget, $request, $now);
            return Decision::allow();
        }
        $times = $this->base->action === Decision::ALLOW_UNCACHED ? $this->settings->uncachedWeight : 1;
        $d = $this->budgetRule($b)->check($request, $now, $times) ?? Decision::allow();
        if (!$d->passes()) {
            $this->signal($budget, $request, $now);              // ban after 10 logins in 15m
        }
        if ($this->settings->mode === 'monitor') {
            if (!$d->passes()) {
                $this->watched($d, $request, $now, $this);
                return Decision::allowUncached($budget);
            }
            return $d;
        }
        if ($d->passes()) {
            $this->watchBudget($budget, $request, $now);
        }
        // A spent budget that lets its client earn it back: a solution sent
        // with this request (the form again, an API's header) starts it again.
        if ($d->spent) {
            $r = $this->gate()->resolve($d, Decision::allowUncached($budget), $request, $now, $this->gateOptions($d, $request));
            if (!headers_sent()) {
                foreach ($r['cookies'] as $cookie) {
                    header('Set-Cookie: ' . $cookie, false);
                }
            }
            if ($r['decision']->passes()) {
                return $r['decision'];
            }
            if ($answer) {
                $this->stop($r['decision'], $request, $r['page'], $now, true, $r['json']);
                exit;
            }
            return $d;
        }
        if ($d->action !== Decision::ALLOW && ($this->settings->logFile !== null || $this->settings->liveEnabled)) {
            Log::note($this->settings, $request, $d, $this->explain($d, $request), $now);
        }
        // answer: true -- the shield answers a refusal itself (a pause, the
        // check) and the request ends here: Shield::active()?->consume('posts', answer: true)
        if ($answer && !$d->passes()) {
            $this->stop($d, $request, null, $now, true, null, false);
            exit;
        }
        return $d;
    }

    /** An on-demand budget of the rules marked "monitor": what it would decide, logged. */
    private function watchBudget(string $budget, Request $request, float $now): void
    {
        $m = $this->settings->monitor;
        $w = $this->watcher($request);
        if ($m === null || $w === null || !isset($m->budgets[$budget])) {
            return;
        }
        $d = $w->budgetRule($m->budgets[$budget])->check($request, $now);
        if ($d !== null && !$d->passes()) {
            $this->watched($d, $request, $now, $w);
        }
    }

    private function budgetRule(Budget $b): BudgetRule
    {
        return new BudgetRule($this->store, $b->name, $b->limit, $b->window, $b->challengeAt,
            $this->settings->exemptIps, $this->settings->ipv6Prefix, $b->earnBack, $b->counter());
    }

    private function gate(): Gate
    {
        $c = $this->settings->challenge;
        return new Gate($c, Secret::resolve($c->secret, $this->settings->storeDir),
            $this->settings->crawlers === [] ? null : $this->crawlers(), $this->settings->ipv6Prefix, $this->store);
    }

    private ?Crawlers $crawlers = null;

    /**
     * The known crawlers, with what verifying them costs remembered for a day
     * per address (APCu, else files in store-dir) and DNS lookups limited
     * (dns-lookups a minute, for all requests together).
     */
    public function crawlers(): Crawlers
    {
        if ($this->crawlers !== null) {
            return $this->crawlers;
        }
        $c = $this->settings->challenge;
        $dir = $this->settings->storeDir;
        $apcu = ApcuStore::usable();
        return $this->crawlers = Crawlers::of($this->settings,
            static function (string $key) use ($apcu, $dir): ?string {
                if ($apcu) {
                    $v = apcu_fetch('rshield:' . $key);
                    return is_string($v) ? $v : null;
                }
                $f = $dir . '/se/' . md5($key);
                $mtime = @filemtime($f);
                if ($mtime === false || $mtime <= time() - 86400) {
                    return null;
                }
                $v = @file_get_contents($f);
                return is_string($v) ? $v : null;
            },
            static function (string $key, string $value) use ($apcu, $dir): void {
                if ($apcu) {
                    apcu_store('rshield:' . $key, $value, 86400);
                    return;
                }
                @mkdir($dir . '/se', 0700, true);
                @file_put_contents($dir . '/se/' . md5($key), $value);
            },
            // New DNS lookups per minute, for all requests together (the store).
            function () use ($c): bool {
                return $c->dnsLookups > 0 && $this->store->hit('se-lookups', 60, microtime(true)) <= $c->dnsLookups;
            },
        );
    }

    /** The store the settings ask for ("auto": APCu when usable, else files). */
    public static function storeFor(Settings $s): Store
    {
        return match ($s->store) {
            'apcu' => new ApcuStore(),
            'file' => new FileStore($s->storeDir),
            'memory' => new MemoryStore(),
            default => ApcuStore::usable() ? new ApcuStore() : new FileStore($s->storeDir),
        };
    }
}
