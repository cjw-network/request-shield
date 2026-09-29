<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Challenge\Gate;
use CjwNetwork\RequestShield\Challenge\SearchEngines;
use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\Rule\BlockedPathRule;
use CjwNetwork\RequestShield\Rule\BudgetRule;
use CjwNetwork\RequestShield\Rule\CacheableRule;
use CjwNetwork\RequestShield\Rule\HostRule;
use CjwNetwork\RequestShield\Rule\LimitsRule;
use CjwNetwork\RequestShield\Rule\MethodPathRule;
use CjwNetwork\RequestShield\Rule\MethodRule;
use CjwNetwork\RequestShield\Rule\PathSanityRule;
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

    /** What decide() found without the budgets: whether the answer may be cached. */
    private Decision $base;

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

        $this->rules[] = new MethodRule($s->methods);
        $this->rules[] = new LimitsRule($s->maxUri, $s->maxQueryParameters, $s->maxHeaderBytes);
        $this->rules[] = new PathSanityRule();
        if ($s->hosts !== []) {
            $this->rules[] = new HostRule($s->hosts);
        }
        $this->rules[] = new BlockedPathRule($s->blockedPaths, $s->blockExceptions);
        if ($s->methodPaths !== []) {
            $this->rules[] = new MethodPathRule($s->methodPaths);
        }
        if ($s->restricted !== []) {
            $this->rules[] = new RestrictedPathRule($s->restricted);
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
        return self::protect(Settings::load($file, $cacheDir, $sources), $known);
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
        $now = microtime(true);
        $settled = $shield->settle($shield->decide($request, $now), $request, $now);
        $decision = $settled['decision'];
        self::$current = $decision;
        // Which rule: looked up only for a request that was stopped or flagged
        // (or when every request is logged) -- a passing one costs nothing.
        $rule = null;
        if ($decision->action !== Decision::ALLOW || $s->logLevel === 'all') {
            $rule = $shield->explain($decision, $request);
            if ($s->logFile !== null && Log::wants($s->logLevel, $decision)) {
                Log::write($s, $request, $decision, $rule, $now);
            }
        }
        self::$rule = $rule;
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

        if (!$decision->passes()) {
            (new Responder())->send($decision, $request, $s->debugHeader, $settled['page'], $rule);
            exit;
        }
        $_SERVER['REQUEST_SHIELD'] = $decision->action;
        if ($rule !== null) {
            $_SERVER['REQUEST_SHIELD_RULE'] = $rule;
        }
        if ($s->debugHeader && !headers_sent()) {
            header('X-Request-Shield: ' . $decision->action . ($decision->reason !== '' ? ' ' . $decision->reason : '')
                . ($rule !== null ? '; rule=' . $rule : ''));
        }
        return $decision;
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
                return $name('methodPaths', $request->method, "methodPaths.$request->method");
            case 'method':
                // Refused: not in the methods; passed uncached: a POST is never cached.
                return $d->action === Decision::REJECT ? $name('methods', '*', 'methods') : 'built-in';
            case 'host':
                return $name('hosts', '*', 'hosts');
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
        foreach ($this->rules as $rule) {
            $wants = $rule->check($request, $now);
            if ($wants === null) {
                continue;
            }
            if ($wants->action === Decision::REJECT) {
                return $this->base = $wants;
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
        if ($always !== []) {
            foreach ($always as $pattern) {
                if (@preg_match($pattern, $request->path) === 1) {
                    $budget = $budget === null ? Decision::challenge('always') : $budget->stricter(Decision::challenge('always'));
                    break;
                }
            }
        }
        return $budget === null ? $base : $base->stricter($budget);
    }

    /**
     * The request after the budgets: a challenged request goes through the
     * gate (pass cookie, solution, crawler, exempt path, or the page).
     *
     * @return array{decision: Decision, cookies: list<string>, page: ?string}
     */
    public function settle(Decision $decision, Request $request, float $now): array
    {
        if ($decision->action !== Decision::CHALLENGE) {
            return ['decision' => $decision, 'cookies' => [], 'page' => null];
        }
        return $this->gate()->resolve($decision, $this->base, $request, $now);
    }

    /**
     * Counts one event against a budget that no rule counts by itself -- a
     * cache miss, a failed sign-in -- and says what the client has earned.
     * Budgets marked 'onDemand' => true in the configuration are only counted here.
     */
    public function consume(string $budget, ?Request $request = null, ?float $now = null): Decision
    {
        $b = $this->settings->budgets[$budget] ?? null;
        $request ??= $this->request;
        if ($b === null || $request === null) {
            return Decision::allow();
        }
        $now ??= microtime(true);
        $d = $this->budgetRule($b)->check($request, $now) ?? Decision::allow();
        if ($this->settings->logFile !== null && $d->action !== Decision::ALLOW && Log::wants($this->settings->logLevel, $d)) {
            Log::write($this->settings, $request, $d, $this->explain($d, $request), $now);
        }
        return $d;
    }

    private function budgetRule(Budget $b): BudgetRule
    {
        return new BudgetRule($this->store, $b->name, $b->limit, $b->window, $b->challengeAt,
            $this->settings->exemptIps, $this->settings->ipv6Prefix);
    }

    private function gate(): Gate
    {
        $c = $this->settings->challenge;
        $dir = $this->settings->storeDir;
        $engines = null;
        if ($c->searchEngines !== null) {
            $apcu = ApcuStore::usable();
            $engines = new SearchEngines(
                $c->searchEngines,
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
            );
        }
        return new Gate($c, Secret::resolve($c->secret, $dir), $engines, $this->settings->ipv6Prefix, $this->store);
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
