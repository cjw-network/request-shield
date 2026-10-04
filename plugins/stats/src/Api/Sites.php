<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Stats\Api;


use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\Report\StatsReport;

/**
 * GET /stats/sites: all websites at a glance (stats-hosts, stats-group) --
 * page views, people, crawlers, bots, stopped, not found and the period
 * before, for each website and group. A customer gets its group's websites.
 */
final class Sites implements ApiService
{
    public const PARAMS = ['days' => Period::PARAMS['days'], 'from' => Period::PARAMS['from'], 'to' => Period::PARAMS['to'], 'by' => Period::PARAMS['by']];

    public const SCHEMA = ['type' => 'object', 'required' => ['from', 'to', 'sites', 'groups', 'all'], 'properties' => [
        'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'sites' => ['type' => 'object'], 'groups' => ['type' => 'object'],
        'all' => ['type' => ['object', 'null']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Period::on($s);
        [$from, $to, $by] = Period::of($params, $ctx['now']);
        $x = StatsReport::sites($s, $from, $to, $by, $ctx['who']);
        return ['from' => $from, 'to' => $to, 'sites' => $x['sites'] === [] ? new \stdClass() : $x['sites'], 'groups' => $x['groups'] === [] ? new \stdClass() : $x['groups'], 'all' => $x['all']];
    }
}
