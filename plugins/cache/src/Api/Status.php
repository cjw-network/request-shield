<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Cache\Api;

use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Cache\CacheExtension;
use CjwNetwork\RequestShield\Cache\FileCache;
use CjwNetwork\RequestShield\Settings;

/** GET /cache: on or off, how many answers, how many bytes. */
final class Status implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['enabled', 'entries', 'bytes', 'ttl'], 'properties' => [
        'enabled' => ['type' => 'boolean'], 'entries' => ['type' => 'integer'], 'bytes' => ['type' => 'integer'], 'ttl' => ['type' => 'integer'],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $o = CacheExtension::of($s);
        return ['enabled' => $o['enabled'], 'ttl' => $o['ttl']] + (new FileCache($o['dir']))->stats();
    }
}
