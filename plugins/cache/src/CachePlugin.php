<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Cache;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Capability;
use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\ContextHandler;
use CjwNetwork\RequestShield\Handler;
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\MethodHandler;
use CjwNetwork\RequestShield\Plugin;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Response;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;

/**
 * The HTTP cache (0031 G.2): a page the shield found cacheable is answered
 * from the cache, before the application starts -- or, the first time, the
 * application's answer is kept for the next. Only what is public: no cookie
 * but the ones set http-cache-cookies names, no Authorization, an answer
 * without Set-Cookie and without private, no-store or no-cache. The addresses
 * a cache may keep are the shield's (cache-path, cache-query), on the host
 * names http-cache-hosts lists, exactly as sent (a port is another name).
 *
 * Tags and purges (0031 G.4, proposal 0039): an answer's tags (xkey,
 * X-Cache-Tags, … -- Tags) are kept with it and taken out of what the
 * visitor gets; a purge -- a PURGE or PURGEKEYS request from
 * http-cache-purgers or with the token, an X-LiteSpeed-Purge in any answer --
 * makes every answer with one of its tags out of date at once.
 *
 * Roles (0031 G.4, proposal 0039): the application names the visitor's role
 * through Shield::active()?->cacheContext() (ContextHandler); the cache keeps
 * MAC(secret, the session cookie) -> role in APCu, and a later request with
 * that cookie gets the page kept for the role -- when the application said
 * the page is the same for everyone with it (shared, or Vary: X-User-Hash /
 * X-User-Context-Hash). Such a page leaves as private.
 */
final class CachePlugin implements Plugin, Handler, MethodHandler, ContextHandler
{
    /** The headers FOSHttpCache varies a page by role on (Ibexa, Exponential Platform): one page per role, not per visitor. */
    private const HASH_VARY = ['x-user-hash', 'x-user-context-hash'];

    /** The tag of every page kept for a role, and of every role remembered: purging it forgets them all (roles changed). */
    public const CONTEXT = 'rs-context';

    /** Headers never kept: they belong to one answer, or the web server makes them, or they are for a cache (tags: TAG_HEADERS). */
    private const DROP = ['set-cookie', 'date', 'age', 'x-rs', 'x-rs-monitor', 'x-rs-cache', 'server-timing', 'content-length', 'transfer-encoding', 'connection', 'keep-alive',
        'x-litespeed-purge', 'surrogate-control', 'x-location-id'];

    /** At most this many tags on one answer: one with more is not kept (its purges could not all be followed). */
    private const MAX_TAGS = 500;

    /** The tag every answer has for its address (path and query, any host): PURGE <address> purges it. */
    private const ADDRESS = 'rs-url:';

    /** @var array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>, purgers: list<string>, token: string, tagHeaders: list<string>, sessionCookies: list<string>, contextTtl: int} */
    private array $o;

    private string $body = '';

    /** Something of the answer was thrown away, cut off or too large: it is not kept. */
    private bool $spoiled = false;

    /** The script has ended (the shutdown functions run before the last buffers are sent). */
    private bool $ending = false;

    /** @var list<string>|null the answer's headers as the application set them, before the tags were taken out (null: not sent yet) */
    private ?array $sent = null;

    private ?Tags $tags = null;

    private Settings $settings;

    /** The MAC of the visitor's session cookies (a request with only those and harmless ones), else null. */
    private ?string $session = null;

    /** The visitor's role: remembered for the session, or named by the application during this request. */
    private ?string $context = null;

    /** The application said this answer is the same for everyone with the role. */
    private bool $shared = false;

