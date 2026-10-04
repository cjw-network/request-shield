<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Cache\Cli;

use CjwNetwork\RequestShield\Cache\CacheExtension;
use CjwNetwork\RequestShield\Cache\FileCache;
use CjwNetwork\RequestShield\Cli\Command;
use CjwNetwork\RequestShield\Cli\Context;

/**
 * `request-shield cache <main.rules>`: what the HTTP cache holds; `purge`
 * empties it (`--path=/news/`: the addresses below it), `expired` removes
 * what has run out -- for cron, a deploy, a CMS after publishing.
 */
final class CacheCommand implements Command
{
    public static function usage(): string
    {
        return 'request-shield cache <main.rules> [purge [--path=/news/] | expired]';
    }

    public static function run(Context $c): int
    {
        $o = CacheExtension::of($c->settings);
        $cache = new FileCache($o['dir']);
        if ($c->what === 'purge') {
            $period = $c->option('period', []);
            $path = is_array($period) ? ($period['path'] ?? null) : null;
            echo 'removed ' . $cache->purge(is_string($path) && $path !== '' ? $path : null) . " answers\n";
            return 0;
        }
        if ($c->what === 'expired') {
            echo 'removed ' . $cache->purge(null, microtime(true)) . " expired answers\n";
            return 0;
        }
        if ($c->what !== null) {
            fwrite(STDERR, 'usage: ' . self::usage() . "\n");
            return 2;
        }
        $st = $cache->stats();
        echo 'http-cache ' . ($o['enabled'] ? 'on' : 'off (set http-cache on)') . ", kept {$o['ttl']} s unless the answer says otherwise\n"
            . "{$st['entries']} answers, " . number_format($st['bytes'] / 1024, 1) . " KB in {$o['dir']}\n";
        return 0;
    }
}
