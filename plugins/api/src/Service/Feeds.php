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

/** GET /feeds: the public blocklists the rules name -- action, entries, when fetched, whether in force. */
final class Feeds implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['feeds'], 'properties' => [
        'feeds' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['name', 'title', 'action', 'count', 'fetched', 'state', 'rule', 'watched'], 'properties' => [
            'name' => ['type' => 'string'], 'title' => ['type' => 'string'], 'action' => ['type' => 'string'], 'count' => ['type' => 'integer'],
            'fetched' => ['type' => 'integer'], 'state' => ['type' => 'string'], 'rule' => ['type' => 'string'], 'watched' => ['type' => 'boolean'],
        ]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $out = [];
        foreach ([[$s->feeds, false], [$s->monitor !== null ? $s->monitor->feeds : [], true]] as [$feeds, $watched]) {
            foreach ($feeds as $f) {
                $out[] = ['name' => $f['name'], 'title' => $f['title'], 'action' => $f['action'], 'count' => $f['count'], 'fetched' => $f['fetched'],
                    'state' => $f['state'], 'rule' => $f['rule'], 'watched' => $watched];
            }
        }
        return ['feeds' => $out];
    }
}
