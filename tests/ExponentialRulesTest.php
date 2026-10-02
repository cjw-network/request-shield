<?php

declare(strict_types=1);

/**
 * examples/exponential/exponential.rules: the proposal for an Exponential
 * site decides as docs/use-cases/exponential.md says -- switched to "enforce"
 * (the file ships in monitor mode).
 */

use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/**
 * The example as a site would switch it on: enforce, query strict.
 *
 * @param string $admin 'uri' (the siteaccess /admin) or 'host' (admin.example.org)
 * @return array{string, string} the directory and the main rule file in it
 */
function exponentialRules(string $admin): array
{
    $from = dirname(__DIR__) . '/examples/exponential';
    $text = (string) file_get_contents("$from/exponential.rules");
    $text = (string) preg_replace('/^set\s+mode monitor.*$/m', 'set mode enforce', $text);
    $text = (string) preg_replace('/^\[EXP-STRICT\]\s+monitor /m', '[EXP-STRICT] ', $text);
    $dir = ruleDir(['exponential.rules' => $text,
        'exponential-admin-uri.rules' => (string) file_get_contents("$from/exponential-admin-uri.rules"),
        'exponential-admin-host.rules' => (string) file_get_contents("$from/exponential-admin-host.rules")]);
    return [$dir, "$dir/exponential-admin-$admin.rules"];
}

