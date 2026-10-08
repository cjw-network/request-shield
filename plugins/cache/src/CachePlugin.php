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
use CjwNetwork\RequestShield\Handler;
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
 */
final class CachePlugin implements Plugin, Handler
{
    /** Headers never kept: they belong to one answer, or the web server makes them. */
    private const DROP = ['set-cookie', 'date', 'age', 'x-rs', 'x-rs-monitor', 'x-rs-cache', 'content-length', 'transfer-encoding', 'connection', 'keep-alive'];

    /** @var array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>} */
    private array $o;

    private string $body = '';

    /** Something of the answer was thrown away, cut off or too large: it is not kept. */
    private bool $spoiled = false;

    /** The script has ended (the shutdown functions run before the last buffers are sent). */
    private bool $ending = false;

    public function __construct(Settings $settings)
    {
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
        if (!$this->o['enabled'] || !$decision->cacheable() || ($request->method !== 'GET' && $request->method !== 'HEAD') || !$this->anonymous($request)
            || !$this->ownHost($request) || !self::plainAddress($request)) {
            return null;
        }
        $key = $request->cacheKey();
        $cache = new FileCache($this->o['dir']);
        $now = microtime(true);
        $hit = $cache->get($key, $now);
        if ($hit !== null) {
            $headers = [...$hit['headers'], 'Age: ' . max(0, (int) $now - $hit['stored']), 'X-RS-Cache: hit'];
            $etag = self::header($hit['headers'], 'etag');
            if ($hit['status'] === 200 && $etag !== null && trim((string) $request->header('if-none-match')) === $etag) {
                return new Response(304, $headers, '');
            }
            return new Response($hit['status'], $headers, $request->method === 'HEAD' ? '' : $hit['body']);
        }
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
        ob_start(function (string $buffer, int $phase) use ($cache, $key, $request): string {
            if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                $this->spoiled = true;
            } elseif (!$this->spoiled) {
                $this->body .= $buffer;
                $this->spoiled = strlen($this->body) > $this->o['maxObject'];
            }
            if ($this->spoiled) {
                $this->body = '';
            } elseif (($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0 && $this->ending) {
                $this->keep($cache, $key, (int) http_response_code(), headers_list(), $this->body, $request->path);
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
     * http-cache-max-object bytes. Every line of a header counts.
     *
     * @param list<string> $headers headers_list()
     */
    public function keep(FileCache $cache, string $key, int $status, array $headers, string $body, string $path = ''): bool
    {
        if (strlen($body) > $this->o['maxObject'] || self::refusal($status, $headers, $this->o['ttl']) !== null) {
            return false;
        }
        $cc = strtolower((string) self::header($headers, 'cache-control'));
        $ttl = preg_match('/\bs-maxage=(\d+)/', $cc, $m) === 1 || preg_match('/\bmax-age=(\d+)/', $cc, $m) === 1 ? (int) $m[1] : $this->o['ttl'];
        $kept = [];
        foreach ($headers as $h) {
            $name = strtolower(trim((string) strstr($h, ':', true)));
            if ($name !== '' && !in_array($name, self::DROP, true)) {
                $kept[] = $h;
            }
        }
        return $cache->put($key, $status, $kept, $body, $ttl, microtime(true), $path);
    }

    /**
     * Why an answer may not be kept, from its status and headers -- null when
     * it may (keep() then checks its size): "status" (not 200, 301, 308),
     * "cookie" (it sets one), "encoded" (the application compressed it),
     * "private" (private, no-store, no-cache, Pragma: no-cache), "expired" (an
     * Expires gone by), "vary" (on more than the encoding), "ttl" (max-age=0,
     * or no ttl at all). The statistics ask it too, for a miss (0046): why the
     * page did not go into the cache.
     *
     * @param list<string> $headers headers_list()
     */
    public static function refusal(int $status, array $headers, int $ttl): ?string
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
        if (preg_match('/\b(private|no-store|no-cache)\b/', $cc) === 1 || preg_match('/\bno-cache\b/i', (string) self::header($headers, 'pragma')) === 1) {
            return 'private';
        }
        $expires = self::header($headers, 'expires');
        if ($expires !== null && strpos($cc, 'max-age') === false && (int) strtotime($expires) <= time()) {
            return 'expired';           // an Expires gone by (or one that is no date): not for a cache
        }
        $vary = array_filter(array_map('trim', explode(',', strtolower((string) self::header($headers, 'vary')))));
        if (array_diff($vary, ['accept-encoding']) !== []) {
            return 'vary';              // an answer that differs by language or cookie: not one page
        }
        $own = preg_match('/\bs-maxage=(\d+)/', $cc, $m) === 1 || preg_match('/\bmax-age=(\d+)/', $cc, $m) === 1 ? (int) $m[1] : $ttl;
        return $own <= 0 ? 'ttl' : null;
    }

    /** No Authorization, and no cookie but those named harmless (analytics, the pass). */
    private function anonymous(Request $request): bool
    {
        if ($request->header('authorization') !== null) {
            return false;
        }
        foreach (explode(';', (string) $request->header('cookie')) as $pair) {
            $name = trim((string) strstr($pair . '=', '=', true));
            if ($name === '') {
                continue;
            }
            $harmless = false;
            foreach ($this->o['cookies'] as $glob) {
                $harmless = $harmless || fnmatch($glob, $name);
            }
            if (!$harmless) {
                return false;       // a session, a cart, a login: the page may be someone's own
            }
        }
        return true;
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
