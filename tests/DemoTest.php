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
                if (stripos($h, 'Content-Type:') === false) {
                    $opts['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
                }
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
            truthy(preg_match('#<tr id="t3-1">\s*<td class="no"><a href="\#t3-1">3\.1</a></td>#', $r['body']) === 1, 'one numbered row per test, with its own anchor');
            truthy(strpos($r['body'], '<tr class="group"><th colspan="4">7 · Forms (POST)</th></tr>') !== false, 'grouped, the groups numbered');
            truthy(strpos($r['body'], 'href="' . $prefix . '/rules?method=GET&amp;url=' . rawurlencode($prefix . '/.env') . '&amp;ip=127.0.0.1#check">See the path') !== false, 'each example links to its path on the rules page');
            $rules = $get('GET', '/rules?method=GET&url=' . rawurlencode($prefix . '/files/%2e%2e/secret') . '&ip=127.0.0.1');
            truthy(strpos($rules['body'], 'This visitor gets a broken request (400)') !== false, 'and the rules page checks exactly that address, encoding and all');
            $r = $get('GET', '/page/about?page=2', ['X-Forwarded-For' => '203.0.113.9']);
            truthy(preg_match('#<code>http://127\.0\.0\.1:\d+' . preg_quote($prefix, '#') . '/page/about\?page=2</code>#', $r['body']) === 1, 'the full URL on the page');
            truthy(strpos($r['body'], '<del class="no">X-Forwarded-For: 203.0.113.9</del>') !== false, 'the forged header shown as removed');
            truthy(strpos($r['body'], 'X-Request-Shield: allow') !== false, 'the answer\'s headers, with the decision');
            same('allow-uncached query parameter; rule=DEMO-CACHE-QUERY', $get('GET', '/?utm_source=newsletter')['shield']);
            // Known parameters: a number is a number, and nothing else gets in.
            same([404, 'reject unknown parameter; rule=DEMO-STRICT'], [($r = $get('GET', '/?page=2%27'))['status'], $r['shield']], 'not of its type');
            same(404, $get('GET', '/?debug=1')['status'], 'unknown');
            // A rule being watched: through, and the log notes what it would have done.
            $r = $get('GET', '/old/api');
            same(200, $r['status'], 'monitor: nobody is refused');
            truthy(in_array('X-Request-Shield-Monitor: reject blocked path; rule=DEMO-OLD', $r['headers'], true), 'what the watched rule would have done');
            same('challenge always; rule=DEMO-CHECKOUT', $get('GET', '/checkout')['shield'], 'the checkout checks');
            same(200, $get('GET', '/?page=2&fbclid=x&gclid=y')['status'], 'known, and marketing tags');
            same(200, $get('GET', '/rules?method=GET&url=' . rawurlencode('https://www.example.org/x?a=1') . '&ip=')['status'], 'the rules page\'s check, an empty field too');
            $r = $get('GET', '/random/abc');
            same(404, $r['status'], 'an unknown path passes the shield: the site answers it ("not found", as a CMS would)');
            same('allow-uncached path not cacheable; rule=DEMO-CACHE', $r['shield']);
            // The statistics page: counted, the page not found listed with the link to it.
            $get('GET', '/no-such-page', ['Referer' => 'http://127.0.0.1/stats']);
            $stats = json_decode($get('GET', '/rs/stats?format=json')['body'], true);
            $missing = array_filter(array_keys((array) ($stats['notFound'] ?? [])), static fn ($p): bool => substr((string) $p, -13) === '/no-such-page');
            truthy($missing !== [], 'the page not found, counted (under its full path): ' . json_encode($stats['notFound'] ?? null));
            $one = $get('GET', '/rs/stats?days=30&by=week&site=127.0.0.1');
            truthy($one['status'] === 200 && strpos($one['body'], '<option value="127.0.0.1" selected>') !== false, 'the statistics page, one website (stats-hosts): the switch, this website chosen');
            $r = $get('GET', '/rs/dashboard');
            same(200, $r['status'], 'the dashboard');
            truthy(strpos($r['body'], '/rs/shield?days=7') !== false && strpos($r['body'], 'class="tab on"') !== false, 'tabs: one address per view');
            same(200, $get('GET', '/RS/shield')['status'], 'in capitals too');
            $filtered = $get('GET', '/rs/stats?from=2026-09-01&to=2026-09-30&lang=de&path=%2Fpage%2F');
            truthy($filtered['status'] === 200 && strpos($filtered['body'], '01.09.2026 – 30.09.2026') !== false && strpos($filtered['body'], 'Seitenaufrufe') !== false,
                'the filter and a range, as their forms send them (query strict in the demo knows from and to)');
            $rules = $get('GET', '/rs/rules?lang=de');
            truthy($rules['status'] === 200 && strpos($rules['body'], 'Der Weg einer Anfrage') !== false && strpos($rules['body'], 'DEMO-PACE') !== false, 'rules & setup');
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

/** A multipart form: fields and one file. */
function multipart(array $fields, string $fileField, string $fileName, string $fileBody): array
{
    $b = 'rsb' . bin2hex(random_bytes(6));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    }
    $body .= "--$b\r\nContent-Disposition: form-data; name=\"$fileField\"; filename=\"$fileName\"\r\nContent-Type: text/plain\r\n\r\n$fileBody\r\n--$b--\r\n";
    return ["multipart/form-data; boundary=$b", $body];
}

