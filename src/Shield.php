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
use CjwNetwork\RequestShield\Rule\ContentRule;
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

    /** Whether this request has passed the browser check (a pass, or a solution just now). */
    private bool $passed = false;

    /** Forms larger than this are not carried through the check (the visitor sends them again). */
    private const RESEND_MAX_BYTES = 262144;

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
        $this->rules[] = new BlockedPathRule($s->blockedPaths, $s->blockExceptions, $s->blockedIndex);
        if ($s->methodPaths !== []) {
            $this->rules[] = new MethodPathRule($s->methodPaths);
        }
        if ($s->restricted !== []) {
            $this->rules[] = new RestrictedPathRule($s->restricted);
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
        self::$current = $decision;
        $shield->passed = $settled['passed'];
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
            case 'app':
                return 'application';
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
     * @return array{solution: ?string, api: bool, earn: array{window: int}|null, resend: array{action: string, fields: list<array{0: string, 1: string}>}|false|null}
     */
    private function gateOptions(Decision $d, Request $request): array
    {
        $api = $this->isApi($request);
        $budget = $d->spent ? ($this->settings->budgets[$d->reason] ?? null) : null;
        return [
            'solution' => $this->postedSolution(),
            'api' => $api,
            'earn' => $budget !== null ? ['window' => $budget->window] : null,
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
        return $w === null ? '' : \CjwNetwork\RequestShield\Challenge\Widget::html($w, $start);
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
        if ($request === null || ($this->passed && $fresh === null)) {
            return;
        }
        $now = microtime(true);
        $resend = $request->method === 'GET' || $request->method === 'HEAD' ? null : self::resendFields($request);
        $r = $this->gate()->resolve(Decision::challenge('app'), Decision::allowUncached('app'), $request, $now,
            ['forced' => true, 'fresh' => $fresh, 'resend' => $resend, 'solution' => $this->postedSolution()]);
        if (!headers_sent()) {
            foreach ($r['cookies'] as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }
        if ($r['decision']->passes()) {
            $this->passed = true;
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
        if ($log && $s->logFile !== null && Log::wants($s->logLevel, $d)) {
            Log::write($s, $request, $d, $rule, $now);
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
        if ($b === null || $request === null) {
            return Decision::allow();
        }
        $now ??= microtime(true);
        $d = $this->budgetRule($b)->check($request, $now) ?? Decision::allow();
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
        if ($this->settings->logFile !== null && $d->action !== Decision::ALLOW && Log::wants($this->settings->logLevel, $d)) {
            Log::write($this->settings, $request, $d, $this->explain($d, $request), $now);
        }
        // answer: true -- the shield answers a refusal itself (a pause, the
        // check) and the request ends here: Shield::active()?->consume('posts', answer: true)
        if ($answer && !$d->passes()) {
            $this->stop($d, $request, null, $now, true, null, false);
            exit;
        }
        return $d;
    }

    private function budgetRule(Budget $b): BudgetRule
    {
        return new BudgetRule($this->store, $b->name, $b->limit, $b->window, $b->challengeAt,
            $this->settings->exemptIps, $this->settings->ipv6Prefix, $b->earnBack);
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
                null,
                null,
                // New DNS lookups per minute, for all requests together (the store).
                function () use ($c): bool {
                    return $c->dnsLookups > 0 && $this->store->hit('se-lookups', 60, microtime(true)) <= $c->dnsLookups;
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
