<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api;

use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Response;
use CjwNetwork\RequestShield\RoutePage;
use CjwNetwork\RequestShield\Settings;

/**
 * The API over HTTP (RSF06-05): the route every endpoint has. Dashboard::serve()
 * has checked who asks (a restrict rule, a token, a session) before it gets
 * here; this adds what an API needs -- the parameters from the query or the
 * JSON body, a cross-site check for a session's POST, CORS for the websites
 * api-origins names, an ETag on the data, and JSON for everything, problems
 * too (RFC 9457).
 */
final class Host implements RoutePage
{
    /** The largest JSON body read: the API takes parameters, not files. */
    public const MAX_BODY = 65536;

    public static function serve(Settings $s, Request $request, array $route, array $ctx): Response
    {
        $api = Api::slot($s);
        $path = $api !== null && strncasecmp($route['path'], $api['base'], strlen($api['base'])) === 0 ? substr($route['path'], strlen($api['base'])) : $route['path'];
        $origin = (string) $request->header('origin');
        $cors = $api !== null && $origin !== '' && in_array(strtolower(rtrim($origin, '/')), $api['origins'], true)
            ? ['Access-Control-Allow-Origin: ' . $origin, 'Vary: Origin'] : [];
        if ($request->method === 'OPTIONS') {
            // A browser asks before a call from another website: only those api-origins names.
            return $cors === [] ? self::json(403, ['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403, 'detail' => 'Calls from another website need api-origins.'], [], true)
                : new Response(204, [...$cors, 'Access-Control-Allow-Methods: GET, POST', 'Access-Control-Allow-Headers: Authorization, Content-Type', 'Access-Control-Max-Age: 600'], '');
        }
        $bearer = strncasecmp((string) $request->header('authorization'), 'Bearer ', 7) === 0;
        if ($request->method === 'POST' && !$bearer && !self::sameSite($request, $origin, $api['origins'] ?? [])) {
            // A session's cookie goes with every request a browser sends: a POST must say it comes from here.
            return self::json(403, ['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403,
                'detail' => 'A POST with a session needs an Origin of this website (or of api-origins); a script sends Authorization: Bearer instead.'], $cors, true);
        }
        $params = $request->method === 'POST' ? self::body($ctx['post']) : self::query($ctx['get']);
        if ($params === null) {
            return self::json(400, ['type' => 'about:blank', 'title' => 'Bad request', 'status' => 400, 'detail' => 'The body is no JSON object (Content-Type: application/json, at most 64 KB).'], $cors, true);
        }
        $answer = Api::dispatch($s, $request->method, $path, $params, $ctx['who'],
            ['ruleFile' => $ctx['ruleFile'], 'ip' => $ctx['ip'], 'lang' => $ctx['lang'] === 'de' ? 'de' : 'en']);
        // The description is the document itself, without the envelope: what OpenAPI tools read.
        if ($answer['data'] && $path === '/openapi.json') {
            return self::json(200, (array) ($answer['body']['data'] ?? []), $cors, false);
        }
        if ($answer['data'] && $path === '/openapi.yaml') {
            return new Response(200, ['Content-Type: application/yaml; charset=utf-8', 'X-Content-Type-Options: nosniff', ...$cors], OpenApi::yaml((array) ($answer['body']['data'] ?? [])));
        }
        $headers = [...$cors, ...$answer['headers']];
        if ($answer['data']) {
            // The ETag of the data alone: "generated" changes every second, the data does not.
            $etag = '"' . substr(hash('sha256', (string) json_encode($answer['body']['data'] ?? null)), 0, 20) . '"';
            $headers[] = 'ETag: ' . $etag;
            if (trim((string) $request->header('if-none-match')) === $etag) {
                return new Response(304, $headers, '');
            }
        }
        $response = self::json($answer['status'], $answer['body'], $headers, !$answer['data']);
        return $request->method === 'HEAD' ? new Response($response->status, $response->headers, '') : $response;
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $headers
     */
    private static function json(int $status, array $body, array $headers, bool $problem): Response
    {
        return new Response($status, [
            'Content-Type: application/' . ($problem ? 'problem+json' : 'json') . '; charset=utf-8',
            'X-Content-Type-Options: nosniff',
            ...$headers,
        ], (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * A POST's parameters: the JSON body, else the form's fields; null when the JSON is broken or too large.
     *
     * @param array<mixed> $post
     * @return array<string, mixed>|null
     */
    private static function body(array $post): ?array
    {
        $raw = (string) @file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (trim($raw) === '') {
            /** @var array<string, mixed> */
            return $post;
        }
        if (strlen($raw) > self::MAX_BODY) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) && $post !== []) {
            /** @var array<string, mixed> */
            return $post;                       // a form's fields (application/x-www-form-urlencoded)
        }
        /** @var array<string, mixed>|null */
        return is_array($data) && ($data === [] || array_keys($data) !== range(0, count($data) - 1)) ? $data : null;
    }

    /**
     * A GET's parameters: the query, without the dashboard's own lang.
     *
     * @param array<mixed> $get
     * @return array<string, mixed>
     */
    private static function query(array $get): array
    {
        $out = [];
        foreach ($get as $k => $v) {
            if (is_string($k) && $k !== 'lang') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param list<string> $origins */
    private static function sameSite(Request $request, string $origin, array $origins): bool
    {
        if ($origin === '' || $origin === 'null') {
            return false;
        }
        $o = strtolower(rtrim($origin, '/'));
        return $o === strtolower($request->scheme . '://' . $request->host) || in_array($o, $origins, true);
    }
}
