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
                // The browser check inside a form (Challenge\Widget): the endpoint's
                // path (/request-shield: .../challenge and .../widget.js); null: off.
                // widgetDifficulty: lower than the check page's -- the visitor types.
                'widgetPath' => null,
                'widgetDifficulty' => 25000,
                // The picture in the middle of the check page's ring: a path to an SVG
                // file of the site's own (checked strictly and inlined when the
                // settings are read -- use a rule file or Settings::load(), so that
                // happens once, not per request); null: a plain shield.
                'logo' => null,
                // The site's API: a check there (past a limit, say) is sent as JSON with
                // a Request-Shield-Challenge header; JSON requests count as API anyway.
                'apiPaths' => [],
                // New DNS lookups a minute to verify search engines, for all requests
                // together; past that a claimed crawler counts as not verified at once.
                // 0: no lookups (a DMZ without DNS).
                'dnsLookups' => 30,
                // Own texts: 'title' for every language, 'de.title' for one
                // (keys: Texts::KEYS).
                'texts' => [],
            ],
            // How hard the shield acts: off (nothing at all), monitor (everything
            // checked, counted and logged as it would be decided -- nobody is
            // refused), enforce, strict (enforce with tighter values, for a site
            // under attack). See docs/features/modes.md.
            'mode' => 'enforce',
            // The rules again with those marked "monitor" in a rule file: they
            // are logged, not enforced (written by RuleFile; null: none).
            'monitorRules' => null,
            // Crawlers that behave, verified by where they come from (proposal 0011):
            // null: the shipped list (rules/crawlers.rules, compiled into
            // rules/crawlers.php); or ID => ['kind' => search|ai-search|ai-user|ai-training,
            // 'ua' => regex, 'dns' => [host suffixes], 'ranges' => [CIDR, ...]].
            // challenge.searchEngines false: none is recognised.
            'crawlers' => null,
            // What the site does with a verified crawler, by kind or by ID: allow
            // (never the browser check; the default), check (like any visitor),
            // block (403). ['ai-training' => 'block', 'CRAWL-GPTBOT' => 'check'].
            'crawlerPolicy' => [],
            // both | ranges (the address lists only, no DNS: a DMZ) | dns
            'crawlerVerify' => 'both',
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
            // Attack patterns in the query and the headers (rules/attacks.rules):
            // [['target' => 'query'|'headers'|'anywhere'|'header:<name>',
            //   'patterns' => [regex, ...]], ...], matched against Request::content().
            'contentRules' => [],
            // Known query parameters and their types (Rule\QueryRule):
            // [['paths' => [regex, ...] or null (every path), 'exact' => [name => type],
            //   'globs' => [regex => type]], ...]; types: int, number, word, id, list,
            // text, any, or a regex. queryStrict: anything else is refused (404).
            'queryParams' => [],
            'queryStrict' => false,
            // Methods allowed only on some paths: ['POST' => [regex, ...]]; other
            // paths answer 405 for that method. Methods not listed: see 'methods'.
            'methodPaths' => [],
            // One line per request the shield did something about (Log). level:
            // stop (rejected, throttled, challenged), flag (also allow-uncached),
            // all (every request), off. ip: masked (/24, /48) or full.
            'log' => ['file' => null, 'level' => 'stop', 'ip' => 'masked', 'maxSize' => 10485760],
            // Counters for the dashboard (proposals 0012, 0014): per hour in the
            // store (APCu, else files in storeDir/stats); hours kept for 'hours'
            // days, day totals for 'days' days. Off: nothing is counted.
            // 'parts': what is counted -- requests (actions, rules, status codes),
            // crawlers (0014), not-found (pages the site did not find, and the
            // links to them), bots (other bots by family), pages (the most visited
            // pages, by people, crawlers, bots). 'flush': with APCu,
            // every so many seconds the counts are written to the hour's file,
            // so a restart of PHP-FPM loses at most that much (0: only hourly).
            // Days past 'days' are summed into their month, kept 'months' months (0: for good).
            'stats' => ['enabled' => false, 'parts' => ['requests', 'crawlers', 'not-found', 'bots', 'pages'], 'hours' => 7, 'days' => 400, 'months' => 0, 'flush' => 60],
            // One log file per known crawler and day (dir/CRAWL-GPTBOT/2026-09-30.log),
            // for the kinds listed ([]: all); kept 'days' days; 'query' false leaves
            // the query string out. null: none.
            'crawlerLog' => ['dir' => null, 'kinds' => [], 'days' => 30, 'query' => true],
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
