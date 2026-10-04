<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api\Service;


use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Http;
use CjwNetwork\RequestShield\Rules\Feeds as FeedFiles;
use CjwNetwork\RequestShield\Settings;

/** POST /feeds/update: the lists that are due fetched now -- as request-shield feeds update (usually cron's job). A write. */
final class FeedsUpdate implements ApiService
{
    public const PARAMS = ['force' => ['type' => 'bool', 'about' => 'keep a list that shrank to less than half, and fetch one not yet due']];

    public const SCHEMA = ['type' => 'object', 'required' => ['feeds'], 'properties' => ['feeds' => ['type' => 'object']]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $named = array_merge($s->feeds, $s->monitor !== null ? $s->monitor->feeds : []);
        if ($named === []) {
            return ['feeds' => (object) []];
        }
        if (($offline = Http::offline()) !== null) {
            throw new ApiProblem(503, 'Service unavailable', $offline);
        }
        $done = FeedFiles::update($named, $s->storeDir . '/feeds', null, Params::bool($params, 'force'));
        foreach ($done as $happened) {
            if (strncmp($happened, 'updated', 7) === 0 && is_string($ctx['ruleFile']) && is_file($ctx['ruleFile'])) {
                @touch($ctx['ruleFile']);                     // every server reads them within its recheck
                break;
            }
        }
        return ['feeds' => (object) $done];
    }
}
