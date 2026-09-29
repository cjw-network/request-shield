<?php

declare(strict_types=1);

/**
 * examples/demo, run as its README says (PHP's built-in server with its
 * router), so the example cannot quietly stop working.
 */

/**
 * Starts the demo, runs $body with a request function, stops it. $prefix ''
 * runs it as its README says (router.php, at the root); '/examples/demo/index.php'
 * with the repository as document root and no rewrite rules, as in a
 * subdirectory of a web server. The request function adds the prefix itself.
 */
function withDemo(callable $body, string $prefix = ''): void
{
    $var = sys_get_temp_dir() . '/rshield-demo-' . getmypid() . '-' . mt_rand();
    mkdir($var, 0700, true);
    $port = freePort();
    $root = dirname(__DIR__);
    $cmd = sprintf('REQUEST_SHIELD_DEMO_VAR=%s exec %s -S 127.0.0.1:%d %s > /dev/null 2>&1',
        escapeshellarg($var), escapeshellarg(PHP_BINARY), $port,
        $prefix === '' ? escapeshellarg($root . '/examples/demo/router.php') : '-t ' . escapeshellarg($root));
    $proc = proc_open($cmd, [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body(function (string $method, string $uri, array $headers = [], string $content = '') use ($port, $prefix): array {
            $h = '';
            foreach ($headers as $k => $v) {
                $h .= "$k: $v\r\n";
            }
            $opts = ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0];
            if ($content !== '') {
                $opts['content'] = $content;
                $opts['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            }
            $body = @file_get_contents("http://127.0.0.1:$port$prefix$uri", false, stream_context_create(['http' => $opts]));
            $status = 0;
            $shield = null;
            $cookies = [];
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                }
                if (stripos($line, 'X-Request-Shield:') === 0) {
                    $shield = trim(substr($line, 17));
                }
                if (preg_match('#^Set-Cookie:\s*([^=]+)=([^;]*)#i', $line, $m)) {
                    $cookies[$m[1]] = $m[2];
                }
            }
            $location = null;
            foreach ($http_response_header ?? [] as $line) {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }
            }
            return ['status' => $status, 'body' => (string) $body, 'shield' => $shield, 'cookies' => $cookies, 'location' => $location, 'headers' => $http_response_header ?? []];
        });
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($var));
    }
}

$examples = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withDemo(function (callable $get) use ($prefix): void {
            $r = $get('GET', '/');
            same(200, $r['status'], 'front page');
            same('allow', $r['shield']);
            truthy(strpos($r['body'], 'request-shield demo') !== false, 'the page itself');
            truthy(strpos($r['body'], 'href="' . $prefix . '/challenge"') !== false, 'links start where the demo lives');
            truthy(strpos($r['body'], 'action="' . $prefix . '/edit"') !== false, 'so does the form');
            truthy(strpos($r['body'], 'href="' . $prefix . '/rules?method=GET&amp;url=' . rawurlencode($prefix . '/.env') . '&amp;ip=127.0.0.1#check">See the path') !== false, 'each example links to its path on the rules page');
            $rules = $get('GET', '/rules?method=GET&url=' . rawurlencode($prefix . '/files/%2e%2e/secret') . '&ip=127.0.0.1');
            truthy(strpos($rules['body'], 'This visitor gets a broken request (400)') !== false, 'and the rules page checks exactly that address, encoding and all');
            $r = $get('GET', '/page/about?page=2', ['X-Forwarded-For' => '203.0.113.9']);
            truthy(preg_match('#<code>http://127\.0\.0\.1:\d+' . preg_quote($prefix, '#') . '/page/about\?page=2</code>#', $r['body']) === 1, 'the full URL on the page');
            truthy(strpos($r['body'], '<del class="no">X-Forwarded-For: 203.0.113.9</del>') !== false, 'the forged header shown as removed');
            truthy(strpos($r['body'], 'X-Request-Shield: allow') !== false, 'the answer\'s headers, with the decision');
            same('allow-uncached query parameter; rule=DEMO-CACHE-QUERY', $get('GET', '/?utm_source=newsletter')['shield']);
            $r = $get('GET', '/random/abc');
            same(200, $r['status'], 'an unknown path passes: the site answers it');
            same('allow-uncached path not cacheable; rule=DEMO-CACHE', $r['shield']);
            $r = $get('GET', '/.env');
            same(404, $r['status'], 'scanner path');
            truthy(strpos($r['body'], '<a href="' . $prefix . '/">To the home page</a>') !== false, 'the shield\'s own page leads back to the demo');
            same('reject blocked path; rule=SCAN-HIDDEN', $r['shield']);
            same(400, $get('GET', '/files/%2e%2e/secret')['status'], 'traversal');
            $r = $get('GET', '/files/.env');
            same(200, $r['status'], 'the file reader: hidden files open there, for this machine');
            truthy(strpos($r['body'], 'The file reader would show &quot;.env&quot;') !== false, 'the file reader page');
            same(200, $get('GET', '/files/backup.sql')['status'], 'backups too');
            $r = $get('GET', '/files/%2e%2e/secret');
            same('reject path traversal; rule=built-in', $r['shield']);
            $r = $get('GET', '/reset');
            same(303, $r['status'], 'reset redirects');
            same($prefix . '/', $r['location'], 'back to the demo\'s front page');
            // PHP deletes a cookie as "name=deleted" with an expiry in the past.
            truthy(in_array($r['cookies']['rs_pass'] ?? null, ['', 'deleted'], true), 'the pass cookie is deleted');
            $r = $get('GET', '/');
            truthy(preg_match('#reject 404 &quot;blocked path&quot; rule=SCAN-HIDDEN &quot;GET http://127\.0\.0\.1' . preg_quote($prefix, '#') . '/\.env&quot;#', $r['body']) === 1, 'the log on the page, with the full URL');
            truthy(strpos($r['body'], ' 127.0.0.0/24 reject') !== false, 'the address anonymised in the log');
        }, $prefix);
};

