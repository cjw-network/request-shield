<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api;

use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Shield;

/**
 * The API described (RSF06-05): an OpenAPI 3.1 document from the endpoints
 * every ApiProvider offers -- what CMS adapters, scripts and language models
 * read -- and the same as YAML and as the reference page in the docs.
 */
final class OpenApi
{
    /** @return array<string, mixed> */
    public static function document(string $dashboardPath = '/rs'): array
    {
        $paths = [];
        foreach (ApiExtension::all() as $ep) {
            $op = [
                'summary' => $ep->summary,
                'tags' => [$ep->feature],
                'x-role' => $ep->role,
            ];
            $docs = Help::url($ep->feature);
            if ($docs !== null) {
                $op['externalDocs'] = ['url' => $docs];
            }
            if ($ep->write) {
                $op['x-write'] = true;
            }
            $params = [];
            foreach ($ep->params as $name => $p) {
                $params[] = ['name' => $name, 'in' => $ep->method === 'GET' ? 'query' : 'body', 'required' => $p['required'] ?? false, 'description' => $p['about'], 'schema' => ['type' => $p['type'] === 'int' ? 'integer' : ($p['type'] === 'bool' ? 'boolean' : 'string')]];
            }
            if ($ep->method === 'GET' && $params !== []) {
                $op['parameters'] = $params;
            }
            if ($ep->method === 'POST' && $params !== []) {
                $props = [];
                $required = [];
                foreach ($params as $p) {
                    $props[$p['name']] = $p['schema'] + ['description' => $p['description']];
                    if ($p['required']) {
                        $required[] = $p['name'];
                    }
                }
                $op['requestBody'] = ['required' => $required !== [], 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $props] + ($required !== [] ? ['required' => $required] : [])]]];
            }
            $op['responses'] = [
                '200' => ['description' => 'The answer', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['data', 'meta'], 'properties' => [
                    'data' => $ep->schema, 'meta' => ['$ref' => '#/components/schemas/Meta']]]]]],
                'default' => ['description' => 'A problem (RFC 9457)', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]],
            ];
            $paths[$ep->path][strtolower($ep->method)] = $op;
        }
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'request-shield', 'version' => Shield::VERSION,
                'description' => 'The shield\'s data: status, rules, the live view, the lists, the statistics. Guarded like the dashboard: a restrict rule, or Authorization: Bearer <token>.'],
            'servers' => [['url' => $dashboardPath . ApiExtension::BASE]],
            'security' => [['bearer' => []]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'request-shield access-token <main.rules> "*"']],
                'schemas' => [
                    'Meta' => ['type' => 'object', 'required' => ['version', 'generated', 'tier'], 'properties' => ['version' => ['type' => 'string'], 'generated' => ['type' => 'string'], 'tier' => ['type' => 'string']]],
                    'Problem' => ['type' => 'object', 'required' => ['type', 'title', 'status', 'detail'], 'properties' => ['type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'], 'detail' => ['type' => 'string']]],
                ],
            ],
        ];
    }

    /**
     * A document as YAML: maps and lists in blocks, every string in double
     * quotes as JSON writes it (valid YAML), keys bare where YAML lets them be.
     *
     * @param array<mixed> $doc
     */
    public static function yaml(array $doc): string
    {
        return self::block($doc, 0);
    }

    /** @param array<mixed> $value */
    private static function block(array $value, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        $list = $value !== [] && array_keys($value) === range(0, count($value) - 1);
        $out = '';
        foreach ($value as $k => $v) {
            $head = $list ? $pad . '- ' : $pad . self::key((string) $k) . ':';
            if (is_object($v)) {
                $v = (array) $v;
            }
            if (is_array($v) && $v !== []) {
                $out .= $list ? $head . "\n" . self::block($v, $depth + 1) : $head . "\n" . self::block($v, $depth + 1);
            } else {
                $out .= $head . ($list ? '' : ' ') . self::scalar($v) . "\n";
            }
        }
        return $out;
    }

    private static function key(string $k): string
    {
        return preg_match('#^[A-Za-z_$][A-Za-z0-9_./{}$-]*$#', $k) === 1 && !in_array(strtolower($k), ['y', 'n', 'yes', 'no', 'true', 'false', 'on', 'off', 'null'], true)
            ? $k : (string) json_encode($k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function scalar(mixed $v): string
    {
        if (is_array($v)) {
            return '[]';
        }
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        return (string) json_encode(is_scalar($v) ? (string) $v : '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
