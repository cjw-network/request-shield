<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Cache;

use CjwNetwork\RequestShield\ApiProvider;
use CjwNetwork\RequestShield\Endpoint;
use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

/**
 * The HTTP cache as a plugin (0031 G.2, RSF04-03): `set http-cache on` keeps
 * the public answers of the addresses a cache may keep (cache-path,
 * cache-query) and answers them before the application starts. Off by
 * default; a request pays nothing for it then -- not even the plugin's class.
 *
 *   set http-cache on
 *   set http-cache-hosts www.example.org example.org   the site's names, as sent (required: nothing is kept without)
 *   set http-cache-ttl 5m              when the answer says nothing (its s-maxage or max-age wins)
 *   set http-cache-cookies _ga* _pk_*  cookies that do not make a page someone's own (default: analytics, the pass)
 *   set http-cache-max-object 1M       the largest answer kept
 *   set http-cache-dir /var/cache/rs   where (default: <store-dir>/http-cache)
 *   set http-cache-purgers 127.0.0.1 ::1   who may purge with a request (PURGE, PURGEKEYS; default: nobody)
 *   set http-cache-purge-token …       or anyone who sends it as X-Invalidate-Token (16 characters or more)
 *   set http-cache-tag-headers X-My-Tags   a header with tags besides the known ones (TAG_HEADERS)
 *   set http-cache-session-cookie eZSESSID*   the session cookies a page per role is kept for
 *   set http-cache-user-context on     the role from FOSHttpCache's user hash (/_fos_user_context_hash), or a URL to ask
 *   set http-cache-user-hash-header X-User-Hash   its header (default X-User-Context-Hash; Exponential Platform: X-User-Hash)
 */
final class CacheExtension implements Extension, ApiProvider
{
    /** Cookies that do not make a page someone's own: analytics, and the shield's pass. */
    public const COOKIES = ['_ga', '_ga_*', '_gid', '_gat*', '_pk_*', '_fbp', 'rsp'];

    /**
     * The headers an answer's tags come in (0039): Varnish's xkey (Ibexa,
     * Exponential Platform), FOSHttpCache's X-Cache-Tags, LiteSpeed's, the
     * CDNs', Magento's. Read, and taken out of the answer: they name content.
     */
    public const TAG_HEADERS = ['xkey', 'x-cache-tags', 'x-litespeed-tag', 'surrogate-key', 'cache-tag', 'edge-cache-tag', 'x-magento-tags'];

    /**
     * Who may purge with a request when nothing is set: nobody. Not this
     * machine, as Symfony's AppCache has it: behind a local proxy that adds
     * no forwarding header (nginx's proxy_pass by default) every visitor
     * comes from 127.0.0.1, and anyone could empty the cache.
     */
    public const PURGERS = [];

    public static function id(): string
    {
        return 'cache';
    }

    /** @return array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>, purgers: list<string>, token: string, tagHeaders: list<string>, sessionCookies: list<string>, contextTtl: int, userContext: string, hashHeader: string, memoryObject: int, memory: int, disk: int} */
    public static function of(Settings $s): array
    {
        $o = $s->ext['cache'] ?? null;
        /** @var array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>, purgers: list<string>, token: string, tagHeaders: list<string>, sessionCookies: list<string>, contextTtl: int, userContext: string, hashHeader: string, memoryObject: int, memory: int, disk: int} $o */
        $o = is_array($o) && isset($o['dir']) ? $o : ['enabled' => false, 'ttl' => 300, 'cookies' => self::COOKIES, 'maxObject' => 1048576, 'dir' => '', 'hosts' => [],
            'purgers' => self::PURGERS, 'token' => '', 'tagHeaders' => self::TAG_HEADERS, 'sessionCookies' => [], 'contextTtl' => 600, 'userContext' => '', 'hashHeader' => 'x-user-context-hash',
            'memoryObject' => 262144, 'memory' => 33554432, 'disk' => 268435456];
        // The folder: the one set, else below the store directory -- resolved here, so the compiled
        // settings do not depend on where they were compiled.
        $o['dir'] = $o['dir'] !== '' ? $o['dir'] : $s->storeDir . '/http-cache';
        return $o;
    }

