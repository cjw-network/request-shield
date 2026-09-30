<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rule\BlockedPathRule;
use CjwNetwork\RequestShield\Rule\CacheableRule;
use CjwNetwork\RequestShield\Rule\ContentRule;
use CjwNetwork\RequestShield\Rule\HostRule;
use CjwNetwork\RequestShield\Rule\LimitsRule;
use CjwNetwork\RequestShield\Rule\MethodPathRule;
use CjwNetwork\RequestShield\Rule\MethodRule;
use CjwNetwork\RequestShield\Rule\PathSanityRule;
use CjwNetwork\RequestShield\Rule\RestrictedPathRule;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\PeekStore;
use CjwNetwork\RequestShield\Store\Store;

/**
 * Tries a request against the rules, step by step, as the shield would check
 * it -- without counting it against anyone's budget. For the rules page and
 * `bin/request-shield trace`: "what happens to https://…/wp-login.php?"
 *
 * Every step says in plain words what was checked and what came of it:
 * pass (fine), note (answered, with a remark: not cached, checked), stop
 * (refused here) or skip (not checked: already refused).
 */
final class Inspector
{
    private Shield $shield;

    private PeekStore $store;

    public function __construct(private Settings $settings, ?Store $store = null)
    {
        // Only looks at the counters: a trace never spends a budget.
        $this->store = new PeekStore($store ?? Shield::storeFor($settings));
        $this->shield = new Shield($settings, $this->store);
    }

