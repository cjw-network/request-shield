<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\ChallengePage;
use CjwNetwork\RequestShield\Challenge\Gate;
use CjwNetwork\RequestShield\Challenge\PassCookie;
use CjwNetwork\RequestShield\Challenge\ProofOfWork;

/**
 * Every transmitted byte counts (ADR 0014): the limits, as numbers. A change
 * that makes a cookie, a header or the check page bigger fails here first.
 * The bench prints the same sizes.
 */

const WIRE_PASS_COOKIE_MAX = 48;         // "rsp=" + the value: on every request of a checked visitor, until 2100 and beyond
const WIRE_SET_COOKIE_MAX = 104;         // the Set-Cookie value that hands out the pass, "; Secure" included
const WIRE_PAGE_MAX = 8704;              // the check page, plain
const WIRE_PAGE_GZIP_MAX = 4096;         // the check page as the web server sends it (gzip -6)
const WIRE_RESEND_PAGE_GZIP_MAX = 4224;  // the same carrying a small form to send again
const WIRE_PAGE_HEADERS_MAX = 80;        // the header lines the shield adds to the check page, CRLF included
const WIRE_PASS_HEADERS_MAX = 176;       // the header lines the shield adds to the answer that hands out the pass

/** The lines of an answer that come from the shield (the built-in server adds Host, Date, Connection, X-Powered-By, Content-type). */
function wireShieldLines(array $lines): array
{
    return array_values(array_filter($lines, fn (string $l) => preg_match('/^(Set-Cookie|Cache-Control|X-Robots-Tag|Vary|Retry-After|Allow|X-RS|X-Request-Shield)/i', $l) === 1));
}

function wireBytes(array $lines): int
{
    return array_sum(array_map(fn (string $l) => strlen($l) + 2, $lines));
}

function wireSolve(array $c): string
{
    for ($n = 0; $n <= $c['maxnumber']; $n++) {
        if (hash('sha256', $c['salt'] . $n) === $c['challenge']) {
            return rtrim(strtr(base64_encode((string) json_encode(['algorithm' => $c['algorithm'], 'challenge' => $c['challenge'], 'number' => $n, 'salt' => $c['salt'], 'signature' => $c['signature'], 'took' => 1])), '+/', '-_'), '=');
        }
    }
    throw new TestFailure('no solution');
}

function withWireServer(callable $body): void
{
    $dir = sys_get_temp_dir() . '/rshield-wire-' . getmypid() . '-' . mt_rand();
    mkdir($dir . '/docroot', 0700, true);
    file_put_contents($dir . '/docroot/index.php', '<?php echo json_encode(["shield" => $_SERVER["REQUEST_SHIELD"] ?? null]);');
    $config = ['store' => 'file', 'storeDir' => $dir . '/store', 'exempt' => ['ips' => []], 'budgets' => ['requests' => ['limit' => 1000, 'window' => 60]],
        'challenge' => ['alwaysPaths' => ['#^/login$#'], 'difficulty' => ['min' => 1000, 'max' => 1000]]];
    file_put_contents($dir . '/config.php', '<?php return ' . var_export($config, true) . ';');
    $port = freePort();
    $cmd = sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
        escapeshellarg($dir . '/config.php'), serverPhp(), escapeshellarg(rsEntry()), $port, escapeshellarg($dir . '/docroot'));
    $proc = proc_open($cmd, [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body(function (string $uri, array $headers = []) use ($port): array {
            $h = '';
            foreach ($headers as $k => $v) {
                $h .= "$k: $v\r\n";
            }
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => $h, 'ignore_errors' => true, 'timeout' => 10]]);
            $body = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, $ctx);
            $lines = $http_response_header ?? [];
            $status = preg_match('#^HTTP/\S+ (\d+)#', $lines[0] ?? '', $m) ? (int) $m[1] : 0;
            return ['status' => $status, 'body' => $body, 'lines' => array_slice($lines, 1)];
        });
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