    public static function vocabulary(Vocabulary $v): void
    {
        $v->set('http-cache', 'bool', 'the HTTP cache: on or off (default)', null, 'enabled');
        $v->set('http-cache-ttl', 'seconds', 'how long an answer is kept when it says nothing itself (default 5m)', null, 'ttl');
        $v->set('http-cache-cookies', 'words', 'cookies that do not make a page someone\'s own (default: analytics, the pass)', null, 'cookies');
        $v->set('http-cache-max-object', 'words', 'the largest answer kept: 1M, 500K (default 1M)', self::size('http-cache-max-object'), 'maxObject');
        $v->set('http-cache-memory-object', 'words', 'the largest answer kept in memory, with APCu (default 256K; 0: none in memory)', self::size('http-cache-memory-object'), 'memoryObject');
        $v->set('http-cache-memory', 'words', 'the most of APCu the cache\'s answers may take (default 32M)', self::size('http-cache-memory'), 'memory');
        $v->set('http-cache-disk', 'words', 'the most the cache\'s folder may hold -- above it the oldest answers go (default 256M; 0: no cap)', self::size('http-cache-disk'), 'disk');
        $v->set('http-cache-hosts', 'words', 'the site\'s host names as visitors send them, a port written out (www.example.org example.org:8080) -- only these are kept', null, 'hosts');
        $v->set('http-cache-dir', 'path', 'where the answers are kept (default <store-dir>/http-cache)', null, 'dir');
        $v->set('http-cache-purgers', 'words', 'the addresses that may purge with a request -- PURGE, PURGEKEYS (default: nobody; 127.0.0.1 ::1 for a CMS on this machine, when no proxy runs on it)', null, 'purgers');
        $v->set('http-cache-purge-token', 'string', 'or anyone who sends this as X-Invalidate-Token (16 characters or more; never shown)', null, 'token');
        $v->set('http-cache-session-cookie', 'words', 'the session cookies a page per role is kept for (wordpress_logged_in_* eZSESSID* PHPSESSID); the application names the role with Shield::active()?->cacheContext() (default: none, no pages per role)', null, 'sessionCookies');
        $v->set('http-cache-context-ttl', 'seconds', 'how long a session\'s role is remembered after the application last named it (default 10m)', null, 'contextTtl');
        $v->set('http-cache-user-context', 'string', 'the role of a visitor with a session from FOSHttpCache\'s user hash: on (asks the site itself, /_fos_user_context_hash) or the address to ask (http://127.0.0.1:8080); off by default', static function ($value, string $at): string {
            $s = is_scalar($value) ? trim((string) $value) : '';
            if (in_array(strtolower($s), ['off', 'on'], true)) {
                return strtolower($s) === 'off' ? '' : 'on';
            }
            if (preg_match('~^(https?://[a-z0-9.:\[\]-]+)(/[^\s?#]*)?$~i', $s, $m) === 1) {
                return strtolower($m[1]) . rtrim($m[2] ?? '', '/');      // the scheme and host in small letters, the path as given
            }
            throw new RuleFileException("$at: http-cache-user-context is on, off or the address to ask (http://127.0.0.1:8080), not \"$s\"");
        }, 'userContext');
        $v->set('http-cache-user-hash-header', 'string', 'the header of the user hash: X-User-Context-Hash (default, Ibexa) or X-User-Hash (Exponential Platform)', static function ($value, string $at): string {
            $s = is_scalar($value) ? strtolower(trim((string) $value)) : '';
            if ($s !== 'x-user-context-hash' && $s !== 'x-user-hash') {
                throw new RuleFileException("$at: http-cache-user-hash-header is X-User-Context-Hash or X-User-Hash, not \"$s\"");
            }
            return $s;
        }, 'hashHeader');
        $v->set('http-cache-tag-headers', 'words', 'a header with an answer\'s tags besides the known ones (xkey, X-Cache-Tags, X-LiteSpeed-Tag, Surrogate-Key, Cache-Tag, Edge-Cache-Tag, X-Magento-Tags)', null, 'tagHeaders');
    }

