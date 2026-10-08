<?php

declare(strict_types=1);

/**
 * examples/showcase, run as its README says: the rules decide every try the
 * page shows, the page speaks German and English for each of them, and the
 * tries answer as real requests what the cards promise.
 */

/** Starts the showcase (router.php), runs $body with a request function, stops it. */
function withShowcase(callable $body): void
{
    if (rsSingle() !== null) {
        skip('the showcase shows the source tree\'s integration (bootstrap.php); the single file has its case in SingleFileTest');
    }
    if (!function_exists('proc_open')) {
        skip('no proc_open');
    }
    $var = sys_get_temp_dir() . '/rshield-showcase-' . getmypid() . '-' . mt_rand();
    mkdir($var, 0700, true);
    $port = freePort();
    $cmd = sprintf('REQUEST_SHIELD_SHOWCASE_VAR=%s exec %s -S 127.0.0.1:%d %s > /dev/null 2>&1',
        escapeshellarg($var), serverPhp(), $port, escapeshellarg(dirname(__DIR__) . '/examples/showcase/router.php'));
    $proc = proc_open($cmd, [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body(static function (string $method, string $uri, array $headers = [], string $content = '') use ($port): array {
            $h = '';
            foreach ($headers as $k => $v) {
                $h .= "$k: $v\r\n";
            }
            $opts = ['method' => $method, 'header' => $h . ($content !== '' ? "Content-Type: application/x-www-form-urlencoded\r\n" : ''), 'content' => $content,
                'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0];
            $body = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => $opts]));
            $status = 0;
            $xrs = '';
            $type = '';
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m) === 1) {
                    $status = (int) $m[1];
                } elseif (stripos($line, 'X-RS:') === 0) {
                    $xrs = trim(substr($line, 5));
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    $type = trim(substr($line, 13));
                }
            }
            return [$status, $xrs, $body, $type, implode("\n", $http_response_header ?? [])];
        }, $port);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($var));
    }
}

/** The showcase's tries, as its page reads them. */
function showcaseTriesForTest(): array
{
    if (!function_exists('showcaseTries')) {
        // site.php routes a request when included; only its function is wanted here.
        $src = (string) file_get_contents(dirname(__DIR__) . '/examples/showcase/site.php');
        $start = strpos($src, 'function showcaseTries');
        $end = strpos($src, '/** @param array<string, mixed> $data */');
        eval(substr($src, (int) $start, (int) $end - (int) $start));    // showcaseTries(), showcaseExpect(), exponentialGroups()
    }
    return showcaseTries(dirname(__DIR__) . '/examples/showcase/showcase.rules');
}

