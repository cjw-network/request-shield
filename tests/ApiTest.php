<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Api\Api;
use CjwNetwork\RequestShield\Api\ApiExtension;
use CjwNetwork\RequestShield\Dashboard;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Response;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;

/**
 * The API (RSF06-05, 0031 G.0): the shield's data as JSON below
 * <dashboard-path>/api/v1 -- guarded like the dashboard, problems as RFC 9457,
 * an ETag on the data, CORS only for api-origins, writes only with
 * api-write; Api::call() in the same process answers what HTTP answers.
 */

const API_ADMIN = 'api-admin-token-0123456789abcdefghijklmnopq';
const API_READER = 'api-reader-token-0123456789abcdefghijklmno';

/** Settings from a rule file in a fresh folder. */
function apiSettings(string $dir, string $rules): Settings
{
    file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset secret " . str_repeat('s', 40) . "\n$rules");
    return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
}

/** The token lines: the administrator, and a reader (a customer's group). */
function apiTokens(): string
{
    return 'dashboard-access * sha256:' . hash('sha256', API_ADMIN) . "\nstats-group \"Customer A\" a.example\n"
        . 'dashboard-access "Customer A" sha256:' . hash('sha256', API_READER) . "\n";
}

/** What the dashboard answers a request (as Shield does for a route). */
function apiServe(Settings $s, string $method, string $uri, array $headers = [], array $post = [], string $ip = '203.0.113.9'): ?Response
{
    $server = ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method, 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => $ip, 'HTTPS' => 'on'];
    foreach ($headers as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $request = Request::fromServer($server);
    $route = Dashboard::routeFor($s, $request);
    if ($route === null) {
        return null;
    }
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $get);
    return Dashboard::serve($s, $request, $route, $get, $post);
}

/** A header's value in a response, or null. */
function apiHeader(Response $r, string $name): ?string
{
    foreach ($r->headers as $h) {
        if (stripos($h, "$name:") === 0) {
            return trim(substr($h, strlen($name) + 1));
        }
    }
    return null;
}

function apiDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-api-' . getmypid() . '-' . mt_rand();
    mkdir($dir);
    return $dir;
}

