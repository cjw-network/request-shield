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
                'externalDocs' => ['url' => (string) Help::url($ep->feature)],
            ];
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
}
