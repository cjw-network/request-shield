<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Cache\Api;

use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Cache\CacheExtension;
use CjwNetwork\RequestShield\Cache\FileCache;
use CjwNetwork\RequestShield\Settings;

/** POST /cache/purge: everything, or the addresses below a path (a CMS after publishing). */
final class Purge implements ApiService
{
    public const PARAMS = ['path' => ['type' => 'string', 'about' => 'only the addresses below it: /news/ (every website); none: everything']];

    public const SCHEMA = ['type' => 'object', 'required' => ['removed'], 'properties' => ['removed' => ['type' => 'integer']]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $path = is_string($params['path'] ?? null) ? trim($params['path']) : '';
        if ($path !== '' && $path[0] !== '/') {
            throw new ApiProblem(400, 'Bad request', 'path starts with /: /news/');
        }
        return ['removed' => (new FileCache(CacheExtension::of($s)['dir']))->purge($path !== '' ? $path : null)];
    }
}
