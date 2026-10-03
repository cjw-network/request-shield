<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Access;
use CjwNetwork\RequestShield\Report\StatsPage;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** Who may read the statistics (proposal 0023, phase 4). */

const ACCESS_ADMIN = 'admin-token-0123456789abcdefghijklmnopqrstuv';
const ACCESS_A = 'customer-a-token-0123456789abcdefghijklmnop';

function accessDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-access-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    return $dir;
}

function accessSettings(string $dir, string $more = ''): Settings
{
    file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset secret " . str_repeat('s', 40) . "\nset stats on\n"
        . "stats-group \"Customer A\" a.de b.de\nstats-group \"Customer B\" c.de\n"
        . 'stats-access * sha256:' . hash('sha256', ACCESS_ADMIN) . "\n"
        . '[ACC-A] stats-access "Customer A" sha256:' . hash('sha256', ACCESS_A) . "\n" . $more);
    return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
}

function accessReq(string $uri = '/rs/stats/visitors', array $headers = [], string $method = 'GET', string $ip = '203.0.113.9'): Request
{
    $server = ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method, 'HTTP_HOST' => 'stats.example.org', 'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/136.0', 'HTTPS' => 'on'];
    foreach ($headers as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }
    return Request::fromServer($server);
}

/** The cookie value a gate's answer sets, or ''. */
function accessCookie(array $g): string
{
    foreach ($g['headers'] as $h) {
        if (preg_match('/^Set-Cookie: rs_stats=([^;]*)/', $h, $m) === 1) {
            return $m[1];
        }
    }
    return '';
}