$forms = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withDemo(function (callable $get) use ($prefix): void {
            $r = $get('POST', '/edit', [], 'message=' . rawurlencode('<b>hi</b>'));
            same(200, $r['status'], 'the edit form takes a POST');
            truthy(strpos($r['body'], '&lt;b&gt;hi&lt;/b&gt;') !== false, 'the posted text, escaped');
            same('allow-uncached method; rule=built-in', $r['shield']);
            $r = $get('POST', '/page/about', [], 'message=spam');
            same(405, $r['status'], 'a POST where there is no form');
            same('reject method not allowed here; rule=DEMO-FORMS', $r['shield']);
            same(403, $get('GET', '/admin/')['status'], 'the admin area: not from here');
            same('reject restricted; rule=DEMO-ADMIN', $get('GET', '/admin/')['shield']);
            foreach (['//admin/', '/%61dmin/', '/ADMIN/users', '/./admin/'] as $sneaked) {
                same(403, $get('GET', $sneaked)['status'], "sneaked: $sneaked");
            }
            $r = $get('GET', '/rules?method=GET&url=' . rawurlencode('/wp-config.php.bak') . '&ip=198.51.100.7');
            same(200, $r['status'], 'the active rules page, for this machine');
            truthy(strpos($r['body'], 'This visitor gets &quot;not found&quot; (404)') !== false, 'the check on the page');
            truthy(strpos($r['body'], 'only for 127.0.0.1, ::1') !== false, 'the page\'s own rule in words');
            $r = $get('GET', '/api/status');
            same(200, $r['status'], 'the API answers this machine');
            truthy(strpos($r['body'], '"ok":true') !== false, 'the API itself');
            for ($i = 1; $i <= 10; $i++) {
                $r = $get('GET', '/search?q=' . $i);
                same(200, $r['status'], "search $i");
            }
            same('allow-uncached query parameter; rule=DEMO-CACHE-QUERY', $r['shield'], 'a search is never cached');
            $r = $get('GET', '/search?q=11');
            same(429, $r['status'], 'search 11: the page\'s own budget');
            truthy(strpos($r['body'], 'Too many searches') !== false, 'says why');
        }, $prefix);
};

$challenge = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        withDemo(function (callable $get): void {
            $r = $get('GET', '/challenge');
            same(429, $r['status'], 'challenged at once, whatever the budget');
            same('challenge always; rule=DEMO-LOGIN', $r['shield']);
            truthy(preg_match('/var RS=(\{.*?\});\(function/s', $r['body'], $m) === 1, 'the challenge page');
            $rs = json_decode($m[1], true);
            [$payload] = solveInNode($rs['c']);
            $r = $get('GET', '/challenge', ['Cookie' => $rs['cookie'] . '=' . $payload]);
            same(200, $r['status'], 'solved');
            truthy(strpos($r['body'], 'You passed the browser check') !== false, 'the page behind the check');
            truthy(strpos($r['body'], 'What just happened') !== false, 'and what just happened, step by step');
            $pass = $r['cookies']['rs_pass'] ?? '';
            truthy($pass !== '', 'pass cookie');
            same(200, $get('GET', '/challenge', ['Cookie' => 'rs_pass=' . $pass])['status'], 'with the pass: straight through');
        }, $prefix);
};

