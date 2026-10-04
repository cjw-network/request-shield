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

    /** @return list<Endpoint> the core's endpoints first, then every other offered ApiProvider's */
    public static function all(): array
    {
        $out = self::api();
        foreach (Vocabulary::extensions() as $class) {
            if ($class !== self::class && is_subclass_of($class, ApiProvider::class)) {
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
        $change = Service\ListsChange::SCHEMA;
        return [
            new Endpoint('GET', '/status', 'reader', false, Service\Status::class, Service\Status::SCHEMA, 'RSF06-05',
                'The version, the tier, the mode and the rule sets in force -- what request-shield version says.'),
            new Endpoint('GET', '/rules', 'admin', false, Service\Rules::class, Service\Rules::SCHEMA, 'RSF06-01',
                'Every rule with an ID: where it is written, what it does, its revision, how often it decided.', Service\Rules::PARAMS),
            new Endpoint('POST', '/trace', 'admin', false, Service\Trace::class, Service\Trace::SCHEMA, 'RSF06-01',
                'What happens to a request, check by check -- as request-shield trace; nothing is counted.', Service\Trace::PARAMS),
            new Endpoint('POST', '/test', 'admin', false, Service\Test::class, Service\Test::SCHEMA, 'RSF05-04',
                'Every example next to the rules, decided on a fresh store -- as request-shield test.', Service\Test::PARAMS),
            new Endpoint('POST', '/check', 'admin', false, Service\Check::class, Service\Check::SCHEMA, 'RSF05-01',
                'The rule files read again and compiled: the first mistake, or the warnings and the tier -- as request-shield check.'),
            new Endpoint('POST', '/reload', 'admin', true, Service\Reload::class, Service\Reload::SCHEMA, 'RSF05-01',
                'Check, then mark the main rule file changed: every server reads the rules on its next check.'),
            new Endpoint('GET', '/live', 'admin', false, Service\Live::class, Service\Live::SCHEMA, 'RSF06-02',
                'What was stopped since the cursor: the live view\'s rows.', Service\Live::PARAMS),
            new Endpoint('GET', '/lists', 'admin', false, Service\Lists::class, Service\Lists::SCHEMA, 'RSF01-02',
                'The addresses kept out and let in, newest first, searched; and the bans in force.', Service\Lists::PARAMS),
            new Endpoint('POST', '/lists', 'admin', true, Service\ListsChange::class, $change, 'RSF01-02',
                'An address kept out or let in, for a while or for good -- with the dashboard\'s checks.', Service\ListsChange::PARAMS_ADD),
            new Endpoint('POST', '/lists/update', 'admin', true, Service\ListsUpdate::class, $change, 'RSF01-02',
                'Another end or another note for an entry, by its ID.', Service\ListsUpdate::PARAMS),
            new Endpoint('POST', '/lists/remove', 'admin', true, Service\ListsRemove::class, $change, 'RSF01-02',
                'An entry taken out, by its ID.', Service\ListsRemove::PARAMS),
            new Endpoint('POST', '/lists/lift', 'admin', true, Service\ListsLift::class, $change, 'RSF01-02',
                'A ban lifted before its end, by its bucket.', Service\ListsLift::PARAMS),
            new Endpoint('GET', '/feeds', 'admin', false, Service\Feeds::class, Service\Feeds::SCHEMA, 'RSF01-03',
                'The public blocklists the rules name: action, entries, when fetched, whether in force.'),
            new Endpoint('POST', '/feeds/update', 'admin', true, Service\FeedsUpdate::class, Service\FeedsUpdate::SCHEMA, 'RSF01-03',
                'The lists that are due fetched now -- as request-shield feeds update.', Service\FeedsUpdate::PARAMS),
            new Endpoint('GET', '/crawlers', 'admin', false, Service\Crawlers::class, Service\Crawlers::SCHEMA, 'RSF01-04',
                'The known crawlers: kind, what the site does with each, how they are verified.'),
            new Endpoint('GET', '/log', 'admin', false, Service\Log::class, Service\Log::SCHEMA, 'RSF05-05',
                'The log\'s lines since the cursor, parsed: what was refused or checked, and why.', Service\Log::PARAMS),
            new Endpoint('GET', '/openapi.json', 'reader', false, Service\OpenApi::class, ['type' => 'object'], 'RSF06-05',
                'This API described as OpenAPI 3.1 (JSON): every endpoint, what it takes and what it answers -- the document itself, no envelope.'),
            new Endpoint('GET', '/openapi.yaml', 'reader', false, Service\OpenApi::class, ['type' => 'object'], 'RSF06-05',
                'The same as YAML.'),
        ];
    }
}
