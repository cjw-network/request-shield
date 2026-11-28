<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/**
 * A ban and an on-demand budget written inside a match block: they are the
 * area's (proposal 0050). The ban counts the area's signals and refuses only
 * there; a spent on-demand budget refuses the area's next requests; its
 * overrun is a "limits" signal for the area's bans. Outside a block, both as
 * before.
 */

function areaDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-area-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    return $dir;
}

function areaSettings(string $dir, string $rules): Settings
{
    file_put_contents("$dir/site.rules", "set store-dir $dir/store\nexempt none\nblock none\nno-limit requests\n" . $rules);
    return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
}

function areaReq(string $uri, string $ip = '203.0.113.9'): Request
{
    return Request::fromServer(['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/136.0']);
}

/** decide(), settle() and the signals for the bans, as protect() does. */
function areaRun(Shield $shield, Request $r, float $now): Decision
{
    $d = $shield->settle($shield->decide($r, $now), $r, $now)['decision'];
    $shield->signals($d, $r, $now);
    return $d;
}

/** @return array{0: string, 1: string} action, reason */
function areaWhat(Decision $d): array
{
    return [$d->action, $d->reason];
}

return [
    'RSF01-02 a ban inside a match block is the block\'s: its paths compiled in; outside a block a ban has none, and "at" is still no word of ban' => function (): void {
        $dir = areaDir();
        try {
            $s = areaSettings($dir, "[B-ALL] ban after 5 refusals in 5m for 1h\nmatch /api/** {\n  [API-PACE] limit api 2/min\n  [API-BAN] ban after 2 limits in 15m for 10m\n}\n");
            same([['after' => 5, 'signal' => 'refusals', 'in' => 300, 'for' => 3600, 'rule' => 'B-ALL']], array_slice($s->bans, 0, 1), 'outside a block: as in 0.4.0, no paths');
            same($s->budgets['api']->paths, $s->bans[1]['paths'] ?? null, 'inside: the block\'s paths, the same as the block\'s budget\'s');
            same('API-BAN', $s->bans[1]['rule'], 'its rule');
            foreach (["ban after 2 limits in 15m for 10m at /api/**\n" => 'ban after', "match /api/** {\n  ban after 2 limits in 15m for 10m at /x\n}\n" => 'ban after'] as $rules => $says) {
                try {
                    areaSettings($dir, $rules);
                    truthy(false, "refused: $rules");
                } catch (RuleFileException $e) {
                    truthy(strpos($e->getMessage(), $says) !== false, $e->getMessage());
                }
            }
            try {
                file_put_contents("$dir/site.rules", "site a.de {\n  match /api/** {\n    ban after 2 limits in 15m for 10m\n  }\n}\n");
                RuleFile::read(["$dir/site.rules"], 'a.de');
                truthy(false, 'a ban in a site block: refused, as before');
            } catch (RuleFileException $e) {
                truthy(strpos($e->getMessage(), 'ban is about the server') !== false, $e->getMessage());
            }
            same([['after' => 2, 'signal' => 'limits', 'in' => 60, 'for' => 60, 'rule' => 'bans[0]', 'paths' => ['#^/api(?:/.*)?$#i']]],
                Settings::from(['bans' => [['after' => 2, 'signal' => 'limits', 'in' => 60, 'for' => 60, 'paths' => ['#^/api(?:/.*)?$#i']]]])->bans, 'PHP settings: paths');
            try {
                Settings::from(['bans' => [['after' => 2, 'signal' => 'limits', 'in' => 60, 'for' => 60, 'paths' => ['#(#']]]]);
                truthy(false, 'a broken pattern: refused');
            } catch (\InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'bans[0].paths') !== false, $e->getMessage());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-02 a ban inside a match block counts the block\'s signals and refuses only there -- the rest of the site passes; it ends by itself' => function (): void {
        $dir = areaDir();
        try {
            $s = areaSettings($dir, "match /api/** {\n  [API-PACE] limit api 2/min\n  [API-BAN] ban after 2 limits in 15m for 10m\n}\n"
                . "match /news/** {\n  [NEWS-PACE] limit news 1/min\n}\n");
            $store = new MemoryStore();
            $shield = new Shield($s, $store);
            $ip = '203.0.113.9';
            $now = 10000.0;
            foreach ([1, 2, 3, 4, 5, 6] as $i) {
                areaRun($shield, areaReq('/news/' . $i, $ip), $now + $i);                // five times past another area's limit
            }
            same(['allow', ''], areaWhat(areaRun($shield, areaReq('/api/a', $ip), $now + 10)), 'another area\'s limits do not count for this ban');
            areaRun($shield, areaReq('/api/b', $ip), $now + 11);
            same(['throttle', 'api'], areaWhat(areaRun($shield, areaReq('/api/c', $ip), $now + 12)), 'past the area\'s limit: a pause (one signal)');
            areaRun($shield, areaReq('/api/d', $ip), $now + 13);                       // the second: banned
            $d = areaRun($shield, areaReq('/api/e', $ip), $now + 14);
            same(['throttle', 429, 'banned'], [$d->action, $d->status, $d->reason], 'banned on the area');
            truthy($d->retryAfter > 590 && $d->retryAfter <= 600, "for its time: $d->retryAfter");
            same('API-BAN', $shield->explain($d, areaReq('/api/e', $ip)), 'the rule named');
            same(['allow', ''], areaWhat($shield->decide(areaReq('/', $ip), $now + 15)), 'the rest of the site: passes');
            same(['allow', ''], areaWhat($shield->decide(areaReq('/apix', $ip), $now + 15)), 'a path that only begins alike: passes');
            same(['allow', ''], areaWhat($shield->decide(areaReq('/api/e', '203.0.113.10'), $now + 15)), 'another client: passes');
            same(0, $store->marked('ban:' . $ip, $now + 15), 'no ban of the whole site');
            truthy($store->marked('ban:' . $ip . '@API-BAN', $now + 15) > 0, 'the area\'s ban, under its rule');
            same(['allow', ''], areaWhat($shield->decide(areaReq('/api/f', $ip), $now + 13 + 600)), 'it ends by itself');
            // Lifted at once like any other ban (the dashboard's lists, unlist).
            foreach ([1, 2, 3, 4] as $i) {
                areaRun($shield, areaReq('/api/g', $ip), $now + 2000 + $i);
            }
            truthy($store->marked('ban:' . $ip . '@API-BAN', $now + 2005) > 0, 'banned again');
            truthy(Shield::liftBan($s, $store, $ip, $now + 2005), 'liftBan() lifts the area\'s ban too');
            same(0, $store->marked('ban:' . $ip . '@API-BAN', $now + 2005), 'lifted');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-02 a ban outside any block, as before: past a limit anywhere, banned on every page' => function (): void {
        $dir = areaDir();
        try {
            $s = areaSettings($dir, "[B-LIM] ban after 2 limits in 15m for 10m\nmatch /api/** {\n  [API-PACE] limit api 2/min\n}\n");
            $store = new MemoryStore();
            $shield = new Shield($s, $store);
            foreach ([1, 2, 3, 4] as $i) {
                areaRun($shield, areaReq('/api/' . $i), 1000.0 + $i);
            }
            same(['throttle', 'banned'], areaWhat($shield->decide(areaReq('/'), 1005.0)), 'banned on the whole site');
            truthy($store->marked('ban:203.0.113.9', 1005.0) > 0, 'under the address alone');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF03-01 an on-demand budget inside a match block: once spent, the block\'s next request waits (429) -- the rest of the site passes, the next window too' => function (): void {
        $dir = areaDir();
        try {
            $s = areaSettings($dir, "match /api/** {\n  [API-AUTH] limit auth 2/min on-demand\n}\n");
            same(true, $s->budgets['auth']->refuseSpent, 'compiled: the block\'s');
            $store = new MemoryStore();
            $shield = new Shield($s, $store);
            $ip = '203.0.113.9';
            foreach ([1, 2] as $i) {
                same('allow', $shield->consume('auth', areaReq('/api/login', $ip), 1000.0 + $i)->action, 'within the budget');
            }
            same(['allow', ''], areaWhat($shield->decide(areaReq('/api/x', $ip), 1003.0)), 'used up to its limit, not past: passes');
            same(['throttle', 'auth'], areaWhat($shield->consume('auth', areaReq('/api/login', $ip), 1004.0)), 'the third: past the limit');
            $d = $shield->decide(areaReq('/api/x', $ip), 1005.0);
            same(['throttle', 429, 'auth'], [$d->action, $d->status, $d->reason], 'spent: the next request to the area waits, whatever it is');
            truthy($d->retryAfter >= 1, "with Retry-After: $d->retryAfter");
            same('API-AUTH', $shield->explain($d, areaReq('/api/x', $ip)), 'the rule named');
            same(['allow', ''], areaWhat($shield->decide(areaReq('/', $ip), 1005.0)), 'the rest of the site passes');
            same(['allow', ''], areaWhat($shield->decide(areaReq('/api/x', '203.0.113.10'), 1005.0)), 'another client passes');
            $again = $shield->decide(areaReq('/api/x', $ip), 1006.0);
            same('throttle', $again->action, 'only looked at: waiting does not count');
            same(['allow', ''], areaWhat($shield->decide(areaReq('/api/x', $ip), 1000.0 + 120 + 5)), 'a window later: passes');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF03-01 an on-demand budget outside any block, as before: past its limit consume() says so, decide() does not look' => function (): void {
        $dir = areaDir();
        try {
            $s = areaSettings($dir, "[LOGINS] limit logins 2/min on-demand\n");
            $shield = new Shield($s, new MemoryStore());
            foreach ([1, 2, 3, 4] as $i) {
                $shield->consume('logins', areaReq('/login'), 1000.0 + $i);
            }
            same(['allow', ''], areaWhat($shield->decide(areaReq('/login'), 1005.0)), 'decide() lets the next request through');
            same('allow', $shield->decide(areaReq('/'), 1005.0)->action, 'and every other');
            // Written with "at <paths>" (the sign-in use case): an area's, but not a block's -- as before.
            $s = areaSettings($dir, "[LOGINS] limit logins 2/min on-demand at /login\n");
            same(false, $s->budgets['logins']->refuseSpent, 'not the block\'s behaviour');
            $shield = new Shield($s, new MemoryStore());
            foreach ([1, 2, 3, 4] as $i) {
                $shield->consume('logins', areaReq('/login'), 1000.0 + $i);
            }
            same(['allow', ''], areaWhat($shield->decide(areaReq('/login'), 1005.0)), 'with at <paths>: decide() lets the next request through');
            foreach ([['onDemand' => false], ['onDemand' => true, 'paths' => []]] as $wrong) {
                try {
                    Settings::from(['budgets' => ['x' => ['limit' => 2, 'refuseSpent' => true] + $wrong]]);
                    truthy(false, 'refuseSpent without an on-demand area: refused');
                } catch (\InvalidArgumentException $e) {
                    truthy(strpos($e->getMessage(), 'budgets.x.refuseSpent') !== false, $e->getMessage());
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-02 an on-demand budget\'s overrun inside a match block is a "limits" signal for the block\'s bans -- not for a ban outside it' => function (): void {
        $dir = areaDir();
        try {
            $s = areaSettings($dir, "[B-ALL] ban after 2 limits in 15m for 1h\nmatch /api/** {\n  [API-AUTH] limit auth 2/min on-demand\n  [API-BAN] ban after 2 limits in 15m for 10m\n}\n"
                . "match /shop/** {\n  [SHOP-BAN] ban after 1 limits in 15m for 10m\n}\n");
            $store = new MemoryStore();
            $shield = new Shield($s, $store);
            $ip = '203.0.113.9';
            foreach ([1, 2, 3] as $i) {
                $shield->consume('auth', areaReq('/api/login', $ip), 1000.0 + $i);
            }
            same(0, $store->marked("ban:$ip@API-BAN", 1004.0), 'one overrun: one signal, not yet');
            $shield->consume('auth', areaReq('/api/login', $ip), 1005.0);
            same(['throttle', 'banned'], areaWhat($shield->decide(areaReq('/api/x', $ip), 1006.0)), 'the second overrun: banned from the area');
            same(['allow', ''], areaWhat($shield->decide(areaReq('/', $ip), 1006.0)), 'not from the site');
            same(0, $store->marked("ban:$ip", 1006.0), 'the ban outside the block did not count them');
            same(0, $store->marked("ban:$ip@SHOP-BAN", 1006.0), 'nor another block\'s');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-02 the area\'s spent budget refusing in decide() is a "limits" signal for the block\'s bans only -- a ban outside any block does not count it' => function (): void {
        $dir = areaDir();
        try {
            $s = areaSettings($dir, "[B-ALL] ban after 2 limits in 15m for 1h\nmatch /api/** {\n  [API-AUTH] limit auth 2/min on-demand\n  [API-BAN] ban after 4 limits in 15m for 10m\n}\n");
            $store = new MemoryStore();
            $shield = new Shield($s, $store);
            $ip = '203.0.113.9';
            foreach ([1, 2, 3] as $i) {
                $shield->consume('auth', areaReq('/api/login', $ip), 1000.0 + $i);       // the third: the overrun (one signal)
            }
            same(['throttle', 'auth'], areaWhat(areaRun($shield, areaReq('/api/x', $ip), 1004.0)), 'spent: refused (the second signal)');
            areaRun($shield, areaReq('/api/x', $ip), 1005.0);                              // the third
            same(0, $store->marked("ban:$ip", 1006.0), 'the ban outside the block counted none of them');
            same(0, $store->marked("ban:$ip@API-BAN", 1006.0), 'the block\'s: three of four');
            areaRun($shield, areaReq('/api/x', $ip), 1006.0);                              // the fourth
            truthy($store->marked("ban:$ip@API-BAN", 1007.0) > 0, 'the block\'s ban counted them all');
            same(0, $store->marked("ban:$ip", 1007.0), 'still no ban of the whole site');
            same(['allow', ''], areaWhat($shield->decide(areaReq('/', $ip), 1007.0)), 'the rest of the site passes');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-02 the real path: failed sign-ins on an API path spend its budget -- then 429 there with Retry-After as JSON, then the area\'s ban; the rest of the site answers' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = areaDir();
        mkdir("$dir/docroot");
        file_put_contents("$dir/docroot/index.php", '<?php if (isset($_GET["fail"])) { \CjwNetwork\RequestShield\Shield::active()?->consume("auth"); } echo "ok";');
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nexempt none\nblock none\nno-limit requests\n"
            . "match /api/** {\n  api-path\n  [API-AUTH] limit auth 2/min on-demand\n  [API-BAN] ban after 3 limits in 15m for 10m\n}\n");
        $port = freePort();
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1', escapeshellarg("$dir/site.rules"),
            serverPhp(), escapeshellarg(rsEntry()), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri) use ($port): array {
                $body = @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10, 'header' => "User-Agent: Mozilla/5.0 Firefox/136.0\r\n"]]));
                $retry = '';
                $type = '';
                foreach ($http_response_header ?? [] as $h) {
                    if (stripos($h, 'Retry-After:') === 0) {
                        $retry = trim(substr($h, 12));
                    }
                    if (stripos($h, 'Content-Type:') === 0) {
                        $type = trim(substr($h, 13));
                    }
                }
                return [substr((string) ($http_response_header[0] ?? ''), 9, 3), $retry, $type, (string) $body];
            };
            same(['200', '200', '200'], [$get('/api/login?fail=1')[0], $get('/api/login?fail=1')[0], $get('/api/login?fail=1')[0]],
                'three failed sign-ins: the application answers (the third is past the budget: one signal)');
            [$status, $retry, $type, $body] = $get('/api/tools');
            truthy($status === '429' && (int) $retry >= 1, "spent: the next request to the area waits, also a good one: $status, Retry-After $retry");
            truthy(strpos($type, 'json') !== false && is_array(json_decode($body, true)), "as JSON on an API path: $type $body");
            same('200', $get('/')[0], 'the rest of the site answers');
            $get('/api/tools');                                                   // the third signal: banned from the area
            [$status, $retry] = $get('/api/tools');
            truthy($status === '429' && (int) $retry > 590 && (int) $retry <= 600, "then the area's ban, 10 minutes: $status, Retry-After $retry");
            same(['200', '200'], [$get('/')[0], $get('/news/1')[0]], 'and still the rest of the site answers');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
