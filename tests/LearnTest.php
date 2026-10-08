<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Learn;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;

return [
    'RSF05-04 learn: the type of a value, and of two values for one name -- the narrowest both fit' => function (): void {
        same(['int', 'number', 'id', 'word', 'list', 'text', ''], array_map([Learn::class, 'typeOf'], ['42', '4.2', 'a_B-9', 'grün', 'a,b', 'two words', '']));
        same('number', Learn::wider('int', 'number'));
        same('word', Learn::wider('number', 'id'), '1.5 and a_b: both words');
        same('text', Learn::wider('word', 'text'));
        same('int', Learn::wider(null, 'int'));
        same('int', Learn::wider('', 'int'), 'an empty value says nothing');
        same('int', Learn::wider('int', ''));
    },
    'RSF05-04 learn: a request\'s shape holds no value -- names, types, the method, the path, the decision' => function (): void {
        $r = Request::fromServer(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact?page=2&q=secret+words&page=3', 'HTTP_HOST' => 'www.example.org',
            'HTTP_CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=utf-8', 'REMOTE_ADDR' => '203.0.113.7']);
        $shape = Learn::shape($r, ['email' => 'someone@example.org', 'tags' => ['a', 'b'], 'age' => '41'], Decision::allow(), 1000.0);
        same(['t' => 1000, 'method' => 'POST', 'host' => 'www.example.org', 'path' => '/contact', 'query' => ['page' => 'int', 'q' => 'text'],
            'form' => ['email' => 'text', 'tags' => 'list', 'age' => 'int'], 'type' => 'application/x-www-form-urlencoded', 'decided' => 'allow', 'status' => null], $shape);
        $odd = Learn::line(Learn::shape(Request::fromServer(['REQUEST_URI' => '/a?x%ff=1']), [], Decision::allow(), 1.0) + ['status' => 200]);
        truthy($odd !== null && strpos($odd, '"path":"/a"') !== false, 'a name that is not UTF-8 still makes a line: ' . var_export($odd, true));
        $many = [];
        for ($i = 0; $i < 500; $i++) {
            $many["f$i"] = 'x';
        }
        same(Learn::MAX_FIELDS, count(Learn::shape(Request::fromServer(['REQUEST_URI' => '/']), $many, Decision::allow(), 1.0)['form']), 'at most so many fields: a line stays small');
        $json = (string) json_encode($shape);
        truthy(strpos($json, 'secret') === false && strpos($json, 'someone') === false && strpos($json, '41') === false && strpos($json, '203.0.113') === false, 'no value, no address: ' . $json);
    },
    'RSF05-04 learn: what a page of the site offers -- forms with their fields by type, links and the addresses in its scripts by path and parameter names, the site\'s other hosts; never a value' => function (): void {
        $r = Request::fromServer(['REQUEST_URI' => '/shop/list?page=2', 'HTTP_HOST' => 'www.example.org']);
        $html = '<html><body>
            <form action="/contact/send?ref=nav" method="post"><input name="email" type="email" value="someone@example.org"><textarea name="message">Hello</textarea>
              <select name="topic"><option>a</option></select><input type="hidden" name="csrf" value="s3cret"><button>Send</button></form>
            <form><input name="q"></form>
            <form action="https://api.example.org/v1/subscribe" method="POST"><input name="email"></form>
            <form action="https://elsewhere.example.net/x"><input name="y"></form>
            <a href="/news/?page=3&amp;sort=new">News</a> <a href="detail?id=7">Detail</a> <a href="#top">Top</a> <a href="mailto:a@b">Mail</a>
            <a href="https://www.example.org/about">About</a> <a href="https://shop.example.org/cart">Cart</a> <a href="https://other.example.net/">Other</a>
            <script>fetch("/api/v1/messages", {method: "POST"}); const u = \'/api/v1/products?format=xml\'; var t = "two words /not a path";</script>
            </body></html>';
        $f = Learn::found($html, $r);
        same([['action' => '/contact/send?ref', 'method' => 'POST', 'fields' => ['email' => 'email', 'message' => 'textarea', 'topic' => 'select', 'csrf' => 'hidden']],
            ['action' => '/shop/list', 'method' => 'GET', 'fields' => ['q' => 'text']]], $f['forms'], 'the forms: where to, how, which fields');
        same(['/news/?page&sort', '/shop/detail?id', '/about'], $f['links'], 'links on this site: path and parameter names');
        same(['/api/v1/messages', '/api/v1/products?format'], $f['scripts'], 'the addresses the scripts name');
        same(['api.example.org', 'shop.example.org'], $f['hosts'], 'the site\'s other hosts; another website\'s not');
        $json = (string) json_encode($f);
        truthy(strpos($json, 'someone') === false && strpos($json, 's3cret') === false && strpos($json, 'Hello') === false && strpos($json, 'xml') === false, 'no value: ' . $json);
    },
    'RSF05-04 learn: a request belongs to the run by its token (cookie or header), within its time, from its addresses -- else not' => function (): void {
        $token = 'f00d';
        $run = ['until' => 2000, 'token' => hash('sha256', $token), 'from' => []];
        $req = static fn (array $server): Request => Request::fromServer($server + ['REQUEST_URI' => '/', 'REMOTE_ADDR' => '203.0.113.7']);
        truthy(Learn::marks($run, $req(['HTTP_COOKIE' => 'a=1; rs-learn=f00d']), 1000.0), 'the cookie');
        truthy(Learn::marks($run, $req(['HTTP_REQUEST_SHIELD_LEARN' => 'f00d']), 1000.0), 'the header');
        truthy(!Learn::marks($run, $req([]), 1000.0), 'no token: not the run');
        truthy(!Learn::marks($run, $req(['HTTP_COOKIE' => 'rs-learn=beef']), 1000.0), 'another token');
        truthy(!Learn::marks($run, $req(['HTTP_COOKIE' => 'rs-learn=f00d']), 2000.0), 'the run has ended');
        $from = ['from' => ['198.51.100.0/24']] + $run;
        truthy(!Learn::marks($from, $req(['HTTP_COOKIE' => 'rs-learn=f00d']), 1000.0), '--from: another address');
        truthy(Learn::marks($from, $req(['HTTP_COOKIE' => 'rs-learn=f00d', 'REMOTE_ADDR' => '198.51.100.9']), 1000.0), '--from: its address');
    },
    'RSF05-04 learn: start writes the state (a token\'s hash), the settings read it when compiled, stop removes it' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-learn-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/site.rules", "set store-dir $dir/store\n");
            same(null, Settings::from(RuleFile::read(["$dir/site.rules"])['config'])->learn, 'no run: nothing');
            $token = Learn::start("$dir/store", 3600, ['192.0.2.0/24'], false, 1000);
            truthy(preg_match('/^[0-9a-f]{32}$/', $token) === 1, 'a token');
            $raw = (string) file_get_contents(Learn::stateFile("$dir/store"));
            truthy(strpos($raw, $token) === false && strpos($raw, hash('sha256', $token)) !== false, 'only its hash is kept');
            same(['until' => 4600, 'token' => hash('sha256', $token), 'from' => ['192.0.2.0/24']], Settings::from(RuleFile::read(["$dir/site.rules"])['config'])->learn);
            file_put_contents(Learn::recordFile("$dir/store"), "{}\n");
            Learn::start("$dir/store", 3600, [], true, 1000);
            truthy(is_file(Learn::recordFile("$dir/store")), '--keep: the earlier recording stays');
            Learn::start("$dir/store", 3600, [], false, 1000);
            truthy(is_file(Learn::recordFile("$dir/store")) && filesize(Learn::recordFile("$dir/store")) === 0, 'a new run starts a new recording');
            same('0600', substr(sprintf('%o', fileperms(Learn::recordFile("$dir/store"))), -4), 'the recording only for its owner');
            truthy(Learn::stop("$dir/store"), 'stopped');
            same(null, Settings::from(RuleFile::read(["$dir/site.rules"])['config'])->learn, 'stopped: nothing');
            truthy(!Learn::stop("$dir/store"), 'nothing to stop');
            foreach ([[30, []], [8 * 86400, []], [3600, ['not an address']]] as [$for, $from]) {
                $refused = false;
                try {
                    Learn::start("$dir/store", $for, $from, false, 1000);
                } catch (\InvalidArgumentException $e) {
                    $refused = true;
                }
                truthy($refused, 'refused: ' . json_encode([$for, $from]));
            }
            $wrong = false;
            try {
                Settings::from(['learn' => ['until' => 1, 'token' => 'nope', 'from' => []]]);
            } catch (\InvalidArgumentException $e) {
                $wrong = true;
            }
            truthy($wrong, 'a state that is not one: refused by the settings');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-04 learn, end to end: learn start, a developer\'s requests with the cookie recorded by shape and with the site\'s status, others not; the token lets nothing past a check; learn stop' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = sys_get_temp_dir() . '/rs-learn-e2e-' . getmypid() . '-' . mt_rand();
        mkdir("$dir/docroot", 0700, true);
        file_put_contents("$dir/docroot/index.php", '<?php if (($_GET["page"] ?? "") === "9") { http_response_code(404); } echo "the site";');
        file_put_contents("$dir/docroot/page.php", '<?php ob_start(); echo "<h1>Contact</h1>"; ?><form action="/send.php" method="post"><input name="email" type="email" value="x@example.org"></form><a href="/index.php?page=1">On</a><script>fetch("/api/v1/messages")</script><?php ob_end_flush();');
        file_put_contents("$dir/docroot/data.php", '<?php header("Content-Type: application/json"); echo "{\\"a\\":\\"<form action=/x>\\"}";');
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset recheck 0\n");
        $cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli());
        exec("$cli learn " . escapeshellarg("$dir/site.rules") . ' start --for=1h 2>&1', $out, $code);
        $said = implode("\n", $out);
        same(0, $code, $said);
        truthy(preg_match('/Request-Shield-Learn: ([0-9a-f]{32})/', $said, $m) === 1 && strpos($said, "javascript:document.cookie='rs-learn=") !== false, 'a token and the bookmarks: ' . $said);
        $token = $m[1] ?? '';
        $port = freePort();
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
            escapeshellarg("$dir/site.rules"), serverPhp(), escapeshellarg(rsEntry()), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $send = static function (string $method, string $uri, array $headers = [], string $body = '') use ($port): int {
                $h = '';
                foreach ($headers as $k => $v) {
                    $h .= "$k: $v\r\n";
                }
                @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['method' => $method, 'header' => $h, 'content' => $body, 'ignore_errors' => true, 'timeout' => 10]]));
                return (int) substr((string) ($http_response_header[0] ?? ''), 9, 3);
            };
            $cookie = ['Cookie' => "rs-learn=$token"];
            same(200, $send('GET', '/index.php?page=2&q=red+shoes', $cookie));
            same(404, $send('GET', '/index.php?page=9', $cookie), 'the site answers 404');
            same(200, $send('POST', '/index.php', $cookie + ['Content-Type' => 'application/x-www-form-urlencoded'], 'email=someone%40example.org&age=41'));
            same(200, $send('GET', '/index.php?from=tests', ['Request-Shield-Learn' => $token]), 'the header');
            same(200, $send('GET', '/index.php?unmarked=1'), 'a visitor without the token');
            same(404, $send('GET', '/index.php/.env', $cookie), 'the token lets nothing past a check');
            same(200, $send('GET', '/page.php', $cookie), 'a page with a form, a link, a script');
            same(200, $send('GET', '/data.php', $cookie), 'JSON: not read for forms');
            $lines = [];
            for ($i = 0; $i < 40 && count(file(Learn::recordFile("$dir/store")) ?: []) < 7; $i++) {
                usleep(50000);          // the line is written when the request has ended
            }
            $lines = array_values(array_filter(array_map(static fn (string $l) => json_decode($l, true), file(Learn::recordFile("$dir/store")) ?: [])));
            same(7, count($lines), 'seven requests recorded, the unmarked one not: ' . json_encode($lines));
            same(['forms' => [['action' => '/send.php', 'method' => 'POST', 'fields' => ['email' => 'email']]], 'links' => ['/index.php?page'], 'scripts' => ['/api/v1/messages'], 'hosts' => []],
                $lines[5]['found'] ?? null, 'what the page offered, read from its answer -- through its own buffer');
            truthy(!isset($lines[6]['found']) && !isset($lines[4]['found']), 'not for JSON, not for a refusal');
            $lines = array_slice($lines, 0, 5);
            same([['GET', '/index.php', ['page' => 'int', 'q' => 'text'], 200], ['GET', '/index.php', ['page' => 'int'], 404], ['POST', '/index.php', [], 200],
                ['GET', '/index.php', ['from' => 'id'], 200], ['GET', '/index.php/.env', [], 404]],
                array_map(static fn (array $l): array => [$l['method'], $l['path'], $l['query'], $l['status']], $lines), 'method, path, parameter types, the status');
            same(['email' => 'text', 'age' => 'int'], $lines[2]['form'], 'the form fields by type');
            same('reject', $lines[4]['decided'], 'what the shield decided');
            $raw = (string) file_get_contents(Learn::recordFile("$dir/store"));
            truthy(strpos($raw, 'someone') === false && strpos($raw, 'red') === false, 'no value in the recording');
            same(['t', 'method', 'host', 'path', 'query', 'form', 'type', 'decided', 'status', 'found'], array_keys($lines[0]), 'these fields, no address among them');
            $out = [];
            exec("$cli learn " . escapeshellarg("$dir/site.rules") . ' stop 2>&1', $out, $code);
            truthy($code === 0 && strpos(implode("\n", $out), 'recorded: 7 requests (6 GET, 1 POST), 4 paths, 3 parameters, 1 form') !== false && strpos(implode("\n", $out), 'found on its pages: 1 form, 1 links, 1 addresses in scripts') !== false, implode("\n", $out));
            $out = [];
            exec("$cli learn " . escapeshellarg("$dir/site.rules") . ' start --from=2026-10-01 2>&1', $out, $code);
            truthy($code !== 0 && strpos(implode("\n", $out), 'address') !== false, 'a date as --from: refused, not taken as "from anywhere": ' . implode("\n", $out));
            truthy(!is_file(Learn::stateFile("$dir/store")), 'and no run started');
            same(200, $send('GET', '/index.php?after=1', $cookie));
            usleep(300000);
            same(7, count(file(Learn::recordFile("$dir/store")) ?: []), 'stopped: the cookie records nothing more');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
