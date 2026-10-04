<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api;

use CjwNetwork\RequestShield\ApiProvider;
use CjwNetwork\RequestShield\Endpoint;
use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

/**
 * The API (RSF06-05, 0031 G.0): the shield's data as JSON below
 * <dashboard-path>/api/v1, for a CMS's backend, a JavaScript page, a script
 * or a language model -- guarded like every page of the dashboard (a
 * restrict rule, or a token: Authorization: Bearer). The core's endpoints
 * are this extension's own (ApiProvider); every other offered extension that
 * is an ApiProvider adds its own (the statistics: /stats/…). Compiled into
 * ext.api: which endpoints, where; a request that is none of them pays
 * nothing -- the routes lie below dashboard-path, as the pages.
 *
 *   set api off                 no API (on by default: it is as guarded as the pages)
 *   set api-write on            the endpoints that change something (lists, reload, feeds update): off by default
 *   set api-origins https://cms.example.org   other websites' pages that may call it from a browser (CORS); none by default
 */
final class ApiExtension implements Extension, ApiProvider
{
    /** Where the API lives below dashboard-path; its version. */
    public const BASE = '/api/v1';

    public static function id(): string
    {
        return 'api';
    }

    public static function vocabulary(Vocabulary $v): void
    {
        $v->set('api', 'bool', 'the API below <dashboard-path>/api/v1: on (default) or off', null, 'enabled');
        $v->set('api-write', 'bool', 'the API\'s endpoints that change something (lists, reload, feeds update): off (default) or on', null, 'write');
        $v->set('api-origins', 'words', 'websites whose pages may call the API from a browser (CORS): https://cms.example.org …',
            static function ($value, string $at): array {
                $out = [];
                foreach (is_array($value) ? $value : [] as $o) {
                    $o = is_scalar($o) ? rtrim(strtolower((string) $o), '/') : '';
                    if (preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#', $o) !== 1) {
                        throw new RuleFileException("$at: api-origins takes websites as a browser names them (https://cms.example.org), not \"$o\"");
                    }
                    $out[] = $o;
                }
                return $out;
            }, 'origins', serverWide: true);
    }

    /**
     * The endpoints of every offered ApiProvider, where they are and who may
     * call them; the service classes by name. Two on one method and path refuse to compile.
     *
     * @param array<string, mixed> $raw
     * @return array{enabled: bool, write: bool, origins: list<string>, base: string, endpoints: array<string, array{method: string, path: string, role: string, write: bool, service: string, feature: string}>}
     */
    public static function compile(array $raw, Settings $base): array
    {
        $enabled = $raw['enabled'] ?? true;
        $write = $raw['write'] ?? false;
        $origins = $raw['origins'] ?? [];
        if (!is_bool($enabled) || !is_bool($write)) {
            throw Settings::wrong('ext.api', 'enabled and write are true or false');
        }
        if (!is_array($origins) || array_filter($origins, static fn ($o): bool => !is_string($o) || preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#', $o) !== 1) !== []) {
            throw Settings::wrong('ext.api.origins', 'websites such as https://cms.example.org');
        }
        $endpoints = [];
        foreach (self::all() as $ep) {
            $key = $ep->method . ' ' . $ep->path;
            if (isset($endpoints[$key])) {
                throw Settings::wrong('ext.api', "two endpoints are $key");
            }
            $endpoints[$key] = ['method' => $ep->method, 'path' => $ep->path, 'role' => $ep->role, 'write' => $ep->write, 'service' => $ep->service, 'feature' => $ep->feature];
        }
        return ['enabled' => $enabled, 'write' => $write, 'origins' => array_values(array_filter($origins, 'is_string')), 'base' => $base->dashboardPath . self::BASE, 'endpoints' => $endpoints];
    }

    /** @return list<Endpoint> every offered ApiProvider's endpoints, this one's first, the openapi documents last */
    public static function all(): array
    {
        $out = [];
        foreach (Vocabulary::extensions() as $class) {
            if (is_subclass_of($class, ApiProvider::class)) {
                foreach ($class::api() as $ep) {
                    $out[] = $ep;
                }
            }
        }
        return $out;
    }

    public static function plugins(array $compiled): array
    {
        return [];
    }

    /**
     * One route per path (a path may have a GET and a POST): the API's host
     * answers it. The route's role is the least any of its endpoints needs;
     * the host checks each endpoint's own.
     */
    public static function routes(array $compiled): array
    {
        if (($compiled['enabled'] ?? true) !== true || !is_string($compiled['base'] ?? null)) {
            return [];
        }
        $routes = [];
        foreach ((array) ($compiled['endpoints'] ?? []) as $ep) {
            if (!is_array($ep) || !is_string($ep['path'] ?? null)) {
                continue;
            }
            $path = $compiled['base'] . $ep['path'];
            $reader = ($ep['role'] ?? 'admin') === 'reader' || (($routes[$path]['role'] ?? 'admin') === 'reader');
            $routes[$path] = ['key' => 'api', 'tab' => null, 'role' => $reader ? 'reader' : 'admin', 'order' => 900, 'page' => Host::class];
        }
        return $routes;
    }

    public static function commands(): array
    {
        return ['api' => Cli\ApiCommand::class];
    }

    /**
     * What keeps a script from the API: a site's own rules run first, as for
     * every request. Said only where a program may call it -- with tokens,
     * writes or api-origins; the office's browser on the dashboard needs none of it.
     */
    public static function check(Settings $s): array
    {
        $api = is_array($s->ext['api'] ?? null) ? $s->ext['api'] : [];
        // Only where a program may call it: with tokens (dashboard-access), writes, or other websites' pages.
        if (($api['enabled'] ?? true) !== true || ($s->dashboardAccess === [] && ($api['write'] ?? false) !== true && ($api['origins'] ?? []) === [])) {
            return [];
        }
        $base = is_string($api['base'] ?? null) ? $api['base'] : $s->dashboardPath . self::BASE;
        $probe = $base . '/status';
        $apiPath = false;
        foreach ($s->challenge->apiPaths as $p) {
            $apiPath = $apiPath || @preg_match($p, $probe) === 1;
        }
        $out = [];
        if (!$apiPath) {
            $out[] = "the API ($base) is no api-path: a script calling it gets the check page as HTML, and post-origin refuses its POST -- add \"api-path $base/**\"";
        }
        if ($s->methodPaths !== []) {
            $post = false;
            foreach ($s->methodPaths['POST'] ?? [] as $p) {
                $post = $post || @preg_match($p, $base . '/trace') === 1;
            }
            if (!$post) {
                $out[] = "allow POST … does not name the API: its POST endpoints (trace, test …) get 405 -- add \"allow POST $base/**\"";
            }
        }
        if ($s->queryStrict) {
            $out[] = "query strict: the API's parameters (cursor, days, q …) are unknown parameters -- add a match block for $base/** without query strict, or name them with query";
        }
        return $out;
    }

    /** The core's endpoints (RSF06-05): one service each, the same data the command line and the pages show. */
    public static function api(): array
    {
        return [
            new Endpoint('GET', '/status', 'reader', false, Service\Status::class, Service\Status::SCHEMA, 'RSF06-05',
                'The version, the tier, the mode and the rule sets in force -- what request-shield version says.'),
            new Endpoint('GET', '/openapi.json', 'reader', false, Service\OpenApi::class, ['type' => 'object'], 'RSF06-05',
                'This API described as OpenAPI 3.1 (JSON): every endpoint, what it takes and what it answers.'),
        ];
    }
}