$widget = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        withDemo(function (callable $get) use ($prefix): void {
            $r = $get('GET', '/contact');
            truthy(strpos($r['body'], '<div data-request-shield></div><script src="' . $prefix . '/request-shield/widget.js?v=' . \CjwNetwork\RequestShield\Challenge\Widget::version() . '" defer></script>') !== false, 'the placeholder and the script, versioned');
            $js = $get('GET', '/request-shield/widget.js');
            same(200, $js['status']);
            truthy(strpos(implode("\n", $js['headers']), 'Content-Type: text/javascript') !== false && strpos($js['body'], 'data-request-shield') !== false, 'the script');
            preg_match('/ETag: (\S+)/', implode("\n", $js['headers']), $m);
            same(304, $get('GET', '/request-shield/widget.js', ['If-None-Match' => $m[1] ?? '-'])['status'], 'cached by the browser');
            same(404, $get('GET', '/request-shield/other')['status'], 'nothing else there');
            // The task, in the visitor's language, never cached
            $r = $get('GET', '/request-shield/challenge', ['Accept-Language' => 'de']);
            same(200, $r['status']);
            $task = json_decode($r['body'], true);
            same([false, 'rs_solution', 'Browser geprüft'], [$task['passed'], $task['field'], $task['texts']['checked']]);
            truthy(strpos(implode("\n", $r['headers']), 'Cache-Control: no-store') !== false, 'never cached');
            [$payload] = solveInNode($task['challenge']);
            // The form with the answer -- and a file: straight through
            [$type, $body] = multipart(['message' => 'Hello <there>', 'rs_solution' => $payload], 'attachment', 'note.txt', 'twelve bytes');
            $r = $get('POST', '/contact', ['Content-Type' => $type], $body);
            same(200, $r['status'], 'the form went through');
            truthy(strpos($r['body'], 'your message &quot;Hello &lt;there&gt;&quot; arrived with the file &quot;note.txt&quot; (12 bytes)') !== false, 'message and file arrived');
            $pass = $r['cookies']['rs_pass'] ?? '';
            truthy($pass !== '', 'and a pass cookie');
            // The same answer again: used up -- the check page (a file cannot come back)
            [$type, $body] = multipart(['message' => 'again', 'rs_solution' => $payload], 'attachment', 'note.txt', 'x');
            $r = $get('POST', '/contact', ['Content-Type' => $type], $body);
            same(429, $r['status'], 'an answer counts once');
            truthy(strpos($r['body'], 'Please go back and send the form again') !== false, 'files cannot come back: asked to send again');
            // A used answer in a form without files: the check page, which sends the
            // form again -- without the old answer, or it would be refused again and again.
            $r = $get('POST', '/contact', [], 'message=stale&rs_solution=' . rawurlencode($payload));
            truthy(strpos($r['body'], 'name="message" value="stale"') !== false, 'the form is carried');
            truthy(strpos($r['body'], 'name="rs_solution"') === false, 'but not the used answer');
            preg_match('/var RS=(\{.*?\});\(function/s', $r['body'], $m);
            $rs = json_decode($m[1] ?? 'null', true);
            [$fresh] = solveInNode($rs['c']);
            same(200, $get('POST', '/contact', ['Cookie' => $rs['cookie'] . '=' . $fresh], 'message=stale')['status'], 'sent again with the new answer: through');
            // With the pass: the endpoint says so, the form goes through without an answer
            $j = json_decode($get('GET', '/request-shield/challenge', ['Cookie' => "rs_pass=$pass"])['body'], true);
            same(true, $j['passed']);
            truthy(is_int($j['until']) && $j['until'] > time() + 30 && $j['until'] <= time() + 61, 'and until when the pass holds (pass-ttl 1m): the widget fetches a task before');
            same(200, $get('POST', '/contact', ['Cookie' => "rs_pass=$pass"], 'message=hi')['status']);
            // Past challenge-at without a pass (it ran out): a form is checked and
            // sent again -- not a pause that loses what was typed.
            for ($n = 0; $n < 25 && $get('GET', '/contact')['status'] === 200; $n++) {
            }
            $r = $get('POST', '/contact', [], 'message=typed+for+minutes');
            truthy(strpos($r['body'], 'then what you entered is sent') !== false && strpos($r['body'], 'typed for minutes') !== false, 'the check page carries the form: ' . substr(strip_tags($r['body']), 0, 200));
            truthy(strpos($r['body'], 'Please try again in') === false, 'no pause');
            // Solved, the page sends the form again with the answer in a cookie: through.
            preg_match('/var RS=(\{.*?\});\(function/s', $r['body'], $m);
            $rs = json_decode($m[1] ?? 'null', true);
            same(true, $rs['resend'] ?? null, 'the script sends the form again');
            [$payload] = solveInNode($rs['c']);
            $r = $get('POST', '/contact', ['Cookie' => $rs['cookie'] . '=' . $payload], 'message=typed+for+minutes');
            same(200, $r['status'], 'sent again: through');
            truthy(strpos($r['body'], 'your message &quot;typed for minutes&quot; arrived') !== false, 'the message arrived');
            truthy(($r['cookies']['rs_pass'] ?? '') !== '', 'and a new pass');
        }, $prefix);
};

