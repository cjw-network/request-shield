<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\ErrorPage;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Responder;
use CjwNetwork\RequestShield\Texts;

/**
 * The error pages (proposal 0030, RSF05-06): the shield's own page for a
 * refusal -- the check page's frame, a title and one sentence in the
 * visitor's language, never why; self-contained, with a CSP that lets it load
 * nothing; an API gets JSON.
 */

/** What Responder::send() writes, with the headers it would send. @return array{0: string, 1: list<string>} */
function errorSend(Decision $d, array $texts = [], ?string $home = null, ?string $logo = null, bool $api = false, string $method = 'GET'): array
{
    $r = Request::fromServer(['REQUEST_URI' => '/x', 'REQUEST_METHOD' => $method]);
    ob_start();
    (new Responder())->send($d, $r, false, null, null, $texts, $home, null, $logo, $api);
    $body = (string) ob_get_clean();
    [$builtIn, $json] = [!$api, $api];
    return [$body, Responder::headerLines($d, false, null, $builtIn, $json)];
}

return [
    'RSF05-06 the built-in page: a title and one sentence per status, in the visitor\'s language -- never the reason, the rule or the pattern' => function (): void {
        foreach ([400 => 'The address could not be read.', 403 => 'This address is not open to you.', 404 => 'This address does not exist here.',
            405 => 'This kind of request is not taken at this address.', 414 => 'The address is longer than this site takes.', 431 => 'The request carries more than this site takes.'] as $status => $sentence) {
            [$html] = errorSend(Decision::reject($status, 'blocked path SECRET-RULE'));
            truthy(strpos($html, '<p>' . htmlspecialchars($sentence, ENT_QUOTES) . '</p>') !== false, "$status: $sentence");
            truthy(strpos($html, 'blocked path') === false && strpos($html, 'SECRET-RULE') === false, "$status: never why");
        }
        [$html] = errorSend(Decision::throttle('requests', 42), Texts::all('de'));
        truthy(strpos($html, '<h1>Zu viele Anfragen</h1>') !== false && strpos($html, 'Bitte warten Sie 42 Sekunden') !== false && strpos($html, 'lang="de"') !== false, 'a pause: how long, in German');
        [$html] = errorSend(Decision::reject(404, 'x'), Texts::all('en', ['not-found-text' => 'Gone <fishing> & co.']));
        truthy(strpos($html, 'Gone &lt;fishing&gt; &amp; co.') !== false, 'a site\'s own sentence, escaped');
    },
    'RSF05-06 the built-in page loads nothing and runs nothing: inline CSS and SVG, a :-( or the site\'s logo, a CSP of its own; the way home escaped' => function (): void {
        [$html, $headers] = errorSend(Decision::reject(404, 'x'), [], '/start?a=1&b="2"');
        truthy(strpos($html, '<script') === false && preg_match('#(src|href)="https?:#', $html) !== 1 && strpos($html, '<link') === false, 'nothing loaded, nothing run');
        truthy(strpos($html, '<g class="fc">') !== false, 'the :-(, drawn');
        truthy(strpos($html, '<a href="/start?a=1&amp;b=&quot;2&quot;">To the home page</a>') !== false, 'home, escaped');
        truthy(in_array("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'none'", $headers, true), 'its CSP');
        [$html] = errorSend(Decision::reject(404, 'x'), [], null, '<svg class="logo"></svg>');
        truthy(strpos($html, '<svg class="logo"></svg>') !== false && strpos($html, '<g class="fc">') === false, 'the site\'s logo instead');
        same(false, (bool) preg_grep('/^Content-Security-Policy/', Responder::headerLines(Decision::reject(404, 'x'))), 'a page of the site\'s own or the check page: no CSP of the shield\'s');
        [$html] = errorSend(Decision::reject(404, 'x'), [], null, null, false, 'HEAD');
        same('', $html, 'HEAD: the headers only');
    },
    'RSF05-06 a program gets JSON: status, what it is, how long to wait -- the same headers otherwise' => function (): void {
        [$body, $headers] = errorSend(Decision::throttle('requests', 30), [], null, null, true);
        same(['status' => 429, 'error' => 'too many', 'retryAfter' => 30], json_decode($body, true));
        truthy(in_array('Content-Type: application/json; charset=utf-8', $headers, true) && in_array('Retry-After: 30', $headers, true) && !preg_grep('/^Content-Security-Policy/', $headers), implode(' | ', $headers));
        same(['status' => 404, 'error' => 'not found'], ErrorPage::json(Decision::reject(404, 'x')));
    },
    'RSF05-06 end to end: a scanner\'s request gets the page with its CSP and a reference, a program the JSON -- the same references in the log' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = sys_get_temp_dir() . '/rs-err-' . getmypid() . '-' . mt_rand();
        mkdir("$dir/docroot", 0700, true);
        file_put_contents("$dir/docroot/index.php", '<?php echo "the site";');
        file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nset log $dir/shield.log\napi-path /api/**\n");
        $port = freePort();
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
            escapeshellarg("$dir/site.rules"), serverPhp(), escapeshellarg(rsEntry()), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $uri, array $headers = []) use ($port): array {
                $h = '';
                foreach ($headers as $k => $v) {
                    $h .= "$k: $v\r\n";
                }
                $out = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['header' => $h, 'ignore_errors' => true, 'timeout' => 10]]));
                return [(int) substr((string) ($http_response_header[0] ?? ''), 9, 3), implode("\n", $http_response_header ?? []), $out];
            };
            [$status, $headers, $body] = $get('/index.php/.env', ['Accept-Language' => 'de']);
            truthy($status === 404 && strpos($body, 'Diese Adresse gibt es hier nicht.') !== false && strpos($headers, "Content-Security-Policy: default-src 'none'") !== false, "$status $headers");
            // The reference: on the page, and the same in the log line (0030 phase 3).
            truthy(preg_match('#<p class="ref">Referenz ([2-9A-Z]{4}-[2-9A-Z]{4}) · \d\d:\d\d UTC</p>#', $body, $ref) === 1, 'a reference on the page: ' . $body);
            [$status, $headers, $body] = $get('/index.php/api/.env', ['Accept' => 'application/json']);
            $json = (array) json_decode($body, true);
            truthy($status === 404 && ($json['error'] ?? null) === 'not found' && preg_match('/^[2-9A-Z]{4}-[2-9A-Z]{4}$/', (string) ($json['reference'] ?? '')) === 1 && strpos($headers, 'application/json') !== false, "$status $body");
            $log = (string) @file_get_contents("$dir/shield.log");
            truthy(strpos($log, ' ref=' . ($ref[1] ?? '?') . ' "GET ') !== false && strpos($log, ' ref=' . ($json['reference'] ?? '?') . ' ') !== false, 'the same references in the log: ' . $log);
            $lines = array_values(array_filter(array_map([\CjwNetwork\RequestShield\LogStats::class, 'parse'], explode("\n", trim($log)))));
            same([$ref[1] ?? null, 'SCAN-HIDDEN'], [$lines[0]['ref'] ?? null, $lines[0]['rule'] ?? null], 'read back from the log, the rule untouched by it: ' . json_encode($lines[0] ?? null));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-06 set error-page: the site\'s own page per status, 4xx for all, {lang} per language -- read when the rules are compiled, its placeholders filled in and escaped; else the built-in one' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-errpage-' . getmypid() . '-' . mt_rand();
        mkdir("$dir/errors", 0700, true);
        try {
            file_put_contents("$dir/errors/404.html", '<style>p{color:red}</style><h1>{title}</h1><p>{text}</p><a href="{home}">{lang}</a> {other} {status}');
            file_put_contents("$dir/errors/pause.de.html", '<p>Pause {wait}</p>');
            file_put_contents("$dir/errors/all.html", '<p>Any {status}</p>');
            file_put_contents("$dir/site.rules", "set error-page 404 errors/404.html\nset error-page 429 errors/pause.{lang}.html\nset error-page 4xx errors/all.html\n");
            $s = \CjwNetwork\RequestShield\Settings::from(\CjwNetwork\RequestShield\Rules\RuleFile::read(["$dir/site.rules"])['config']);
            same(['404', '429', '4xx'], array_map('strval', array_keys($s->errorPages)));
            same(['de'], array_keys($s->errorPages['429']), 'one page per language');
            $page = static function (Decision $d, string $lang) use ($s): array {
                return (new Responder())->page($d, null, Texts::all($lang), '/home?a="1"', null, null, null, null, $s->errorPages);
            };
            [$html, $builtIn] = $page(Decision::reject(404, 'x'), 'en');
            same(false, $builtIn, 'the site\'s: no CSP of the shield\'s');
            same('<style>p{color:red}</style><h1>Not found</h1><p>This address does not exist here.</p><a href="/home?a=&quot;1&quot;">en</a> {other} 404', $html, 'filled in, escaped; other braces as they were');
            same('<p>Pause 30</p>', $page(Decision::throttle('requests', 30), 'de')[0], 'German');
            same('<p>Any 429</p>', $page(Decision::throttle('requests', 30), 'en')[0], '429 has a page for German, not for English: the 4xx page');
            same('<p>Any 403</p>', $page(Decision::reject(403, 'x'), 'en')[0], '4xx for the rest');
            foreach (["set error-page 404 errors/none.html\n" => 'cannot read none.html', "set error-page 500 errors/404.html\n" => 'error-page <status> <file>',
                "set error-page 429 errors/gone.{lang}.html\n" => 'no file errors/gone.{lang}.html for any language'] as $rules => $why) {
                file_put_contents("$dir/bad.rules", $rules);
                $thrown = '';
                try {
                    \CjwNetwork\RequestShield\Rules\RuleFile::read(["$dir/bad.rules"]);
                } catch (\InvalidArgumentException $e) {
                    $thrown = $e->getMessage();
                }
                truthy(strpos($thrown, 'bad.rules:1: ') === 0 && strpos($thrown, $why) !== false, "a mistake names its line: $thrown");
            }
            file_put_contents("$dir/errors/big.html", str_repeat('x', 65537));
            file_put_contents("$dir/bad.rules", "set error-page 404 errors/big.html\n");
            $thrown = '';
            try {
                \CjwNetwork\RequestShield\Rules\RuleFile::read(["$dir/bad.rules"]);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e->getMessage();
            }
            truthy(strpos($thrown, 'larger than 64 KB') !== false, $thrown);
            file_put_contents("$dir/site.rules", "site shop.example {\n  set error-page 404 errors/all.html\n}\n");
            $shop = \CjwNetwork\RequestShield\Settings::from(\CjwNetwork\RequestShield\Rules\RuleFile::read(["$dir/site.rules"], 'shop.example')['config']);
            $base = \CjwNetwork\RequestShield\Settings::from(\CjwNetwork\RequestShield\Rules\RuleFile::read(["$dir/site.rules"])['config']);
            same([['404'], []], [array_map('strval', array_keys($shop->errorPages)), $base->errorPages], 'one website its own');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-06 review: {lang} pages from a folder with [ ] in its name, a page for a new language noticed by the folder, JSON with nosniff; try-again named as what it became' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-errpage2-' . getmypid() . '-' . mt_rand() . '/a[1]';
        mkdir("$dir/errors", 0700, true);
        try {
            file_put_contents("$dir/errors/p.de.html", 'DE');
            $page = ErrorPage::load('429 errors/p.{lang}.html', $dir);
            same(['de' => 'DE'], $page['pages'], 'found in a folder named a[1]');
            truthy(in_array("$dir/errors", $page['files'], true), 'the folder is watched: a page for another language is noticed');
            truthy(in_array('X-Content-Type-Options: nosniff', Responder::headerLines(Decision::reject(404, 'x'), false, null, false, true), true), 'JSON: nosniff');
            file_put_contents(dirname($dir) . '/site.rules', "set text.de.try-again Gleich nochmal\n");
            $thrown = '';
            try {
                \CjwNetwork\RequestShield\Rules\RuleFile::read([dirname($dir) . '/site.rules']);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e->getMessage();
            }
            truthy(strpos($thrown, 'try-again is now too-many-text') !== false, $thrown);
        } finally {
            exec('rm -rf ' . escapeshellarg(dirname($dir)));
        }
    },
];
