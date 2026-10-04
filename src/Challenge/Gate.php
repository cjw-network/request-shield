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
 *   a verified known crawler (allow) -> through (past a limit: 429)
 *   a path exempt from challenges   -> through (the budget's limit still applies)
 *   not GET or HEAD                 -> 429: a reload cannot repeat a form's POST
 *   anything else                   -> the challenge page
 */
final class Gate
{
    public function __construct(
        private ChallengeSettings $config,
        private string $secret,
        private ?Crawlers $crawlers = null,
        private int $ipv6Prefix = 64,
        private ?Store $store = null,
        /** @var (callable(string, array<string, mixed>): ?string)|null the Pages hook (PageHook::asker()): the site's own check page, or null for the shield's */
        private $pages = null,
        /** where the docs are (set docs-url): the check page links to the check in plain words; "" no link */
        private string $docs = '',
    ) {
    }

    /**
     * @param Decision $base what every check but the budgets decided (cacheable or not)
     * @param array{forced?: bool, fresh?: ?int, resend?: array{action: string, fields: list<array{0: string, 1: string}>}|false|null, solution?: ?string, api?: bool, earn?: array{window: int, counter?: string}|null} $o
     *   forced: the application asks for the check (Shield::requirePass()): exempt paths do not count;
     *   fresh: only a pass issued in the last so many seconds counts;
     *   resend: a form sent without a pass, to be sent again after the check (false: it cannot be);
     *   solution: an answer the check inside the form sent in a hidden field;
     *   api: answer with the task as JSON (json in the result), the solution from Request-Shield-Solution;
     *   earn: for a spent budget (Decision::spent), its window: a solution starts its counter again
     * @return array{decision: Decision, cookies: list<string>, page: ?string, json: ?array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}}
     *         cookies are complete Set-Cookie header values; json: the task for an API
     */
    public function resolve(Decision $challenged, Decision $base, Request $request, float $now, array $o = []): array
    {
        $forced = $o['forced'] ?? false;
        // An answer from the check inside the form (a hidden field), or none.
        $posted = $o['solution'] ?? null;
        $fresh = $o['fresh'] ?? null;
        $resend = $o['resend'] ?? null;
        $api = $o['api'] ?? false;
        $earn = $o['earn'] ?? null;
        // A budget past its limit that lets its client earn it back: no pass
        // gets past it -- only a solution made for this budget, which starts
        // its counter again.
        $spent = $challenged->spent;
        $budget = $challenged->reason;
        $bucket = IpAddress::bucket($request->clientIp, $this->ipv6Prefix);
        $ua = (string) $request->header('user-agent');
        $pass = new PassCookie($this->secret, $this->config->bindUserAgent);
        $passName = $this->config->cookie;
        $solutionName = $this->config->solutionCookie;
        $secure = $request->scheme === 'https';

        if (!$spent && $pass->valid($request->cookie($passName), $bucket, $ua, $now)
            && ($fresh === null || $pass->expires((string) $request->cookie($passName)) - $this->config->passTtl >= $now - $fresh)) {
            return ['decision' => $base, 'cookies' => [], 'page' => null, 'json' => null];
        }

        // A solution comes with the reload of a page, a form sent again (the
        // check page carried it: resend), the check inside the form (a field),
        // or an API's header.
        $solution = $posted ?? ($api ? $request->header('request-shield-solution') : null) ?? $request->cookie($solutionName);
        $cookies = [];
        if ($solution !== null && ($request->method === 'GET' || $request->method === 'HEAD' || $forced || $resend !== null || $posted !== null || $spent || $api)) {
            if ($request->cookie($solutionName) !== null) {
                $cookies[] = self::cookie($solutionName, '', 0, $secure);
            }
            $pow = new ProofOfWork($this->secret);
            if ($pow->verify($solution, $bucket, $now) && (!$spent || ProofOfWork::budgetOf($solution) === $budget) && $this->firstUse($solution, $now)) {
                if ($spent && $earn !== null && $this->store !== null) {
                    $this->store->reset(($earn['counter'] ?? $budget) . ':' . $bucket, $earn['window'], $now);
                    $this->store->hit('solved:' . ($earn['counter'] ?? $budget) . ':' . $bucket, 3600, $now);
                }
                $ttl = $this->config->passTtl;
                $cookies[] = self::cookie($passName, $pass->issue($bucket, $ua, (int) $now + $ttl), $ttl, $secure);
                // The page this request gets is for a challenged client:
                // answered, but kept out of every cache.
                return ['decision' => Decision::allowUncached('challenge solved'), 'cookies' => $cookies, 'page' => null, 'json' => null];
            }
            // A wrong or stale solution: a new challenge below, without the old cookie.
        }

        // A known crawler the site lets through (policy allow), verified by its
        // address: it cannot solve the check -- past a limit, the pause it
        // understands. One that only claims the name is an ordinary visitor,
        // noted in the log.
        $crawler = $this->crawlers !== null ? $this->crawlers->claims($ua) : null;
        if ($crawler !== null && $this->crawlers->policy($crawler) === 'allow') {
            if ($this->crawlers->verified($request->clientIp, $crawler)) {
                return $spent
                    ? ['decision' => Decision::throttle($budget, $challenged->retryAfter), 'cookies' => $cookies, 'page' => null, 'json' => null]
                    : ['decision' => $base, 'cookies' => $cookies, 'page' => null, 'json' => null];
            }
            $challenged = $challenged->claiming($crawler);
        }
        foreach ($forced || $spent ? [] : $this->config->exemptPaths as $pattern) {
            if (@preg_match($pattern, $request->path) === 1) {
                return ['decision' => $base, 'cookies' => $cookies, 'page' => null, 'json' => null];
            }
        }
        if ($request->method !== 'GET' && $request->method !== 'HEAD' && $resend === null && !$api) {
            return ['decision' => Decision::throttle($budget, max(10, $challenged->retryAfter)), 'cookies' => $cookies, 'page' => null, 'json' => null];
        }

        $c = $this->config;
        if ($spent) {
            // Harder with every solve in the hour: from difficulty-min, doubled,
            // at most difficulty-max.
            $solves = $this->store !== null ? (int) floor($this->store->peek('solved:' . ($earn['counter'] ?? $budget) . ':' . $bucket, 3600, $now)) : 0;
            $maxNumber = (int) min($c->difficultyMax, $c->difficultyMin * 2 ** min($solves, 20));
        } else {
            $maxNumber = (int) round($c->difficultyMin + ($c->difficultyMax - $c->difficultyMin) * $challenged->level);
        }
        $challenge = (new ProofOfWork($this->secret))->create($bucket, $maxNumber, (int) $now + $c->solutionTtl, $spent ? $budget : null);
        if ($api) {
            return ['decision' => $challenged, 'cookies' => $cookies, 'page' => null, 'json' => $challenge];
        }
        $texts = \CjwNetwork\RequestShield\Texts::all(\CjwNetwork\RequestShield\Texts::language($c->language, $request->header('accept-language'), $c->texts), $c->texts);
        if ($spent && $resend === null) {
            $texts['text'] = $texts['spent'];
        }
        // The site's own check page (the Pages hook, 0031 B.10), else the shield's.
        $page = $this->pages !== null ? ($this->pages)(\CjwNetwork\RequestShield\Pages::CHALLENGE, ['challenge' => $challenge, 'field' => $solutionName, 'secure' => $secure, 'texts' => $texts,
            'lang' => $texts['lang'] ?? 'en', 'resend' => $resend, 'home' => $c->home, 'logo' => $c->logo, 'status' => $challenged->status,
            'about' => \CjwNetwork\RequestShield\Help::explained($this->docs)]) : null;
        $page ??= ChallengePage::render($challenge, $solutionName, $secure, $texts, $resend, $c->home, $c->logo, \CjwNetwork\RequestShield\Help::explained($this->docs));
        return ['decision' => $challenged, 'cookies' => $cookies, 'page' => $page, 'json' => null];
    }

    /**
     * A task for the check inside the form (Widget), or null when the visitor
     * holds a pass already.
     *
     * @return array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}|null
     */
    public function widgetTask(Request $request, float $now): ?array
    {
        // A pass about to run out gets a task anyway: the form may be sent after it.
        if ($this->passUntil($request, $now) > $now + 30) {
            return null;
        }
        $bucket = IpAddress::bucket($request->clientIp, $this->ipv6Prefix);
        return (new ProofOfWork($this->secret))->create($bucket, $this->config->widgetDifficulty, (int) $now + $this->config->solutionTtl);
    }

    /**
     * Until when the visitor's pass is valid (a Unix time), 0 without one --
     * the check inside the form fetches a task before it runs out.
     */
    public function passUntil(Request $request, float $now): int
    {
        $bucket = IpAddress::bucket($request->clientIp, $this->ipv6Prefix);
        $pass = new PassCookie($this->secret, $this->config->bindUserAgent);
        $cookie = $request->cookie($this->config->cookie);
        return $cookie !== null && $pass->valid($cookie, $bucket, (string) $request->header('user-agent'), $now) ? $pass->expires($cookie) : 0;
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
