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
use CjwNetwork\RequestShield\Report\Counts;
use CjwNetwork\RequestShield\Settings;

/** GET /rules: every rule with an ID -- where it is written, what it does, its revision, how often it decided. */
final class Rules implements ApiService
{
    public const PARAMS = ['days' => ['type' => 'int', 'about' => 'the days the counts are of: 1 to 400 (default 7)']];

    public const SCHEMA = ['type' => 'object', 'required' => ['days', 'rules'], 'properties' => [
        'days' => ['type' => 'integer'],
        'rules' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'where', 'text', 'builtIn', 'decided'], 'properties' => [
            'id' => ['type' => 'string'], 'where' => ['type' => 'string'], 'text' => ['type' => ['string', 'null']], 'revision' => ['type' => ['integer', 'null']],
            'builtIn' => ['type' => 'boolean'], 'decided' => ['type' => ['integer', 'null']],
        ]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $days = Params::int($params, 'days', 7, 1, 400);
        $counts = Counts::rules($s, $days, (float) $ctx['now']);
        $rules = [];
        foreach ((array) ($s->origins['at'] ?? []) as $id => $at) {
            $id = (string) $id;
            $rev = $s->origins['rev'][$id] ?? null;
            $rules[] = ['id' => $id, 'where' => Params::relative((string) $at, $ctx['ruleFile']), 'text' => $s->origin('text', $id), 'revision' => is_numeric($rev) ? (int) $rev : null,
                'builtIn' => strncmp((string) $at, 'built-in ', 9) === 0, 'decided' => $counts === [] ? null : ($counts[$id] ?? 0)];
        }
        return ['days' => $days, 'rules' => $rules];
    }
}
