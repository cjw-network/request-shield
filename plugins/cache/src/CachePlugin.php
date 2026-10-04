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
 * a cache may keep are the shield's (cache-path, cache-query): made-up ones
 * never fill it.
 */
final class CachePlugin implements Plugin, Handler
{
    /** Headers never kept: they belong to one answer, or the web server makes them. */
    private const DROP = ['set-cookie', 'date', 'age', 'x-rs', 'x-rs-monitor', 'x-rs-cache', 'content-length', 'transfer-encoding', 'connection', 'keep-alive'];

    /** @var array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string} */
    private array $o;

    private string $body = '';

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
        if (!$this->o['enabled'] || !$decision->cacheable() || ($request->method !== 'GET' && $request->method !== 'HEAD') || !$this->anonymous($request)) {
            return null;
        }
        $key = $request->cacheKey();
        $cache = new FileCache($this->o['dir']);
        $now = microtime(true);
        $hit = $cache->get($key, $now);
        if ($hit !== null) {
            $headers = [...$hit['headers'], 'Age: ' . max(0, (int) $now - $hit['stored']), 'X-RS-Cache: hit'];
            $etag = self::header($hit['headers'], 'etag');
            if ($etag !== null && trim((string) $request->header('if-none-match')) === $etag) {
                return new Response(304, $headers, '');
            }
            return new Response($hit['status'], $headers, $request->method === 'HEAD' ? '' : $hit['body']);
        }
        if (headers_sent() || $request->method !== 'GET') {
            return null;
        }
        header('X-RS-Cache: miss');
        // The application's answer, caught as it is sent and kept when it is public.
        ob_start(function (string $buffer, int $phase) use ($cache, $key): string {
            $this->body .= $buffer;
            if (($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0) {
                $this->keep($cache, $key, (int) http_response_code(), headers_list(), $this->body);
            }
            return $buffer;
        });
        return null;
    }

    /**
     * Keeps an answer when it may be kept by anyone: 200, 301 or 308, no
     * cookie set, no private, no-store or no-cache, no Vary but on encoding;
     * for its own s-maxage or max-age, else http-cache-ttl; at most
     * http-cache-max-object bytes.
     *
     * @param list<string> $headers headers_list()
     */
    public function keep(FileCache $cache, string $key, int $status, array $headers, string $body): bool
    {
        if (!in_array($status, [200, 301, 308], true) || strlen($body) > $this->o['maxObject'] || self::header($headers, 'set-cookie') !== null) {
            return false;
        }
        $cc = strtolower((string) self::header($headers, 'cache-control'));
        if (preg_match('/\b(private|no-store|no-cache)\b/', $cc) === 1) {
            return false;
        }
        $vary = array_filter(array_map('trim', explode(',', strtolower((string) self::header($headers, 'vary')))));
        if (array_diff($vary, ['accept-encoding']) !== []) {
            return false;           // an answer that differs by language or cookie: not one page
        }
        $ttl = preg_match('/\bs-maxage=(\d+)/', $cc, $m) === 1 || preg_match('/\bmax-age=(\d+)/', $cc, $m) === 1 ? (int) $m[1] : $this->o['ttl'];
        if ($ttl <= 0) {
            return false;
        }
        $kept = [];
        foreach ($headers as $h) {
            $name = strtolower(trim((string) strstr($h, ':', true)));
            if ($name !== '' && !in_array($name, self::DROP, true)) {
                $kept[] = $h;
            }
        }
        return $cache->put($key, $status, $kept, $body, $ttl, microtime(true));
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

    /** @param list<string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $h) {
            if (strncasecmp($h, $name . ':', strlen($name) + 1) === 0) {
                return trim(substr($h, strlen($name) + 1));
            }
        }
        return null;
    }
}
