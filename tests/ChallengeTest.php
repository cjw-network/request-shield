<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\ChallengeSettings;
use CjwNetwork\RequestShield\Challenge\ChallengePage;
use CjwNetwork\RequestShield\Challenge\Gate;
use CjwNetwork\RequestShield\Challenge\PassCookie;
use CjwNetwork\RequestShield\Challenge\ProofOfWork;
use CjwNetwork\RequestShield\Challenge\SearchEngines;
use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

const SECRET = 'test-secret-0123456789abcdef0123456789abcdef';

/** What the page's script does: find the number, build the payload. */
function solveInPhp(array $c): string
{
    for ($n = 0; $n <= $c['maxnumber']; $n++) {
        if (hash('sha256', $c['salt'] . $n) === $c['challenge']) {
            $json = json_encode(['algorithm' => $c['algorithm'], 'challenge' => $c['challenge'], 'number' => $n, 'salt' => $c['salt'], 'signature' => $c['signature'], 'took' => 1]);
            return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        }
    }
    throw new TestFailure('no solution');
}

function creq(string $uri = '/', array $cookies = [], string $method = 'GET', string $ip = '203.0.113.7', string $ua = 'Mozilla/5.0 Test'): Request
{
    $c = [];
    foreach ($cookies as $k => $v) {
        $c[] = "$k=$v";
    }
    return Request::fromServer(['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method, 'HTTP_HOST' => 'example.org',
        'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua] + ($c ? ['HTTP_COOKIE' => implode('; ', $c)] : []));
}

/** The cookie's value from a Set-Cookie line. */
function cookieValue(array $cookies, string $name): ?string
{
    foreach ($cookies as $line) {
        if (strncmp($line, $name . '=', strlen($name) + 1) === 0) {
            return substr($line, strlen($name) + 1, strpos($line, ';') - strlen($name) - 1);
        }
    }
    return null;
}

return [
    'proof of work: a solution verifies; tampering, expiry and another client do not' => function (): void {
        $pow = new ProofOfWork(SECRET);
        $c = $pow->create('203.0.113.7', 2000, 2000);
        same('SHA-256', $c['algorithm']);
        $payload = solveInPhp($c);
        truthy($pow->verify($payload, '203.0.113.7', 1000.0), 'valid');
        truthy(!$pow->verify($payload, '198.51.100.1', 1000.0), 'another client');
        truthy(!$pow->verify($payload, '203.0.113.7', 2001.0), 'expired');
        truthy(!(new ProofOfWork('another-secret-0123456789abcdef0123456789'))->verify($payload, '203.0.113.7', 1000.0), 'another secret');
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/') . '=='), true);
        $wrong = $data;
        $wrong['number'] = $data['number'] + 1;
        truthy(!$pow->verify(rtrim(strtr(base64_encode(json_encode($wrong)), '+/', '-_'), '='), '203.0.113.7', 1000.0), 'wrong number');
        $wrong = $data;
        $wrong['salt'] = str_replace('expires=2000', 'expires=9999', $data['salt']);
        truthy(!$pow->verify(rtrim(strtr(base64_encode(json_encode($wrong)), '+/', '-_'), '='), '203.0.113.7', 1000.0), 'extended expiry');
        truthy(!$pow->verify('garbage', '203.0.113.7', 1000.0), 'garbage');
    },
    'pass cookie: bound to client and User-Agent, expires, cannot be forged' => function (): void {
        $p = new PassCookie(SECRET);
        $v = $p->issue('203.0.113.7', 'UA', 5000);
        truthy($p->valid($v, '203.0.113.7', 'UA', 4000.0), 'valid');
        truthy(!$p->valid($v, '203.0.113.8', 'UA', 4000.0), 'other client');
        truthy(!$p->valid($v, '203.0.113.7', 'Other UA', 4000.0), 'other User-Agent');
        truthy(!$p->valid($v, '203.0.113.7', 'UA', 5001.0), 'expired');
        truthy(!$p->valid(str_replace('.5000.', '.9999.', $v), '203.0.113.7', 'UA', 4000.0), 'extended');
        truthy((new PassCookie(SECRET, false))->valid((new PassCookie(SECRET, false))->issue('b', 'UA1', 5000), 'b', 'UA2', 1.0), 'User-Agent binding can be off');
    },
    'secret: made once, kept, shared' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-secret-' . getmypid() . '-' . mt_rand();
        $a = Secret::resolve(null, $dir);
        same(64, strlen($a));
        same($a, Secret::resolve(null, $dir), 'the same on the next request');
        same('0600', substr(sprintf('%o', fileperms($dir . '/secret')), -4));
        same(str_repeat('s', 40), Secret::resolve(str_repeat('s', 40), $dir), 'a configured one wins');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'gate: page, then solution -> pass cookie, then pass -> through' => function (): void {
        $gate = new Gate(ChallengeSettings::from(['difficulty' => ['min' => 1000, 'max' => 3000]]), SECRET);
        $challenged = Decision::challenge('requests', 0.0);
        $base = Decision::allow();

        $r = $gate->resolve($challenged, $base, creq('/page'), 1000.0);
        same(Decision::CHALLENGE, $r['decision']->action, 'first: challenged');
        truthy(is_string($r['page']) && strpos($r['page'], 'var RS=') !== false, 'the page carries the challenge');
        preg_match('/var RS=(\{.*?\});\(function/s', $r['page'], $m);
        $rs = json_decode($m[1], true);
        same(1000, $rs['c']['maxnumber'], 'level 0: minimum difficulty');

        $r = $gate->resolve($challenged, $base, creq('/page', ['rs_solution' => solveInPhp($rs['c'])]), 1001.0);
        same(Decision::ALLOW_UNCACHED, $r['decision']->action, 'solved: through, not cached');
        $pass = cookieValue($r['cookies'], 'rs_pass');
        truthy($pass !== null && $pass !== '', 'pass cookie set');
        same('', cookieValue($r['cookies'], 'rs_solution'), 'solution cookie removed');

        $r = $gate->resolve($challenged, $base, creq('/other', ['rs_pass' => $pass]), 1500.0);
        same(Decision::ALLOW, $r['decision']->action, 'pass: through as the checks decided');
        same(Decision::CHALLENGE, $gate->resolve($challenged, $base, creq('/other', ['rs_pass' => $pass], 'GET', '198.51.100.1'), 1500.0)['decision']->action, 'the pass is for one client only');
    },
    'gate: difficulty grows with the level; POST is throttled; exempt paths pass' => function (): void {
        $gate = new Gate(ChallengeSettings::from(['difficulty' => ['min' => 1000, 'max' => 5000], 'exemptPaths' => ['#^/api/#']]), SECRET);
        $r = $gate->resolve(Decision::challenge('requests', 1.0), Decision::allow(), creq('/'), 1.0);
        preg_match('/var RS=(\{.*?\});\(function/s', $r['page'], $m);
        same(5000, json_decode($m[1], true)['c']['maxnumber'], 'level 1: maximum difficulty');
        same(Decision::THROTTLE, $gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/', [], 'POST'), 1.0)['decision']->action);
        same(Decision::ALLOW, $gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/api/x'), 1.0)['decision']->action);
    },
    'gate: a solution buys one pass, not one per replay' => function (): void {
        $gate = new Gate(ChallengeSettings::from(['difficulty' => ['min' => 1000, 'max' => 1000]]), SECRET, null, 64, new MemoryStore());
        $c = (new ProofOfWork(SECRET))->create('203.0.113.7', 1000, 2000);
        $solved = creq('/', ['rs_solution' => solveInPhp($c)]);
        same(Decision::ALLOW_UNCACHED, $gate->resolve(Decision::challenge('requests'), Decision::allow(), $solved, 1000.0)['decision']->action, 'first use');
        same(Decision::CHALLENGE, $gate->resolve(Decision::challenge('requests'), Decision::allow(), $solved, 1010.0)['decision']->action, 'replayed');
    },
    'gate: a wrong solution gets a new challenge and loses its cookie' => function (): void {
        $gate = new Gate(ChallengeSettings::from(['difficulty' => ['min' => 1000, 'max' => 1000]]), SECRET);
        $r = $gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/', ['rs_solution' => 'bogus']), 1.0);
        same(Decision::CHALLENGE, $r['decision']->action);
        same('', cookieValue($r['cookies'], 'rs_solution'));
    },
    'search engines: a verified crawler passes, a fake one does not' => function (): void {
        $cache = [];
        $engines = new SearchEngines(SearchEngines::defaults(),
            function ($k) use (&$cache) { return $cache[$k] ?? null; },
            function ($k, $v) use (&$cache) { $cache[$k] = $v; },
            fn (string $ip) => ['66.249.66.1' => 'crawl-66-249-66-1.googlebot.com', '6.6.6.6' => 'evil.example'][$ip] ?? false,
            fn (string $host) => $host === 'crawl-66-249-66-1.googlebot.com' ? ['66.249.66.1'] : []);
        $bot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        truthy($engines->verified('66.249.66.1', $bot), 'real Googlebot');
        same('1', $cache['se:66.249.66.1'] ?? null, 'remembered');
        truthy(!$engines->verified('6.6.6.6', $bot), 'claims Googlebot, resolves elsewhere');
        truthy(!$engines->verified('66.249.66.1', 'curl/8'), 'no crawler claimed');
        $gate = new Gate(ChallengeSettings::from([]), SECRET, $engines);
        same(Decision::ALLOW, $gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/', [], 'GET', '66.249.66.1', $bot), 1.0)['decision']->action);
        same(Decision::CHALLENGE, $gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/', [], 'GET', '6.6.6.6', $bot), 1.0)['decision']->action);
    },
    'shield: budget -> challenge -> solved -> through, cacheability kept' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-ch-' . getmypid() . '-' . mt_rand();
        $shield = new Shield(['storeDir' => $dir, 'challenge' => ['secret' => SECRET, 'searchEngines' => false, 'difficulty' => ['min' => 1000, 'max' => 2000]],
            'cacheable' => ['query' => []], 'budgets' => ['requests' => ['limit' => 100, 'window' => 60, 'challengeAt' => 3]]], new MemoryStore());
        $now = 6000.0;
        for ($i = 0; $i < 3; $i++) {
            same(Decision::ALLOW, $shield->settle($shield->decide(creq('/'), $now), creq('/'), $now)['decision']->action);
        }
        $r = $shield->settle($shield->decide(creq('/'), $now), creq('/'), $now);
        same(Decision::CHALLENGE, $r['decision']->action, '4th: challenged');
        preg_match('/var RS=(\{.*?\});\(function/s', $r['page'], $m);
        $solved = creq('/', ['rs_solution' => solveInPhp(json_decode($m[1], true)['c'])]);
        $r = $shield->settle($shield->decide($solved, $now), $solved, $now);
        same(Decision::ALLOW_UNCACHED, $r['decision']->action);
        $withPass = creq('/?x=1', ['rs_pass' => cookieValue($r['cookies'], 'rs_pass')]);
        $r = $shield->settle($shield->decide($withPass, $now), $withPass, $now);
        same('query parameter', $r['decision']->reason, 'with a pass, the cacheable definition still counts');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'the page escapes what it embeds' => function (): void {
        $page = ChallengePage::render(['algorithm' => 'SHA-256', 'challenge' => 'x', 'maxnumber' => 1, 'salt' => '</script><b>', 'signature' => 's'], 'rs', false, ['title' => '<i>T</i>']);
        truthy(strpos($page, '</script><b>') === false, 'no raw </script> from the data');
        truthy(strpos($page, '&lt;i&gt;T&lt;/i&gt;') !== false, 'texts escaped');
    },
    'alwaysPaths: challenged whatever the budget says, until the client holds a pass' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-always-' . getmypid() . '-' . mt_rand();
        $shield = new Shield(['storeDir' => $dir, 'budgets' => ['requests' => ['limit' => 1000, 'window' => 60]],
            'challenge' => ['secret' => SECRET, 'searchEngines' => false, 'alwaysPaths' => ['#^/login$#'], 'difficulty' => ['min' => 1000, 'max' => 1000]]], new MemoryStore());
        same(Decision::ALLOW, $shield->settle($shield->decide(creq('/page'), 1.0), creq('/page'), 1.0)['decision']->action, 'other paths untouched');
        $r = $shield->settle($shield->decide(creq('/login'), 1.0), creq('/login'), 1.0);
        same(Decision::CHALLENGE, $r['decision']->action, 'first visit: challenged');
        same('always', $r['decision']->reason);
        preg_match('/var RS=(\{.*?\});\(function/s', (string) $r['page'], $m);
        $solved = creq('/login', ['rs_solution' => solveInPhp(json_decode($m[1], true)['c'])]);
        $r = $shield->settle($shield->decide($solved, 2.0), $solved, 2.0);
        same(Decision::ALLOW_UNCACHED, $r['decision']->action, 'solved');
        $withPass = creq('/login', ['rs_pass' => (string) cookieValue($r['cookies'], 'rs_pass')]);
        same(Decision::ALLOW, $shield->settle($shield->decide($withPass, 3.0), $withPass, 3.0)['decision']->action, 'with the pass: through');
        same(Decision::THROTTLE, $shield->settle($shield->decide(creq('/login', [], 'POST'), 4.0), creq('/login', [], 'POST'), 4.0)['decision']->action, 'a POST without a pass: not through');
        $postWithPass = creq('/login', ['rs_pass' => (string) cookieValue($r['cookies'], 'rs_pass')], 'POST');
        same(Decision::ALLOW_UNCACHED, $shield->settle($shield->decide($postWithPass, 5.0), $postWithPass, 5.0)['decision']->action, 'a POST with the pass: through, uncached');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'gate, asked for by the application: fresh passes, exempt paths do not count, a form comes back' => function (): void {
        $c = ChallengeSettings::from(['difficulty' => ['min' => 1000, 'max' => 3000], 'exemptPaths' => ['#^/api/#'], 'passTtl' => 3600]);
        $gate = new Gate($c, SECRET);
        $app = Decision::challenge('app');
        $base = Decision::allowUncached('app');
        // A pass issued at 1000 (valid until 4600).
        $r = $gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/'), 1000.0);
        preg_match('/var RS=(\{.*?\});\(function/s', (string) $r['page'], $m);
        $pass = cookieValue($gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/', ['rs_solution' => solveInPhp(json_decode($m[1], true)['c'])]), 1000.0)['cookies'], 'rs_pass');
        same(Decision::ALLOW_UNCACHED, $gate->resolve($app, $base, creq('/x', ['rs_pass' => $pass]), 1200.0, ['forced' => true])['decision']->action, 'a pass is enough');
        same(Decision::ALLOW_UNCACHED, $gate->resolve($app, $base, creq('/x', ['rs_pass' => $pass]), 1200.0, ['forced' => true, 'fresh' => 300])['decision']->action, 'issued 200 s ago: fresh enough for 300');
        same(Decision::CHALLENGE, $gate->resolve($app, $base, creq('/x', ['rs_pass' => $pass]), 1400.0, ['forced' => true, 'fresh' => 300])['decision']->action, 'issued 400 s ago: not for 300');
        same(Decision::ALLOW, $gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/api/x'), 1.0)['decision']->action, 'exempt path, budget');
        same(Decision::CHALLENGE, $gate->resolve($app, $base, creq('/api/x'), 1.0, ['forced' => true])['decision']->action, 'exempt path, asked for by the application: checked');
        // A POST with its form: the page carries it; its solution comes by POST.
        $r = $gate->resolve($app, $base, creq('/comment', [], 'POST'), 1.0, ['forced' => true, 'resend' => ['action' => '/comment?x=1', 'fields' => [['comment', '"hi"'], ['a[b]', '1']]]]);
        same(Decision::CHALLENGE, $r['decision']->action);
        truthy(strpos((string) $r['page'], '<form id="resend" method="post" action="/comment?x=1"><input type="hidden" name="comment" value="&quot;hi&quot;"><input type="hidden" name="a[b]" value="1">') !== false, (string) $r['page']);
        preg_match('/var RS=(\{.*?\});\(function/s', (string) $r['page'], $m);
        $sol = solveInPhp(json_decode($m[1], true)['c']);
        same(Decision::ALLOW_UNCACHED, $gate->resolve($app, $base, creq('/comment', ['rs_solution' => $sol], 'POST'), 2.0, ['forced' => true])['decision']->action, 'the form sent again, solved');
        same(Decision::THROTTLE, $gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/comment', ['rs_solution' => $sol], 'POST'), 2.0)['decision']->action, 'not asked for: a POST is still throttled');
        $lost = $gate->resolve($app, $base, creq('/upload', [], 'POST'), 1.0, ['forced' => true, 'resend' => false]);
        truthy(strpos((string) $lost['page'], 'Please go back and send the form again') !== false && strpos((string) $lost['page'], 'id="resend"') === false, 'a form that cannot come back: asked to send it again');
    },
    'the fields that come back: as PHP read them; not with files, not too large' => function (): void {
        $fields = new ReflectionMethod(\CjwNetwork\RequestShield\Shield::class, 'resendFields');
        $fields->setAccessible(true);
        $req = creq('/comment?x=1', [], 'POST');
        [$post, $files] = [$_POST, $_FILES];
        try {
            $_POST = ['comment' => 'hi', 'tags' => ['a', 'b'], 'deep' => ['x' => ['y' => 'z']]];
            $_FILES = [];
            same(['action' => '/comment?x=1', 'fields' => [['comment', 'hi'], ['tags[0]', 'a'], ['tags[1]', 'b'], ['deep[x][y]', 'z']]], $fields->invoke(null, $req));
            $_FILES = ['upload' => ['name' => 'a.pdf', 'error' => UPLOAD_ERR_OK]];
            same(false, $fields->invoke(null, $req), 'a file cannot come back');
            $_FILES = ['upload' => ['name' => '', 'error' => UPLOAD_ERR_NO_FILE]];
            truthy(is_array($fields->invoke(null, $req)), 'an empty file field is fine');
            $_POST = ['text' => str_repeat('x', 300000)];
            same(false, $fields->invoke(null, $req), 'too large');
        } finally {
            [$_POST, $_FILES] = [$post, $files];
        }
    },
    'the check inside the form: the answer from a form field, the task, the setting, the placeholder' => function (): void {
        $c = ChallengeSettings::from(['difficulty' => ['min' => 1000, 'max' => 3000], 'widgetPath' => '/request-shield', 'widgetDifficulty' => 2000]);
        $gate = new Gate($c, SECRET, null, 64, new \CjwNetwork\RequestShield\Store\MemoryStore());      // a store: an answer counts once
        $task = $gate->widgetTask(creq('/request-shield/challenge'), 1.0);
        truthy(is_array($task) && $task['maxnumber'] === 2000, 'a task of the widget\'s difficulty');
        $answer = solveInPhp($task);
        // ALTCHA's own widget sends standard base64, with padding: the same answer counts.
        $std = base64_encode((string) base64_decode(strtr($answer, '-_', '+/') . str_repeat('=', (4 - strlen($answer) % 4) % 4)));
        truthy((new \CjwNetwork\RequestShield\Challenge\ProofOfWork(SECRET))->verify($std, \CjwNetwork\RequestShield\IpAddress::bucket('203.0.113.7'), 2.0), 'an answer in ALTCHA\'s encoding verifies');
        $r = $gate->resolve(Decision::challenge('always'), Decision::allow(), creq('/login', [], 'POST'), 2.0, ['solution' => $answer]);
        same(Decision::ALLOW_UNCACHED, $r['decision']->action, 'a POST with the answer in the form: through');
        $pass = cookieValue($r['cookies'], 'rs_pass');
        truthy($pass !== null && $pass !== '', 'with a pass');
        same(Decision::THROTTLE, $gate->resolve(Decision::challenge('always'), Decision::allow(), creq('/login', [], 'POST'), 2.0, ['solution' => $answer])['decision']->action, 'the same answer twice: no');
        same(null, $gate->widgetTask(creq('/x', ['rs_pass' => $pass]), 3.0), 'with a pass: no task');
        foreach (['request-shield', '/a b', '/x/../y"', '/'] as $bad) {
            try {
                ChallengeSettings::from(['widgetPath' => $bad]);
                throw new TestFailure("accepted $bad");
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'challenge.widgetPath') !== false, $e->getMessage());
            }
        }
        same(null, ChallengeSettings::from([])->widgetPath, 'off by default');
        $shield = new \CjwNetwork\RequestShield\Shield([], new \CjwNetwork\RequestShield\Store\MemoryStore());
        same('', $shield->widget(), 'off: no placeholder');
        $on = new \CjwNetwork\RequestShield\Shield(['challenge' => ['widgetPath' => '/rs']], new \CjwNetwork\RequestShield\Store\MemoryStore());
        $first = $on->widget();
        $second = $on->widget('load');
        truthy(substr_count($first . $second, '<script') === 1, 'the script once per page');
        truthy(strpos($second, 'data-start="load"') !== false, 'the start option');
        $node = nodeBinary();
        if ($node !== null) {
            $f = sys_get_temp_dir() . '/rshield-widget-' . getmypid() . '.js';
            file_put_contents($f, \CjwNetwork\RequestShield\Challenge\Widget::script());
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($f) . ' 2>&1', $out, $code);
            unlink($f);
            same(0, $code, 'widget.js is valid JavaScript: ' . implode("\n", $out));
        }
    },
    'a spent budget: no pass gets past it, only its own solution -- which starts the counter again, twice as hard each time' => function (): void {
        $store = new \CjwNetwork\RequestShield\Store\MemoryStore();
        $c = ChallengeSettings::from(['difficulty' => ['min' => 1000, 'max' => 5000], 'exemptPaths' => ['#^/api/#']]);
        $gate = new Gate($c, SECRET, null, 64, $store);
        $bucket = \CjwNetwork\RequestShield\IpAddress::bucket('203.0.113.7');
        $spent = Decision::spent('posts', 30);
        $earn = ['earn' => ['window' => 60]];
        // a pass from an ordinary check
        $r = $gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/'), 1.0);
        preg_match('/var RS=(\{.*?\});\(function/s', (string) $r['page'], $m);
        $plain = solveInPhp(json_decode($m[1], true)['c']);
        $pass = cookieValue($gate->resolve(Decision::challenge('requests'), Decision::allow(), creq('/', ['rs_solution' => $plain]), 1.0)['cookies'], 'rs_pass');
        same(Decision::CHALLENGE, $gate->resolve($spent, Decision::allow(), creq('/x', ['rs_pass' => $pass]), 2.0, $earn)['decision']->action, 'a pass does not get past a spent budget');
        same(Decision::CHALLENGE, $gate->resolve($spent, Decision::allow(), creq('/api/x'), 2.0, $earn)['decision']->action, 'nor an exempt path');
        // its own task, bound to it
        for ($i = 0; $i < 5; $i++) {
            $store->hit('posts:' . $bucket, 60, 3.0);
        }
        $r = $gate->resolve($spent, Decision::allow(), creq('/x'), 3.0, $earn);
        preg_match('/var RS=(\{.*?\});\(function/s', (string) $r['page'], $m);
        $task = json_decode($m[1], true)['c'];
        same(1000, $task['maxnumber'], 'the first time: difficulty-min');
        truthy(strpos($task['salt'], '&b=posts') !== false, 'bound to the budget');
        $other = (new \CjwNetwork\RequestShield\Challenge\ProofOfWork(SECRET))->create($bucket, 1000, 100, 'searches');
        same(Decision::CHALLENGE, $gate->resolve($spent, Decision::allow(), creq('/x', ['rs_solution' => solveInPhp($other)]), 3.0, $earn)['decision']->action, 'another budget\'s solution does not count');
        $plain2 = (new \CjwNetwork\RequestShield\Challenge\ProofOfWork(SECRET))->create($bucket, 1000, 100);
        same(Decision::CHALLENGE, $gate->resolve($spent, Decision::allow(), creq('/x', ['rs_solution' => solveInPhp($plain2)]), 3.0, $earn)['decision']->action, 'nor an ordinary one');
        $r = $gate->resolve($spent, Decision::allow(), creq('/x', ['rs_solution' => solveInPhp($task)]), 4.0, $earn);
        same(Decision::ALLOW_UNCACHED, $r['decision']->action, 'its own solution: through');
        same(0.0, $store->peek('posts:' . $bucket, 60, 4.0), 'and the counter starts again');
        $r = $gate->resolve($spent, Decision::allow(), creq('/x'), 5.0, $earn);
        preg_match('/var RS=(\{.*?\});\(function/s', (string) $r['page'], $m);
        same(2000, json_decode($m[1], true)['c']['maxnumber'], 'the second time within the hour: twice as hard');
        for ($i = 0; $i < 5; $i++) {
            $store->hit('solved:posts:' . $bucket, 3600, 6.0);
        }
        $r = $gate->resolve($spent, Decision::allow(), creq('/x'), 6.0, $earn);
        preg_match('/var RS=(\{.*?\});\(function/s', (string) $r['page'], $m);
        same(5000, json_decode($m[1], true)['c']['maxnumber'], 'at most difficulty-max');
        // an API: the task as JSON; a POST without its form: a pause
        $api = $gate->resolve($spent, Decision::allow(), creq('/x', [], 'POST'), 7.0, $earn + ['api' => true]);
        same([null, true], [$api['page'], is_array($api['json'])], 'an API gets the task as JSON');
        same(Decision::THROTTLE, $gate->resolve($spent, Decision::allow(), creq('/x', [], 'POST'), 7.0, $earn)['decision']->action, 'a POST whose form cannot come back: a pause');
        same(30, $gate->resolve($spent, Decision::allow(), creq('/x', [], 'POST'), 7.0, $earn)['decision']->retryAfter, 'as long as the budget says');
    },
    'decisions: a spent budget outranks a plain check; the budget rule gives it only when asked' => function (): void {
        same(true, Decision::challenge('requests')->stricter(Decision::spent('posts', 5))->spent);
        same(true, Decision::spent('posts', 5)->stricter(Decision::challenge('requests'))->spent);
        same(Decision::THROTTLE, Decision::spent('posts', 5)->stricter(Decision::throttle('x', 5))->action);
        $store = new \CjwNetwork\RequestShield\Store\MemoryStore();
        $pause = new \CjwNetwork\RequestShield\Rule\BudgetRule($store, 'a', 2, 60);
        $earn = new \CjwNetwork\RequestShield\Rule\BudgetRule($store, 'b', 2, 60, null, [], 64, true);
        $req = creq('/');
        for ($i = 0; $i < 2; $i++) {
            $pause->check($req, 1.0);
            $earn->check($req, 1.0);
        }
        same(Decision::THROTTLE, $pause->check($req, 1.0)->action, 'by default a pause');
        truthy($earn->check($req, 1.0)->spent, 'on-exceeded challenge: the check that frees the counter');
    },
    'stores: reset forgets a key\'s count' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-reset-' . getmypid() . '-' . mt_rand();
        $stores = ['memory' => new \CjwNetwork\RequestShield\Store\MemoryStore(), 'file' => new \CjwNetwork\RequestShield\Store\FileStore($dir, 0.0)];
        if (\CjwNetwork\RequestShield\Store\ApcuStore::usable()) {
            $stores['apcu'] = new \CjwNetwork\RequestShield\Store\ApcuStore('rshield-test-' . mt_rand() . ':');
        }
        foreach ($stores as $name => $store) {
            $store->hit('k', 60, 119.0);
            $store->hit('k', 60, 121.0);
            $store->hit('other', 60, 121.0);
            $store->reset('k', 60, 121.0);
            same(0.0, $store->peek('k', 60, 121.0), "$name: this window and the one before");
            same(1.0, $store->peek('other', 60, 121.0), "$name: other keys stay");
        }
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'DNS lookups for search engines: at most so many a minute -- a flood of fake crawlers does not wait for DNS' => function (): void {
        $calls = 0;
        // DNS that does not answer (a DMZ): every lookup waits, then fails.
        $slow = function (string $ip) use (&$calls) { $calls++; usleep(20000); return false; };
        $cache = [];
        $get = function ($k) use (&$cache) { return $cache[$k] ?? null; };
        $set = function ($k, $v) use (&$cache) { $cache[$k] = $v; };
        $flood = function (?\Closure $guard) use ($slow, $get, $set, &$calls, &$cache): array {
            [$calls, $cache] = [0, []];
            $se = new SearchEngines(SearchEngines::defaults(), $get, $set, $slow, null, $guard);
            $t = microtime(true);
            for ($i = 1; $i <= 50; $i++) {
                truthy(!$se->verified("198.51.100.$i", 'Mozilla/5.0 (compatible; Googlebot/2.1)'), 'a fake crawler is never verified');
            }
            return [$calls, microtime(true) - $t];
        };
        [$without, $slowTime] = $flood(null);
        same(50, $without, 'without the guard: 50 fake crawlers, 50 lookups');
        $store = new \CjwNetwork\RequestShield\Store\MemoryStore();
        [$with, $fastTime] = $flood(function () use ($store): bool { return $store->hit('se-lookups', 60, 1000.0) <= 5; });
        same(5, $with, 'with it: 5 lookups, the rest refused at once');
        truthy($fastTime < $slowTime / 5, sprintf('and fast: %.2f s instead of %.2f s', $fastTime, $slowTime));
        // Past the budget nothing is remembered: a real crawler is known again later.
        $real = new SearchEngines(SearchEngines::defaults(), $get, $set,
            fn (string $ip) => 'crawl-66-249-66-1.googlebot.com', fn (string $h) => ['66.249.66.1'], fn (): bool => false);
        same(false, $real->verified('66.249.66.1', 'Googlebot/2.1'), 'no lookups left: not verified');
        same(null, $cache['se:66.249.66.1'] ?? null, 'and not remembered');
        $later = new SearchEngines(SearchEngines::defaults(), $get, $set,
            fn (string $ip) => 'crawl-66-249-66-1.googlebot.com', fn (string $h) => ['66.249.66.1'], fn (): bool => true);
        same(true, $later->verified('66.249.66.1', 'Googlebot/2.1'), 'a minute later: verified');
        same(30, ChallengeSettings::from([])->dnsLookups, 'the default: 30 a minute');
        same(0, ChallengeSettings::from(['dnsLookups' => 0])->dnsLookups, '0: none (a DMZ without DNS)');
    },
];
