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
            return [$status, $xrs, $body, $type];
        });
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
        $end = strpos($src, "\n}\n", (int) $start);
        eval(substr($src, (int) $start, (int) $end - (int) $start + 3));
    }
    return showcaseTries(dirname(__DIR__) . '/examples/showcase/showcase.rules');
}

return [
    'RSF05-04 the showcase: request-shield test decides every try its page shows, and they all hold' => function (): void {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' test ' . escapeshellarg(dirname(__DIR__) . '/examples/showcase/showcase.rules') . ' 2>&1', $out, $code);
        same(0, $code, implode("\n", array_slice($out, -5)));
        truthy(strpos(implode("\n", $out), 'without an example') === false, 'every rule has its example: ' . implode("\n", array_slice($out, -2)));
        truthy(count(showcaseTriesForTest()) >= 20, 'the page reads the tries from the rules');
    },
    'RSF05-04 the showcase speaks German too: every try and every explained rule has its German sentence' => function (): void {
        $texts = require dirname(__DIR__) . '/examples/showcase/texts.php';
        foreach (showcaseTriesForTest() as $t) {
            if ($t['section'] !== 'SHOW-TRY') {
                truthy(isset($texts['de']['tries'][$t['text']]), 'German for the try: ' . $t['text']);
            }
        }
        $on = false;
        foreach (file(dirname(__DIR__) . '/examples/showcase/showcase.rules', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $on = $on || strncmp($line, 'ids ', 4) === 0;
            if (strncmp($line, "# The showcase's own settings", 29) === 0) {
                break;
            }
            if (!$on || strncmp($line, 'expect ', 7) === 0) {
                continue;
            }
            $note = strncmp(ltrim($line), '#', 1) === 0 ? trim(ltrim(trim($line), '#')) : (strpos($line, ' # ') !== false ? trim(substr($line, (int) strpos($line, ' # ') + 3)) : '');
            if ($note !== '') {
                truthy(isset($texts['de']['notes'][$note]), 'German for the rule\'s explanation: ' . $note);
            }
        }
        same(array_keys($texts['de']), array_keys($texts['en']), 'the same words in both languages');
    },
    'RSF05-04 the showcase as its README runs it: the page, its own files, and every try a real request answered as its card says' => function (): void {
        withShowcase(static function (callable $get): void {
            [$st, , $page] = $get('GET', '/?lang=de');
            same(200, $st, 'the front page');
            truthy(strpos($page, 'Bots raus.') !== false && strpos($page, 'showcase.rules') !== false, 'in German, with the rules');
            truthy(strpos($get('GET', '/?lang=en')[2], 'Bots out.') !== false, 'in English');
            [$st, , , $type] = $get('GET', '/assets/showcase.css');
            truthy($st === 200 && strpos($type, 'text/css') === 0, 'its own styles, as CSS');
            same(200, $get('GET', '/assets/vendor/bootstrap/bootstrap.min.css')[0], 'Bootstrap from the page\'s own folder, not from elsewhere');
            truthy(preg_match('#(src|href)="(https?:)?//#', $page) !== 1, 'nothing loaded from another host');
            foreach (showcaseTriesForTest() as $t) {
                if ($t['times'] > 1 || $t['pass']) {
                    continue;           // the burst and the pass: decided by request-shield test above
                }
                $uri = (string) preg_replace('#^[a-z]+://[^/]+#', '', $t['url']);
                if ($t['headers'] !== []) {
                    // A form from another website: the page asks the server to decide it.
                    $j = json_decode($get('GET', '/__try?n=' . $t['n'])[2], true);
                    $got = in_array($j['action'] ?? '', ['allow', 'allow-uncached'], true) ? 'answered' : (string) ($j['status'] ?? 0);
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
        });
    },
];
