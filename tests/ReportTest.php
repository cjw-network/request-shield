<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Log;
use CjwNetwork\RequestShield\Describe;
use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\LogStats;
use CjwNetwork\RequestShield\Report\RulesPage;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

function reportSettings(string $rules, ?string $log = null): Settings
{
    $dir = sys_get_temp_dir() . '/rshield-report-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    file_put_contents("$dir/site.rules", $rules . ($log !== null ? "set log $log\nset log-level flag\n" : ''));
    try {
        return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

/** A trace's step by its name. */
function step(array $trace, string $check): array
{
    foreach ($trace['steps'] as $st) {
        if ($st['check'] === $check) {
            return $st;
        }
    }
    throw new TestFailure("no step \"$check\"");
}

/** @return array<string, string> check => state */
function steps(array $trace): array
{
    $out = [];
    foreach ($trace['steps'] as $s) {
        $out[$s['check']] = $s['state'];
    }
    return $out;
}

const REPORT_RULES = "host www.example.org\nblock /wp-admin/**\nrestrict /admin/** to 192.0.2.0/24\nallow POST /contact\ncache-path / /page/*\ncache-query page\nlimit requests 5/min challenge-at 3\nchallenge /login\n";

return [
    'RSF06-01 trace: every check as a step, in order; after a refusal the rest is not checked' => function (): void {
        $i = new Inspector(reportSettings(REPORT_RULES), new MemoryStore());
        $t = $i->trace(Inspector::request('GET', 'https://www.example.org/wp-admin/install.php', '198.51.100.7'), 1000.0);
        same(Decision::REJECT, $t['decision']->action);
        same('site.rules:2', $t['rule']);
        same('gets "not found" (404) — the site never sees it', $t['verdict']);
        same(['Kept out' => 'pass', 'Public lists' => 'pass', 'Banned' => 'pass', 'Kind of request' => 'pass', 'Size' => 'pass', 'Disguised address' => 'pass', 'Website name' => 'pass', 'Addresses only attackers ask for' => 'stop',
            'Where forms may be sent' => 'skip', 'Where forms come from' => 'skip', 'Areas for certain visitors' => 'skip', 'Known crawlers' => 'skip', 'Known parameters' => 'skip', 'Attack patterns' => 'skip', 'May a cache keep the answer?' => 'skip', 'Pace: "requests"' => 'skip', 'Browser check' => 'skip'], steps($t));
        same('refused: /wp-admin/**', step($t, 'Addresses only attackers ask for')['text'], 'the pattern as it was written');
    },
    'RSF06-01 trace: the other outcomes, in plain words' => function (): void {
        $i = new Inspector(reportSettings(REPORT_RULES), new MemoryStore());
        $at = static fn (string $m, string $u, string $ip = '198.51.100.7'): array => $i->trace(Inspector::request($m, $u, $ip), 1000.0);
        same('allow', $at('GET', 'https://www.example.org/page/about?page=2')['decision']->action);
        same('sees the page — a cache may keep it', $at('GET', 'https://www.example.org/')['verdict']);
        $t = $at('GET', 'https://www.example.org//ADMIN/users');
        same(['reject', 403, 'site.rules:3'], [$t['decision']->action, $t['decision']->status, $t['rule']], 'restricted, sneaked');
        truthy(strpos(step($t, 'Areas for certain visitors')['text'], 'only for 192.0.2.0/24') !== false, step($t, 'Areas for certain visitors')['text']);
        same('allow', $at('GET', 'https://www.example.org/admin/', '192.0.2.9')['decision']->action === 'allow-uncached' ? 'allow' : 'x', 'allowed from the office');
        same(405, $at('POST', 'https://www.example.org/page/about')['decision']->status);
        same('allow-uncached', $at('POST', 'https://www.example.org/contact')['decision']->action);
        same(404, $at('GET', 'https://evil.example/')['decision']->status, 'an unknown host');
        $t = $at('GET', 'https://www.example.org/login');
        same('challenge', $t['decision']->action);
        same('note', steps($t)['Browser check']);
        same('wp-admin', explode('/', Inspector::request('GET', 'wp-admin/x', '1.2.3.4')->path)[1], 'a bare path is a path');
    },
    'RSF06-01 trace: counts nothing, but shows the count' => function (): void {
        $s = reportSettings(REPORT_RULES);
        $store = new MemoryStore();
        $shield = new Shield($s, $store);
        $r = Request::fromServer(['REQUEST_URI' => '/', 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'www.example.org']);
        for ($n = 0; $n < 3; $n++) {
            $shield->decide($r, 1000.0);
        }
        $i = new Inspector($s, $store);
        for ($n = 0; $n < 5; $n++) {
            $t = $i->trace(Inspector::request('GET', 'https://www.example.org/', '198.51.100.7'), 1000.0);
        }
        truthy(strpos(step($t, 'Pace: "requests"')['text'], '4 of 5 per minute') !== false, 'three counted + this one: ' . step($t, 'Pace: "requests"')['text']);
        same('note', step($t, 'Pace: "requests"')['state'], 'past challenge-at 3');
        same('challenge', $t['decision']->action);
        same(3.0, round($store->peek('requests:198.51.100.7', 60, 1000.0)), 'the traces counted nothing');
    },
    'RSF06-01 describe: built-in patterns, durations, verdicts' => function (): void {
        same('backups, dumps and archives: .bak, .old, .sql, .zip, .tar.gz, .log …', Describe::builtIn(\CjwNetwork\RequestShield\Config::scannerPaths()[1]));
        same(['minute', '10 seconds', '2 hours', '1 minute', '1 day'], [Describe::duration(60), Describe::duration(10), Describe::duration(7200), Describe::span(60), Describe::span(86400)]);
        same('has to wait 7 seconds (429 Too Many Requests)', Describe::verdict(Decision::throttle('x', 7)));
        same('regex ^/x$', Describe::pattern(Settings::from([]), '#^/x$#'), 'a PHP array pattern: the expression');
    },
    'RSF06-01 log stats: count and last time per rule, the latest lines, only the last 24 hours' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-stats-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            $s = Settings::from(['log' => ['file' => "$dir/shield.log"]]);
            $r = Request::fromServer(['REQUEST_URI' => '/.env', 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'example.org']);
            Log::write($s, $r, Decision::reject(404, 'blocked path'), 'SCAN-HIDDEN', 1000.0);
            Log::write($s, $r, Decision::reject(404, 'blocked path'), 'SCAN-HIDDEN', 200000.0);
            Log::write($s, $r, Decision::reject(404, 'blocked path'), 'SCAN-HIDDEN', 200100.0);
            Log::write($s, $r, Decision::challenge('requests'), 'site.rules:7', 200200.0);
            file_put_contents("$dir/shield.log", "garbage line\n", FILE_APPEND);
            $st = LogStats::read("$dir/shield.log", 200000 - 86400);
            same(['count' => 2, 'last' => 200100], $st['rules']['SCAN-HIDDEN'], 'the old one is outside the 24 hours');
            same(['reject' => 2, 'challenge' => 1], $st['actions']);
            same('site.rules:7', $st['recent'][0]['rule'], 'newest first');
            same('http://example.org/.env', $st['recent'][0]['url']);
            same(0, LogStats::read(null, 0)['lines']);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-01 the page: rules in plain words, origins, counts, the check -- everything escaped' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-page-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            $s = reportSettings(REPORT_RULES, "$dir/shield.log");
            $r = Request::fromServer(['REQUEST_URI' => '/<script>x', 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'www.example.org', 'HTTP_USER_AGENT' => '<b>bot']);
            Log::write($s, $r, Decision::reject(403, 'restricted'), 'site.rules:3', time() - 30);
            $html = RulesPage::render($s, ['check' => ['url' => 'https://www.example.org/admin/"><script>alert(1)</script>', 'method' => 'GET', 'ip' => '198.51.100.7'], 'action' => '/rules', 'store' => new MemoryStore()]);
            truthy(strpos($html, '<script>alert(1)') === false && strpos($html, '<script>x') === false, 'nothing from a request or the log is markup');
            truthy(strpos($html, 'This visitor gets no access (403)') !== false, 'the verdict');
            truthy(strpos($html, '/wp-admin/**') !== false, 'rules as written');
            truthy(strpos($html, 'How does it work — and what does it bring?') !== false, 'the browser check explained');
            truthy(strpos($html, 'hidden files and folders: .env, .git') !== false, 'the built-ins in words');
            truthy(strpos($html, '/admin/** — only for 192.0.2.0/24') !== false, 'the restricted area');
            truthy(preg_match('#site\.rules:3</code></td><td class="hits"[^>]*><span class="badge">1×</span>#', $html) === 1, 'how often the rule decided');
            truthy(strpos($html, 'refused (403): an area only for certain addresses') !== false, 'the latest activity in words');
            truthy(strpos($html, 'value="https://www.example.org/admin/&quot;&gt;&lt;script&gt;') !== false, 'the form keeps the address, escaped');
            truthy(strpos($html, '&quot;requests&quot;: 5 requests per minute, the browser check from 3, then a pause') !== false, 'the budget in words');
            $plain = RulesPage::render(Settings::from([]));
            truthy(strpos($plain, 'No log: <code>set log') !== false, 'without a log it says how to get one');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-01 diagrams: valid SVG, the path of a request, everything escaped; the files in docs/ are current' => function (): void {
        $i = new Inspector(reportSettings(REPORT_RULES), new MemoryStore());
        $t = $i->trace(Inspector::request('GET', 'https://www.example.org/wp-admin/<script>', '198.51.100.7'), 1000.0);
        $svg = \CjwNetwork\RequestShield\Report\Diagram::trace($t, 'GET /wp-admin/<script>');
        truthy(@simplexml_load_string($svg) !== false, 'well-formed');
        truthy(strpos($svg, '<script') === false, 'nothing from the request is markup');
        same(1, substr_count($svg, 'class="stop"'), 'one refusing check');
        same(count(array_filter($t['steps'], static fn (array $st): bool => $st['state'] === 'skip')), substr_count($svg, 'class="skip"'), 'the rest not checked, a circle each');
        truthy(strpos($svg, '>404</text>') !== false, 'where it ends: the shield\'s answer');
        $ok = \CjwNetwork\RequestShield\Report\Diagram::trace($i->trace(Inspector::request('GET', 'https://www.example.org/', '198.51.100.7'), 1000.0), 'GET /');
        truthy(strpos($ok, '>Your site</text>') !== false && strpos($ok, 'class="stop"') === false, 'a passing request: the site');
        foreach (['browserCheck' => 'browser-check.svg', 'overview' => 'overview.svg'] as $method => $file) {
            $drawn = \CjwNetwork\RequestShield\Report\Diagram::$method();
            truthy(@simplexml_load_string($drawn) !== false, "$method: well-formed");
            same($drawn . "\n", (string) file_get_contents(dirname(__DIR__) . "/docs/explained/$file"),
                "docs/explained/$file is current (php -r 'require \"bootstrap.php\"; echo CjwNetwork\\RequestShield\\Report\\Diagram::$method(), \"\\n\";' > docs/explained/$file)");
        }
    },
];