    /**
     * A request from its parts: "GET", "https://www.example.org/x?y=1", the
     * client's address.
     *
     * @param array<string, string> $headers extra headers ("User-Agent" => ...)
     */
    public static function request(string $method, string $url, string $ip, array $headers = []): Request
    {
        // A full URL, or a path: "//admin/users" is a path here (as a browser
        // sends it in the request line), not a host.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $parts = parse_url($url) ?: ['path' => '/'];
        } else {
            $q = strpos($url, '?');
            $path = $q === false ? $url : substr($url, 0, $q);
            $parts = ['path' => $path === '' ? '/' : ($path[0] === '/' ? $path : '/' . $path)];     // "wp-login.php" as a path
            if ($q !== false) {
                $parts['query'] = substr($url, $q + 1);
            }
        }
        $server = [
            'REQUEST_METHOD' => strtoupper($method !== '' ? $method : 'GET'),
            'REQUEST_URI' => ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''),
            'REMOTE_ADDR' => $ip,
            'HTTP_HOST' => ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : ''),
        ];
        if (($parts['scheme'] ?? '') === 'https') {
            $server['HTTPS'] = 'on';
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return Request::fromServer($server);
    }

    /**
     * @return array{steps: list<array{check: string, state: string, text: string, rule: ?string}>, decision: Decision, verdict: string, rule: ?string, watched: ?string}
     */
    public function trace(Request $request, ?float $now = null): array
    {
        $now ??= microtime(true);
        $s = $this->settings;
        /** @var list<array{check: string, state: string, text: string, rule: ?string}> $steps */
        $steps = [];
        $stopped = false;
        $step = function (string $check, ?Decision $d, string $passText, callable $stopText) use (&$steps, &$stopped, $request): void {
            if ($stopped) {
                $steps[] = ['check' => $check, 'state' => 'skip', 'text' => 'not checked: already refused above', 'rule' => null];
                return;
            }
            if ($d === null) {
                $steps[] = ['check' => $check, 'state' => 'pass', 'text' => $passText, 'rule' => null];
                return;
            }
            $text = $stopText($d);
            $stop = $d->action === Decision::REJECT || $d->action === Decision::THROTTLE;
            $stopped = $d->action === Decision::REJECT;
            $steps[] = ['check' => $check, 'state' => $stop ? 'stop' : 'note', 'text' => is_string($text) ? $text : '', 'rule' => $this->shield->explain($d, $request)];
        };

        $step('Kind of request', (new MethodRule($s->methods))->check($request, $now),
            "$request->method is accepted (" . implode(', ', $s->methods) . ')',
            static fn (): string => "$request->method is not accepted — only " . implode(', ', $s->methods));
        $step('Size', (new LimitsRule($s->maxUri, $s->maxQueryParameters, $s->maxHeaderBytes))->check($request, $now),
            "address, parameters and headers within the limits ($s->maxUri characters, $s->maxQueryParameters parameters)",
            static fn (Decision $d): string => ucfirst(Describe::reason($d->reason)));
        $step('Disguised address', (new PathSanityRule())->check($request, $now),
            'the address is what it seems: no hidden encoding, no way out of the website\'s folder',
            static fn (Decision $d): string => ucfirst(Describe::reason($d->reason)));
        $step('Website name', $s->hosts === [] ? null : (new HostRule($s->hosts))->check($request, $now),
            $s->hosts === [] ? 'any website name is accepted' : "\"$request->host\" is one of this site's names",
            static fn (): string => "\"$request->host\" is not one of this site's names (" . implode(', ', $s->hosts) . ')');
        $step('Addresses only attackers ask for', (new BlockedPathRule($s->blockedPaths, $s->blockExceptions))->check($request, $now),
            $this->blockedPass($request),
            fn (Decision $d): string => 'refused: ' . $this->blockedMatch($request));
        $step('Where forms may be sent', $s->methodPaths === [] ? null : (new MethodPathRule($s->methodPaths))->check($request, $now),
            isset($s->methodPaths[$request->method]) ? "$request->method is allowed at this address" : ($s->methodPaths === [] ? 'no restriction' : "no restriction for $request->method"),
            static fn (): string => "a $request->method is only accepted at: " . implode(', ', array_map(static fn (string $p): string => Describe::pattern($s, $p), $s->methodPaths[$request->method] ?? [])));
        $restricted = $s->restricted === [] ? null : (new RestrictedPathRule($s->restricted))->check($request, $now);
        $step('Areas for certain visitors', $restricted, $this->restrictedPass($request),
            fn (): string => 'only for ' . $this->restrictedFor($request) . " — $request->clientIp is not one of them");
        $query = $s->queryParams === [] && !$s->queryStrict ? null : (new \CjwNetwork\RequestShield\Rule\QueryRule($s->queryIndex, $s->queryStrict))->check($request, $now);
        $step('Known parameters', $query, $this->queryPass($request),
            fn (): string => 'refused: ' . $this->queryProblem($request) . ' (query strict)');
        $step('Attack patterns', $s->contentIndex === [] ? null : (new ContentRule($s->contentIndex, $s->contentRules, $s->blockExceptions, $s->contentHints))->check($request, $now),
            $s->contentIndex === [] ? 'no attack patterns configured' : $this->attackPass($request),
            fn (): string => 'refused: ' . $this->attackMatch($request));
        $step('May a cache keep the answer?', (new CacheableRule($s->cacheablePaths, $s->cacheableQuery))->check($request, $now),
            'yes: a known address with known parameters',
            static fn (Decision $d): string => 'answered, but not kept: ' . Describe::reason($d->reason));

        // The budgets, as they stand (this request included, nothing counted).
        foreach ($s->budgets as $b) {
            if ($b->onDemand) {
                continue;
            }
            $exempt = IpAddress::inRanges($request->clientIp, $s->exemptIps);
            $count = $exempt ? 0 : (int) round($this->store->hit($b->name . ':' . IpAddress::bucket($request->clientIp, $s->ipv6Prefix), $b->window, $now));
            $pace = $exempt ? "$request->clientIp is never counted" : "$count of $b->limit per " . Describe::duration($b->window)
                . ($b->challengeAt !== null ? ", browser check from $b->challengeAt" : '');
            $d = null;
            if (!$exempt && $count > $b->limit) {
                $d = $b->earnBack ? Decision::spent($b->name, 1) : Decision::throttle($b->name, 1);
            } elseif (!$exempt && $b->challengeAt !== null && $count > $b->challengeAt) {
                $d = Decision::challenge($b->name);
            }
            $step("Pace: \"$b->name\"", $d, $pace, static fn (Decision $d): string => $pace . ($d->action === Decision::THROTTLE ? ' — too many: wait'
                : ($d->spent ? ' — too many: the check, then the counter starts again' : ' — past the check')));
        }
        $always = null;
        $age = null;
        foreach ($s->challenge->alwaysPaths as $p) {
            if (@preg_match($p, $request->path) === 1) {
                $always = Decision::challenge('always');
                $age = $s->challenge->alwaysMaxAge[$p] ?? null;
                break;
            }
        }
        $step('Browser check', $always, 'not asked for at this address' . ($s->challenge->exemptPaths !== [] ? ' (and never at ' . implode(', ', array_map(static fn (string $p): string => Describe::pattern($s, $p), $s->challenge->exemptPaths)) . ')' : ''),
            static fn (): string => 'every visitor is checked here, once per pass (valid for ' . Describe::span($s->challenge->passTtl) . ')'
                . ($age !== null ? '; here only a pass from the last ' . Describe::span($age) : ''));

        $decision = $this->shield->decide($request, $now);
        $verdict = Describe::verdict($decision);
        if ($s->mode === 'off') {
            $verdict = 'sees the page — the shield is switched off (set mode off)';
        } elseif ($s->mode === 'monitor' && !$decision->passes()) {
            $verdict = 'sees the page — monitor mode; enforced, it ' . $verdict;
        }
        // Rules marked "monitor": what they would add, for a request the others let through.
        $watched = null;
        if ($s->monitor !== null && $decision->passes() && $s->mode !== 'off') {
            $w = (new self($s->monitor, $this->store))->shield;
            $d = $w->decide($request, $now);
            if (!$d->passes()) {
                $watched = Describe::verdict($d) . ' — if the rule marked "monitor" were enforced (' . ($w->explain($d, $request) ?? 'monitor') . ')';
            }
        }
        return ['steps' => $steps, 'decision' => $decision, 'verdict' => $verdict, 'rule' => $this->shield->explain($decision, $request), 'watched' => $watched];
    }

    private function blockedMatch(Request $request): string
    {
        $path = strtolower(rawurldecode($request->path));
        foreach ($this->settings->blockedPaths as $p) {
            if (@preg_match($p, $path) === 1) {
                return Describe::rule($this->settings, 'blockedPaths', $p);
            }
        }
        return 'a refused address';
    }

    /** Not blocked -- or blocked, but let through by an exception here. */
    private function blockedPass(Request $request): string
    {
        $path = strtolower(rawurldecode($request->path));
        foreach ($this->settings->blockedPaths as $p) {
            if (@preg_match($p, $path) === 1) {
                $i = BlockedPathRule::excepted($this->settings->blockExceptions, $p, $request);
                if ($i !== null) {
                    $x = $this->settings->blockExceptions[$i];
                    return 'would be refused (' . Describe::rule($this->settings, 'blockedPaths', $p) . '), but open here'
                        . ($x['ips'] !== [] ? " for $request->clientIp (" . implode(', ', $x['ips']) . ')' : ' for everyone')
                        . ' — ' . ($this->settings->origin('blockExceptions', $x['paths'][0] ?? '') ?? "blockExceptions[$i]");
                }
            }
        }
        return 'not one of the ' . count($this->settings->blockedPaths) . ' refused kinds of address';
    }

    private function attackMatch(Request $request): string
    {
        $p = ContentRule::matched($this->settings->contentRules, $this->settings->blockExceptions, null, $request);
        return $p === null ? 'an attack pattern' : Describe::rule($this->settings, 'contentRules', $p);
    }

    /** No attack pattern -- or one matched, but open here by an exception. */
    private function attackPass(Request $request): string
    {
        $s = $this->settings;
        foreach ($s->contentRules as $r) {
            $content = $request->content($r['target']);
            foreach ($r['patterns'] as $p) {
                if (@preg_match($p, $content) === 1) {
                    $i = BlockedPathRule::excepted($s->blockExceptions, $p, $request);
                    if ($i !== null) {
                        $x = $s->blockExceptions[$i];
                        return 'would be refused (' . Describe::rule($s, 'contentRules', $p) . '), but open here'
                            . ($x['ips'] !== [] ? " for $request->clientIp (" . implode(', ', $x['ips']) . ')' : ' for everyone')
                            . ' — ' . ($s->origin('blockExceptions', $x['paths'][0] ?? '') ?? "blockExceptions[$i]");
                    }
                }
            }
        }
        return 'no attack pattern in the address or the headers';
    }

    /** What the known parameters make of the query. */
    private function queryPass(Request $request): string
    {
        $s = $this->settings;
        if ($s->queryParams === [] && !$s->queryStrict) {
            return 'no known parameters configured';
        }
        if ($request->query === '') {
            return 'no parameters';
        }
        $problem = $this->queryProblem($request);
        return $problem === null ? 'every parameter known and of its type'
            : $problem . ' — answered, not cached, scanned by the attack patterns';
    }

    private function queryProblem(Request $request): ?string
    {
        $rule = new \CjwNetwork\RequestShield\Rule\QueryRule($this->settings->queryIndex);
        foreach ($request->queryPairs() as [$name, $value]) {
            $type = $rule->type($name, $request->matchPath());
            if ($type === null) {
                return "\"$name\" is not a known parameter here";
            }
            if (!\CjwNetwork\RequestShield\Rule\QueryRule::fits($type, $value)) {
                return "\"$name\" is not " . (strncmp($type, '#', 1) === 0 ? 'of its pattern' : "of the type $type");
            }
        }
        return null;
    }

    private function restrictedPass(Request $request): string
    {
        if ($this->settings->restricted === []) {
            return 'no areas are restricted';
        }
        $for = $this->restrictedFor($request);
        return $for === '' ? 'not a restricted area' : "a restricted area, and $request->clientIp is allowed ($for)";
    }

    private function restrictedFor(Request $request): string
    {
        foreach ($this->settings->restricted as $r) {
            foreach ($r['paths'] as $p) {
                if (@preg_match($p, $request->matchPath()) === 1) {
                    return implode(', ', $r['ips']);
                }
            }
        }
        return '';
    }
}