return [
    'RSF05-04 the showcase: request-shield test decides every try its page shows, and they all hold' => function (): void {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' test ' . escapeshellarg(dirname(__DIR__) . '/examples/showcase/showcase.rules') . ' 2>&1', $out, $code);
        same(0, $code, implode("\n", array_slice($out, -5)));
        // The budgets the pages count themselves (on-demand) cannot be decided by test: their own test below sends real requests.
        $last = (string) end($out);
        truthy(strpos($last, 'without an example') === false || preg_match('/without an example: (SHOW-SEARCH, SHOW-LOGINS|SHOW-LOGINS, SHOW-SEARCH)$/', $last) === 1,
            'every rule has its example, but the page-counted budgets: ' . $last);
        truthy(count(showcaseTriesForTest()) >= 20, 'the page reads the tries from the rules');
    },
    'RSF05-04 the showcase speaks German too: every try and every explained rule has its German sentence' => function (): void {
        $texts = require dirname(__DIR__) . '/examples/showcase/texts.php';
        foreach (showcaseTriesForTest() as $t) {
            if ($t['text'][0] !== '(') {           // "(…)": an example of the page's own, not a card
                truthy(isset($texts['de']['tries'][$t['text']]), 'German for the try: ' . $t['text']);
            }
        }
        $on = false;
        foreach (file(dirname(__DIR__) . '/examples/showcase/showcase.rules', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $on = $on || strncmp($line, 'ids ', 4) === 0;
            if (strncmp($line, "# The showcase's own settings", 29) === 0) {
                break;
            }
            if (!$on || strncmp(ltrim($line), 'expect ', 7) === 0) {
                continue;
            }
            $note = strncmp(ltrim($line), '#', 1) === 0 ? trim(ltrim(trim($line), '#')) : (strpos($line, ' # ') !== false ? trim(substr($line, (int) strpos($line, ' # ') + 3)) : '');
            if ($note !== '') {
                truthy(isset($texts['de']['notes'][$note]), 'German for the rule\'s explanation: ' . $note);
            }
        }
        same(array_keys($texts['de']), array_keys($texts['en']), 'the same words in both languages');
    },
    'RSF03-01 the showcase\'s pages that count for themselves: the search, 5 a minute, then a pause that doubles; the sign-in, 3 wrong passwords, then the same -- a right one counts nothing' => function (): void {
        withShowcase(static function (callable $get, int $port): void {
            $ip = '198.18.200.' . mt_rand(1, 250);
            for ($i = 1; $i <= 5; $i++) {
                [$st, , $body] = $get('GET', '/search?q=php', ['X-Forwarded-For' => $ip]);
                truthy($st === 200 && strpos($body, '"results"') !== false, "search $i: answered by the page");
            }
            [$st, $xrs] = $get('GET', '/search?q=php', ['X-Forwarded-For' => $ip]);
            truthy($st === 429 && strpos($xrs, 'rule=SHOW-SEARCH') !== false, "the sixth: the shield answers for the page ($xrs)");
            [$st, $xrs, , , $raw] = $get('GET', '/search?q=php', ['X-Forwarded-For' => $ip]);
            truthy($st === 429 && strpos($xrs, 'throttle banned') === 0 && preg_match('/^Retry-After: [1-5]\r?$/mi', $raw) === 1, "then a pause of 5 seconds, before the page runs ($xrs)");
            $form = static fn (string $pw, string $from): array => $get('POST', '/account/login', ['X-Forwarded-For' => $from, 'Origin' => "http://127.0.0.1:$port"], 'user=demo&password=' . $pw);
            $ip = '198.18.201.' . mt_rand(1, 250);
            for ($i = 1; $i <= 3; $i++) {
                [$st, , $body] = $form('falsch', $ip);
                same([200, '{"ok":false}'], [$st, $body], "wrong password $i: the page says so");
            }
            [$st, $xrs] = $form('falsch', $ip);
            truthy($st === 429 && strpos($xrs, 'rule=SHOW-LOGINS') !== false, "the fourth: the shield answers ($xrs)");
            $other = '198.18.202.' . mt_rand(1, 250);
            for ($i = 1; $i <= 5; $i++) {
                same([200, '{"ok":true}'], [$form('sesam', $other)[0], $form('sesam', $other)[2]], "the right password, again and again: nothing counted ($i)");
            }
        });
    },
    'RSF02-04 the showcase\'s API: products as JSON or XML; a JSON message needs a pass -- 429 with the task, solved and sent again 201, then straight through; a bot gets only the task' => function (): void {
        withShowcase(static function (callable $get, int $port): void {
            $ip = '198.18.210.' . mt_rand(1, 250);
            [$st, , $body, $type] = $get('GET', '/api/v1/products', ['X-Forwarded-For' => $ip]);
            truthy($st === 200 && strpos($type, 'application/json') === 0 && isset(json_decode($body, true)['products']), "products as JSON ($type)");
            [$st, , $body, $type] = $get('GET', '/api/v1/products?format=xml', ['X-Forwarded-For' => $ip]);
            truthy($st === 200 && strpos($type, 'application/xml') === 0 && @simplexml_load_string($body) !== false, "... as XML ($type)");
            [$st, , $body, $type] = $get('GET', '/api/v1/products?page=2%27', ['X-Forwarded-For' => $ip]);
            truthy($st === 404 && strpos($type, 'application/json') === 0, 'a refusal on the API is JSON too (api-path): ' . $body);
            $msg = static fn (array $h): array => $get('POST', '/api/v1/messages', $h + ['X-Forwarded-For' => $ip, 'Origin' => "http://127.0.0.1:$port", 'Content-Type' => 'application/json'],
                '{"name":"Ada","message":"Hallo"}');
            [$st, $xrs, $body, , $raw] = $msg([]);
            truthy($st === 429 && preg_match('/^Request-Shield-Challenge: (\S+)/mi', $raw, $m) === 1, "a message without a pass: the check, the task in a header ($xrs)");
            $task = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);
            $answer = solveInPhp($task);
            [$st, , $body, , $raw] = $msg(['Request-Shield-Solution' => $answer]);
            truthy($st === 201 && strpos($body, '"ok":true') !== false && preg_match('/^Set-Cookie: (rsp=[^;]+)/mi', $raw, $c) === 1, "solved and sent again: it arrives, with a pass ($body)");
            same(201, $msg(['Cookie' => $c[1]])[0], 'the next one with the pass: straight through');
            [$st, , $body] = $get('POST', '/api/v1/messages', ['X-Forwarded-For' => '198.18.211.' . mt_rand(1, 250), 'Content-Type' => 'application/json'], '{"name":"Bot","message":"spam"}');
            truthy($st === 429 && strpos($body, '"challenge"') !== false, 'a bot without a browser: 429 and the task, nothing more');
            same(415, $msg(['Cookie' => $c[1], 'Content-Type' => 'text/plain'])[0], 'the API checks its own content: JSON only');
        });
    },
    'RSF05-04 the showcase\'s Exponential example: every example of examples/exponential, decided on the server with its rules, as request-shield test decides it' => function (): void {
        showcaseTriesForTest();                     // loads exponentialGroups() too
        $groups = exponentialGroups(dirname(__DIR__) . '/examples/exponential');
        truthy(count($groups) >= 4 && array_sum(array_map(static fn (array $g): int => count($g['tries']), $groups)) >= 50, 'the sections and their examples');
        withShowcase(static function (callable $get) use ($groups): void {
            // "Check all": every example in one request -- one by one they would run into SHOW-PACE.
            $all = json_decode($get('GET', '/__exp?n=0')[2], true);
            foreach ($groups as $g) {
                foreach ($g['tries'] as $x) {
                    $j = $all['results'][(string) $x['n']] ?? null;
                    truthy(is_array($j) && ($j['ok'] ?? false) === true, $g['title'] . ': ' . $x['method'] . ' ' . $x['url'] . ' -- ' . json_encode($j));
                }
            }
            $last = end($groups)['tries'];
            $x = end($last);
            same($all['results'][(string) $x['n']], json_decode($get('GET', '/__exp?n=' . $x['n'])[2], true), 'one example alone, as in all of them');
            $first = $groups[0]['tries'][0];
            same($all['results'][(string) $first['n']], json_decode($get('GET', '/__exp?n=' . $first['n'])[2], true), 'the first one alone too (n=0 is all of them)');
            same(404, $get('GET', '/__exp?n=9999')[0], 'no such example');
        });
    },
    'RSF05-04 the showcase as its README runs it: the page, its own files, and every try a real request answered as its card says' => function (): void {
        withShowcase(static function (callable $get): void {
            [$st, , $page] = $get('GET', '/?lang=de');
            same(200, $st, 'the front page');
            truthy(strpos($page, 'Bots raus.') !== false && strpos($page, 'showcase.rules') !== false, 'in German, with the rules');
            truthy(strpos($get('GET', '/?lang=en')[2], 'Bots out.') !== false, 'in English');
            truthy(strpos($page, 'mit Hilfe von KI generiert') !== false && strpos($get('GET', '/?lang=en')[2], 'generated with the help of AI') !== false, 'says it was made with the help of AI, in both languages');
            $hero = (string) strstr((string) strstr($page, '<header id="top"'), '</header>', true);
            truthy(strpos($hero, 'Exponential') !== false && strpos($hero, 'href="#exponential"') !== false, 'the hero names Exponential and leads to its example');
            $texts = require dirname(__DIR__) . '/examples/showcase/texts.php';
            $shape = static function (array $a) use (&$shape): array {
                return array_map(static fn ($v) => is_array($v) ? $shape($v) : (is_string($v) && $v !== '' ? 's' : 'EMPTY'), $a);
            };
            same($shape($texts['de']['comp']), $shape($texts['en']['comp']), 'the section in German and English: the same keys, as many points, none empty');
            foreach (['de' => 'nicht verbunden', 'en' => 'not affiliated'] as $lang => $note) {
                $p = $lang === 'de' ? $page : $get('GET', '/?lang=en')[2];
                truthy(strpos($p, 'id="compliance"') !== false && strpos($p, 'href="#compliance"') !== false, "$lang: the WCAG and GDPR section, in the menu");
                truthy(strpos($p, 'href="https://altcha.org/legal/compliance/wcag/"') !== false && strpos($p, 'href="https://altcha.org/legal/compliance/gdpr/"') !== false, "$lang: ALTCHA's two pages linked");
                $sec = (string) strstr((string) strstr($p, 'id="compliance"'), '<section id="exponential"', true);
                truthy(strpos($sec, $note) !== false && strpos($sec, '§ 25') !== false, "$lang: in the section: not ALTCHA, and that there is a cookie (§ 25 TDDDG)");
            }
            [$st, , , $type] = $get('GET', '/assets/showcase.css');
            truthy($st === 200 && strpos($type, 'text/css') === 0, 'its own styles, as CSS');
            same(200, $get('GET', '/assets/vendor/bootstrap/bootstrap.min.css')[0], 'Bootstrap from the page\'s own folder, not from elsewhere');
            truthy(preg_match('#\bsrc="(https?:)?//#', $page) !== 1 && preg_match('#<link[^>]+href="(https?:)?//#', $page) !== 1,
                'nothing loaded from another host (a link to the docs is a link, not a load)');
            foreach (showcaseTriesForTest() as $t) {
                if ($t['times'] > 1 || $t['pass'] || $t['text'][0] === '(') {
                    continue;           // the burst, the pass, the page's own: decided by request-shield test above
                }
                $uri = (string) preg_replace('#^[a-z]+://[^/]+#', '', $t['url']);
                if ($t['headers'] !== []) {
                    // A form from another website: the page asks the server to decide it.
                    $j = json_decode($get('GET', '/__try?n=' . $t['n'])[2], true);
                    $got = ($j['action'] ?? '') === 'challenge' ? 'check' : (in_array($j['action'] ?? '', ['allow', 'allow-uncached'], true) ? 'answered' : (string) ($j['status'] ?? 0));
                    same($t['outcome'], $got, $t['text']);
                    continue;
                }
                [$st, $xrs] = $get($t['method'], $uri, ['X-Forwarded-For' => $t['from']], $t['method'] === 'POST' ? 'message=Hello' : '');
                $action = explode(' ', $xrs)[0];
                $got = $action === 'challenge' ? 'check' : (in_array($action, ['allow', 'allow-uncached'], true) ? 'answered' : (string) $st);
                same($t['outcome'], $got, $t['text'] . " ($xrs)");
                if ($t['by'] !== null) {
                    truthy(strpos($xrs, 'rule=' . $t['by']) !== false, $t['text'] . ': by ' . $t['by'] . " ($xrs)");
                }
            }
            [$st, $xrs] = $get('GET', '/', ['X-Forwarded-For' => '203.0.113.66']);
            same(403, $st, 'the deny list, for real');
            $get('GET', '/.env', ['X-Forwarded-For' => '198.51.100.11']);     // the last two lines of the log: sent right before
            $get('GET', '/', ['X-Forwarded-For' => '203.0.113.66']);
            $log = json_decode($get('GET', '/__log')[2], true);
            $lines = implode("\n", is_array($log) ? $log['lines'] : []);
            truthy(strpos($lines, 'rule=SCAN-HIDDEN') !== false && strpos($lines, 'rule=SHOW-DENY') !== false && strpos($lines, '198.51.100.11') === false,
                'the page shows the end of its log: the tries above in it, the addresses masked -- ' . substr($lines, -300));
            [$st, $xrs] = $get('GET', '/login');
            truthy($st === 429 && strpos($xrs, 'challenge') === 0, 'you, on this machine: the login checks you too (exempt none) -- ' . $xrs);
            [$st, , , , $raw] = $get('GET', '/__login');
            truthy($st === 303 && preg_match('#^Set-Cookie: rsp=(deleted)?;.*(expires=Thu, 01[- ]Jan[- ]1970|Max-Age=0)#mi', $raw) === 1 && preg_match('#^Location: /login#mi', $raw) === 1,
                '"see the check": the pass forgotten, on to the login -- ' . $raw);
        });
    },
    'RSF05-04 the showcase\'s page on building rules: a learning run started for this browser, its clicks recorded by shape and shown, the rules checked against them (replay), stopped' => function (): void {
        withShowcase(static function (callable $get): void {
            foreach (['de' => 'Regeln bauen', 'en' => 'Build rules'] as $lang => $title) {
                [$st, , $page] = $get('GET', "/learn?lang=$lang");
                truthy($st === 200 && strpos($page, $title) !== false && strpos($page, 'learn-start') !== false && strpos($page, 'learned.jsonl') !== false, "$lang: the page, with the start button (this machine)");
            }
            truthy(strpos($get('GET', '/?lang=de')[2], 'href="/learn?lang=de"') !== false, 'the front page\'s menu leads to it');
            // Behind a proxy (the showcase trusts 127.0.0.1): the visitor is the forwarded address, not this machine.
            $remote = ['X-Forwarded-For' => '203.0.113.9', 'Origin' => 'http://127.0.0.1'];
            same([403, 403, 403], [$get('POST', '/__learn/start', $remote)[0], $get('GET', '/__learned', $remote)[0], $get('GET', '/__replay', $remote)[0]],
                'from elsewhere: no run started, nothing of a run shown');
            truthy(strpos($get('GET', '/learn?lang=en', $remote)[2], 'learn-start') === false, 'and no start button');
            [$st, , $body, , $headers] = $get('POST', '/__learn/start', ['Origin' => 'http://127.0.0.1']);
            truthy($st === 200 && preg_match('/Set-Cookie: rs-learn=([0-9a-f]{32})/i', $headers, $m) === 1, 'started, the cookie set: ' . $headers);
            $cookie = ['Cookie' => 'rs-learn=' . ($m[1] ?? '')];
            usleep(1100000);                // the settings see the run (the rule file touched, recheck 0)
            same(200, $get('GET', '/search?q=red+shoes', $cookie)[0]);
            same(200, $get('GET', '/try?lang=en', $cookie)[0]);
            same(200, $get('GET', '/?page=3')[0], 'without the cookie: not recorded');
            $j = [];
            for ($i = 0; $i < 30 && count($j['rows'] ?? []) < 2; $i++) {
                usleep(100000);
                $j = (array) json_decode($get('GET', '/__learned')[2], true);
            }
            same([['/try', ['lang' => 'id']], ['/search', ['q' => 'text']]], array_map(static fn (array $r): array => [$r['path'], $r['query']], $j['rows'] ?? []), 'the two clicks, newest first, by shape');
            truthy(is_int($j['until'] ?? null) && count($j['rows'][0]['found']['forms'] ?? []) > 0, 'running; what /try offers (its forms) was found');
            $replay = (array) json_decode($get('GET', '/__replay')[2], true);
            $kinds = array_count_values(array_column($replay['results'] ?? [], 'kind'));
            truthy(($kinds['pass'] ?? 0) >= 2 && !isset($kinds['refused']), 'the rules against the run: the clicks pass -- ' . json_encode($replay));
            same(200, $get('POST', '/__learn/stop', ['Origin' => 'http://127.0.0.1'] + $cookie)[0]);
            $after = (array) json_decode($get('GET', '/__learned')[2], true);
            truthy(array_key_exists('until', $after) && $after['until'] === null, 'stopped: ' . json_encode($after['until'] ?? 'missing'));
        });
    },
    'RSF05-04 the showcase in pages: the front page a taste with a way to everything, /try every card, /exponential every example, the menu marking where you are' => function (): void {
        $cards = static fn (string $html): int => preg_match_all('/class="card-try h-100" data-n="\d+"/', $html);
        $groups = exponentialGroups(dirname(__DIR__) . '/examples/exponential');
        $examples = array_sum(array_map(static fn (array $g): int => count($g['tries']), $groups));
        withShowcase(static function (callable $get) use ($cards, $examples): void {
            foreach (['de', 'en'] as $lang) {
                $main = $get('GET', "/?lang=$lang")[2];
                truthy(preg_match('#(Warning|Notice|Deprecated)(</b>)?:  ?.{0,300} on line#', $main) !== 1, "$lang: no PHP warning on the front page");
                truthy(strpos($main, 'href="#try"') === false && strpos($main, 'href="#taste"') !== false, "$lang: the hero's button leads to the taste on this page");
                same(3, $cards($main), "$lang: three cards to taste on the front page");
                $guard = (string) strstr((string) strstr($main, '<section id="guard"'), '</section>', true);
                truthy(strpos($guard, 'class="check-demo"') !== false && strpos($guard, 'href="/__login"') !== false, "$lang: the browser check, shown and to try, high on the front page");
                truthy(strpos($guard, 'class="stairs"') !== false && strpos($guard, 'data-kind="login"') !== false && strpos($guard, 'counter-form') !== false, "$lang: the growing pause, drawn and the sign-in to try");
                truthy(strpos($main, '<section id="guard"') < strpos($main, '<section id="what"'), "$lang: right after the promises");
                // The staircase as the rules have it: SHOW-LOGINS lets 3 through, SHOW-LOGIN-BAN pauses 5 s, ban-growth 2.
                $texts = (require dirname(__DIR__) . '/examples/showcase/texts.php')[$lang]['guard']['stairs'];
                $rules = (string) file_get_contents(dirname(__DIR__) . '/examples/showcase/showcase.rules');
                preg_match('/limit logins (\d+)\/15m/', $rules, $free);
                preg_match('/\[SHOW-LOGIN-BAN\]\s+ban after 1 logins in 15m for (\d+)s/', $rules, $first);
                preg_match('/set\s+ban-growth (\d+)/', $rules, $growth);
                $want = [];
                for ($i = 0; $i < count($texts); $i++) {
                    $want[] = ($i < (int) ($free[1] ?? 0) ? 0 : (int) ($first[1] ?? 0) * ((int) ($growth[1] ?? 2)) ** ($i - (int) ($free[1] ?? 0))) . ' s';
                }
                same($want, array_column($texts, 1), "$lang: the staircase is what the rules do");
                truthy(strpos($guard, 'role="img" aria-label="') !== false, "$lang: the staircase read out as one sentence");
                truthy(strpos($main, 'href="/try?lang=' . $lang . '"') !== false && strpos($main, 'href="/exponential?lang=' . $lang . '"') !== false, "$lang: the way to /try and /exponential");
                truthy(strpos($main, 'json-form') === false && strpos($main, 'burst-go') === false && strpos($main, 'exp-row') === false, "$lang: the rest is on its own pages");
                [$st, , $try] = $get('GET', "/try?lang=$lang");
                truthy($st === 200 && $cards($try) >= 20 && strpos($try, 'burst-go') !== false && strpos($try, 'json-form') !== false && strpos($try, 'counter-form') !== false, "$lang: /try, every card, the burst, the API, search and sign-in");
                truthy(strpos($try, 'class="nav-link active" href="/try?lang=' . $lang . '" aria-current="page"') !== false && strpos($try, 'id="taste"') === false, "$lang: the menu marks it");
                [$st, , $exp] = $get('GET', "/exponential?lang=$lang");
                truthy($st === 200 && preg_match_all('/class="exp-row"/', $exp) === $examples && strpos($exp, 'exp-all') !== false, "$lang: /exponential, all $examples examples");
            }
        });
    },
];
