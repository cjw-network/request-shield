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
                // The language visitors read: auto (their browser's, among those
                // there are texts for; else English) or a code. Built in: en, de.
                'language' => 'auto',
                // Where the shield's own pages (404, a pause, the check page) link to,
                // "To the home page": a path (/) or an address; null: no link.
                'home' => null,
                // Own texts: 'title' for every language, 'de.title' for one
                // (keys: Texts::KEYS).
                'texts' => [],
            ],
            // X-Request-Shield: <action> <reason> on every response (for testing).
            'debugHeader' => false,
            // The application may ask for the browser check with a response header,
            // X-Request-Shield-Challenge: required (for a form page). Needs output
            // buffering, so it is off unless asked for; Shield::requirePass()
            // works without it.
            'appChallenge' => false,
            // Paths only some addresses may open: [['paths' => [regex, ...], 'ips' => [range, ...]], ...].
            'restricted' => [],
            // Where blocked paths are let through anyway (an admin's file reader):
            // [['paths' => [regex, ...], 'patterns' => [blockedPaths entries] or null
            // (all), 'ips' => [range, ...] or [] (everyone)], ...]. Path sanity
            // (traversal, disguised paths) is never lifted.
            'blockExceptions' => [],
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
     * The built-in blocks with their IDs and descriptions, for settings from a
     * PHP array. The source is rules/scanners.rules and rules/wordpress.rules
     * (a rule file reads those); a test keeps this copy the same.
     *
     * @return array<string, array{0: string, 1: string}> pattern => [ID, description]
     */
    public static function builtIns(): array
    {
        $s = self::scannerPaths();
        $w = self::wordpressPaths();
        return [
            $s[0] => ['SCAN-HIDDEN', 'hidden files and folders: .env, .git, .htpasswd, editor settings'],
            $s[1] => ['SCAN-BACKUP', 'backups, dumps and archives: .bak, .old, .sql, .zip, .tar.gz, .log …'],
            $s[2] => ['SCAN-TEST', 'test and info scripts: phpinfo.php, info.php, test.php'],
            $s[3] => ['SCAN-DBTOOL', 'database and test tools: phpMyAdmin, Adminer, PHPUnit'],
            $s[4] => ['SCAN-CGI', 'cgi-bin, and .well-known except certificates, security.txt and password change'],
            $w[0] => ['WP-FOLDERS', 'WordPress folders: /wp-admin, /wp-includes, /wp-content'],
            $w[1] => ['WP-SCRIPTS', 'WordPress scripts: wp-login.php, xmlrpc.php, wp-config.php'],
        ];
    }

    /** A built-in pattern's ID ("SCAN-BACKUP"), or null. */
    public static function setName(string $pattern): ?string
    {
        return self::builtIns()[$pattern][0] ?? null;
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
