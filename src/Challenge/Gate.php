<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Challenge;

use CjwNetwork\RequestShield\ChallengeSettings;
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
    public function __construct(
        private ChallengeSettings $config,
        private string $secret,
        private ?SearchEngines $searchEngines = null,
        private int $ipv6Prefix = 64,
        private ?Store $store = null,
    ) {
    }

    /**
     * @param Decision $base what every check but the budgets decided (cacheable or not)
     * @param array{forced?: bool, fresh?: ?int, resend?: array{action: string, fields: list<array{0: string, 1: string}>}|false|null} $o
     *   forced: the application asks for the check (Shield::requirePass()): exempt paths do not count;
     *   fresh: only a pass issued in the last so many seconds counts;
     *   resend: a form sent without a pass, to be sent again after the check (false: it cannot be)
     * @return array{decision: Decision, cookies: list<string>, page: ?string}
     *         cookies are complete Set-Cookie header values
     */
    public function resolve(Decision $challenged, Decision $base, Request $request, float $now, array $o = []): array
    {
        $forced = $o['forced'] ?? false;
        $fresh = $o['fresh'] ?? null;
        $resend = $o['resend'] ?? null;
        $bucket = IpAddress::bucket($request->clientIp, $this->ipv6Prefix);
        $ua = (string) $request->header('user-agent');
        $pass = new PassCookie($this->secret, $this->config->bindUserAgent);
        $passName = $this->config->cookie;
        $solutionName = $this->config->solutionCookie;
        $secure = $request->scheme === 'https';

        if ($pass->valid($request->cookie($passName), $bucket, $ua, $now)
            && ($fresh === null || $pass->expires((string) $request->cookie($passName)) - $this->config->passTtl >= $now - $fresh)) {
            return ['decision' => $base, 'cookies' => [], 'page' => null];
        }

        // A solution comes with the reload of a page -- or, for a form the
        // application asked the check for, with the form sent again.
        $solution = $request->cookie($solutionName);
        if ($solution !== null && ($request->method === 'GET' || $request->method === 'HEAD' || $forced)) {
            $cookies = [self::cookie($solutionName, '', 0, $secure)];
            if ((new ProofOfWork($this->secret))->verify($solution, $bucket, $now) && $this->firstUse($solution, $now)) {
                $ttl = $this->config->passTtl;
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
        foreach ($forced ? [] : $this->config->exemptPaths as $pattern) {
            if (@preg_match($pattern, $request->path) === 1) {
                return ['decision' => $base, 'cookies' => $cookies, 'page' => null];
            }
        }
        if ($request->method !== 'GET' && $request->method !== 'HEAD' && $resend === null) {
            return ['decision' => Decision::throttle($challenged->reason, 10), 'cookies' => $cookies, 'page' => null];
        }

        $min = $this->config->difficultyMin;
        $maxNumber = (int) round($min + ($this->config->difficultyMax - $min) * $challenged->level);
        $expires = (int) $now + $this->config->solutionTtl;
        $challenge = (new ProofOfWork($this->secret))->create($bucket, $maxNumber, $expires);
        $c = $this->config;
        $texts = \CjwNetwork\RequestShield\Texts::all(\CjwNetwork\RequestShield\Texts::language($c->language, $request->header('accept-language'), $c->texts), $c->texts);
        $page = ChallengePage::render($challenge, $solutionName, $secure, $texts, $resend);
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
        return $this->store->hit('pow:' . $challenge, $this->config->solutionTtl, $now) <= 1.0;
    }

    /** A Set-Cookie value; $maxAge 0 deletes the cookie. */
    public static function cookie(string $name, string $value, int $maxAge, bool $secure): string
    {
        return $name . '=' . $value . '; Path=/; Max-Age=' . $maxAge . '; HttpOnly; SameSite=Lax' . ($secure ? '; Secure' : '');
    }
}
