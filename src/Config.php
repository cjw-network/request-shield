<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
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
            // The browser check for clients past a budget's challengeAt: a proof of
            // work (ALTCHA-compatible) solved by a small script, then a signed pass
            // cookie. secret: shared by every server of the site (null: one is made
            // in storeDir). difficulty: maxnumber at the threshold and at the limit.
            'challenge' => [
                'secret' => null,
                'passTtl' => 3600,
                'solutionTtl' => 300,
                'difficulty' => ['min' => 50000, 'max' => 500000],
                'cookie' => 'rs_pass',
                'solutionCookie' => 'rs_solution',
                'bindUserAgent' => true,
                // Verified crawlers are never challenged (the limit still applies).
                // true: the engines in Challenge\SearchEngines::defaults(); false: none.
                'searchEngines' => true,
                // Paths no browser loads as a page (APIs, feeds): never challenged.
                'exemptPaths' => [],
                // Paths every visitor has to pass the check for (once per pass
                // cookie), whatever the budgets say: a login or admin page.
                'alwaysPaths' => [],
                'texts' => [],
            ],
            // X-Request-Shield: <action> <reason> on every response (for testing).
            'debugHeader' => false,
            // Paths only some addresses may open: [['paths' => [regex, ...], 'ips' => [range, ...]], ...].
            'restricted' => [],
            // Methods allowed only on some paths: ['POST' => [regex, ...]]; other
            // paths answer 405 for that method. Methods not listed: see 'methods'.
            'methodPaths' => [],
            // One line per request the shield did something about (Log). level:
            // stop (rejected, throttled, challenged), flag (also allow-uncached),
            // all (every request), off. ip: masked (/24, /48) or full.
            'log' => ['file' => null, 'level' => 'stop', 'ip' => 'masked', 'maxSize' => 10485760],
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
     * A name for each built-in pattern, so a decision (and the log) says
     * which one: "@scanners.backups".
     */
    public static function setName(string $pattern): ?string
    {
        /** @var array<string, string>|null $names */
        static $names = null;
        if ($names === null) {
            $names = array_combine(self::scannerPaths(), ['@scanners.hidden-files', '@scanners.backups', '@scanners.test-scripts', '@scanners.db-tools', '@scanners.cgi'])
                + array_combine(self::wordpressPaths(), ['@wordpress.folders', '@wordpress.scripts']);
        }
        return $names[$pattern] ?? null;
    }

    /**
     * @param array<mixed> $config
     * @return array<mixed>
     */
    public static function merge(array $config): array
    {
        $merged = self::defaults();
        foreach ($config as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])
                && !self::isList($value) && !self::isList($merged[$key])) {
                $merged[$key] = array_replace($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    /**
     * array_is_list(), which PHP has only from 8.1.
     *
     * @param array<mixed> $a
     */
    private static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }
}
