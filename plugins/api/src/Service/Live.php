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
use CjwNetwork\RequestShield\Report\LivePage;
use CjwNetwork\RequestShield\Routes;
use CjwNetwork\RequestShield\Settings;

/** GET /live: what was stopped since the cursor -- the live view's rows (the log, or the live memory with set live on). */
final class Live implements ApiService
{
    public const PARAMS = ['cursor' => ['type' => 'string', 'about' => 'where the last call ended (its answer\'s cursor); none: the newest rows']];

    public const SCHEMA = ['type' => 'object', 'required' => ['cursor', 'rows', 'skipped', 'log'], 'properties' => [
        'cursor' => ['type' => 'string'], 'rows' => ['type' => 'array', 'items' => ['type' => 'object']],
        'skipped' => ['type' => 'integer'], 'log' => ['type' => 'boolean'], 'memory' => ['type' => 'boolean'],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $cursor = Params::string($params, 'cursor', '', 64);
        // A rule's ID links to its row on Rules & setup, an entry to the lists -- the dashboard's pages, without a front controller's prefix.
        return LivePage::json($s, $cursor === '' ? null : $cursor, ['lang' => $ctx['lang'], 'ip' => $ctx['ip'], 'links' => Routes::links($s)]);
    }
}
