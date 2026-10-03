<?php

declare(strict_types=1);

/**
 * examples/exponential: the click demo, run as its README says (PHP's
 * built-in server with its router, and in a subdirectory without one). Every
 * numbered row on its front page is fetched and must answer what the row says.
 */

/** Starts the Exponential demo, runs $body with a request function, stops it. */
function withExponentialDemo(callable $body, string $prefix = ''): void
{
    if (!function_exists('proc_open')) {
        skip('no proc_open');
    }
    $var = sys_get_temp_dir() . '/rshield-expdemo-' . getmypid() . '-' . mt_rand();
    mkdir($var, 0700, true);
    $port = freePort();
    $root = dirname(__DIR__);
    $cmd = sprintf('EXP_DEMO_VAR=%s exec %s -S 127.0.0.1:%d %s > /dev/null 2>&1', escapeshellarg($var), serverPhp(), $port,
        $prefix === '' ? escapeshellarg($root . '/examples/exponential/router.php') : '-t ' . escapeshellarg($root));
    $proc = proc_open($cmd, [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body(static function (string $method, string $uri) use ($port, $prefix): array {
            $opts = ['method' => $method, 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0];
            if ($method === 'POST') {
                // As a browser sends a form: from the page's own origin (post-origin same).
                $opts['header'] = "Content-Type: application/x-www-form-urlencoded\r\nOrigin: http://127.0.0.1:$port\r\n";
                $opts['content'] = 'demo=1';
            }
            // The page's links are relative to its <base href> (the demo's own address).
            $target = $uri[0] === '/' ? $prefix . $uri : $prefix . '/' . ($uri === './' ? '' : $uri);
            $body = @file_get_contents("http://127.0.0.1:$port" . str_replace([' ', "'", '<', '>'], ['%20', '%27', '%3C', '%3E'], $target),
                false, stream_context_create(['http' => $opts]));
            $status = 0;
            $shield = '';
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                }
                if (stripos($line, 'X-RS:') === 0) {
                    $shield = trim(substr($line, 5));
                }
            }
            return ['status' => $status, 'shield' => $shield, 'body' => (string) $body];
        });
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($var));
    }
}

$everyRow = static function (string $prefix): void {
    withExponentialDemo(static function (callable $get): void {
        $front = $get('GET', '/');
        same(200, $front['status'], 'the front page');
        preg_match_all('#<tr id="(t\d+-\d+)">.*?<td class="exp">(.*?)</td>.*?data-m="([A-Z0-9]+)" data-u="([^"]+)"#s', $front['body'], $rows, PREG_SET_ORDER);
        truthy(count($rows) >= 30, 'the numbered tests: ' . count($rows));
        foreach ($rows as [, $id, $expected, $method, $href]) {
            $href = html_entity_decode($href, ENT_QUOTES);
            $rule = preg_match('#<small>([A-Z0-9-]+)</small>#', $expected, $m) ? $m[1] : '';
            $check = strpos($expected, 'the check') !== false;
            $status = $check ? 0 : (int) strip_tags($expected);
            $times = $method === 'SEARCH11' ? 11 : 1;
            for ($i = 0; $i < $times; $i++) {
                $r = $get($method === 'POST' ? 'POST' : 'GET', $href);
            }
            if ($check) {
                truthy(strncmp($r['shield'], 'challenge', 9) === 0, "$id $href: the check, got {$r['status']} {$r['shield']}");
            } else {
                same($status, $r['status'], "$id $href ({$r['shield']})");
            }
            if ($rule !== '' && $rule !== 'EXP-SYSVIEW-OK' && $rule !== 'EXP-DOWNLOAD') {
                truthy(substr($r['shield'], -strlen("rule=$rule")) === "rule=$rule", "$id $href: decided by $rule, got {$r['shield']}");
            }
        }
    }, $prefix);
};

return [
    'the Exponential demo: every numbered row answers what it says' => static fn () => $everyRow(''),
    'the Exponential demo in a subdirectory, without rewrite rules: the same' => static fn () => $everyRow('/examples/exponential/index.php'),
    'the Exponential demo: the pages behind the shield -- an article, the search with its time filter, the contact form' => static function (): void {
        withExponentialDemo(static function (callable $get): void {
            truthy(strpos($get('GET', '/news/2026/fit-and-healthy')['body'], 'the shield let it through:</b> allow') !== false, 'an article, and the decision shown');
            $search = $get('GET', '/content/search?SearchText=yoga&SearchDate=2');
            truthy(strpos($search['body'], '<option value="2" selected>the last week</option>') !== false && strpos($search['body'], 'This search was counted') !== false, 'the search: its time filter, counted');
            same(405, $get('POST', '/kontakt')['status'], 'a POST to the contact page itself: no form lives there');
            truthy(strpos($get('GET', '/kontakt')['body'], 'action="content/action"') !== false, 'the contact form posts to content/action, as Exponential\'s (relative to the demo)');
            truthy(strpos($get('GET', '/')['body'], '<a href="content/view/full/2">') !== false, 'the links are relative');
            $article = $get('GET', '/news/x?utm_source=nl')['body'];
            truthy(strpos($article, '<summary>Request headers</summary><pre>GET /news/x?utm_source=nl') !== false
                && strpos($article, 'X-RS: allow-uncached query parameter; rule=EXP-CACHE-Q') !== false, 'every page shows the request\'s headers and the answer\'s');
        });
    },
];