    /** The application named the role during this request (cacheContext()): only then is its answer kept for the role. */
    private bool $named = false;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->o = CacheExtension::of($settings);
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
    }

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
    }

    public function handle(Request $request, Decision $decision): ?Response
    {
        if (!$this->o['enabled']) {
            return null;
        }
        $visitor = $this->visitor($request);
        if (!$decision->cacheable() || ($request->method !== 'GET' && $request->method !== 'HEAD') || $visitor === 'own'
            || !$this->ownHost($request) || !self::plainAddress($request)) {
            $this->watchHeaders();      // not for the cache, but its tags go and its purges count
            return null;
        }
        $key = $request->cacheKey();
        $cache = new FileCache($this->o['dir']);
        $now = microtime(true);
        if ($visitor === 'session') {
            // Signed in: the page of the visitor's role, when the session's role is known.
            $this->session = $this->sessionOf($request);
            if ($this->session === null) {
                $this->watchHeaders();
                return null;
            }
            $this->context = $this->remembered($this->session);
        }
        $hit = $visitor === 'anonymous' || $this->context !== null ? $cache->get($this->keyFor($key), $now) : null;
        if ($hit !== null && $this->tags()->purgedSince($hit['tags'], $hit['born'])) {
            $hit = null;            // purged since its request began: asked again (and kept anew)
        }
        if ($hit !== null) {
            $headers = [...$hit['headers'], 'Age: ' . max(0, (int) $now - $hit['stored']), 'X-RS-Cache: hit'];
            $etag = self::header($hit['headers'], 'etag');
            if ($hit['status'] === 200 && $etag !== null && trim((string) $request->header('if-none-match')) === $etag) {
                return new Response(304, $headers, '');
            }
            return new Response($hit['status'], $headers, $request->method === 'HEAD' ? '' : $hit['body']);
        }
        $this->watchHeaders();
        if (headers_sent() || $request->method !== 'GET') {
            return null;
        }
        header('X-RS-Cache: miss');
        register_shutdown_function(function (): void {
            $error = error_get_last();
            $this->ending = true;
            $this->spoiled = $this->spoiled || ($error !== null && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) !== 0);
        });
        // The application's answer, caught as it is sent and kept when it is public -- and whole: what
        // the application throws away (ob_clean), an answer it ends before the script does, one larger
        // than http-cache-max-object (not held in memory either) is not kept.
        ob_start(function (string $buffer, int $phase) use ($cache, $key, $request, $now): string {
            if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                $this->spoiled = true;
            } elseif (!$this->spoiled) {
                $this->body .= $buffer;
                $this->spoiled = strlen($this->body) > $this->o['maxObject'];
            }
            if ($this->spoiled) {
                $this->body = '';
            } elseif (($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0 && $this->ending && ($this->session === null || ($this->context !== null && $this->named))) {
                // A signed-in visitor's answer only under the role the application named in this request
                // (a remembered role is no proof: the session may have ended), and only when shared.
                $this->keep($cache, $this->keyFor($key), (int) http_response_code(), $this->sent ?? headers_list(), $this->body, $request->path, $now,
                    $this->session !== null);
            }
            return $buffer;
        });
        return null;
    }

    /**
     * Keeps an answer when it may be kept by anyone: 200, 301 or 308, no
     * cookie set, no private, no-store or no-cache (nor Pragma: no-cache, nor
     * an Expires gone by), not encoded by the application (gzip it made would
     * go to visitors who did not ask for it), no Vary but on encoding; for its
     * own s-maxage or max-age, else http-cache-ttl; at most
     * http-cache-max-object bytes. Every line of a header counts. Its tags (Tags)
     * go with it, and the tag of its address; not kept: a page with ESI (its
     * fragments are not put together here), a LiteSpeed tag "private:", more
     * than MAX_TAGS tags.
     *
     * A page for a role ($role: the visitor is signed in) is kept only when
     * the application said it is the same for everyone with the role -- the
     * adapter's cacheContext(..., shared: true), which also stands for its
     * Cache-Control, or Vary on X-User-Hash / X-User-Context-Hash -- and
     * leaves as "private, no-cache" (no cache behind, no browser keeps one
     * role's page for another). The Vary on the role's hash stays in the
     * answer: a cache in front of the shield still varies by it.
     *
     * @param list<string> $headers headers_list()
     * @param ?float $born when its request began: a purge after it makes it out of date
     */
    public function keep(FileCache $cache, string $key, int $status, array $headers, string $body, string $path = '', ?float $born = null, bool $role = false): bool
    {
        $byRole = $role && $this->shared;
        if (strlen($body) > $this->o['maxObject'] || self::refusal($status, $headers, $this->o['ttl'], $byRole) !== null) {
            return false;
        }
        $vary = array_filter(array_map('trim', explode(',', strtolower((string) self::header($headers, 'vary')))));
        if ($role && !$byRole && array_intersect($vary, self::HASH_VARY) === []) {
            return false;           // a signed-in visitor's page the application did not call the same for the role
        }
        $cc = strtolower((string) self::header($headers, 'cache-control'));
        $ttl = preg_match('/\bs-maxage=(\d+)/', $cc, $m) === 1 || preg_match('/\bmax-age=(\d+)/', $cc, $m) === 1 ? (int) $m[1] : $this->o['ttl'];
        if ($byRole && ($ttl <= 0 || preg_match('/\b(no-store|no-cache)\b/', $cc) === 1)) {
            $ttl = $this->o['ttl'];     // the adapter's word: the page is the role's, kept for http-cache-ttl
        }
        if ($ttl <= 0 || stripos((string) self::header($headers, 'surrogate-control'), 'ESI/') !== false) {
            return false;           // nothing to keep it for (http-cache-ttl 0); ESI: fragments for a cache to put together
        }
        $tags = $this->tagsOf($headers);
        if ($tags === null) {
            return false;
        }
        $tags[] = self::ADDRESS . self::address((string) strstr($key . "\n", "\n", true));     // the address, not the role's suffix
        if ($role) {
            $tags[] = self::CONTEXT;
        }
        $kept = [];
        foreach ($headers as $h) {
            $name = strtolower(trim((string) strstr($h, ':', true)));
            if ($role && ($name === 'cache-control' || $name === 'pragma' || $name === 'expires')) {
                continue;
            } elseif ($name !== '' && !in_array($name, self::DROP, true) && !in_array($name, $this->o['tagHeaders'], true)) {
                $kept[] = $h;
            }
        }
        if ($role) {
            $kept[] = 'Cache-Control: private, no-cache';
        }
        $now = microtime(true);
        return $cache->put($key, $status, $kept, $body, min($ttl, Tags::MAX_AGE), $now, $path, array_values(array_unique($tags)), $born ?? $now);
    }

    /**
     * A request with a method the site does not take (MethodHandler): a
     * purge in the dialects of Varnish/FOSHttpCache, Ibexa and Exponential
     * Platform, from http-cache-purgers or with X-Invalidate-Token -- or
     * null, and the rules refuse it as any unknown method (405): no hint that
     * a cache is there.
     *
     *   PURGE <address>                          that address (path and query, every host)
     *   PURGE / + key: a b                       tags (Exponential Platform; key: ez-all, all of it)
     *   PURGE / + X-Cache-Tags: a,b              tags (FOSHttpCache, Ibexa "local")
     *   PURGE / + X-Location-Id: * | 12 | (1|2)  everything, or location-12 … (Exponential's older calls)
     *   PURGEKEYS / + xkey-purge: a b            tags (Ibexa with Varnish; xkey-softpurge: the same, for now)
     */
    public function handleMethod(Request $request): ?Response
    {
        if (!$this->o['enabled'] || ($request->method !== 'PURGE' && $request->method !== 'PURGEKEYS') || !$this->mayPurge($request)) {
            return null;
        }
        $tags = self::purgeOf($request);
        if ($tags === null) {
            return new Response(400, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], "Nothing to purge\n");
        }
        if (!$this->tags()->purge($tags, microtime(true))) {
            return new Response(500, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], "Not purged\n");
        }
        return new Response(200, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], "Purged\n");
    }

    /**
     * What a purge request names: tags, Tags::ALL, or the tag of its address;
     * null when it names nothing that can be purged.
     *
     * @return list<string>|null
     */
    public static function purgeOf(Request $request): ?array
    {
        if ($request->method === 'PURGEKEYS') {
            $tags = Tags::split((string) ($request->header('xkey-purge') ?? $request->header('xkey-softpurge')));
            return $tags !== [] ? $tags : null;
        }
        $key = $request->header('key') ?? $request->header('x-cache-tags');
        if ($key !== null) {
            $tags = Tags::split($key);
            return $tags !== [] ? $tags : null;
        }
        $location = $request->header('x-location-id');
        if ($location !== null) {
            $location = trim($location);
            if ($location === '*' || $location === '.*') {
                return [Tags::ALL];
            }
            if (preg_match('/^\(?(\d+(?:\|\d+)*)\)?$/', $location, $m) !== 1) {
                return null;
            }
            return array_map(static fn (string $id): string => "location-$id", explode('|', $m[1]));
        }
        return [self::ADDRESS . self::address($request->cacheKey())];
    }

    /**
     * Purges what the application names in its answer (LiteSpeed's way, any
     * method): "X-LiteSpeed-Purge: tag=c52, /news/, *" -- tags, addresses,
     * everything; "private, …" is a browser's own cache, not this one.
     */
    public function purgeFromAnswer(string $value): void
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $i): bool => $i !== ''));
        if ($items === [] || strtolower($items[0]) === 'private') {
            return;
        }
        $tags = [];
        foreach ($items as $item) {
            if ($item === '*') {
                $tags[] = Tags::ALL;
            } elseif (strncasecmp($item, 'tag=', 4) === 0) {
                foreach (Tags::split(substr($item, 4)) as $t) {
                    $tags[] = strncasecmp($t, 'public:', 7) === 0 ? substr($t, 7) : $t;
                }
            } elseif ($item[0] === '/') {
                $tags[] = self::ADDRESS . self::address($item);
            }
        }
        if ($tags !== []) {
            $this->tags()->purge($tags, microtime(true));
        }
    }

    /**
     * The tags an answer carries in the headers http-cache-tag-headers knows,
     * a LiteSpeed "public:" taken off; null when it may not be kept (a
     * "private:" tag, more than MAX_TAGS).
     *
     * @param list<string> $headers
     * @return list<string>|null
     */
    private function tagsOf(array $headers): ?array
    {
        $tags = [];
        foreach ($this->o['tagHeaders'] as $name) {
            foreach (Tags::split((string) self::header($headers, $name)) as $t) {
                if (strncasecmp($t, 'private:', 8) === 0) {
                    return null;    // LiteSpeed: for one visitor's private cache
                }
                $tags[] = strncasecmp($t, 'public:', 7) === 0 ? substr($t, 7) : $t;
            }
        }
        $location = trim((string) self::header($headers, 'x-location-id'));
        if (ctype_digit($location)) {
            $tags[] = "location-$location";     // Exponential's older header
        }
        return count($tags) > self::MAX_TAGS ? null : $tags;
    }

    /**
     * Before the answer's headers go out (any answer, while the cache is
     * on): its purges are done, its tags and purges taken out -- they name
     * content and are for a cache, not for the visitor. headers_list() as it
     * was is kept for keep(): once sent, the headers cannot change.
     */
    private function watchHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_register_callback(function (): void {
            $list = headers_list();
            $this->sent = $list;
            $purge = self::header($list, 'x-litespeed-purge');
            if ($purge !== null) {
                $this->purgeFromAnswer($purge);
            }
            $vary = array_filter(array_map('trim', explode(',', strtolower((string) self::header($list, 'vary')))));
            $byHash = array_intersect($vary, self::HASH_VARY) !== [];       // left in the answer: a cache in front varies by it
            foreach ($list as $h) {
                $name = strtolower(trim((string) strstr($h, ':', true)));
                if ($name === 'x-litespeed-purge' || $name === 'x-location-id' || in_array($name, $this->o['tagHeaders'], true)) {
                    header_remove($name);
                }
            }
            if ($this->session !== null && $this->context !== null && $this->named && ($this->shared || $byHash)) {
                // A page for a role: never kept by a cache behind the shield, nor by a browser for another role.
                header('Cache-Control: private, no-cache');
                header_remove('Expires');
                header_remove('Pragma');
                header('X-RS-Cache: miss; role');     // the statistics: this Cache-Control is the shield's, not the site's
            }
        });
    }

    /**
     * The visitor names a role (ContextHandler, from the application's
     * adapter): remembered in APCu for the session cookies the request
     * carries, keyed by their MAC -- the cookie is the credential, and an
     * unknown or forged one finds nothing. With $shared, this answer may be
     * kept for the role. Nothing without APCu, without
     * http-cache-session-cookie, or without a session cookie on the request.
     */
    public function cacheContext(Request $request, string $context, bool $shared): void
    {
        if (!$this->o['enabled'] || $context === '' || strlen($context) > 200 || !Capability::apcu()) {
            return;
        }
        $session = $this->session ?? $this->sessionOf($request);
        if ($session === null) {
            return;
        }
        apcu_store($this->contextKey($session), [microtime(true), $context], $this->o['contextTtl']);
        $this->session = $session;
        $this->context = $context;
        $this->named = true;
        $this->shared = $this->shared || $shared;
    }

    /** The visitor signed out: the session's role is forgotten, and this answer is not kept for it. */
    public function forgetContext(Request $request): void
    {
        $session = $this->session ?? $this->sessionOf($request);
        if ($session !== null && Capability::apcu()) {
            apcu_delete($this->contextKey($session));
        }
        $this->context = null;
        $this->shared = false;
        $this->named = false;
    }

    /**
     * The role remembered for a session, unless it ran out or the roles
     * were purged since (the tag CONTEXT).
     */
    private function remembered(string $session): ?string
    {
        $got = apcu_fetch($this->contextKey($session));
        if (!is_array($got) || !is_float($got[0] ?? null) || !is_string($got[1] ?? null)
            || $this->tags()->purgedSince([self::CONTEXT], $got[0])) {
            return null;
        }
        return $got[1];
    }

    /**
     * The MAC of the session cookies a request carries (the names
     * http-cache-session-cookie matches, sorted, with their values), or
     * null: none, or no APCu to remember a role in.
     */
    private function sessionOf(Request $request): ?string
    {
        if ($this->o['sessionCookies'] === [] || !Capability::apcu()) {
            return null;
        }
        $pairs = [];
        foreach (explode(';', (string) $request->header('cookie')) as $pair) {
            $name = trim((string) strstr($pair . '=', '=', true));
            if ($name !== '' && !self::matches($name, $this->o['cookies']) && self::matches($name, $this->o['sessionCookies'])) {
                $pairs[] = trim($pair);     // as visitor() reads them: a harmless cookie is never a session
            }
        }
        if ($pairs === []) {
            return null;
        }
        sort($pairs);
        $s = $this->settings;
        return hash_hmac('sha256', 'session|' . implode('; ', $pairs), Secret::resolve($s->challenge->secret, $s->storeDir));
    }

    private function contextKey(string $session): string
    {
        return 'rshield:hc:' . substr(md5($this->o['dir']), 0, 12) . ':ctx:' . $session;
    }

    /** The key of the answer: the address's, and the role's when there is one. */
    private function keyFor(string $key): string
    {
        return $this->context === null ? $key : $key . "\nctx=" . hash('sha256', $this->context);
    }

    /**
     * Who the visitor is to the cache: "anonymous" (no cookie but harmless
     * ones, no Authorization), "session" (besides those only session cookies
     * -- with http-cache-session-cookie and APCu), else "own": the page may
     * be someone's own.
     */
    private function visitor(Request $request): string
    {
        if ($request->header('authorization') !== null || $request->header('x-user-hash') !== null || $request->header('x-user-context-hash') !== null) {
            return 'own';       // a role's hash sent by the client: an application that believes it would make a role's page
        }
        $who = 'anonymous';
        foreach (explode(';', (string) $request->header('cookie')) as $pair) {
            $name = trim((string) strstr($pair . '=', '=', true));
            if ($name === '' || self::matches($name, $this->o['cookies'])) {
                continue;
            }
            if ($this->o['sessionCookies'] === [] || !self::matches($name, $this->o['sessionCookies'])) {
                return 'own';       // a cart, another login: the page may be someone's own
            }
            $who = 'session';
        }
        return $who === 'session' && !Capability::apcu() ? 'own' : $who;
    }

    /** @param list<string> $globs */
    private static function matches(string $name, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * May purge: X-Invalidate-Token equal to http-cache-purge-token, or an
     * address on http-cache-purgers -- the client's address as the shield
     * found it; one that came through a proxy the shield does not trust (it
     * sent forwarding headers) never counts as the proxy's own.
     */
    private function mayPurge(Request $request): bool
    {
        $token = $request->header('x-invalidate-token');
        if ($this->o['token'] !== '' && $token !== null && hash_equals($this->o['token'], $token)) {
            return true;
        }
        if (!$request->viaTrustedProxy) {
            foreach (['x-forwarded-for', 'forwarded', 'x-real-ip', 'via'] as $h) {
                if ($request->header($h) !== null) {
                    return false;
                }
            }
        }
        return IpAddress::inRanges($request->clientIp, $this->o['purgers']);
    }

    /**
     * The address part of a key or of an address as a purge names it --
     * path and sorted query, as Request::cacheKey() makes them; no scheme,
     * no host (a purge from 127.0.0.1 names no host the visitors use).
     */
    public static function address(string $keyOrUri): string
    {
        $uri = preg_replace('#^[a-z][a-z0-9+.-]*://[^/?]*#i', '', $keyOrUri) ?? $keyOrUri;
        $key = Request::fromServer(['REQUEST_URI' => $uri === '' ? '/' : $uri, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'h'])->cacheKey();
        return substr($key, strlen('http://h'));
    }

    private function tags(): Tags
    {
        return $this->tags ??= new Tags($this->o['dir'], Capability::apcu());
    }

    /**
     * Why an answer may not be kept, from its status and headers -- null when
     * it may (keep() then checks its size): "status" (not 200, 301, 308),
     * "cookie" (it sets one), "encoded" (the application compressed it),
     * "private" (private, no-store, no-cache, Pragma: no-cache), "expired" (an
     * Expires gone by), "vary" (on more than the encoding), "ttl" (max-age=0,
     * or no ttl at all). The statistics ask it too, for a miss (0046): why the
     * page did not go into the cache. A Vary on the role's hash (X-User-Hash,
     * X-User-Context-Hash) is the shield's to follow. $byRole: the application
     * called the page the same for the visitor's role (cacheContext(...,
     * shared: true)) -- its private, no-cache, Expires and max-age=0 are for
     * caches behind, not this one.
     *
     * @param list<string> $headers headers_list()
     */
    public static function refusal(int $status, array $headers, int $ttl, bool $byRole = false): ?string
    {
        if (!in_array($status, [200, 301, 308], true)) {
            return 'status';
        }
        if (self::header($headers, 'set-cookie') !== null) {
            return 'cookie';
        }
        if (self::header($headers, 'content-encoding') !== null) {
            return 'encoded';
        }
        $cc = strtolower((string) self::header($headers, 'cache-control'));
        if (!$byRole && (preg_match('/\b(private|no-store|no-cache)\b/', $cc) === 1 || preg_match('/\bno-cache\b/i', (string) self::header($headers, 'pragma')) === 1)) {
            return 'private';
        }
        $expires = self::header($headers, 'expires');
        if (!$byRole && $expires !== null && strpos($cc, 'max-age') === false && (int) strtotime($expires) <= time()) {
            return 'expired';           // an Expires gone by (or one that is no date): not for a cache
        }
        $vary = array_filter(array_map('trim', explode(',', strtolower((string) self::header($headers, 'vary')))));
        if (array_diff($vary, ['accept-encoding'], self::HASH_VARY) !== []) {
            return 'vary';              // an answer that differs by language or cookie: not one page
        }
        $own = preg_match('/\bs-maxage=(\d+)/', $cc, $m) === 1 || preg_match('/\bmax-age=(\d+)/', $cc, $m) === 1 ? (int) $m[1] : $ttl;
        return $own <= 0 && !$byRole ? 'ttl' : null;
    }

    /**
     * The host name as the visitor sent it -- with its port -- on the list
     * http-cache-hosts: an answer is kept under the name without the port, so
     * a page an application built from a made-up Host (links, a redirect) must
     * never be kept, and made-up names never fill the cache.
     */
    private function ownHost(Request $request): bool
    {
        $raw = $request->viaTrustedProxy && $request->header('x-forwarded-host') !== null
            ? explode(',', (string) $request->header('x-forwarded-host'))[0] : (string) $request->header('host');
        return in_array(strtolower(trim($raw)), $this->o['hosts'], true);
    }

    /**
     * An address with one key for one answer: no parameter twice (PHP takes
     * the last, the key sorts them) and no encoded "/", "?" or "#" in the
     * path (the key holds the path decoded).
     */
    private static function plainAddress(Request $request): bool
    {
        $names = $request->queryNames();
        return count($names) === count(array_unique($names)) && preg_match('/%(2f|3f|23)/i', $request->path) !== 1;
    }

    /**
     * A header's value: every line of it, joined with commas (two Vary lines are one list).
     *
     * @param list<string> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $values = [];
        foreach ($headers as $h) {
            if (strncasecmp($h, $name . ':', strlen($name) + 1) === 0) {
                $values[] = trim(substr($h, strlen($name) + 1));
            }
        }
        return $values === [] ? null : implode(', ', $values);
    }
}
