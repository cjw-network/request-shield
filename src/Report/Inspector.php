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

    /** @param string $lang the words of the steps: en or de */
    public function __construct(private Settings $settings, ?Store $store = null, private string $lang = 'en')
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
     * Every step of the chain (Shield::chain(), 0031 C.1) tried against the
     * request, in words; then the browser check and the verdict.
     *
     * @return array{steps: list<array{check: string, key: string, state: string, text: string, rule: ?string}>, decision: Decision, verdict: string, rule: ?string, watched: ?string}
     */
    public function trace(Request $request, ?float $now = null): array
    {
        $now ??= microtime(true);
        $s = $this->settings;
        $l = $this->lang;
        $w = fn (string $en, string ...$a): string => $this->w($en, ...$a);
        $pattern = static fn (string $p): string => Describe::pattern($s, $p);
        /** @var list<array{check: string, key: string, state: string, text: string, rule: ?string}> $steps */
        $steps = [];
        $stopped = false;
        $step = function (string $check, string $key, ?Decision $d, string $passText, callable $stopText, ?string $name = null) use (&$steps, &$stopped, $request): void {
            $name ??= $this->w($check);
            if ($stopped) {
                $steps[] = ['check' => $name, 'key' => $key, 'state' => 'skip', 'text' => $this->w('not checked: already refused above'), 'rule' => null];
                return;
            }
            if ($d === null) {
                $steps[] = ['check' => $name, 'key' => $key, 'state' => 'pass', 'text' => $passText, 'rule' => null];
                return;
            }
            $text = $stopText($d);
            $stop = $d->action === Decision::REJECT || $d->action === Decision::THROTTLE;
            $stopped = $d->action === Decision::REJECT;
            $steps[] = ['check' => $name, 'key' => $key, 'state' => $stop ? 'stop' : 'note', 'text' => is_string($text) ? $text : '', 'rule' => $this->shield->explain($d, $request)];
        };
        $methods = implode(', ', $s->methods);

        foreach ($this->shield->chain() as $st) {
            // The step's rule, tried where its words want it (the budgets and the cache
            // have tries of their own; feeds and crawlers are described, not run).
            $try = static fn (): ?Decision => $st->rule?->check($request, $now);
            switch ($st->key) {
                case 'deny':
                    $step($st->describe, $st->key, $try(), $st->rule === null ? $w('no address is kept out') : $w('%s is not on the deny list', $request->clientIp),
                        static fn (): string => $w('%s is on the deny list: 403 before everything else', $request->clientIp));
                    break;
                case 'feed':
                    // The lists named "check" too: the browser check, not a refusal.
                    $feed = $s->feeds === [] ? null : ($this->shield->feedHit($request, 'deny') !== null ? Decision::reject(403, 'feed')
                        : ($this->shield->feedHit($request, 'check') !== null ? Decision::challenge('feed') : null));
                    $step($st->describe, $st->key, $feed, $s->feeds === [] ? $w('no public blocklists') : $w('%s is on none of the public blocklists', $request->clientIp),
                        static fn (Decision $d): string => $d->action === Decision::REJECT ? $w('%s is on a public blocklist: 403', $request->clientIp)
                            : $w('%s is on a public blocklist: the browser check', $request->clientIp));
                    break;
                case 'ban':
                    $step($st->describe, $st->key, $try(), $st->rule === null ? $w('no automatic bans') : $w('%s is not banned', $request->clientIp),
                        static fn (Decision $d): string => $w('banned for a while: %s more seconds, nothing but 429', (string) $d->retryAfter));
                    break;
                case 'method':
                    $step($st->describe, $st->key, $try(), $w('%s is accepted (%s)', $request->method, $methods),
                        static fn (): string => $w('%s is not accepted — only %s', $request->method, $methods));
                    break;
                case 'limits':
                    $step($st->describe, $st->key, $try(), $w('address, parameters and headers within the limits (%s characters, %s parameters)', (string) $s->maxUri, (string) $s->maxQueryParameters),
                        static fn (Decision $d): string => ucfirst(Describe::reason($d->reason, $l)));
                    break;
                case 'path':
                    $step($st->describe, $st->key, $try(), $w('the address is what it seems: no hidden encoding, no way out of the website\'s folder'),
                        static fn (Decision $d): string => ucfirst(Describe::reason($d->reason, $l)));
                    break;
                case 'host':
                    $step($st->describe, $st->key, $try(), $s->hosts === [] ? $w('any website name is accepted') : $w('"%s" is one of this site\'s names', $request->host),
                        static fn (): string => $w('"%s" is not one of this site\'s names (%s)', $request->host, implode(', ', $s->hosts)));
                    break;
                case 'blocked':
                    $step($st->describe, $st->key, $try(), $this->blockedPass($request), fn (Decision $d): string => $w('refused: %s', $this->blockedMatch($request)));
                    break;
                case 'method-path':
                    $step($st->describe, $st->key, $try(),
                        isset($s->methodPaths[$request->method]) ? $w('%s is allowed at this address', $request->method) : ($s->methodPaths === [] ? $w('no restriction') : $w('no restriction for %s', $request->method)),
                        static fn (): string => $w('a %s is only accepted at: %s', $request->method, implode(', ', array_map($pattern, $s->methodPaths[$request->method] ?? []))));
                    break;
                case 'post-origin':
                    $originPass = $w('not checked (no post-origin rule)');
                    if ($s->postOrigin !== null) {
                        $from = \CjwNetwork\RequestShield\Rule\PostOriginRule::sentFrom($request);
                        $originPass = !in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? $w('not a form (%s)', $request->method)
                            : ($from === null ? $w('it says nowhere where it comes from -- let through here (missing allow, an exception, or an address let in)') : $w('sent from %s: this website, or not checked here', $from));
                    }
                    $step($st->describe, $st->key, $try(), $originPass, static function (Decision $d) use ($w, $request): string {
                        $from = \CjwNetwork\RequestShield\Rule\PostOriginRule::sentFrom($request);
                        return $d->reason === 'cross-site' ? $w('sent from %s -- another website (Origin, else Referer)', (string) $from) : $w('neither Origin nor Referer: %s', $d->action === Decision::CHALLENGE ? $w('the browser check') : $w('refused'));
                    });
                    break;
                case 'restricted':
                    $step($st->describe, $st->key, $try(), $this->restrictedPass($request), fn (): string => $w('only for %s — %s is not one of them', $this->restrictedFor($request), $request->clientIp));
                    break;
                case 'crawlers':
                    // Named crawlers are described whether or not any is refused (the rule exists only then).
                    $crawler = null;
                    $crawlerText = $w('no known crawlers configured');
                    if ($s->crawlers !== []) {
                        $cr = $this->shield->crawlers();
                        $id = $cr->claims((string) $request->header('user-agent'));
                        if ($id === null) {
                            $crawlerText = $w('the User-Agent names no known crawler');
                        } elseif (!$cr->verified($request->clientIp, $id)) {
                            $crawlerText = $w('names %s, but %s is not one of its addresses — an ordinary visitor (when it is checked or stopped, the log notes claimed=%s)', $id, $request->clientIp, $id);
                        } else {
                            $policy = $cr->policy($id);
                            $crawlerText = $w('%s, verified by its address — ', $id) . (['allow' => $w('never given the browser check (its pace is still limited)'), 'check' => $w('checked like any visitor (crawler %s check)', $id)][$policy] ?? $w('refused'));
                            if ($policy === 'block') {
                                $crawler = Decision::reject(403, 'crawler');
                            }
                        }
                    }
                    $step($st->describe, $st->key, $crawler, $crawlerText, static fn (): string => $w('refused (403): the site does not want this crawler'));
                    break;
                case 'query':
                    $step($st->describe, $st->key, $try(), $this->queryPass($request), fn (): string => $w('refused: %s (query strict)', (string) $this->queryProblem($request)));
                    break;
                case 'content':
                    $step($st->describe, $st->key, $try(), $s->contentIndex === [] ? $w('no attack patterns configured') : $this->attackPass($request),
                        fn (): string => $w('refused: %s', $this->attackMatch($request)));
                    break;
                case 'cache':
                    // The inspector's own cache check: without the application's $known callback.
                    $cache = (new CacheableRule($s->cacheablePaths, $s->cacheableQuery))->check($request, $now);
                    $step($st->describe, $st->key, $cache, $w('yes: a known address with known parameters'),
                        static fn (Decision $d): string => $w('answered, but not kept: %s', Describe::reason($d->reason, $l)));
                    break;
                default:
                    if (strncmp($st->key, 'budget:', 7) === 0 && isset($s->budgets[substr($st->key, 7)])) {
                        // The budgets, as they stand (this request included, nothing counted).
                        $b = $s->budgets[substr($st->key, 7)];
                        $name = $w('Pace: "%s"', $b->name);
                        if (!$b->covers($request->matchPath())) {
                            $steps[] = ['check' => $name, 'key' => $st->key, 'state' => $stopped ? 'skip' : 'pass', 'text' => $stopped ? $w('not checked: already refused above') : $w('not counted at this address (only in its area: %s)', implode(', ', array_map($pattern, $b->paths))), 'rule' => null];
                            break;
                        }
                        $exempt = IpAddress::inRanges($request->clientIp, $s->exemptIps);
                        $count = $exempt ? 0 : (int) round($this->store->hit($b->counter() . ':' . IpAddress::bucket($request->clientIp, $s->ipv6Prefix), $b->window, $now));
                        $pace = $exempt ? $w('%s is never counted', $request->clientIp) : $w('%s of %s per %s', (string) $count, (string) $b->limit, Describe::duration($b->window, $l))
                            . ($b->challengeAt !== null ? $w(', browser check from %s', (string) $b->challengeAt) : '');
                        $pd = null;
                        if (!$exempt && $count > $b->limit) {
                            $pd = $b->earnBack ? Decision::spent($b->name, 1) : Decision::throttle($b->name, 1);
                        } elseif (!$exempt && $b->challengeAt !== null && $count > $b->challengeAt) {
                            $pd = Decision::challenge($b->name);
                        }
                        $step($st->describe, $st->key, $pd, $pace, static fn (Decision $d): string => $pace . ($d->action === Decision::THROTTLE ? $w(' — too many: wait')
                            : ($d->spent ? $w(' — too many: the check, then the counter starts again') : $w(' — past the check'))), $name);
                    } else {
                        // A step this inspector has no words for yet (a rule provider's, 0031 C.3): its outcome, plainly.
                        $step($st->describe, $st->key, $try(), $w('passed'), static fn (Decision $d): string => ucfirst(Describe::reason($d->reason, $l)));
                    }
            }
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
        $step('Browser check', 'always', $always, $w('not asked for at this address') . ($s->challenge->exemptPaths !== [] ? $w(' (and never at %s)', implode(', ', array_map($pattern, $s->challenge->exemptPaths))) : ''),
            static fn (): string => $w('every visitor is checked here, once per pass (valid for %s)', Describe::span($s->challenge->passTtl, $l))
                . ($age !== null ? $w('; here only a pass from the last %s', Describe::span($age, $l)) : ''));

        $decision = $this->shield->decide($request, $now);
        $verdict = Describe::verdict($decision, $l);
        if ($s->mode === 'off') {
            $verdict = $w('sees the page — the shield is switched off (set mode off)');
        } elseif ($s->mode === 'monitor' && !$decision->passes()) {
            $verdict = $w('sees the page — monitor mode; enforced, it %s', $verdict);
        }
        // Rules marked "monitor": what they would add, for a request the others let through.
        $watched = null;
        if ($s->monitor !== null && $decision->passes() && $s->mode !== 'off') {
            $m = (new self($s->monitor, $this->store))->shield;
            $d = $m->decide($request, $now);
            if (!$d->passes()) {
                $watched = $w('%s — if the rule marked "monitor" were enforced (%s)', Describe::verdict($d, $l), $m->explain($d, $request) ?? 'monitor');
            }
        }
        return ['steps' => $steps, 'decision' => $decision, 'verdict' => $verdict, 'rule' => $this->shield->explain($decision, $request), 'watched' => $watched];
    }

    /** The text in the tracer's language: English, or German. */
    private function w(string $en, string ...$args): string
    {
        return vsprintf($this->lang === 'de' ? (self::DE[$en] ?? $en) : $en, $args);
    }

    private function blockedMatch(Request $request): string
    {
        $path = strtolower(rawurldecode($request->path));
        foreach ($this->settings->blockedPaths as $p) {
            if (@preg_match($p, $path) === 1) {
                return Describe::rule($this->settings, 'blockedPaths', $p);
            }
        }
        return $this->w('a refused address');
    }

    /** Not blocked -- or blocked, but let through by an exception here. */
    private function blockedPass(Request $request): string
    {
        $path = strtolower(rawurldecode($request->path));
        foreach ($this->settings->blockedPaths as $p) {
            if (@preg_match($p, $path) === 1) {
                $i = BlockedPathRule::excepted($this->settings->blockExceptions, $p, $request);
                if ($i !== null) {
                    return $this->openHere(Describe::rule($this->settings, 'blockedPaths', $p), $i, $request);
                }
            }
        }
        return $this->w('not one of the %s refused kinds of address', (string) count($this->settings->blockedPaths));
    }

    /** "would be refused (…), but open here for …": an exception that applies. */
    private function openHere(string $rule, int $i, Request $request): string
    {
        $x = $this->settings->blockExceptions[$i];
        return $this->w('would be refused (%s), but open here', $rule)
            . ($x['ips'] !== [] ? $this->w(' for %s (%s)', $request->clientIp, implode(', ', $x['ips'])) : $this->w(' for everyone'))
            . ' — ' . ($this->settings->origin('blockExceptions', $x['paths'][0] ?? '') ?? "blockExceptions[$i]");
    }

    private function attackMatch(Request $request): string
    {
        $p = ContentRule::matched($this->settings->contentRules, $this->settings->blockExceptions, null, $request);
        return $p === null ? $this->w('an attack pattern') : Describe::rule($this->settings, 'contentRules', $p);
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
                        return $this->openHere(Describe::rule($s, 'contentRules', $p), $i, $request);
                    }
                }
            }
        }
        return $this->w('no attack pattern in the address or the headers');
    }

    /** What the known parameters make of the query. */
    private function queryPass(Request $request): string
    {
        $s = $this->settings;
        if ($s->queryParams === [] && !$s->queryStrict) {
            return $this->w('no known parameters configured');
        }
        if ($request->query === '') {
            return $this->w('no parameters');
        }
        $problem = $this->queryProblem($request);
        return $problem === null ? $this->w('every parameter known and of its type')
            : $this->w('%s — answered, not cached, scanned by the attack patterns', $problem);
    }

    private function queryProblem(Request $request): ?string
    {
        $rule = new \CjwNetwork\RequestShield\Rule\QueryRule($this->settings->queryIndex);
        foreach ($request->queryPairs() as [$name, $value]) {
            $type = $rule->type($name, $request->matchPath());
            if ($type === null) {
                return $this->w('"%s" is not a known parameter here', $name);
            }
            if (!\CjwNetwork\RequestShield\Rule\QueryRule::fits($type, $value)) {
                return strncmp($type, '#', 1) === 0 ? $this->w('"%s" is not of its pattern', $name) : $this->w('"%s" is not of the type %s', $name, $type);
            }
        }
        return null;
    }

    private function restrictedPass(Request $request): string
    {
        if ($this->settings->restricted === []) {
            return $this->w('no areas are restricted');
        }
        $for = $this->restrictedFor($request);
        return $for === '' ? $this->w('not a restricted area') : $this->w('a restricted area, and %s is allowed (%s)', $request->clientIp, $for);
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

    /** The tracer in German: the English text => its translation (%s: the same values). */
    private const DE = [
        'Kept out' => 'Ausgesperrt', 'Public lists' => 'Öffentliche Listen', 'no public blocklists' => 'keine öffentlichen Sperrlisten',
        '%s is on none of the public blocklists' => '%s steht auf keiner der öffentlichen Sperrlisten', '%s is on a public blocklist: 403' => '%s steht auf einer öffentlichen Sperrliste: 403',
        '%s is on a public blocklist: the browser check' => '%s steht auf einer öffentlichen Sperrliste: der Browser-Check', 'Banned' => 'Zeitsperre', 'no address is kept out' => 'keine Adresse ausgesperrt', '%s is not on the deny list' => '%s steht nicht auf der Sperrliste',
        '%s is on the deny list: 403 before everything else' => '%s steht auf der Sperrliste: 403 vor allem anderen', 'no automatic bans' => 'keine automatischen Sperren',
        '%s is not banned' => '%s ist nicht gesperrt', 'banned for a while: %s more seconds, nothing but 429' => 'für eine Weile gesperrt: noch %s Sekunden, nur 429',
        'Kind of request' => 'Art der Anfrage', 'Size' => 'Größe', 'Disguised address' => 'Getarnte Adresse', 'Website name' => 'Name der Website',
        'Addresses only attackers ask for' => 'Adressen, die nur Angreifer aufrufen', 'Where forms may be sent' => 'Wohin Formulare dürfen', 'Where forms come from' => 'Woher Formulare kommen',
        'not checked (no post-origin rule)' => 'nicht geprüft (keine post-origin-Regel)', 'not a form (%s)' => 'kein Formular (%s)',
        'it says nowhere where it comes from -- let through here (missing allow, an exception, or an address let in)' => 'es sagt nirgends, woher es kommt -- hier durchgelassen (missing allow, eine Ausnahme oder eine freigegebene Adresse)',
        'sent from %s: this website, or not checked here' => 'gesendet von %s: diese Website, oder hier nicht geprüft',
        'sent from %s -- another website (Origin, else Referer)' => 'gesendet von %s -- einer anderen Website (Origin, sonst Referer)',
        'neither Origin nor Referer: %s' => 'weder Origin noch Referer: %s', 'the browser check' => 'der Browser-Check', 'Areas for certain visitors' => 'Bereiche für bestimmte Besucher',
        'Known crawlers' => 'Bekannte Crawler', 'Known parameters' => 'Bekannte Parameter', 'Attack patterns' => 'Angriffsmuster', 'May a cache keep the answer?' => 'Darf ein Cache die Antwort behalten?',
        'Pace' => 'Tempo', 'Pace: "%s"' => 'Tempo: „%s“', 'Browser check' => 'Browser-Check',
        'not checked: already refused above' => 'nicht geprüft: schon oben abgewiesen',
        '%s is accepted (%s)' => '%s ist erlaubt (%s)', '%s is not accepted — only %s' => '%s ist nicht erlaubt — nur %s',
        'address, parameters and headers within the limits (%s characters, %s parameters)' => 'Adresse, Parameter und Header innerhalb der Grenzen (%s Zeichen, %s Parameter)',
        'the address is what it seems: no hidden encoding, no way out of the website\'s folder' => 'die Adresse ist, was sie scheint: keine versteckte Kodierung, kein Weg aus dem Ordner der Website',
        'any website name is accepted' => 'jeder Name der Website wird angenommen', '"%s" is one of this site\'s names' => '„%s“ ist einer der Namen dieser Website',
        '"%s" is not one of this site\'s names (%s)' => '„%s“ ist keiner der Namen dieser Website (%s)', 'refused: %s' => 'abgewiesen: %s',
        '%s is allowed at this address' => '%s ist an dieser Adresse erlaubt', 'no restriction' => 'keine Einschränkung', 'no restriction for %s' => 'keine Einschränkung für %s',
        'a %s is only accepted at: %s' => 'ein %s ist nur hier erlaubt: %s', 'only for %s — %s is not one of them' => 'nur für %s — %s gehört nicht dazu',
        'no known crawlers configured' => 'keine bekannten Crawler eingestellt', 'the User-Agent names no known crawler' => 'der User-Agent nennt keinen bekannten Crawler',
        'names %s, but %s is not one of its addresses — an ordinary visitor (when it is checked or stopped, the log notes claimed=%s)' => 'nennt %s, aber %s ist keine seiner Adressen — ein gewöhnlicher Besucher (wird er geprüft oder gestoppt, vermerkt das Log claimed=%s)',
        '%s, verified by its address — ' => '%s, bestätigt über seine Adresse — ', 'never given the browser check (its pace is still limited)' => 'nie der Browser-Check (sein Tempo bleibt begrenzt)',
        'checked like any visitor (crawler %s check)' => 'geprüft wie jeder Besucher (crawler %s check)', 'refused' => 'abgewiesen',
        'refused (403): the site does not want this crawler' => 'abgewiesen (403): die Website will diesen Crawler nicht', 'refused: %s (query strict)' => 'abgewiesen: %s (query strict)',
        'no attack patterns configured' => 'keine Angriffsmuster eingestellt', 'yes: a known address with known parameters' => 'ja: eine bekannte Adresse mit bekannten Parametern',
        'answered, but not kept: %s' => 'beantwortet, aber nicht behalten: %s', '%s is never counted' => '%s wird nie gezählt', 'not counted at this address (only in its area: %s)' => 'an dieser Adresse nicht gezählt (nur in seinem Bereich: %s)', '%s of %s per %s' => '%s von %s pro %s',
        ', browser check from %s' => ', Browser-Check ab %s', ' — too many: wait' => ' — zu viele: warten', ' — too many: the check, then the counter starts again' => ' — zu viele: der Check, dann beginnt der Zähler neu',
        ' — past the check' => ' — über der Check-Schwelle', 'not asked for at this address' => 'an dieser Adresse nicht verlangt', ' (and never at %s)' => ' (und nie unter %s)',
        'every visitor is checked here, once per pass (valid for %s)' => 'hier wird jeder Besucher geprüft, einmal pro Pass (gültig %s)', '; here only a pass from the last %s' => '; hier nur ein Pass aus den letzten %s',
        'sees the page — the shield is switched off (set mode off)' => 'sieht die Seite — der Schutz ist abgeschaltet (set mode off)',
        'sees the page — monitor mode; enforced, it %s' => 'sieht die Seite — Beobachtungsmodus; durchgesetzt: %s',
        '%s — if the rule marked "monitor" were enforced (%s)' => '%s — würde die Regel mit „monitor“ durchgesetzt (%s)',
        'a refused address' => 'eine abgewiesene Adresse', 'not one of the %s refused kinds of address' => 'keine der %s abgewiesenen Arten von Adressen',
        'would be refused (%s), but open here' => 'würde abgewiesen (%s), ist hier aber offen', ' for %s (%s)' => ' für %s (%s)', ' for everyone' => ' für alle',
        'an attack pattern' => 'ein Angriffsmuster', 'no attack pattern in the address or the headers' => 'kein Angriffsmuster in der Adresse oder den Headern',
        'no known parameters configured' => 'keine bekannten Parameter eingestellt', 'no parameters' => 'keine Parameter', 'every parameter known and of its type' => 'jeder Parameter bekannt und von seinem Typ',
        '%s — answered, not cached, scanned by the attack patterns' => '%s — beantwortet, nicht gecacht, von den Angriffsmustern geprüft',
        '"%s" is not a known parameter here' => '„%s“ ist hier kein bekannter Parameter', '"%s" is not of its pattern' => '„%s“ passt nicht zu seinem Muster', '"%s" is not of the type %s' => '„%s“ ist nicht vom Typ %s',
        'no areas are restricted' => 'kein Bereich ist beschränkt', 'not a restricted area' => 'kein beschränkter Bereich', 'a restricted area, and %s is allowed (%s)' => 'ein beschränkter Bereich, und %s darf hinein (%s)',
    ];
}