$earnBack = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        withDemo(function (callable $get) use ($prefix): void {
            $task = static function (array $r): array {
                preg_match('/var RS=(\{.*?\});\(function/s', $r['body'], $m);
                return json_decode($m[1] ?? 'null', true)['c'] ?? [];
            };
            // The API: 5 calls, then the task as JSON; solved, the call goes through and the counter starts again
            for ($i = 1; $i <= 5; $i++) {
                same(200, $get('GET', '/api/status', ['Accept' => 'application/json'])['status'], "call $i");
            }
            $r = $get('GET', '/api/status', ['Accept' => 'application/json']);
            same(429, $r['status'], 'call 6: past the budget');
            $body = json_decode($r['body'], true);
            same(['rate_limited', 'Request-Shield-Solution'], [$body['error'], $body['solution']], 'JSON, and where the solution goes');
            truthy(preg_match('/Request-Shield-Challenge: \S+/', implode("\n", $r['headers'])) === 1 && preg_match('/Retry-After: \d+/', implode("\n", $r['headers'])) === 1, 'the task in a header, and how long to wait instead');
            same(50000, $body['challenge']['maxnumber'], 'the first time: difficulty-min');
            [$solution] = solveInNode($body['challenge']);
            $r = $get('GET', '/api/status', ['Accept' => 'application/json', 'Request-Shield-Solution' => $solution]);
            same(200, $r['status'], 'solved: through');
            for ($i = 1; $i <= 5; $i++) {
                same(200, $get('GET', '/api/status', ['Accept' => 'application/json'])['status'], "the counter started again: call $i");
            }
            same(429, $get('GET', '/api/status', ['Accept' => 'application/json', 'Request-Shield-Solution' => $solution])['status'], 'the same solution twice: no');
            $again = json_decode($get('GET', '/api/status', ['Accept' => 'application/json'])['body'], true);
            same(100000, $again['challenge']['maxnumber'], 'the second time within the hour: twice as hard');
            // The edit form: 3 a minute, the 4th gets the check with the form, which comes back after it
            for ($i = 1; $i <= 3; $i++) {
                same(200, $get('POST', '/edit', [], 'message=edit' . $i)['status'], "edit $i");
            }
            $r = $get('POST', '/edit', ['Accept-Language' => 'de'], 'message=' . rawurlencode('the fourth'));
            same(429, $r['status'], 'edit 4: the check');
            truthy(strpos($r['body'], '<input type="hidden" name="message" value="the fourth">') !== false, 'the form comes along');
            [$solution] = solveInNode($task($r));
            $r = $get('POST', '/edit', ['Cookie' => 'rs_solution=' . $solution], 'message=' . rawurlencode('the fourth'));
            same(200, $r['status'], 'sent again with the solution: through');
            truthy(strpos($r['body'], 'Saved: &quot;the fourth&quot;') !== false, 'the form arrived');
            $pass = $r['cookies']['rs_pass'] ?? '';
            same(200, $get('POST', '/edit', ['Cookie' => "rs_pass=$pass"], 'message=next')['status'], 'the counter started again');
        }, $prefix);
};

