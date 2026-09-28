<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

/**
 * The settings, as one PHP array (config/request-shield.dist.php shows every
 * key). A PHP file returning an array is cached by OPcache, so reading it
 * costs nothing after the first request.
 */
final class Config
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            // Addresses that may say who the client is (X-Forwarded-For, -Proto,
            // -Host): the load balancer, a reverse proxy, a CDN's ranges.
            'trustedProxies' => [],
            // Remove X-Forwarded-* from $_SERVER when the peer is not trusted, so
            // the application cannot be told another client, scheme or host either.
            'stripUntrustedForwarded' => true,
            'methods' => ['GET', 'HEAD', 'POST', 'OPTIONS'],
            // The site's hosts; empty: any host.
            'hosts' => [],
            'limits' => ['uri' => 4096, 'queryParameters' => 64, 'headerBytes' => 16384],
            'blockedPaths' => self::scannerPaths(),
            // What may be cached. paths: regular expressions (null: every path);
            // query: parameter names (null: any, []: none).
            'cacheable' => ['paths' => null, 'query' => null],
            // Requests per client (IPv4 address, IPv6 /64) and window. Above
            // challengeAt: prove to be a browser (null: never); above limit: 429.
            'budgets' => [
                'requests' => ['limit' => 600, 'window' => 60, 'challengeAt' => null],
            ],
            'exempt' => ['ips' => ['127.0.0.1', '::1']],
            'ipv6Prefix' => 64,
            // auto: APCu when usable, else files in storeDir.
            'store' => 'auto',
            'storeDir' => rtrim(sys_get_temp_dir(), '/') . '/request-shield',
            // X-Request-Shield: <action> <reason> on every response (for testing).
            'debugHeader' => false,
        ];
    }

    /**
     * Paths only scanners ask for, on any PHP site.
     *
     * @return list<string>
     */
    public static function scannerPaths(): array
    {
        return [
            '#/\.(git|svn|hg|bzr|env|htpasswd|ds_store|idea|vscode)(/|$)#',
            '#\.(bak|old|orig|save|swp|sql|sql\.gz|tar|tar\.gz|tgz|zip|7z|rar|log)$#',
            '#/(phpinfo|php_info|info|test)\.php$#',
            '#/(vendor/phpunit|phpmyadmin|pma|adminer)(/|\.php|$)#',
            '#/(cgi-bin|\.well-known/(?!acme-challenge|security\.txt|change-password))#',
        ];
    }

    /**
     * Paths of WordPress, for every site that is not one.
     *
     * @return list<string>
     */
    public static function wordpressPaths(): array
    {
        return ['#^/(wp-admin|wp-includes|wp-content)(/|$)#', '#^/(wp-login|xmlrpc|wp-config)\.php$#'];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function merge(array $config): array
    {
        $merged = self::defaults();
        foreach ($config as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])
                && !array_is_list($value) && !array_is_list($merged[$key])) {
                $merged[$key] = array_replace($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }
}
