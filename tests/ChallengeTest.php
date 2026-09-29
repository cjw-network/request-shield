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
];
