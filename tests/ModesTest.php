<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\PassCookie;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** Modes (proposal 0004): off, monitor, enforce, strict; "monitor" for single rules; challenge … max-age. */

const MODES_SECRET = 'modes-secret-0123456789abcdef0123456789abcdef';

function modesDir(string $rules): string
{
    $dir = sys_get_temp_dir() . '/rshield-modes-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    file_put_contents("$dir/site.rules", $rules);
    return $dir;
}

function modesSettings(string $rules): Settings
{
    $dir = modesDir($rules);
    try {
        return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

function modesFail(string $rules, string $at, string $part): void
{
    $dir = modesDir($rules);
    try {
        RuleFile::read(["$dir/site.rules"]);
    } catch (RuleFileException $e) {
        truthy(strncmp($e->getMessage(), "$at: ", strlen($at) + 2) === 0, "starts with $at: " . $e->getMessage());
        truthy(strpos($e->getMessage(), $part) !== false, "says \"$part\": " . $e->getMessage());
        return;
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
    throw new TestFailure('accepted: ' . $rules);
}

/**
 * The real path: the rules as the site's rule file, protect() through
 * bootstrap.php on PHP's built-in server; an application that says what it was
 * told. $body gets a request function (status, headers, json) and the log.
 */
function withModes(string $rules, callable $body): void
{
    $dir = sys_get_temp_dir() . '/rshield-modes-e2e-' . getmypid() . '-' . mt_rand();
    mkdir($dir . '/docroot', 0700, true);
    file_put_contents($dir . '/docroot/index.php', '<?php if (isset($_GET["n"])) { $d = \CjwNetwork\RequestShield\Shield::active()?->consume("searches"); echo json_encode(["consumed" => $d?->action]); exit; } echo json_encode(["shield" => $_SERVER["REQUEST_SHIELD"] ?? null, "rule" => $_SERVER["REQUEST_SHIELD_RULE"] ?? null]);');
    file_put_contents($dir . '/site.rules', "set store file\nset store-dir $dir/store\nset secret " . MODES_SECRET . "\nset debug-header on\nset log $dir/shield.log\nset log-level flag\nexempt none\n" . $rules);
    $port = freePort();
    $cmd = sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
        escapeshellarg($dir . '/site.rules'), serverPhp(), escapeshellarg(rsEntry()),
        $port, escapeshellarg($dir . '/docroot'));
    $proc = proc_open($cmd, [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $get = function (string $uri, array $headers = []) use ($port): array {
            $h = '';
            foreach ($headers as $k => $v) {
                $h .= "$k: $v\r\n";
            }
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => $h, 'ignore_errors' => true, 'timeout' => 10]]);
            $body = @file_get_contents("http://127.0.0.1:$port$uri", false, $ctx);
            $status = 0;
            $head = [];
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                } elseif (preg_match('#^([^:]+):\s*(.*)$#', $line, $m)) {
                    $head[strtolower($m[1])] = $m[2];
                }
            }
            return ['status' => $status, 'headers' => $head, 'json' => json_decode((string) $body, true)];
        };
        $log = static fn (): array => array_values(array_filter(explode("\n", (string) @file_get_contents("$dir/shield.log"))));
        $body($get, $log);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

/** A pass cookie issued $ago seconds before $now (with the settings' pass lifetime). */
function modesPass(Settings $s, float $now, int $ago): string
{
    return (new PassCookie(MODES_SECRET, $s->challenge->bindUserAgent))->issue(IpAddress::bucket('203.0.113.7'), 'Mozilla/5.0 Test', (int) $now - $ago + $s->challenge->passTtl);
}

