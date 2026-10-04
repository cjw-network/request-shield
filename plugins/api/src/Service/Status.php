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
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\ApcuStore;
use CjwNetwork\RequestShield\Tier;

/** GET /status: what request-shield version says -- the version, PHP, the tier and store, the mode, the rule sets. */
final class Status implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['version', 'php', 'apcu', 'tier', 'store', 'mode', 'ruleSets', 'sites'], 'properties' => [
        'version' => ['type' => 'string'], 'php' => ['type' => 'string'], 'apcu' => ['type' => 'boolean'],
        'tier' => ['type' => 'string', 'enum' => ['S0', 'S1', 'S2']], 'store' => ['type' => 'string'],
        'mode' => ['type' => 'string', 'enum' => ['off', 'monitor', 'enforce', 'strict']],
        'ruleSets' => ['type' => 'object'], 'sites' => ['type' => 'integer'],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $tier = Tier::of($s, is_string($ctx['ruleFile']) ? Settings::cacheDirFor($ctx['ruleFile']) : sys_get_temp_dir());
        $sets = [];
        foreach ((array) ($s->origins['versions'] ?? []) as $name => $v) {
            $sets[(string) $name] = (string) $v;
        }
        return [
            'version' => Shield::VERSION, 'php' => PHP_VERSION, 'apcu' => ApcuStore::usable(), 'tier' => $tier['tier'], 'store' => $tier['store'],
            'mode' => $s->mode, 'ruleSets' => (object) $sets, 'sites' => count(array_unique(array_values($s->sites))),
        ];
    }
}