$budget = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withDemo(function (callable $get): void {
            for ($i = 1; $i <= 20; $i++) {
                same(200, $get('GET', '/')['status'], "request $i");
            }
            $r = $get('GET', '/');
            same(429, $r['status'], 'request 21');
            same('challenge requests; rule=DEMO-PACE', $r['shield']);
        }, $prefix);
};

$appChallenges = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        withDemo(function (callable $get) use ($prefix): void {
            // A comment without a pass: the check, with the comment inside.
            $form = 'comment=' . rawurlencode('Hello <b>world</b>') . '&token=abc%26123&tags%5B0%5D=a&tags%5B1%5D=b';
            $r = $get('POST', '/comment', ['Accept-Language' => 'de'], $form);
            same(429, $r['status'], 'the check first');
            same('challenge app; rule=application', $r['shield']);
            truthy(strpos($r['body'], 'danach wird gesendet, was Sie eingegeben haben') !== false, 'says so, in German');
            truthy(strpos($r['body'], '<form id="resend" method="post" action="' . $prefix . '/comment">') !== false, 'the form, to the same address');
            truthy(strpos($r['body'], '<input type="hidden" name="comment" value="Hello &lt;b&gt;world&lt;/b&gt;">') !== false, 'the comment, escaped');
            truthy(strpos($r['body'], 'name="token" value="abc&amp;123"') !== false && strpos($r['body'], 'name="tags[1]" value="b"') !== false, 'every field, the form token and lists too');
            truthy(strpos($r['body'], '<noscript><button type="submit">Erneut senden</button></noscript>') !== false, 'a button without JavaScript');
            // The browser solves it and sends the form again, with the solution.
            preg_match('/var RS=(\{.*?\});\(function/s', $r['body'], $m);
            $rs = json_decode($m[1], true);
            same(true, $rs['resend'], 'the script sends the form, it does not reload');
            [$payload] = solveInNode($rs['c']);
            $r = $get('POST', '/comment', ['Cookie' => $rs['cookie'] . '=' . $payload], $form);
            same(200, $r['status'], 'sent again: through');
            truthy(strpos($r['body'], 'your comment &quot;Hello &lt;b&gt;world&lt;/b&gt;&quot; arrived') !== false, 'the comment arrived');
            $pass = $r['cookies']['rs_pass'] ?? '';
            truthy($pass !== '', 'and a pass');
            same(200, $get('POST', '/comment', ['Cookie' => "rs_pass=$pass"], 'comment=again')['status'], 'with the pass: straight through');
            same(429, $get('POST', '/comment', ['Cookie' => $rs['cookie'] . '=' . $payload], $form)['status'], 'the same solution twice: no');
            // A page that asks for the check with a header.
            $r = $get('GET', '/profile');
            same(429, $r['status'], 'the page asked for the check');
            truthy(strpos($r['body'], 'var RS=') !== false && strpos($r['body'], 'This page asked for the browser check') === false, 'the check page, nothing of the page');
            $r = $get('GET', '/profile', ['Cookie' => "rs_pass=$pass"]);
            same(200, $r['status'], 'with a pass: the page');
            truthy(strpos($r['body'], 'This page asked for the browser check with a header') !== false, 'the page itself');
            truthy(strpos(implode("\n", $r['headers']), 'X-Request-Shield-Challenge') === false, 'the header never reaches the browser');
        }, $prefix);
};

$sub = '/examples/demo/index.php';
return [
    'the demo: every example link does what the page says' => fn () => $examples(''),
    'the demo: /challenge is always checked; solved, it opens' => fn () => $challenge(''),
    'the demo: past 20 requests a minute the check appears on any page' => fn () => $budget(''),
    'the demo: search budget, edit form, a POST elsewhere, admin and API by address' => fn () => $forms(''),
    'the demo in a subdirectory, without rewrite rules: the same' => fn () => $examples($sub),
    'the demo in a subdirectory: /challenge is always checked' => fn () => $challenge($sub),
    'the demo in a subdirectory: the budget' => fn () => $budget($sub),
    'the demo in a subdirectory: search, forms, admin and API' => fn () => $forms($sub),
    'the demo: the site asks for the check -- a comment sent again after it, a page that asks with a header' => fn () => $appChallenges(''),
    'the demo in a subdirectory: the site asks for the check' => fn () => $appChallenges($sub),
];