    /** A size in a rule file: 1M, 500K, 2G, a number of bytes. */
    private static function size(string $name): \Closure
    {
        return static function ($value, string $at) use ($name): int {
            $first = is_array($value) ? ($value[0] ?? '') : '';
            $s = is_scalar($first) ? (string) $first : '';
            if (preg_match('/^(\d{1,12})([kmg])?$/i', $s, $m) !== 1) {
                throw new RuleFileException("$at: $name is a size (1M, 500K), not \"$s\"");
            }
            return (int) $m[1] * ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2] ?? '')];
        };
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>, purgers: list<string>, token: string, tagHeaders: list<string>, sessionCookies: list<string>, contextTtl: int, userContext: string, hashHeader: string, memoryObject: int, memory: int, disk: int}
     */
    public static function compile(array $raw, Settings $base): array
    {
        $enabled = $raw['enabled'] ?? false;
        $ttl = $raw['ttl'] ?? 300;
        $cookies = $raw['cookies'] ?? self::COOKIES;
        $max = $raw['maxObject'] ?? 1048576;
        $dir = $raw['dir'] ?? '';
        $hosts = $raw['hosts'] ?? [];
        $purgers = $raw['purgers'] ?? self::PURGERS;
        $token = $raw['token'] ?? '';
        $tagHeaders = $raw['tagHeaders'] ?? [];
        $sessionCookies = $raw['sessionCookies'] ?? [];
        $contextTtl = $raw['contextTtl'] ?? 600;
        $userContext = $raw['userContext'] ?? '';
        $hashHeader = $raw['hashHeader'] ?? 'x-user-context-hash';
        $memoryObject = $raw['memoryObject'] ?? 262144;
        $memory = $raw['memory'] ?? 33554432;
        $disk = $raw['disk'] ?? 268435456;
        if (!is_int($memoryObject) || $memoryObject < 0 || !is_int($memory) || $memory < 0 || !is_int($disk) || $disk < 0) {
            throw Settings::wrong('ext.cache.memory', 'memoryObject, memory and disk sizes in bytes (0 or more)');
        }
        if (!is_string($userContext) || ($userContext !== '' && $userContext !== 'on' && preg_match('~^https?://[a-z0-9.:\[\]-]+(/[^\s?#]*)?$~D', $userContext) !== 1)
            || !in_array($hashHeader, ['x-user-context-hash', 'x-user-hash'], true)) {
            throw Settings::wrong('ext.cache.userContext', 'userContext "", "on" or an http(s) address; hashHeader x-user-context-hash or x-user-hash');
        }
        if (!is_bool($enabled) || !is_int($ttl) || $ttl < 0 || !is_int($max) || $max < 1 || !is_string($dir) || !is_array($cookies) || !is_array($hosts)
            || !is_array($purgers) || !is_string($token) || !is_array($tagHeaders) || !is_array($sessionCookies) || !is_int($contextTtl) || $contextTtl < 1) {
            throw Settings::wrong('ext.cache', 'enabled on/off, ttl seconds, maxObject bytes, dir a folder, cookies names, hosts names, purgers addresses, token a word, tagHeaders names, sessionCookies names, contextTtl seconds');
        }
        $sessions = [];
        foreach ($sessionCookies as $c) {
            if (!is_string($c) || preg_match('/^[A-Za-z0-9_.*-]{1,128}$/', $c) !== 1) {
                throw Settings::wrong('ext.cache.sessionCookies', 'cookie names, * for any characters (wordpress_logged_in_* eZSESSID*)');
            }
            $sessions[] = $c;
        }
        $ranges = [];
        foreach ($purgers as $p) {
            if (!is_string($p) || !self::range($p)) {
                throw Settings::wrong('ext.cache.purgers', 'addresses or ranges (127.0.0.1 ::1 10.0.0.0/8)');
            }
            $ranges[] = $p;
        }
        if ($token !== '' && preg_match('/^[\x21-\x7e]{16,}$/', $token) !== 1) {
            throw Settings::wrong('ext.cache.token', 'a word of 16 characters or more');   // never the value: it is a credential
        }
        $headers = self::TAG_HEADERS;
        foreach ($tagHeaders as $h) {
            if (!is_string($h) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $h) !== 1) {
                throw Settings::wrong('ext.cache.tagHeaders', 'header names (X-My-Tags)');
            }
            $headers[] = strtolower($h);
        }
        $names2 = [];
        foreach ($hosts as $h) {
            if (!is_string($h) || preg_match('/^[a-z0-9]([a-z0-9.-]{0,252})(:\d{1,5})?$/', strtolower($h)) !== 1) {
                throw Settings::wrong('ext.cache.hosts', 'host names (www.example.org), a port written out (example.org:8080)');
            }
            $names2[] = strtolower($h);
        }
        $names = [];
        foreach ($cookies as $c) {
            if (!is_string($c) || preg_match('/^[A-Za-z0-9_.*-]{1,64}$/', $c) !== 1) {
                throw Settings::wrong('ext.cache.cookies', 'cookie names, * as a wildcard (_ga*)');
            }
            $names[] = $c;
        }
        return ['enabled' => $enabled, 'ttl' => $ttl, 'cookies' => $names, 'maxObject' => $max, 'dir' => $dir, 'hosts' => $names2,
            'purgers' => $ranges, 'token' => $token, 'tagHeaders' => array_values(array_unique($headers)), 'sessionCookies' => $sessions, 'contextTtl' => $contextTtl,
            'userContext' => $userContext, 'hashHeader' => $hashHeader, 'memoryObject' => min($memoryObject, $max), 'memory' => $memory, 'disk' => $disk];
    }

    /** An address, or a range of them (10.0.0.0/8). */
    private static function range(string $p): bool
    {
        $parts = explode('/', $p, 2);
        $bin = @inet_pton($parts[0]);
        return $bin !== false && (!isset($parts[1]) || (ctype_digit($parts[1]) && (int) $parts[1] <= strlen($bin) * 8));
    }

    public static function plugins(array $compiled): array
    {
        return ($compiled['enabled'] ?? false) === true ? [CachePlugin::class] : [];
    }

    public static function routes(array $compiled): array
    {
        return [];
    }

    public static function commands(): array
    {
        return ['cache' => Cli\CacheCommand::class];
    }

    /** What makes a cache keep what it should not. */
    public static function check(Settings $s): array
    {
        if (!self::of($s)['enabled']) {
            return [];
        }
        $out = [];
        if (self::of($s)['hosts'] === []) {
            $out[] = 'http-cache on, but no http-cache-hosts: nothing is kept -- name the site\'s host names as visitors send them (http-cache-hosts www.example.org example.org)';
        }
        if (self::of($s)['userContext'] !== '' && self::of($s)['sessionCookies'] === []) {
            $out[] = 'http-cache-user-context on, but no http-cache-session-cookie: no visitor has a session to ask the role for -- name the session cookie (http-cache-session-cookie eZSESSID*)';
        }
        if (self::of($s)['userContext'] !== '' && \CjwNetwork\RequestShield\Http::offline() !== null) {
            $out[] = 'http-cache-user-context on, but this PHP cannot ask anything (allow_url_fopen off, no curl): no role is found';
        }
        if ($s->cacheableQuery === null) {
            $out[] = 'http-cache on, and every query parameter is cacheable (no cache-query): made-up parameters fill the cache -- name the ones the site uses (cache-query page sort)';
        }
        return $out;
    }

    /** The cache in the API (RSF06-05): what it holds, and emptying it. */
    public static function api(): array
    {
        return [
            new Endpoint('GET', '/cache', 'admin', false, Api\Status::class, Api\Status::SCHEMA, 'RSF04-03', 'The HTTP cache: on or off, how many answers it holds, how many bytes.'),
            new Endpoint('POST', '/cache/purge', 'admin', true, Api\Purge::class, Api\Purge::SCHEMA, 'RSF04-03',
                'Empties the HTTP cache, or the addresses below a path -- after a page changed.', Api\Purge::PARAMS),
        ];
    }
}
