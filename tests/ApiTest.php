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
    'RSF06-05 the core\'s endpoints read what the command line and the pages show: rules, trace, crawlers, feeds, log, live -- each in its schema' => function (): void {
        $dir = apiDir();
        try {
            $s = apiSettings($dir, "restrict **/rs/** to 203.0.113.0/24\nset log $dir/shield.log\nset log-level all\n[SITE-ADMIN] restrict /admin/** to 192.0.2.0/24   # the office only\n");
            $rules = Api::call($s, 'GET', '/rules', ['days' => '3'])['data'];
            same(3, $rules['days']);
            $own = array_values(array_filter($rules['rules'], static fn (array $r): bool => $r['id'] === 'SITE-ADMIN'))[0] ?? null;
            same(['id' => 'SITE-ADMIN', 'where' => 'site.rules:7', 'text' => 'the office only', 'revision' => null, 'builtIn' => false], array_diff_key((array) $own, ['decided' => 1]));
            $hidden = array_values(array_filter($rules['rules'], static fn (array $r): bool => $r['id'] === 'SCAN-HIDDEN'))[0];
            same([true, 1], [$hidden['builtIn'], $hidden['revision']], 'a shipped rule: built in, its revision');
            same(400, Api::call($s, 'GET', '/rules', ['days' => 'x'])['status'], 'a wrong parameter names itself');
            truthy(strpos(Api::call($s, 'GET', '/rules', ['days' => '9999'])['detail'], 'days is a whole number from 1 to 400') !== false, 'the detail says what fits');
            $t = Api::call($s, 'POST', '/trace', ['url' => 'https://www.example.org/admin/', 'ip' => '198.51.100.7'])['data'];
            same([false, 403, 'SITE-ADMIN'], [$t['passes'], $t['status'], $t['rule']], 'trace: refused by the rule');
            truthy(count($t['steps']) > 10 && $t['steps'][0]['state'] === 'pass', 'every step');
            same(true, Api::call($s, 'POST', '/trace', ['url' => '/admin/', 'ip' => '192.0.2.10'])['data']['passes'], 'from the office');
            same(400, Api::call($s, 'POST', '/trace', [])['status'], 'url is missing');
            same(400, Api::call($s, 'POST', '/trace', ['url' => '/', 'ip' => 'nope'])['status']);
            truthy(count(Api::call($s, 'GET', '/crawlers')['data']['crawlers']) > 5, 'the known crawlers');
            same(['feeds' => []], Api::call($s, 'GET', '/feeds')['data'], 'no feed named');
            \CjwNetwork\RequestShield\Log::write($s, Request::fromServer(['REQUEST_URI' => '/admin/', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'curl/8']),
                \CjwNetwork\RequestShield\Decision::reject(403, 'restricted'), 'SITE-ADMIN', 1790800000.0, false);
            $log = Api::call($s, 'GET', '/log', ['cursor' => '0:0'])['data'];
            same([1, 'SITE-ADMIN', 403], [count($log['rows']), $log['rows'][0]['rule'], $log['rows'][0]['status']], 'the log, parsed');
            same([], Api::call($s, 'GET', '/log', ['cursor' => $log['cursor']])['data']['rows'], 'from its cursor: nothing new');
            same(true, Api::call($s, 'GET', '/live')['data']['log'], 'the live view reads the log');
            same(409, Api::call(apiSettings($dir, ''), 'GET', '/log')['status'], 'no log: a conflict that says set log');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-05 test and check run on the rule file the shield runs from; settings from a PHP array have none: 409' => function (): void {
        $dir = apiDir();
        try {
            $s = apiSettings($dir, "[SITE-OLD] block /old/**\nexpect GET /old/x 404 by SITE-OLD\nexpect GET /new/x answered\n");
            $ctx = ['ruleFile' => "$dir/site.rules"];
            $t = Api::call($s, 'POST', '/test', [], '*', $ctx)['data'];
            truthy($t['fail'] === 0 && $t['pass'] === $t['examples'] && $t['examples'] >= 2, json_encode($t));
            same(2, Api::call($s, 'POST', '/test', ['only' => 'SITE-OLD'], '*', $ctx)['data']['examples'], 'one rule\'s: both lines under it');
            $c = Api::call($s, 'POST', '/check', [], '*', $ctx)['data'];
            same([true, null], [$c['ok'], $c['error']]);
            file_put_contents("$dir/site.rules", "restrict /x too 1.2.3.4\n", FILE_APPEND);
            $c = Api::call($s, 'POST', '/check', [], '*', $ctx)['data'];
            truthy($c['ok'] === false && strpos((string) $c['error'], 'site.rules:') === 0 && strpos((string) $c['error'], $dir) === false, 'the mistake, by the file\'s name and line, not its path: ' . $c['error']);
            same(409, Api::call($s, 'POST', '/test')['status'], 'no rule file');
            same(409, Api::call($s, 'POST', '/check')['status']);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-05 writes only with set api-write on and only for the administrator: lists, reload -- with the dashboard\'s own checks' => function (): void {
        $dir = apiDir();
        try {
            $rules = "restrict **/rs/** to 203.0.113.0/24\nset lists-dir $dir/lists\n" . apiTokens();
            $off = apiSettings($dir, $rules);
            same(403, Api::call($off, 'POST', '/lists', ['address' => '203.0.113.66'])['status'], 'off by default');
            $s = apiSettings($dir, $rules . "set api-write on\n");
            $ctx = ['ruleFile' => "$dir/site.rules", 'ip' => '192.0.2.10'];
            same(403, Api::call($s, 'POST', '/lists', ['address' => '203.0.113.66'], 'customer-a', $ctx)['status'], 'a reader may not write');
            $add = Api::call($s, 'POST', '/lists', ['address' => '203.0.113.66', 'for' => '7d', 'note' => 'login attempts'], '*', $ctx);
            truthy(($add['data']['ok'] ?? false) === true, json_encode($add));
            $list = Api::call($s, 'GET', '/lists', ['q' => '203.0.113.66'])['data'];
            same([1, 'deny', ['203.0.113.66']], [$list['total'], $list['entries'][0]['kind'], $list['entries'][0]['addresses']]);
            $self = Api::call($s, 'POST', '/lists', ['address' => '192.0.2.10'], '*', $ctx);
            same(409, $self['status'], 'the caller\'s own address: refused, as on the page');
            $wide = Api::call($s, 'POST', '/lists', ['address' => '10.0.0.0/8'], '*', $ctx);
            same(409, $wide['status'], 'a wide range without confirm');
            $id = $list['entries'][0]['id'];
            same(true, Api::call($s, 'POST', '/lists/remove', ['id' => $id], '*', $ctx)['data']['ok']);
            same(0, Api::call($s, 'GET', '/lists', ['q' => '203.0.113.66'])['data']['total'], 'taken out');
            same(409, Api::call($s, 'POST', '/lists/remove', ['id' => $id], '*', $ctx)['status'], 'gone already');
            same(400, Api::call($s, 'POST', '/lists/remove', [], '*', $ctx)['status'], 'id is missing');
            touch("$dir/site.rules", 1000000000);
            clearstatcache();
            $r = Api::call($s, 'POST', '/reload', [], '*', $ctx);
            clearstatcache();
            truthy(($r['data']['reloaded'] ?? false) === true && filemtime("$dir/site.rules") > 1000000000, 'reload: marked changed');
            file_put_contents("$dir/site.rules", "restrict /x too 1.2.3.4\n", FILE_APPEND);
            touch("$dir/site.rules", 1000000000);
            same(409, Api::call($s, 'POST', '/reload', [], '*', $ctx)['status'], 'rules that do not compile are not reloaded');
            clearstatcache();
            same(1000000000, filemtime("$dir/site.rules"), 'untouched');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-05 review: with tokens a browser\'s preflight is answered before the login, and every problem carries CORS for api-origins; a form\'s fields count; a login\'s own answers are no API\'s; no server paths' => function (): void {
        $dir = apiDir();
        try {
            $s = apiSettings($dir, apiTokens() . "set api-origins https://cms.example.org\nset api-write on\n");
            $r = apiServe($s, 'OPTIONS', '/rs/api/v1/status', ['Origin' => 'https://cms.example.org']);
            same([204, 'https://cms.example.org'], [$r->status, apiHeader($r, 'Access-Control-Allow-Origin')], 'the preflight, without a token');
            $r = apiServe($s, 'GET', '/rs/api/v1/status', ['Origin' => 'https://cms.example.org']);
            same([401, 'https://cms.example.org'], [$r->status, apiHeader($r, 'Access-Control-Allow-Origin')], 'the 401 the page can read');
            same(null, apiHeader(apiServe($s, 'GET', '/rs/api/v1/status', ['Origin' => 'https://evil.example']), 'Access-Control-Allow-Origin'));
            $r = apiServe($s, 'GET', '/rs/api/v1/status?rs-logout=1');
            same([401, 'application/problem+json; charset=utf-8'], [$r->status, apiHeader($r, 'Content-Type')], 'a sign-out on the API: 401, not 200 with a problem');
            $r = apiServe($s, 'POST', '/rs/api/v1/trace', ['Authorization' => 'Bearer ' . API_ADMIN], ['url' => '/x', 'ip' => '192.0.2.1']);
            same(200, $r->status, 'a form\'s fields: ' . $r->body);
            file_put_contents("$dir/inc.rules", "block /old/**\n");
            $warned = apiSettings($dir, "include $dir/inc.rules\n");
            file_put_contents("$dir/inc.rules", "restrict /x too 1.2.3.4\n");
            $c = Api::call($warned, 'POST', '/check', [], '*', ['ruleFile' => "$dir/site.rules"])['data'];
            truthy($c['ok'] === false && strpos((string) $c['error'], $dir) === false && strpos((string) $c['error'], 'inc.rules') !== false, 'the mistake without the folder: ' . $c['error']);
            foreach (Api::call($s, 'GET', '/rules', [], '*', ['ruleFile' => "$dir/site.rules"])['data']['rules'] as $rule) {
                truthy(strpos($rule['where'], $dir) === false, "where: {$rule['where']}");
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
