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

/** POST /lists/lift: a ban lifted before its end, by its bucket (an IPv4 address, an IPv6 /64). */
final class ListsLift implements ApiService
{
    public const PARAMS = ['bucket' => ['type' => 'string', 'required' => true, 'about' => 'as GET /lists names it under bans']];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Params::string($params, 'bucket', null, 64);
        return ListsChange::change($s, 'lift', $params, $ctx);
    }
}