return [
    'RSF06-05 the API is on, below dashboard-path, guarded like the pages: GET /status answers the envelope -- data, version, when, the tier' => function (): void {
        $dir = apiDir();
        try {
            $s = apiSettings($dir, "restrict **/rs/** to 203.0.113.0/24\n");
            truthy(isset($s->routes['/rs/api/v1/status'], $s->routes['/rs/api/v1/openapi.json']), 'the routes: ' . implode(', ', array_keys($s->routes)));
            $r = apiServe($s, 'GET', '/rs/api/v1/status');
            truthy($r !== null && $r->status === 200 && apiHeader($r, 'Content-Type') === 'application/json; charset=utf-8', 'JSON');
            $j = json_decode($r->body, true);
            same(['data', 'meta'], array_keys($j));
            same(\CjwNetwork\RequestShield\Shield::VERSION, $j['data']['version']);
            truthy(in_array($j['meta']['tier'], ['S0', 'S1', 'S2'], true) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $j['meta']['generated']) === 1, 'meta');
            truthy(strpos((string) apiHeader($r, 'Cache-Control'), 'no-store') !== false && apiHeader($r, 'X-Robots-Tag') !== null, 'the dashboard\'s headers: never kept, never indexed');
            $etag = (string) apiHeader($r, 'ETag');
            truthy(preg_match('/^"[0-9a-f]{20}"$/', $etag) === 1, "an ETag: $etag");
            same(304, apiServe($s, 'GET', '/rs/api/v1/status', ['If-None-Match' => $etag])->status, 'unchanged: 304');
            same(null, apiServe($s, 'GET', '/rs/api/v1/nothing-here'), 'an address that is no endpoint is no route: the site answers it');
            same([], apiSettings($dir, "restrict **/rs/** to 203.0.113.0/24\nset api off\n")->ext['api']['enabled'] ? ['on'] : [], 'set api off');
            same(false, isset(apiSettings($dir, "set api off\n")->routes['/rs/api/v1/status']), 'off: no routes');
            truthy(isset(apiSettings($dir, "set dashboard-path /admin/rs\n")->routes['/admin/rs/api/v1/status']), 'it moves with dashboard-path');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-05 a program gets JSON, never the login form: no token or a wrong one 401 with WWW-Authenticate, unguarded 403 -- problems as RFC 9457' => function (): void {
        $dir = apiDir();
        try {
            $s = apiSettings($dir, apiTokens());
            $r = apiServe($s, 'GET', '/rs/api/v1/status');
            same([401, 'application/problem+json; charset=utf-8', 'Bearer'], [$r->status, apiHeader($r, 'Content-Type'), apiHeader($r, 'WWW-Authenticate')]);
            same(['type', 'title', 'status', 'detail'], array_keys(json_decode($r->body, true)));
            truthy(strpos($r->body, 'rs-token') === false && strpos($r->body, '<form') === false, 'no form');
            same(401, apiServe($s, 'GET', '/rs/api/v1/status', ['Authorization' => 'Bearer wrong'])->status, 'a wrong token');
            same(200, apiServe($s, 'GET', '/rs/api/v1/status', ['Authorization' => 'Bearer ' . API_ADMIN])->status, 'the administrator\'s token');
            same(200, apiServe($s, 'GET', '/rs/api/v1/status', ['Authorization' => 'Bearer ' . API_READER])->status, 'a reader\'s: status is for readers');
            $open = apiSettings($dir, '');
            $r = apiServe($open, 'GET', '/rs/api/v1/status');
            same([403, 'application/problem+json; charset=utf-8'], [$r->status, apiHeader($r, 'Content-Type')], 'nobody guards it: refused');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-05 Api::call() answers what HTTP answers; roles, writes and unknown methods are refused the same way' => function (): void {
        $dir = apiDir();
        try {
            $s = apiSettings($dir, "restrict **/rs/** to 203.0.113.0/24\n");
            $http = json_decode(apiServe($s, 'GET', '/rs/api/v1/status')->body, true);
            $call = Api::call($s, 'GET', '/status', [], '*', ['ruleFile' => null]);
            unset($http['meta']['generated'], $call['meta']['generated']);
            same(json_encode($http), json_encode($call), 'the same bytes (but the second it was made)');
            same(405, Api::call($s, 'POST', '/status')['status'], 'POST /status: 405');
            same(404, Api::call($s, 'GET', '/nope')['status']);
            same(['type', 'title', 'status', 'detail'], array_keys(Api::call($s, 'GET', '/nope')));
            same(404, Api::call(apiSettings($dir, "set api off\n"), 'GET', '/status')['status'], 'off: nothing, in-process too');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-05 another website\'s page may call it only when api-origins names it (CORS); a session\'s POST must come from here' => function (): void {
        $dir = apiDir();
        try {
            $s = apiSettings($dir, "restrict **/rs/** to 203.0.113.0/24\nset api-origins https://cms.example.org\n");
            $r = apiServe($s, 'OPTIONS', '/rs/api/v1/status', ['Origin' => 'https://cms.example.org']);
            same([204, 'https://cms.example.org'], [$r->status, apiHeader($r, 'Access-Control-Allow-Origin')], 'the preflight');
            same('https://cms.example.org', apiHeader(apiServe($s, 'GET', '/rs/api/v1/status', ['Origin' => 'https://cms.example.org']), 'Access-Control-Allow-Origin'));
            $r = apiServe($s, 'GET', '/rs/api/v1/status', ['Origin' => 'https://evil.example']);
            same(null, apiHeader($r, 'Access-Control-Allow-Origin'), 'another website: no CORS header, the browser keeps the answer from its page');
            same(403, apiServe($s, 'OPTIONS', '/rs/api/v1/status', ['Origin' => 'https://evil.example'])->status);
            same(403, apiServe($s, 'POST', '/rs/api/v1/status')->status, 'a POST without Origin and without a token');
            same(405, apiServe($s, 'POST', '/rs/api/v1/status', ['Origin' => 'https://www.example.org'])->status, 'from this website: on to the endpoint');
            same(405, apiServe($s, 'POST', '/rs/api/v1/status', ['Authorization' => 'Bearer x'])->status === 405 ? 405 : 0, 'a token instead of a cookie: no Origin needed');
            $thrown = false;
            try {
                apiSettings($dir, "set api-origins cms.example.org\n");
            } catch (\InvalidArgumentException $e) {
                $thrown = strpos($e->getMessage(), 'api-origins') !== false;
            }
            truthy($thrown, 'api-origins takes websites as a browser names them');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-05 check says what keeps a script from the API -- no api-path, allow POST without it, query strict -- where a program may call it' => function (): void {
        $dir = apiDir();
        try {
            same([], ApiExtension::check(apiSettings($dir, "allow POST /contact\nquery strict\n")), 'no tokens, no writes, no other websites: nobody calls it by script -- nothing to say');
            $warn = ApiExtension::check(apiSettings($dir, "allow POST /contact\nquery strict\n" . apiTokens()));
            same(3, count($warn), implode("\n", $warn));
            truthy(strpos($warn[0], 'api-path /rs/api/v1/**') !== false && strpos($warn[1], 'allow POST /rs/api/v1/**') !== false, implode("\n", $warn));
            same([], ApiExtension::check(apiSettings($dir, "api-path /rs/api/v1/**\nallow POST /contact /rs/api/v1/**\n" . apiTokens())), 'named: nothing to say');
            same([], ApiExtension::check(apiSettings($dir, "set api off\nquery strict\n")), 'off: nothing to say');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-05 end to end: a script asks the real server with a token and gets JSON; the site never runs for it' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = apiDir();
        mkdir("$dir/docroot");
        file_put_contents("$dir/docroot/index.php", '<?php echo "the site";');
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset secret " . str_repeat('s', 40) . "\napi-path /rs/api/v1/**\n" . apiTokens());
        $port = freePort();
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
            escapeshellarg("$dir/site.rules"), serverPhp(), escapeshellarg(rsEntry()), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri, array $headers = []) use ($port): array {
                $h = '';
                foreach ($headers as $k => $v) {
                    $h .= "$k: $v\r\n";
                }
                $out = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['header' => $h, 'ignore_errors' => true, 'timeout' => 10]]));
                return [(int) substr((string) ($http_response_header[0] ?? ''), 9, 3), implode("\n", $http_response_header ?? []), $out];
            };
            [$status, $headers, $body] = $get('/rs/api/v1/status', ['Authorization' => 'Bearer ' . API_ADMIN]);
            same(200, $status, $body);
            truthy(strpos($headers, 'Content-Type: application/json') !== false && (json_decode($body, true)['data']['version'] ?? null) === \CjwNetwork\RequestShield\Shield::VERSION, $body);
            [$status, $headers, $body] = $get('/rs/api/v1/status');
            truthy($status === 401 && strpos($headers, 'application/problem+json') !== false && strpos($body, 'the site') === false, "$status $body");
            [, , $body] = $get('/');
            same('the site', $body, 'the site itself answers the rest');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