return [
    'RSF3.2 the pass cookie: at most 48 bytes with its name, today and in 2100' => function (): void {
        $p = new PassCookie(str_repeat('s', 48));
        foreach ([time() + 3600, 4102444800 + 3600] as $expires) {     // 2100-01-01 is 7 base36 digits
            $v = $p->issue('203.0.113.7', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36', $expires);
            truthy(strlen('rsp=' . $v) <= WIRE_PASS_COOKIE_MAX, 'rsp=' . $v . ' is ' . strlen('rsp=' . $v) . ' bytes');
            truthy(strlen(Gate::cookie('rsp', $v, 3600, true)) <= WIRE_SET_COOKIE_MAX, 'Set-Cookie: ' . Gate::cookie('rsp', $v, 3600, true) . ' is ' . strlen(Gate::cookie('rsp', $v, 3600, true)) . ' bytes');
        }
    },
    'RSF3.2 the check page: the plain and the gzipped size, with and without a form to send again' => function (): void {
        $task = (new ProofOfWork(str_repeat('s', 48)))->create('203.0.113.7', 500000, 2000000000);
        $page = ChallengePage::render($task, 'rss', true);
        truthy(strlen($page) <= WIRE_PAGE_MAX, 'the page is ' . strlen($page) . ' bytes');
        truthy(strlen((string) gzencode($page, 6)) <= WIRE_PAGE_GZIP_MAX, 'the page is ' . strlen((string) gzencode($page, 6)) . ' bytes gzipped');
        $resend = ChallengePage::render($task, 'rss', true, [], ['action' => '/comment', 'fields' => [['comment', 'Hello world'], ['token', 'abc']]]);
        truthy(strlen((string) gzencode($resend, 6)) <= WIRE_RESEND_PAGE_GZIP_MAX, 'the page with a form is ' . strlen((string) gzencode($resend, 6)) . ' bytes gzipped');
    },
    'RSF3.2 on the wire: a pass carries no X-RS header and no cookie; the check page and the pass answer stay within their header budgets' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withWireServer(function (callable $get): void {
            $r = $get('/page');
            same(200, $r['status'], 'a passing request');
            same([], wireShieldLines($r['lines']), 'nothing of the shield in the answer, debug-header off: ' . implode(' | ', $r['lines']));
            truthy(preg_grep('/^X-R(S|equest-Shield)/i', $r['lines']) === [], 'no X-RS* header');

            $r = $get('/login');
            same(429, $r['status'], 'the check page');
            $lines = wireShieldLines($r['lines']);
            truthy(wireBytes($lines) <= WIRE_PAGE_HEADERS_MAX, 'the check page\'s headers are ' . wireBytes($lines) . ' bytes: ' . implode(' | ', $lines));
            truthy(preg_grep('/^Set-Cookie/i', $lines) === [], 'the page sets no cookie itself (the script does, once solved)');
            truthy(preg_match('/var RS=(\{.*?\});\(function/s', $r['body'], $m) === 1, 'the task is in the page');
            $rs = json_decode($m[1], true);
            same('rss', $rs['cookie'], 'the solution cookie\'s name');

            $r = $get('/login', ['Cookie' => 'rss=' . wireSolve($rs['c'])]);
            same(200, $r['status'], 'solved: through');
            $lines = wireShieldLines($r['lines']);
            truthy(wireBytes($lines) <= WIRE_PASS_HEADERS_MAX, 'the pass answer\'s headers are ' . wireBytes($lines) . ' bytes: ' . implode(' | ', $lines));
            $pass = preg_grep('/^Set-Cookie: rsp=/', $lines);
            same(1, count($pass), 'one pass cookie: ' . implode(' | ', $lines));
            truthy(preg_match('/^Set-Cookie: (rsp=[^;]+)/', (string) reset($pass), $m) === 1 && strlen($m[1]) <= WIRE_PASS_COOKIE_MAX, 'the pass cookie as sent: ' . $m[1]);
            truthy(preg_grep('/^X-R(S|equest-Shield)/i', $r['lines']) === [], 'no X-RS* header on the pass answer either');

            $r = $get('/page', ['Cookie' => $m[1]]);
            same(200, $r['status'], 'with the pass');
            same([], wireShieldLines($r['lines']), 'and again nothing of the shield in the answer: ' . implode(' | ', $r['lines']));
        });
    },
];