/** Each request decided as expected, for each admin file given. */
function exponentialCases(array $cases, string ...$admins): void
{
    foreach ($admins as $admin) {
        [$dir, $file] = exponentialRules($admin);
        try {
            foreach ($cases as $request => $expected) {
                $got = exponentialDecides($file, $dir, $request);
                same($expected, strpos($expected, ' ') === false ? strtok($got, ' ') : $got, "$request (admin: $admin)");   // without a rule: the decision only
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}

/** "<action> <rule>" for one request: "reject EXP-SYSVIEW", "allow". */
function exponentialDecides(string $file, string $dir, string $request): string
{
    [$method, $url] = explode(' ', $request, 2);
    $parts = parse_url($url) ?: [];
    $host = (string) ($parts['host'] ?? 'www.example.org');
    $uri = (string) ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'QUERY_STRING' => (string) ($parts['query'] ?? ''),
        'HTTP_HOST' => $host, 'SERVER_NAME' => $host, 'REMOTE_ADDR' => '198.51.100.7', 'HTTPS' => 'on',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'HTTP_ACCEPT' => 'text/html'];
    putenv("EXP_VAR=$dir/var");
    $settings = Settings::loadFor($file, $server, "$dir/cache");
    $shield = new Shield($settings, new MemoryStore());
    $r = Request::fromServer($server);
    $d = $shield->decide($r, 1000.0);
    $rule = $shield->explain($d, $r);
    return $d->action . ($rule !== null && $d->action !== 'allow' && $d->action !== 'allow-uncached' ? " $rule" : '');
}

return [
    'both main files are valid as shipped (monitor mode): check finds no error' => function (): void {
        putenv('EXP_VAR=' . sys_get_temp_dir() . '/rshield-exp-' . getmypid());
        foreach (['uri', 'host'] as $admin) {
            $read = CjwNetwork\RequestShield\Rules\RuleFile::read([dirname(__DIR__) . "/examples/exponential/exponential-admin-$admin.rules"]);
            same('monitor', $read['config']['mode'] ?? null, "admin-$admin ships in monitor mode: log first, refuse nobody");
        }
    },

    'frontend: pages by alias pass; system URLs, admin modules and internal files are refused' => function (): void {
        exponentialCases([
                'GET https://www.example.org/' => 'allow',
                'GET https://www.example.org/news/2026/fit-and-healthy' => 'allow',
                'GET https://www.example.org/content/view/full/2' => 'reject EXP-SYSVIEW',
                'GET https://www.example.org/Content/View/Full/2' => 'reject EXP-SYSVIEW',
                'GET https://www.example.org/ger/content/view/full/89' => 'reject EXP-SYSVIEW',
                'GET https://www.example.org/index.php/content/view/full/89' => 'reject EXP-SYSVIEW',
                'GET https://www.example.org/layout/set/print/content/view/full/89' => 'reject EXP-SYSVIEW',
                'GET https://www.example.org/content/view/sitemap/2' => 'allow',
                'GET https://www.example.org/content/view/tagcloud/2' => 'allow',
                'GET https://www.example.org/class/grouplist' => 'reject EXP-MODULES',
                'GET https://www.example.org/eng/setup/info' => 'reject EXP-MODULES',
                'GET https://www.example.org/shop/package' => 'allow',
                'GET https://www.example.org/news/setup-guide' => 'allow',
                'GET https://www.example.org/settings/site.ini' => 'reject EXP-INTERNAL',
                'GET https://www.example.org/var/site/cache/expiry.php' => 'reject EXP-INTERNAL',
                'GET https://www.example.org/extension/x/settings/site.ini.append.php' => 'reject EXP-INI',
                'GET https://www.example.org/content/download/12/34/file/report.zip' => 'allow',
                'GET https://www.example.org/backup.zip' => 'reject SCAN-BACKUP',
        ], 'uri', 'host');
    },

    'view parameters are numbers; the search takes its fields and its time filter -- anything else 404, attacks 403' => function (): void {
        exponentialCases([
                'GET https://www.example.org/news/(offset)/20' => 'allow',
                'GET https://www.example.org/blog/(year)/2026/(month)/9' => 'allow',
                'GET https://www.example.org/news/(offset)/1%27or1' => 'reject EXP-VIEWPARAMS',
                'GET https://www.example.org/news/(offset)/99999999' => 'reject EXP-VIEWPARAMS',
                'GET https://www.example.org/content/search?SearchText=yoga&SearchDate=3' => 'allow-uncached',
                'GET https://www.example.org/content/advancedsearch?SearchText=a&SubTreeArray[]=2&SearchContentClassID=-1&SearchDate=-1' => 'allow-uncached',
                'GET https://www.example.org/content/search?SearchText=yoga&SearchDate=9' => 'reject EXP-STRICT',
                'GET https://www.example.org/content/search?SearchText=x%27+union+select+1--' => 'reject ATK-SQL-UNION',
                'GET https://www.example.org/news?debug=1' => 'reject EXP-STRICT',
                'GET https://www.example.org/news?utm_source=newsletter' => 'allow-uncached',
        ], 'uri', 'host');
    },

    'forms: a POST only where Exponential takes one; login and contact forms checked first' => function (): void {
        exponentialCases([
                'POST https://www.example.org/news/2026/fit-and-healthy' => 'reject EXP-POST',   // 405, named by the first allow POST line (the admin's own comes later)
                'POST https://www.example.org/content/action' => 'challenge EXP-FORMS',
                'POST https://www.example.org/bold_ger/content/action' => 'challenge EXP-FORMS',
                'GET https://www.example.org/user/login' => 'challenge EXP-LOGIN',
                'POST https://www.example.org/user/login' => 'challenge EXP-LOGIN',
                'POST https://www.example.org/ezjscore/call/ezstarrating::rate' => 'allow-uncached',
        ], 'uri', 'host');
    },

    'the admin as the siteaccess /admin: system URLs, its modules and saving everywhere -- each editor checked once per pass' => function (): void {
        exponentialCases([
            'GET https://www.example.org/admin/content/view/full/2' => 'challenge EXP-ADMIN-CHECK',
            'GET https://www.example.org/admin/class/grouplist' => 'challenge EXP-ADMIN-CHECK',
            'GET https://www.example.org/admin/setup/info' => 'challenge EXP-ADMIN-CHECK',
            'POST https://www.example.org/admin/class/edit/1' => 'challenge EXP-ADMIN-CHECK',
            'POST https://www.example.org/admin/content/edit/87/1/ger-DE' => 'challenge EXP-ADMIN-CHECK',
            'GET https://www.example.org/admin/content/edit/87?anything=goes' => 'challenge EXP-ADMIN-CHECK',
            'GET https://www.example.org/admin/content/edit/87?x=%3Cscript%3Ealert(1)%3C/script%3E' => 'reject ATK-XSS-TAG',
            'GET https://www.example.org/admin/user/login' => 'challenge EXP-LOGIN',
            'GET https://www.example.org/admin/dashboard' => 'challenge EXP-ADMIN-CHECK',
            'GET https://www.example.org/administration-guide' => 'allow',
            'GET https://www.example.org/content/view/full/2' => 'reject EXP-SYSVIEW',
            'POST https://www.example.org/class/edit/1' => 'reject EXP-MODULES',
        ], 'uri');
        [$dir, $file] = exponentialRules('uri');
        try {
            putenv("EXP_VAR=$dir/var");
            same(7200, Settings::loadFor($file, ['SERVER_NAME' => 'www.example.org'], "$dir/cache")->challenge->passTtl, 'one host for visitors and editors: two hours for both');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },

    'the admin on a host of its own: its block, picked by the server name -- the frontend unchanged' => function (): void {
        exponentialCases([
            'GET https://admin.example.org/content/view/full/2' => 'challenge EXP-AH-CHECK',
            'GET https://admin.example.org/class/grouplist' => 'challenge EXP-AH-CHECK',
            'POST https://admin.example.org/class/edit/1' => 'challenge EXP-AH-CHECK',
            'POST https://admin.example.org/content/edit/87/1/ger-DE' => 'challenge EXP-AH-CHECK',
            'GET https://admin.example.org/user/login' => 'challenge EXP-LOGIN',
            'GET https://admin.example.org/dashboard?anything=goes' => 'challenge EXP-AH-CHECK',
            'GET https://www.example.org/content/view/full/2' => 'reject EXP-SYSVIEW',
            'GET https://www.example.org/admin/content/view/full/2' => 'reject EXP-SYSVIEW',
        ], 'host');
        [$dir, $file] = exponentialRules('host');
        try {
            putenv("EXP_VAR=$dir/var");
            same(28800, Settings::loadFor($file, ['SERVER_NAME' => 'admin.example.org'], "$dir/cache")->challenge->passTtl, 'editors on their own host: one check a working day');
            same(7200, Settings::loadFor($file, ['SERVER_NAME' => 'www.example.org'], "$dir/cache")->challenge->passTtl, 'visitors: two hours');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
