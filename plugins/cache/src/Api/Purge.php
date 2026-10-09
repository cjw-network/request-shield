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
use CjwNetwork\RequestShield\Cache\MemoryCache;
use CjwNetwork\RequestShield\Cache\Tags;
use CjwNetwork\RequestShield\Capability;
use CjwNetwork\RequestShield\Settings;

/** POST /cache/purge: everything, the addresses below a path, or the answers with a tag (a CMS after publishing). */
final class Purge implements ApiService
{
    public const PARAMS = ['path' => ['type' => 'string', 'about' => 'only the addresses below it: /news/ (every website); none: everything'],
        'tags' => ['type' => 'string', 'about' => 'only the answers with one of these tags: c52 l2 (as xkey names them); out of date at once, removed when they run out']];

    public const SCHEMA = ['type' => 'object', 'required' => ['removed'], 'properties' => ['removed' => ['type' => 'integer']]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $tags = is_string($params['tags'] ?? null) ? Tags::split($params['tags']) : [];
        if ($tags !== []) {
            if (!(new Tags(CacheExtension::of($s)['dir'], Capability::apcu()))->purge($tags, microtime(true))) {
                throw new ApiProblem(500, 'Not purged', 'the purge could not be written');
            }
            return ['removed' => 0];
        }
        $path = is_string($params['path'] ?? null) ? trim($params['path']) : '';
        if ($path !== '' && $path[0] !== '/') {
            throw new ApiProblem(400, 'Bad request', 'path starts with /: /news/');
        }
        $dir = CacheExtension::of($s)['dir'];
        $removed = (new FileCache($dir))->purge($path !== '' ? $path : null);
        if (!MemoryCache::forget($dir, Capability::apcu(), microtime(true))) {
            throw new ApiProblem(500, 'Not purged', 'the purge of the answers in memory could not be written');
        }
        return ['removed' => $removed];
    }
}
