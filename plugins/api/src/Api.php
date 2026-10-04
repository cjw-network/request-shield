<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api;

use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Log;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Tier;

/**
 * The API in the same process (RSF06-05): Api::call() answers exactly what
 * the HTTP endpoint sends as JSON -- the same dispatch, the same service,
 * the same check of who may ask. A CMS's backend calls it with its
 * administrator ('*') or a customer's group; a JavaScript page uses HTTP.
 *
 *   $answer = Api::call(Shield::active()->settings, 'GET', '/status');
 *   // ['data' => [...], 'meta' => ['version' => …, 'generated' => …, 'tier' => …]]
 *   // or a problem: ['type' => 'about:blank', 'title' => …, 'status' => 403, 'detail' => …]
 */
final class Api
{
    /**
     * @param array<string, mixed> $params the query (GET) or the JSON body (POST)
     * @param array{ruleFile?: ?string, now?: int, ip?: string, lang?: string} $ctx
     * @return array<string, mixed> the answer's body: the envelope, or a problem
     */
    public static function call(Settings $s, string $method, string $path, array $params = [], string $who = '*', array $ctx = []): array
    {
        return self::dispatch($s, $method, $path, $params, $who, $ctx)['body'];
    }

    /**
     * The answer: its status, headers of its own (Allow), and its body.
     *
     * @param array<string, mixed> $params
     * @param array{ruleFile?: ?string, now?: int, ip?: string, lang?: string} $ctx
     * @return array{status: int, headers: list<string>, body: array<string, mixed>, data: bool}
     */
    public static function dispatch(Settings $s, string $method, string $path, array $params, string $who, array $ctx = []): array
    {
        $api = self::slot($s);
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $path = '/' . trim($path, '/');
        if ($api === null || $api['enabled'] !== true) {
            return self::problem(new ApiProblem(404, 'Not found', 'The API is off here (set api off).'));
        }
        $ep = $api['endpoints']["$method $path"] ?? null;
        if ($ep === null) {
            $allow = [];
            foreach ($api['endpoints'] as $e) {
                if ($e['path'] === $path) {
                    $allow[] = $e['method'];
                }
            }
            return $allow === []
                ? self::problem(new ApiProblem(404, 'Not found', "No endpoint $path in this API (GET {$api['base']}/openapi.json lists them)."))
                : self::problem(new ApiProblem(405, 'Method not allowed', "$path takes " . implode(', ', $allow) . '.'), ['Allow: ' . implode(', ', $allow)]);
        }
        if ($ep['role'] === 'admin' && $who !== '*') {
            return self::problem(new ApiProblem(403, 'Forbidden', "$method $path is the administrator's."));
        }
        if ($ep['write'] && !$api['write']) {
            return self::problem(new ApiProblem(403, 'Forbidden', "$method $path changes something: the rule file has to allow it (set api-write on)."));
        }
        $service = $ep['service'];
        if (!class_exists($service) || !is_subclass_of($service, ApiService::class)) {
            return self::problem(new ApiProblem(501, 'Not implemented', "$method $path is not part of this build."));
        }
        $now = $ctx['now'] ?? time();
        $c = ['who' => $who, 'ruleFile' => $ctx['ruleFile'] ?? null, 'now' => $now, 'ip' => $ctx['ip'] ?? '', 'lang' => $ctx['lang'] ?? 'en'];
        try {
            $data = $service::handle($s, $params, $c);
        } catch (ApiProblem $p) {
            return self::problem($p);
        } catch (\Throwable $e) {
            // Fail safe: the API says that it failed, never why in detail (paths, values); the error log does.
            Shield::failed('api', "$method $path: " . $e->getMessage());
            return self::problem(new ApiProblem(500, 'Internal error', "$method $path failed; the server's error log has one line about it."));
        }
        if ($ep['write']) {
            self::audit($s, $method, $api['base'] . $path, $c);
        }
        $tier = Tier::of($s, is_string($c['ruleFile']) ? Settings::cacheDirFor($c['ruleFile']) : sys_get_temp_dir());
        return ['status' => 200, 'headers' => [], 'data' => true,
            'body' => ['data' => $data, 'meta' => ['version' => Shield::VERSION, 'generated' => gmdate('Y-m-d\TH:i:s\Z', $now), 'tier' => $tier['tier']]]];
    }

    /**
     * A write, for the record (0031 G.0): one line in the log (set log, at
     * every log level) and an event to every sink -- what changed, by
     * whom (the administrator), from which address. Never the parameters.
     *
     * @param array{who: string, ruleFile: ?string, now: int, ip: string, lang: string} $c
     */
    private static function audit(Settings $s, string $method, string $path, array $c): void
    {
        try {
            $request = Request::fromServer(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'HTTP_HOST' => 'request-shield-api',
                'REMOTE_ADDR' => @inet_pton($c['ip']) !== false ? $c['ip'] : '127.0.0.1', 'HTTP_USER_AGENT' => 'request-shield API (' . $c['who'] . ')']);
            $d = Decision::allowUncached('api write');
            Log::note($s, $request, $d, 'api-write', (float) $c['now']);
            if ($s->logFile !== null && !Log::wants($s->logLevel, $d)) {
                Log::write($s, $request, $d, 'api-write', (float) $c['now']);   // a change is on the record at every log level
            }
        } catch (\Throwable $e) {
            Shield::failed('api', "the write $method $path was done, its record failed: " . $e->getMessage());
        }
    }

    /**
     * The API's compiled slot, or null without the extension.
     *
     * @return array{enabled: bool, write: bool, origins: list<string>, base: string, endpoints: array<string, array{method: string, path: string, role: string, write: bool, service: string, feature: string}>}|null
     */
    public static function slot(Settings $s): ?array
    {
        $api = $s->ext['api'] ?? null;
        /** @var array{enabled: bool, write: bool, origins: list<string>, base: string, endpoints: array<string, array{method: string, path: string, role: string, write: bool, service: string, feature: string}>}|null */
        return is_array($api) && isset($api['endpoints'], $api['base']) ? $api : null;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: list<string>, body: array<string, mixed>, data: bool}
     */
    private static function problem(ApiProblem $p, array $headers = []): array
    {
        return ['status' => $p->status, 'headers' => $headers, 'body' => $p->body(), 'data' => false];
    }
}
