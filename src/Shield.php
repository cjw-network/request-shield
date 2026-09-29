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
use CjwNetwork\RequestShield\Rule\MethodRule;
use CjwNetwork\RequestShield\Rule\PathSanityRule;
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
        $this->rules[] = new BlockedPathRule($s->blockedPaths);
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
     *
     * @param (callable(Request): ?bool)|null $known
     */
    public static function protectFile(string $file, ?callable $known = null, ?string $cacheDir = null): Decision
    {
        return self::protect(Settings::load($file, $cacheDir), $known);
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
        $now = microtime(true);
        $settled = $shield->settle($shield->decide($request, $now), $request, $now);
        $decision = $settled['decision'];
        self::$current = $decision;
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
            (new Responder())->send($decision, $request, $s->debugHeader, $settled['page']);
            exit;
        }
        $_SERVER['REQUEST_SHIELD'] = $decision->action;
        if ($s->debugHeader && !headers_sent()) {
            header('X-Request-Shield: ' . $decision->action . ($decision->reason !== '' ? ' ' . $decision->reason : ''));
        }
        return $decision;
    }

    /** The decision protect() made for this request, if it ran. */
    public static function current(): ?Decision
    {
        return self::$current;
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
    public function consume(string $budget, Request $request, ?float $now = null): Decision
    {
        $b = $this->settings->budgets[$budget] ?? null;
        if ($b === null) {
            return Decision::allow();
        }
        return $this->budgetRule($b)->check($request, $now ?? microtime(true)) ?? Decision::allow();
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

    private static function storeFor(Settings $s): Store
    {
        return match ($s->store) {
            'apcu' => new ApcuStore(),
            'file' => new FileStore($s->storeDir),
            'memory' => new MemoryStore(),
            default => ApcuStore::usable() ? new ApcuStore() : new FileStore($s->storeDir),
        };
    }
}