$pace = function (string $prefix): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        withDemo(function (callable $get) use ($prefix): void {
            $task = static function (array $r): array {
                preg_match('/var RS=(\{.*?\});\(function/s', $r['body'], $m);
                return json_decode($m[1] ?? 'null', true)['c'] ?? [];
            };
            for ($i = 1; $i <= 20; $i++) {
                $get('GET', '/page/about');
            }
            [$solution] = solveInNode($task($get('GET', '/page/about')));
            $pass = $get('GET', '/page/about', ['Cookie' => "rs_solution=$solution"])['cookies']['rs_pass'] ?? '';
            truthy($pass !== '', 'past 20: the check, and a pass');
            for ($i = 23; $i <= 60; $i++) {
                $get('GET', '/page/about', ['Cookie' => "rs_pass=$pass"]);
            }
            $r = $get('GET', '/page/about', ['Cookie' => "rs_pass=$pass", 'Accept-Language' => 'de']);
            same(429, $r['status'], 'past 60: the pass does not get past it');
            same('challenge requests; rule=DEMO-PACE', $r['shield']);
            truthy(strpos($r['body'], 'Sie haben in kurzer Zeit viele Anfragen gesendet') !== false, 'says why, in the visitor\'s language');
            [$solution] = solveInNode($task($r));
            same(200, $get('GET', '/page/about', ['Cookie' => "rs_pass=$pass; rs_solution=$solution"])['status'], 'solved: through');
            same(200, $get('GET', '/page/about', ['Cookie' => "rs_pass=$pass"])['status'], 'and the counter started again');
        }, $prefix);
};

$panel = function (string $prefix): void {
    withDemo(function (callable $get): void {
        for ($i = 0; $i < 5; $i++) {
            $get('GET', '/.env');                       // five refusals: the watched ban (monitor ban) notes it
        }
        $r = $get('GET', '/rs/live?lang=de');
        same(200, $r['status'], 'the live view');
        truthy(strpos($r['body'], 'SCAN-HIDDEN') !== false && strpos($r['body'], 'eingebaute Regel') !== false, 'the refusals, with where they came from');
        truthy(strpos($r['body'], 'class="tab on" href="') !== false && strpos($r['body'], '/rs/lists?lang=de') !== false, 'tabs to the lists');
        $first = json_decode($get('GET', '/rs/live?format=json')['body'], true);
        $rows = (array) ($first['rows'] ?? []);
        truthy(in_array('ban', array_column($rows, 'source'), true) && in_array(true, array_column($rows, 'watched'), true), 'the watched ban, in the live rows: ' . json_encode(array_column($rows, 'label')));
        same([], array_filter($rows, static fn (array $x): bool => strpos((string) $x['request'], '/rs/') !== false), 'the dashboard\'s own requests are not shown');
        $get('GET', '/.git/config');
        $next = json_decode($get('GET', '/rs/live?format=json&cursor=' . rawurlencode((string) $first['cursor']))['body'], true);
        same(['/.git/config'], array_values(array_unique(array_map(static fn (array $x): string => substr((string) $x['request'], -12), (array) $next['rows']))), 'with the cursor: only what is new');
        $what = array_column((array) $next['rows'], 'what');
        sort($what);
        same(['banned', 'refused'], $what, 'the refusal, and the watched ban it would have set (the sixth)');
        $page = $get('GET', '/rs/lists?address=203.0.113.0%2F24&note=scanner&for=7d');
        same(200, $page['status'], 'the lists');
        truthy(preg_match('/name="token" value="([0-9a-f]{32})"/', $page['body'], $m) === 1, 'the form token');
        $added = $get('POST', '/rs/lists', [], http_build_query(['token' => $m[1], 'do' => 'add', 'kind' => 'deny', 'address' => '203.0.113.0/24', 'for' => '7d', 'note' => 'scanner']));
        truthy($added['status'] === 200 && strpos($added['body'], 'LIST-D1: 203.0.113.0/24 added') !== false && strpos($added['body'], '<td class="mono">LIST-D1</td>') !== false, 'added, and listed');
        $self = $get('POST', '/rs/lists', [], http_build_query(['token' => $m[1], 'do' => 'add', 'kind' => 'deny', 'address' => '127.0.0.1', 'for' => '1d', 'note' => 'x']));
        truthy(strpos($self['body'], 'you would lock yourself out') !== false, 'never the address of the person clicking');
        truthy(strpos($get('POST', '/rs/lists', [], 'do=remove&id=LIST-D1')['body'], 'too old or not from this page') !== false, 'without the token: refused');
        $removed = $get('POST', '/rs/lists', [], http_build_query(['token' => $m[1], 'do' => 'remove', 'id' => 'LIST-D1']));
        truthy(strpos($removed['body'], 'LIST-D1 removed') !== false, 'removed');
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
    'the demo: the check inside the form -- task, answer in the form, a file straight through' => fn () => $widget(''),
    'the demo in a subdirectory: the check inside the form' => fn () => $widget($sub),
    'the demo: earn a spent budget back -- an API with a header, a form sent again, twice as hard the second time' => fn () => $earnBack(''),
    'the demo: past 60 requests a minute, a check no pass gets past -- solved, the counter starts again' => fn () => $pace(''),
    'the demo in a subdirectory: earn a spent budget back' => fn () => $earnBack($sub),
    'the demo: the live view (from the log, with where each refusal came from) and the lists (add with a comment, the guards, remove)' => fn () => $panel(''),
    'the demo in a subdirectory: live and lists' => fn () => $panel($sub),
];
