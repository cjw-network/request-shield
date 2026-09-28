<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

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
 * few string comparisons. The cacheable definition and the budgets follow.
 */
final class Shield
{
    /** @var list<Rule> */
    private array $rules = [];

    /** @var array<string, mixed> */
    private array $config;

    private Store $store;

    private static ?Decision $current = null;

    /**
     * @param array<string, mixed> $config see Config::defaults()
     * @param (callable(Request): ?bool)|null $known an adapter's URL index (see CacheableRule)
     */
    public function __construct(array $config = [], ?Store $store = null, ?callable $known = null)
    {
        $this->config = $c = Config::merge($config);
        $this->store = $store ?? self::storeFor($c);

        $this->rules[] = new MethodRule(array_map('strtoupper', (array) $c['methods']));
        $limits = (array) $c['limits'];
        $this->rules[] = new LimitsRule((int) ($limits['uri'] ?? 0), (int) ($limits['queryParameters'] ?? 0), (int) ($limits['headerBytes'] ?? 0));
        $this->rules[] = new PathSanityRule();
        if ((array) $c['hosts'] !== []) {
            $this->rules[] = new HostRule(array_values((array) $c['hosts']));
        }
        $this->rules[] = new BlockedPathRule(array_values((array) $c['blockedPaths']));
        $cacheable = (array) $c['cacheable'];
        $this->rules[] = new CacheableRule(
            isset($cacheable['paths']) ? array_values((array) $cacheable['paths']) : null,
            isset($cacheable['query']) ? array_values((array) $cacheable['query']) : null,
            $known,
        );
        $exempt = array_values((array) ($c['exempt']['ips'] ?? []));
        foreach ((array) $c['budgets'] as $name => $budget) {
            if (!is_array($budget) || (int) ($budget['limit'] ?? 0) <= 0 || !empty($budget['onDemand'])) {
                continue;
            }
            $this->rules[] = new BudgetRule(
                $this->store, (string) $name, (int) $budget['limit'], (int) ($budget['window'] ?? 60),
                isset($budget['challengeAt']) ? (int) $budget['challengeAt'] : null, $exempt, (int) $c['ipv6Prefix'],
            );
        }
    }

    /**
     * Decides about the request in $_SERVER, answers it itself when the
     * application must not run (and ends the request), and returns the
     * decision otherwise. Also Shield::current() and
     * $_SERVER['REQUEST_SHIELD'] ("allow" or "allow-uncached") afterwards.
     *
     * @param array<string, mixed> $config
     */
    public static function protect(array $config = [], ?callable $known = null): Decision
    {
        $shield = new self($config, null, $known);
        $request = Request::fromServer($_SERVER, array_values((array) $shield->config['trustedProxies']));
        $decision = $shield->decide($request, microtime(true));
        self::$current = $decision;

        if (!$request->viaTrustedProxy && !empty($shield->config['stripUntrustedForwarded'])) {
            foreach (array_keys($_SERVER) as $name) {
                if (is_string($name) && strncmp($name, 'HTTP_X_FORWARDED_', 17) === 0) {
                    unset($_SERVER[$name]);
                }
            }
            unset($_SERVER['HTTP_FORWARDED']);
        }

        if (!$decision->passes()) {
            (new Responder())->send($decision, $request, (bool) $shield->config['debugHeader']);
            exit;
        }
        $_SERVER['REQUEST_SHIELD'] = $decision->action;
        if (!empty($shield->config['debugHeader']) && !headers_sent()) {
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
        $decision = Decision::allow();
        foreach ($this->rules as $rule) {
            $wants = $rule->check($request, $now);
            if ($wants === null) {
                continue;
            }
            if ($wants->action === Decision::REJECT) {
                return $wants;
            }
            $decision = $decision->stricter($wants);
        }
        return $decision;
    }

    /**
     * Counts one event against a budget that no rule counts by itself -- a
     * cache miss, a failed sign-in -- and says what the client has earned.
     * Budgets marked 'onDemand' => true in the configuration are only counted here.
     */
    public function consume(string $budget, Request $request, ?float $now = null): Decision
    {
        $b = $this->config['budgets'][$budget] ?? null;
        if (!is_array($b) || (int) ($b['limit'] ?? 0) <= 0) {
            return Decision::allow();
        }
        $rule = new BudgetRule(
            $this->store, $budget, (int) $b['limit'], (int) ($b['window'] ?? 60),
            isset($b['challengeAt']) ? (int) $b['challengeAt'] : null,
            array_values((array) ($this->config['exempt']['ips'] ?? [])), (int) $this->config['ipv6Prefix'],
        );
        return $rule->check($request, $now ?? microtime(true)) ?? Decision::allow();
    }

    /** @param array<string, mixed> $c */
    private static function storeFor(array $c): Store
    {
        return match ((string) $c['store']) {
            'apcu' => new ApcuStore(),
            'file' => new FileStore((string) $c['storeDir']),
            'memory' => new MemoryStore(),
            default => ApcuStore::usable() ? new ApcuStore() : new FileStore((string) $c['storeDir']),
        };
    }
}
