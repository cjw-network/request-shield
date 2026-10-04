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
 */
final class CacheExtension implements Extension, ApiProvider
{
    /** Cookies that do not make a page someone's own: analytics, and the shield's pass. */
    public const COOKIES = ['_ga', '_ga_*', '_gid', '_gat*', '_pk_*', '_fbp', 'rsp'];

    public static function id(): string
    {
        return 'cache';
    }

    /** @return array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>} */
    public static function of(Settings $s): array
    {
        $o = $s->ext['cache'] ?? null;
        /** @var array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>} $o */
        $o = is_array($o) && isset($o['dir']) ? $o : ['enabled' => false, 'ttl' => 300, 'cookies' => self::COOKIES, 'maxObject' => 1048576, 'dir' => '', 'hosts' => []];
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
        $v->set('http-cache-max-object', 'words', 'the largest answer kept: 1M, 500K (default 1M)', static function ($value, string $at): int {
            $first = is_array($value) ? ($value[0] ?? '') : '';
            $s = is_scalar($first) ? (string) $first : '';
            if (preg_match('/^(\d+)([km])?$/i', $s, $m) !== 1) {
                throw new RuleFileException("$at: http-cache-max-object is a size (1M, 500K), not \"$s\"");
            }
            return (int) $m[1] * ['' => 1, 'k' => 1024, 'm' => 1048576][strtolower($m[2] ?? '')];
        }, 'maxObject');
        $v->set('http-cache-hosts', 'words', 'the site\'s host names as visitors send them, a port written out (www.example.org example.org:8080) -- only these are kept', null, 'hosts');
        $v->set('http-cache-dir', 'path', 'where the answers are kept (default <store-dir>/http-cache)', null, 'dir');
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{enabled: bool, ttl: int, cookies: list<string>, maxObject: int, dir: string, hosts: list<string>}
     */
    public static function compile(array $raw, Settings $base): array
    {
        $enabled = $raw['enabled'] ?? false;
        $ttl = $raw['ttl'] ?? 300;
        $cookies = $raw['cookies'] ?? self::COOKIES;
        $max = $raw['maxObject'] ?? 1048576;
        $dir = $raw['dir'] ?? '';
        $hosts = $raw['hosts'] ?? [];
        if (!is_bool($enabled) || !is_int($ttl) || $ttl < 0 || !is_int($max) || $max < 1 || !is_string($dir) || !is_array($cookies) || !is_array($hosts)) {
            throw Settings::wrong('ext.cache', 'enabled on/off, ttl seconds, maxObject bytes, dir a folder, cookies names, hosts names');
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
        return ['enabled' => $enabled, 'ttl' => $ttl, 'cookies' => $names, 'maxObject' => $max, 'dir' => $dir, 'hosts' => $names2];
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