return [
    'stats-access: * or a group, by a token\'s hash; until; mistakes named -- the rule file never holds a token' => function (): void {
        $dir = accessDir();
        try {
            $s = accessSettings($dir, '[ACC-OLD] stats-access "Customer B" sha256:' . str_repeat('a', 64) . " until 2020-01-01\n"
                . '[ACC-B] stats-access "Customer B" sha256:' . str_repeat('b', 64) . ' until ' . date('Y-m-d', time() + 86400) . "\n");
            same(['*', 'customer-a', 'customer-b'], array_column($s->statsAccess, 'who'), 'the ended one left out');
            same(28800, $s->statsSession, 'a login lasts 8 hours');
            truthy($s->listsUntil > time() && $s->listsUntil <= time() + 86400 * 2, 'built again when the next one ends');
            same(['*', 'customer-a', null], [Access::whoseToken($s, ACCESS_ADMIN), Access::whoseToken($s, ACCESS_A), Access::whoseToken($s, 'guess')]);
            [$token, $hash] = Access::token();
            truthy(preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1 && $hash === hash('sha256', $token), 'a token: 32 random bytes, its SHA-256');
            same(3600, accessSettings($dir, "set stats-session 1h\n")->statsSession);
            foreach (['stats-access "Nobody" sha256:' . str_repeat('c', 64) . "\n" => 'there is no group',
                "stats-access * sha256:abc\n" => 'stats-access "<group>"|*',
                "stats-access * " . ACCESS_ADMIN . "\n" => 'stats-access "<group>"|*',
                "site a.de {\n  stats-access * sha256:" . str_repeat('d', 64) . "\n}\n" => 'is about the server'] as $text => $says) {
                try {
                    file_put_contents("$dir/bad.rules", "set store-dir $dir/store\nstats-group X a.de\n" . $text);
                    Settings::from(RuleFile::read(["$dir/bad.rules"], strpos($text, 'site ') === 0 ? 'a.de' : null)['config']);
                    throw new TestFailure('accepted: ' . json_encode($text));
                } catch (RuleFileException | InvalidArgumentException $e) {
                    truthy(strpos($e->getMessage(), $says) !== false, $e->getMessage());
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'the gate: the form, a wrong token counted, a right one a signed cookie -- the address without it; the cookie then; tampered, ended or its token gone: asked again' => function (): void {
        $dir = accessDir();
        try {
            $s = accessSettings($dir);
            $store = new MemoryStore();
            $o = ['store' => $store, 'lang' => 'en', 'now' => 1790800000];
            same(['*', 200, null], array_values(array_intersect_key(Access::gate(Settings::from(['storeDir' => $dir . '/store']), accessReq(), [], [], ['store' => $store]), ['who' => 1, 'status' => 1, 'body' => 1])),
                'without stats-access: nothing asked (the site\'s own rules decide)');
            $g = Access::gate($s, accessReq(), [], [], $o);
            same([null, 401], [$g['who'], $g['status']], 'nobody: the form');
            truthy(strpos((string) $g['body'], 'name="rs-token"') !== false && strpos((string) $g['body'], 'type="password"') !== false, 'a field for the token');
            truthy(in_array('X-Robots-Tag: noindex, nofollow', $g['headers'], true) && in_array('Cache-Control: private, no-store', $g['headers'], true)
                && in_array('Referrer-Policy: no-referrer', $g['headers'], true) && in_array("Content-Security-Policy: frame-ancestors 'self'", $g['headers'], true), 'never indexed, kept or framed elsewhere');
            $g = Access::gate($s, accessReq('/rs/stats/visitors', ['Origin' => 'https://stats.example.org'], 'POST'), [], ['rs-token' => 'wrong'], $o);
            same([401, ''], [$g['status'], accessCookie($g)], 'a wrong token: no cookie');
            same(1.0, $store->peek('access:203.0.113.9', 60, 1790800000.0), 'counted');
            $g = Access::gate($s, accessReq('/rs/stats/visitors?days=7', ['Origin' => 'https://evil.example'], 'POST'), [], ['rs-token' => ACCESS_A], $o);
            same([403, ''], [$g['status'], accessCookie($g)], 'sent from another site: refused');
            $g = Access::gate($s, accessReq('/rs/stats/visitors?days=7', ['Origin' => 'https://stats.example.org'], 'POST'), [], ['rs-token' => ACCESS_A], $o);
            $cookie = accessCookie($g);
            same([303, true], [$g['status'], in_array('Location: /rs/stats/visitors?days=7', $g['headers'], true)], 'right: a cookie, then the page by GET');
            truthy(preg_match('/^customer-a\.\d+\.[0-9a-f]{8}\.[0-9a-f]{32}$/', $cookie) === 1, 'the cookie: who, until when, signed -- no token in it');
            truthy((bool) preg_grep('/^Set-Cookie: rs_stats=.*; HttpOnly; SameSite=Lax; Secure$/', $g['headers']), 'HttpOnly, SameSite=Lax, Secure on HTTPS');
            $g = Access::gate($s, accessReq('/rs/stats/visitors', ['Cookie' => "rs_stats=$cookie"]), [], [], $o);
            same([200, 'customer-a'], [$g['status'], $g['who']], 'the cookie: Customer A');
            $tampered = (string) preg_replace('/^customer-a/', 'customer-b', $cookie);
            same(null, Access::gate($s, accessReq('/', ['Cookie' => "rs_stats=$tampered"]), [], [], $o)['who'], 'another group in it: the signature does not fit');
            same(null, Access::fromCookie($s, $cookie, 1790800000 + 28801), 'after 8 hours: ended');
            $rotated = accessSettings($dir, '[ACC-A2] stats-access "Customer A" sha256:' . hash('sha256', 'a-new-token-for-a') . "\n");
            same(null, Access::fromCookie($rotated, $cookie, 1790800001), 'a token added or removed for the group: its sessions end');
            same('customer-a', Access::fromCookie(accessSettings($dir), $cookie, 1790800001), 'the same tokens: still in');
            $g = Access::gate($s, accessReq('/rs/stats/visitors?rs-logout=1', ['Cookie' => "rs_stats=$cookie"]), ['rs-logout' => '1'], [], $o);
            truthy($g['who'] === null && (bool) preg_grep('/^Set-Cookie: rs_stats=; Path=\/; Max-Age=0/', $g['headers']), 'signed out: the cookie deleted');
            same(['*', 200], array_values(array_intersect_key(Access::gate($s, accessReq(), [], [], $o + ['admin' => true]), ['who' => 1, 'status' => 1])),
                'admin: the site knows its administrator (say, by its address): everything, no form');
            same([null, 401], array_values(array_intersect_key(Access::gate($s, accessReq('/rs/stats/sites?rs-login=1'), ['rs-login' => '1'], [], $o + ['admin' => true]), ['who' => 1, 'status' => 1])),
                'with ?rs-login=1 the form all the same (to try a customer\'s view)');
            same('customer-a', Access::gate($s, accessReq('/', ['Cookie' => "rs_stats=$cookie"]), [], [], $o + ['admin' => true])['who'], 'a customer\'s cookie first: its own view, on the administrator\'s machine too');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'a signed link from the customer\'s panel: opens its group, then the address without it -- expired, too long, forged or for no group: refused; ten wrong tries: 429' => function (): void {
        $dir = accessDir();
        try {
            $s = accessSettings($dir);
            $store = new MemoryStore();
            $now = 1790800000;
            $o = ['store' => $store, 'lang' => 'en', 'now' => $now];
            $q = Access::link($s, 'Customer A', 600, $now);
            parse_str($q, $get);
            same(['customer-a', (string) ($now + 600)], [$get['rs-g'], $get['rs-exp']], 'the group\'s ID and when it ends; no token in it');
            $g = Access::gate($s, accessReq('/rs/stats/visitors?days=7&' . $q), $get + ['days' => '7'], [], $o);
            same([303, true, 'customer-a'], [$g['status'], in_array('Location: /rs/stats/visitors?days=7', $g['headers'], true), Access::fromCookie($s, accessCookie($g), $now)],
                'opened: the cookie, and the address without the signature');
            same(null, Access::fromLink($s, $get, $now + 601), 'expired');
            parse_str(Access::link($s, 'Customer A', 86400, $now), $long);
            same($now + 3600, (int) $long['rs-exp'], 'at most an hour');
            same(null, Access::fromLink($s, ['rs-exp' => (string) ($now + 7200)] + $get, $now), 'an end too far away (a link made by hand)');
            same(null, Access::fromLink($s, ['rs-g' => 'customer-b'] + $get, $now), 'another group: the signature does not fit');
            parse_str(Access::link($s, 'Nobody', 600, $now), $none);
            same(null, Access::fromLink($s, $none, $now), 'a group that is not there');
            parse_str(Access::link($s, '*', 600, $now), $admin);
            same('*', Access::fromLink($s, $admin, $now), 'the admin, too');
            for ($i = 0; $i < Access::TRIES; $i++) {
                Access::gate($s, accessReq('/x?rs-sig=bad'), ['rs-g' => 'customer-a', 'rs-exp' => (string) ($now + 60), 'rs-sig' => 'bad'], [], $o);
            }
            $g = Access::gate($s, accessReq('/x?' . $q), $get, [], $o);
            same([429, true], [$g['status'], in_array('Retry-After: 60', $g['headers'], true)], 'ten wrong tries a minute: 429, even for a right one');
            same(200, Access::gate($s, accessReq('/x', [], 'GET', '198.51.100.1'), [], [], $o + ['now' => $now])['status'] === 401 ? 200 : 0, 'another address: not affected');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'JSON for a program: Authorization: Bearer <token> -- no cookie; wrong: 401' => function (): void {
        $dir = accessDir();
        try {
            $s = accessSettings($dir);
            $o = ['store' => new MemoryStore(), 'now' => 1790800000];
            $g = Access::gate($s, accessReq('/rs/stats/visitors?format=json', ['Authorization' => 'Bearer ' . ACCESS_A]), [], [], $o);
            same(['customer-a', 200, ''], [$g['who'], $g['status'], accessCookie($g)]);
            $g = Access::gate($s, accessReq('/rs/stats/visitors?format=json', ['Authorization' => 'Bearer nope']), [], [], $o);
            same([null, 401, null], [$g['who'], $g['status'], $g['body']], 'wrong: 401, no form');
            same('*', Access::gate(accessSettings($dir), accessReq('/', ['Authorization' => 'Bearer ' . ACCESS_ADMIN]), [], [], $o)['who']);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'a customer sees only its own: its group\'s websites, no Rules & setup, no server overview, no other group -- whatever address it asks for' => function (): void {
        $dir = accessDir();
        try {
            $s = accessSettings($dir);
            foreach (['a.de' => 3, 'b.de' => 2, 'c.de' => 7] as $site => $n) {
                for ($i = 0; $i < $n; $i++) {
                    Stats::of($s, $site)->count(['a:allow', 'pg:people|/p', 'r:SECRET-RULE'], 1790800000.0);
                }
            }
            same(['group:customer-a', 'a.de', 'group:customer-a', 'group:customer-a'], [Access::site($s, 'customer-a', null), Access::site($s, 'customer-a', 'a.de'),
                Access::site($s, 'customer-a', 'c.de'), Access::site($s, 'customer-a', 'group:customer-b')], 'only its group or one of its websites');
            same('c.de', Access::site($s, '*', 'c.de'), 'the admin: anything');
            $links = StatsPage::links($s);
            same(['sites', 'site', 'shield'], array_keys(Access::links('customer-a', $links + ['live' => '/rs/waf/live', 'lists' => '/rs/waf/lists'])), 'its tabs');
            $o = ['links' => Access::links('customer-a', $links), 'lang' => 'en', 'now' => 1790800000, 'who' => 'customer-a'];
            $rules = StatsPage::render($s, ['view' => 'rules'] + $o);
            truthy(strpos($rules, 'The way of a request') === false && strpos($rules, 'Rules &amp; setup') === false, 'Rules & setup asked for: not shown');
            $other = StatsPage::render($s, ['view' => 'site', 'site' => 'c.de'] + $o);
            truthy(strpos($other, '<option value="c.de"') === false && strpos($other, 'Customer B') === false && strpos($other, 'All websites</option>') === false
                && strpos($other, 'Customer A: all 2 websites') !== false, 'another group asked for: its own instead; the switch only its group');
            $shield = StatsPage::render($s, ['view' => 'shield'] + $o);
            truthy(strpos($shield, 'SECRET-RULE') === false, 'the protection without the rules that decided');
            $sites = StatsPage::render($s, ['view' => 'sites'] + $o);
            truthy(strpos($sites, '>a.de</a>') !== false && strpos($sites, '>c.de</a>') === false && strpos($sites, '>All websites</a>') === false
                && strpos($sites, '>other hosts</a>') === false, 'all websites: its own only');
            $admin = StatsPage::render($s, ['view' => 'sites', 'links' => $links, 'lang' => 'en', 'now' => 1790800000, 'who' => '*']);
            truthy(strpos($admin, '>c.de</a>') !== false && strpos($admin, '>All websites</a>') !== false, 'the admin sees everything');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'end to end: a server asks, the token by the form, the cookie, the customer\'s page -- and nothing of the others' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = accessDir();
        accessSettings($dir);
        mkdir("$dir/docroot");
        $boot = dirname(__DIR__) . '/bootstrap.php';
        file_put_contents("$dir/docroot/index.php", '<?php
require ' . var_export($boot, true) . ';
use CjwNetwork\RequestShield\{Access, Request, Settings};
use CjwNetwork\RequestShield\Report\StatsPage;
$s = Settings::load(' . var_export("$dir/site.rules", true) . ', ' . var_export("$dir/cache", true) . ');
$r = Request::fromServer($_SERVER);
$g = Access::gate($s, $r, $_GET, $_POST);
foreach ($g["headers"] as $h) { header($h, false); }
http_response_code($g["status"]);
if ($g["who"] === null) { echo (string) $g["body"]; exit; }
$view = StatsPage::viewFor($s, $r->path) ?? "site";
echo StatsPage::render($s, ["view" => $view, "who" => $g["who"], "links" => Access::links($g["who"], StatsPage::links($s)), "lang" => "en", "site" => $_GET["site"] ?? null]);
');
        $port = freePort();
        // REQUEST_SHIELD_CONFIG: index.php gates the pages itself; bootstrap.php must not start the shield from a config/ of this checkout.
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=/nonexistent exec %s -S 127.0.0.1:%d %s > /dev/null 2>&1', escapeshellarg(PHP_BINARY), $port, escapeshellarg("$dir/docroot/index.php")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $http = static function (string $method, string $uri, array $headers = [], string $body = '') use ($port): array {
                $h = '';
                foreach ($headers as $k => $v) {
                    $h .= "$k: $v\r\n";
                }
                $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $h . ($body !== '' ? "Content-Type: application/x-www-form-urlencoded\r\n" : ''), 'content' => $body,
                    'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
                $out = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, $ctx);
                return [(int) substr((string) ($http_response_header[0] ?? ''), 9, 3), $http_response_header ?? [], $out];
            };
            [$status, , $page] = $http('GET', '/rs/stats/visitors');
            truthy($status === 401 && strpos($page, 'name="rs-token"') !== false, 'asked first');
            [$status, $headers] = $http('POST', '/rs/stats/visitors', ['Origin' => "http://127.0.0.1:$port"], 'rs-token=' . rawurlencode(ACCESS_A));
            $cookie = '';
            foreach ($headers as $line) {
                if (preg_match('/^Set-Cookie: (rs_stats=[^;]+)/i', $line, $m) === 1) {
                    $cookie = $m[1];
                }
            }
            truthy($status === 303 && $cookie !== '', "signed in: $status");
            [$status, , $page] = $http('GET', '/rs/stats/visitors?site=c.de', ['Cookie' => $cookie]);
            truthy($status === 200 && strpos($page, 'Customer A: all 2 websites') !== false && strpos($page, 'c.de') === false, 'its own statistics, not c.de although asked for');
            [$status, , $page] = $http('GET', '/rs/waf/rules', ['Cookie' => $cookie]);
            truthy(strpos($page, 'The way of a request') === false, 'Rules & setup: not for a customer');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
