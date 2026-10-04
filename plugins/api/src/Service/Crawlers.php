<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api\Service;


use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

/** GET /crawlers: the known crawlers -- kind, what the site does with each, how they are verified, how old their address lists are. */
final class Crawlers implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['verify', 'crawlers'], 'properties' => [
        'verify' => ['type' => 'string'],
        'crawlers' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'kind', 'policy', 'dns', 'lists'], 'properties' => [
            'id' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'policy' => ['type' => 'string'],
            'dns' => ['type' => 'array', 'items' => ['type' => 'string']], 'lists' => ['type' => 'object'],
        ]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $out = [];
        foreach ($s->crawlers as $id => $x) {
            $lists = [];
            foreach ($x['lists'] as $name => $about) {
                $lists[(string) $name] = ['created' => is_string($about['created'] ?? null) ? $about['created'] : null, 'from' => is_string($about['from'] ?? null) ? $about['from'] : null];
            }
            $out[] = ['id' => (string) $id, 'kind' => $x['kind'], 'policy' => $x['policy'], 'dns' => $x['dns'], 'lists' => (object) $lists];
        }
        return ['verify' => $s->crawlerVerify, 'crawlers' => $out];
    }
}