return [
    'RSF05-03 rule files: set mode, monitor before a rule, challenge … max-age -- and the mistakes' => function (): void {
        same('enforce', modesSettings("")->mode, 'the default');
        foreach (['off', 'monitor', 'strict'] as $m) {
            same($m, modesSettings("set mode $m\n")->mode);
        }
        modesFail("set mode loud\n", 'site.rules:1', 'mode is off, monitor, enforce, strict');
        $s = modesSettings("[SITE-OLD] monitor block /old/**   # the old API\nblock /gone/**\n");
        same(null, $s->monitor->monitor ?? null, 'one level');
        truthy($s->monitor !== null, 'the monitored rules, read once more');
        truthy(!in_array('#^/old(?:/.*)?$#', $s->blockedPaths, true) && in_array('#^/gone(?:/.*)?$#', $s->blockedPaths, true), 'enforced: without the monitored rule');
        truthy(in_array('#^/old(?:/.*)?$#', $s->monitor->blockedPaths, true) && in_array('#^/gone(?:/.*)?$#', $s->monitor->blockedPaths, true), 'watched: with it');
        same('block /old/**', $s->origin('monitor', 'SITE-OLD'), 'the rule as written');
        same('the old API', $s->origin('text', 'SITE-OLD'), 'with its description');
        same(null, modesSettings("block /x/**\n")->monitor, 'none: no second reading');
        modesSettings("monitor restrict /admin/** to 192.0.2.0/24\nmonitor allow POST /edit\nmonitor limit searches 10/min on-demand\nmonitor challenge /login\nmonitor query strict\nmatch /shop/** {\n  monitor block\n}\n");
        modesFail("monitor set mode off\n", 'site.rules:1', 'monitor <rule>');
        modesFail("monitor host example.org\n", 'site.rules:1', 'set mode monitor');
        modesFail("monitor query page int\n", 'site.rules:1', 'query strict');
        modesFail("monitor\n", 'site.rules:1', 'monitor <rule>');
        modesFail("monitor block\n", 'site.rules:1', 'needs at least one value');
        $a = modesSettings("challenge /login /account max-age 5m\nchallenge /other\n");
        same(['#^/login$#' => 300, '#^/account$#' => 300], $a->challenge->alwaysMaxAge);
        same(['#^/shop(?:/.*)?$#' => 60], modesSettings("match /shop/** {\n  challenge max-age 1m\n}\n")->challenge->alwaysMaxAge, 'inside a match block');
        same([], modesSettings("challenge /login max-age 5m\nchallenge /login\n")->challenge->alwaysMaxAge, 'written again without: without');
        modesFail("challenge /login max-age 5m /x\n", 'site.rules:1', 'max-age and its duration come last');
        modesFail("challenge /login max-age soon\n", 'site.rules:1', 'max-age is a duration');
        modesFail("challenge /login max-age 0\n", 'site.rules:1', 'at least one second');
    },
    'RSF05-03 strict: the check from a quarter of each limit, a pass for 15 minutes, twice the difficulty -- the site\'s own values win where it sets them' => function (): void {
        $s = modesSettings("set mode strict\nlimit requests 600/min\nlimit posts 20/min challenge-at 5\nlimit misses 60/min on-demand\nset difficulty-min 50000\nset difficulty-max 80000\n");
        same(150, $s->budgets['requests']->challengeAt, 'a quarter');
        same(5, $s->budgets['posts']->challengeAt, 'its own challenge-at');
        same(15, $s->budgets['misses']->challengeAt, 'on-demand too');
        same(900, $s->challenge->passTtl, '15 minutes');
        same(60, modesSettings("set mode strict\nset pass-ttl 1m\n")->challenge->passTtl, 'a shorter pass stays');
        same(80000, $s->challenge->difficultyMin, 'twice, at most the maximum');
        same(2, $s->uncachedWeight);
        same(null, modesSettings("limit requests 600/min\n")->budgets['requests']->challengeAt, 'enforce: as written');
        same(1, modesSettings("")->uncachedWeight);
        truthy(modesSettings("set mode strict\n")->challenge->searchEngines !== null, 'search engines stay welcome');
        try {
            Settings::from(['mode' => 'loud']);
            throw new TestFailure('accepted mode loud');
        } catch (InvalidArgumentException $e) {
            truthy(strpos($e->getMessage(), 'mode') !== false, $e->getMessage());
        }
    },
    'RSF05-03 strict: an address a cache must not keep counts twice' => function (): void {
        foreach (['enforce' => [10, 10], 'strict' => [10, 5]] as $mode => [$cached, $uncached]) {
            $s = modesSettings("set mode $mode\nset search-engines off\ncache-path /page/*\nlimit requests 10/min challenge-at 50\n");
            $shield = new Shield($s, new MemoryStore());
            $passed = static function (string $uri) use ($shield): int {
                for ($n = 0; $n < 30 && $shield->decide(creq($uri, [], 'GET', '203.0.113.' . strlen($uri)), 1000.0)->passes(); $n++) {
                }
                return $n;
            };
            same($cached, $passed('/page/a'), "$mode: a page a cache may keep");
            same($uncached, $passed('/random/x1'), "$mode: a made-up address");
        }
    },
    'RSF05-03 challenge … max-age: a pass issued too long ago is not enough there, and enough elsewhere' => function (): void {
        $s = modesSettings("set secret " . MODES_SECRET . "\nset search-engines off\nchallenge /login max-age 5m\nchallenge /account\n");
        $shield = new Shield($s, new MemoryStore());
        $settle = static function (string $uri, string $pass) use ($shield): string {
            $r = creq($uri, ['rsp' => $pass]);
            return $shield->settle($shield->decide($r, 1000.0), $r, 1000.0)['decision']->action;
        };
        $old = modesPass($s, 1000.0, 600);
        $fresh = modesPass($s, 1000.0, 60);
        same(Decision::CHALLENGE, $settle('/login', $old), 'issued 10 minutes ago: checked again');
        same(Decision::ALLOW_UNCACHED === $settle('/login', $fresh) || Decision::ALLOW === $settle('/login', $fresh), true, 'a minute ago: through');
        truthy($settle('/account', $old) !== Decision::CHALLENGE, 'elsewhere the long pass counts');
    },
    'RSF05-03 the rules page names the mode and the watched rules; trace says what they would do' => function (): void {
        needsPlugins();         // the shipped plugins' pages (the WAF's, the statistics'): not in the core single file
        $s = modesSettings("[SITE-OLD] monitor block /old/**   # the old API\n[SITE-CO] challenge /checkout max-age 5m\n");
        $html = \CjwNetwork\RequestShield\Waf\RulesPage::render($s, ['check' => ['url' => '/old/x', 'method' => 'GET', 'ip' => '198.51.100.7'], 'store' => new MemoryStore()]);
        truthy(strpos($html, '1 rule is only watched (monitor)') !== false, 'the mode line');
        truthy(strpos($html, 'Watched, not enforced') !== false && strpos($html, 'monitor block /old/**') !== false, 'the group, the rule as written');
        truthy(strpos($html, 'Watched: it gets &quot;not found&quot; (404)') !== false, 'the check: what the watched rule would do');
        truthy(strpos($html, 'a pass from the last 5 minutes') !== false, 'max-age in words');
        truthy(strpos(\CjwNetwork\RequestShield\Waf\RulesPage::render(modesSettings("set mode monitor\n")), 'Monitor mode (set mode monitor)') !== false, 'monitor mode');
        truthy(strpos(\CjwNetwork\RequestShield\Waf\RulesPage::render(modesSettings("set mode strict\n")), 'a pass for 15 minutes') !== false, 'strict');
        $t = (new \CjwNetwork\RequestShield\Inspector(modesSettings("set mode monitor\n"), new MemoryStore()))->trace(\CjwNetwork\RequestShield\Inspector::request('GET', '/.env', '198.51.100.7'), 1000.0);
        same('sees the page — monitor mode; enforced, it gets "not found" (404) — the site never sees it', $t['verdict']);
    },
    'RSF05-03 mode off: nothing checked, counted or logged -- the site runs' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withModes("set mode off\nlimit requests 2/min\n", function (callable $get, callable $log): void {
            $r = $get('/index.php/.env');
            same([200, 'allow'], [$r['status'], $r['json']['shield'] ?? null], 'a scanner\'s path reaches the site');
            for ($n = 0; $n < 4; $n++) {
                same(200, $get('/')['status'], 'no budget');
            }
            same(['consumed' => 'allow'], $get('/?n=1')['json'], 'consume() counts nothing');
            same([], $log(), 'no log');
        });
    },
    'RSF05-03 mode monitor: everything decided, counted and logged as it would be -- nobody refused, nothing cached' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withModes("set mode monitor\n[SITE-REQ] limit requests 3/min\n[SITE-S] limit searches 1/min on-demand\n", function (callable $get, callable $log): void {
            $r = $get('/index.php/.env');
            same(200, $r['status'], 'let through');
            same('allow-uncached', $r['json']['shield'] ?? null, 'and marked: a cache must not keep it');
            same('monitor reject blocked path; rule=SCAN-HIDDEN', $r['headers']['x-rs'] ?? null);
            for ($n = 0; $n < 4; $n++) {
                $r = $get('/');             // a refused request (.env) counted nothing: this is the 4th of 3
            }
            same(200, $r['status'], 'past the limit: through');
            truthy(strpos($r['headers']['x-rs'] ?? '', 'monitor throttle requests; rule=SITE-REQ') === 0, (string) ($r['headers']['x-rs'] ?? ''));
            $get('/?n=1');
            same(['consumed' => 'allow-uncached'], $get('/?n=1')['json'], 'consume(): past the limit, let through');
            $lines = implode("\n", $log());
            truthy(strpos($lines, ' monitor-reject 404 "blocked path" rule=SCAN-HIDDEN ') !== false, $lines);
            truthy(strpos($lines, ' monitor-throttle 429 "requests" rule=SITE-REQ ') !== false, 'counted as enforce would');
            truthy(strpos($lines, ' monitor-throttle 429 "searches" rule=SITE-S ') !== false, 'the on-demand budget too');
            truthy(strpos($lines, ' reject ') === false && strpos($lines, ' throttle ') === false, 'nothing enforced');
        });
    },
    'RSF05-03 monitor before a rule: logged, not enforced -- the rest as always, and a budget counted once' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $rules = "cache-path any\n[SITE-OLD] monitor block /old/**\n[SITE-GONE] block /gone/**\n[SITE-REQ] limit requests 6/min\n[SITE-REQW] monitor limit requests 2/min\n[SITE-S] monitor limit searches 1/min on-demand\n";
        withModes($rules, function (callable $get, callable $log): void {
            $r = $get('/old/api');                                          // requests: 1
            same([200, 'allow'], [$r['status'], $r['json']['shield'] ?? null], 'the watched rule refuses nobody');
            same('reject blocked path; rule=SITE-OLD', $r['headers']['x-rs-monitor'] ?? null, 'what it would have done');
            same(404, $get('/gone/x')['status'], 'the enforced one as always (refused: not counted)');
            same(['consumed' => 'allow'], $get('/?n=1')['json'] ?? null, 'a watched on-demand budget: 1 of 1');      // requests: 2
            $r = $get('/?n=1');                                             // requests: 3
            same(['consumed' => 'allow'], $r['json'] ?? null, '2 of 1: logged, nothing enforced');
            same('throttle requests; rule=SITE-REQW', $r['headers']['x-rs-monitor'] ?? null, 'the stricter watched limit (2)');
            $statuses = [];
            for ($n = 0; $n < 4; $n++) {
                $statuses[] = $get('/')['status'];                          // requests: 4, 5, 6, 7
            }
            same([200, 200, 200, 429], $statuses, 'the enforced limit (6): counted once, not twice');
            $lines = implode("\n", $log());
            truthy(strpos($lines, ' monitor-reject 404 "blocked path" rule=SITE-OLD ') !== false, $lines);
            truthy(strpos($lines, ' monitor-throttle 429 "requests" rule=SITE-REQW ') !== false, $lines);
            truthy(strpos($lines, ' monitor-throttle 429 "searches" rule=SITE-S ') !== false, $lines);
            truthy(strpos($lines, ' reject 404 "blocked path" rule=SITE-GONE ') !== false, 'the enforced block logged as always');
            truthy(strpos($lines, ' throttle 429 "requests" rule=SITE-REQ ') !== false, 'and the enforced limit');
        });
    },
];
