<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Challenge;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Store\Store;

/**
 * What becomes of a request the budgets want challenged:
 *
 *   a valid pass cookie             -> through, as the rest of the checks decided
 *   a valid solution cookie         -> through, with a pass cookie set
 *   a verified search engine        -> through
 *   a path exempt from challenges   -> through (the budget's limit still applies)
 *   not GET or HEAD                 -> 429: a reload cannot repeat a form's POST
 *   anything else                   -> the challenge page
 */
final class Gate
{
    /**
     * @param array<string, mixed> $config Config's 'challenge' section
     */
    public function __construct(
        private readonly array $config,
        private readonly string $secret,
        private readonly ?SearchEngines $searchEngines = null,
        private readonly int $ipv6Prefix = 64,
        private readonly ?Store $store = null,
    ) {
    }

    /**
     * @param Decision $base what every check but the budgets decided (cacheable or not)
     * @return array{decision: Decision, cookies: list<string>, page: ?string}
     *         cookies are complete Set-Cookie header values
     */
    public function resolve(Decision $challenged, Decision $base, Request $request, float $now): array
    {
        $bucket = IpAddress::bucket($request->clientIp, $this->ipv6Prefix);
        $ua = (string) $request->header('user-agent');
        $pass = new PassCookie($this->secret, (bool) ($this->config['bindUserAgent'] ?? true));
        $passName = (string) ($this->config['cookie'] ?? 'rs_pass');
        $solutionName = (string) ($this->config['solutionCookie'] ?? 'rs_solution');
        $secure = $request->scheme === 'https';

        if ($pass->valid($request->cookie($passName), $bucket, $ua, $now)) {
            return ['decision' => $base, 'cookies' => [], 'page' => null];
        }

        $solution = $request->cookie($solutionName);
        if ($solution !== null && ($request->method === 'GET' || $request->method === 'HEAD')) {
            $cookies = [self::cookie($solutionName, '', 0, $secure)];
            if ((new ProofOfWork($this->secret))->verify($solution, $bucket, $now) && $this->firstUse($solution, $now)) {
                $ttl = max(60, (int) ($this->config['passTtl'] ?? 3600));
                $cookies[] = self::cookie($passName, $pass->issue($bucket, $ua, (int) $now + $ttl), $ttl, $secure);
                // The page this request gets is for a challenged client:
                // answered, but kept out of every cache.
                return ['decision' => Decision::allowUncached('challenge solved'), 'cookies' => $cookies, 'page' => null];
            }
            // A wrong or stale solution: a new challenge below, without the old cookie.
        } else {
            $cookies = [];
        }

        if ($this->searchEngines !== null && $this->searchEngines->verified($request->clientIp, $ua)) {
            return ['decision' => $base, 'cookies' => $cookies, 'page' => null];
        }
        foreach ((array) ($this->config['exemptPaths'] ?? []) as $pattern) {
            if (@preg_match($pattern, $request->path) === 1) {
                return ['decision' => $base, 'cookies' => $cookies, 'page' => null];
            }
        }
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return ['decision' => Decision::throttle($challenged->reason, 10), 'cookies' => $cookies, 'page' => null];
        }

        $min = max(1000, (int) ($this->config['difficulty']['min'] ?? 50000));
        $max = max($min, (int) ($this->config['difficulty']['max'] ?? 500000));
        $maxNumber = (int) round($min + ($max - $min) * $challenged->level);
        $expires = (int) $now + max(30, (int) ($this->config['solutionTtl'] ?? 300));
        $challenge = (new ProofOfWork($this->secret))->create($bucket, $maxNumber, $expires);
        $page = ChallengePage::render($challenge, $solutionName, $secure, (array) ($this->config['texts'] ?? []));
        return ['decision' => $challenged, 'cookies' => $cookies, 'page' => $page];
    }

    /**
     * Whether this is the first time the solution is used: a solved challenge
     * buys one pass cookie, not one per replay while it is valid. Counted in
     * the store for the challenge's lifetime; without a store, always true.
     */
    private function firstUse(string $solution, float $now): bool
    {
        $challenge = ProofOfWork::challengeOf($solution);
        if ($this->store === null || $challenge === null) {
            return $this->store === null;
        }
        $ttl = max(30, (int) ($this->config['solutionTtl'] ?? 300));
        return $this->store->hit('pow:' . $challenge, $ttl, $now) <= 1.0;
    }

    /** A Set-Cookie value; $maxAge 0 deletes the cookie. */
    public static function cookie(string $name, string $value, int $maxAge, bool $secure): string
    {
        return $name . '=' . $value . '; Path=/; Max-Age=' . $maxAge . '; HttpOnly; SameSite=Lax' . ($secure ? '; Secure' : '');
    }
}
