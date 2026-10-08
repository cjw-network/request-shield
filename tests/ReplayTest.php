<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Rules\Replay;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;

/**
 * The replay (proposal 0016, first part; RSF05-04): requests known to be
 * good -- a session recorded as HAR, an access log, a list -- through the
 * rules, each once on a fresh store, nothing counted; what would be refused
 * or checked, by which rule.
 */

function replayDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-replay-' . getmypid() . '-' . mt_rand();
    mkdir($dir);
    return $dir;
}

/** @return array{0: string, 1: int} what the command line printed, and its exit code */
function replayCli(string $args): array
{
    if (!function_exists('exec')) {
        skip('no exec');
    }
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . " replay $args 2>&1", $out, $code);
    return [implode("\n", $out), $code];
}

const REPLAY_RULES = "host www.example.org\n[APP-PARAMS] query page int sort word\n[APP-STRICT] query strict\n[APP-FORMS] allow POST /contact\n[APP-ORIGIN] post-origin same\n[APP-WATCH] monitor block /old/**\n";

return [
    'RSF05-04 replay reads a session (HAR), an access log and a list -- the shape only: never a cookie, never a body; an access log\'s refused lines left out' => function (): void {
        $har = json_encode(['log' => ['entries' => [
            ['request' => ['method' => 'post', 'url' => 'https://www.example.org/contact', 'headers' => [['name' => 'Origin', 'value' => 'https://www.example.org'], ['name' => 'Cookie', 'value' => 'session=secret'], ['name' => 'Authorization', 'value' => 'Bearer x']]]],
            ['request' => ['method' => 'GET', 'url' => 'data:image/png;base64,AAAA', 'headers' => []]],
        ]]]);
        $r = Replay::read((string) $har);
        same(['HAR', 1], [$r['format'], $r['skipped']]);
        same([['method' => 'POST', 'url' => 'https://www.example.org/contact', 'headers' => ['origin' => 'https://www.example.org'], 'from' => null]], $r['requests'], 'no cookie, no token');
        $log = Replay::read("198.51.100.7 - - [04/Oct/2026:10:00:00 +0200] \"GET /a?page=2 HTTP/1.1\" 200 512 \"https://www.example.org/\" \"Mozilla/5.0\"\n"
            . "203.0.113.9 - - [04/Oct/2026:10:00:02 +0200] \"GET /.env HTTP/1.1\" 404 0 \"-\" \"curl/8\"\n");
        same(['access log', 1, 1], [$log['format'], count($log['requests']), $log['skipped']]);
        same(['GET', '/a?page=2', '198.51.100.7', 'https://www.example.org/'], [$log['requests'][0]['method'], $log['requests'][0]['url'], $log['requests'][0]['from'], $log['requests'][0]['headers']['referer']]);
        $list = Replay::read("# a comment\n/a\nPOST /b\nhttps://www.example.org/c\n");
        same(['list', ['GET /a', 'POST /b', 'GET https://www.example.org/c']], [$list['format'], array_map(static fn (array $x): string => $x['method'] . ' ' . $x['url'], $list['requests'])]);
        $thrown = false;
        try {
            Replay::read("nonsense here\n");
        } catch (\InvalidArgumentException $e) {
            $thrown = strpos($e->getMessage(), 'line 1') === 0;
        }
        truthy($thrown, 'a line that is no request names itself');
    },
    'RSF05-04 replay decides each different request once, on a fresh store, the rules switched on: refused and checked with their rule; static files and other websites left out' => function (): void {
        $dir = replayDir();
        try {
            file_put_contents("$dir/site.rules", REPLAY_RULES);
            $s = Settings::from(RuleFile::switchedOn(["$dir/site.rules"]));
            $reqs = Replay::read("/products/?page=2\n/products/?page=2\n/products/?debug=1\nPOST /cart/add\n/assets/app.css\nhttps://cdn.example.net/x.js\n/old/page\n")['requests'];
            $run = Replay::run($reqs, static fn (string $host): Settings => $s, null);
            same([7, 1, 1], [$run['total'], $run['static'], $run['foreign']]);
            $by = [];
            foreach ($run['results'] as $r) {
                $by[$r['method'] . ' ' . $r['url']] = [$r['kind'], $r['got'], $r['rule'], $r['count']];
            }
            same(['pass', 'passes', null, 2], $by['GET /products/?page=2'], 'twice, decided once');
            same(['refused', '404', 'APP-STRICT', 1], $by['GET /products/?debug=1']);
            same(['refused', '405', 'APP-FORMS', 1], $by['POST /cart/add']);
            same(['refused', '404', 'APP-WATCH', 1], $by['GET /old/page'], 'a watched rule switched on, as test does');
            $written = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same('pass', array_values(array_filter(Replay::run($reqs, static fn (string $h): Settings => $written, null)['results'], static fn (array $r): bool => $r['url'] === '/old/page'))[0]['kind'], 'as written: only watched');
            same(count($run['results']) + 1, count(Replay::run($reqs, static fn (string $h): Settings => $s, null, true)['results']), '--all keeps the static file');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-04 replay reads a learning run (learned.jsonl): the clicks it let through with a value of each type, and what the pages offered -- forms (a POST with the site\'s Origin), links, script addresses' => function (): void {
        $l = static fn (array $x): string => (string) json_encode($x + ['t' => 1, 'host' => 'www.example.org', 'query' => [], 'form' => [], 'type' => null, 'decided' => 'allow', 'status' => 200]);
        $rec = implode("\n", [
            $l(['method' => 'GET', 'path' => '/products/', 'query' => ['page' => 'int', 'sort' => 'word', 'q' => 'text'],
                'found' => ['forms' => [['action' => '/contact', 'method' => 'POST', 'fields' => ['email' => 'email']], ['action' => '/search', 'method' => 'GET', 'fields' => ['q' => 'text']]],
                    'links' => ['/products/?page&sort', '/old/page'], 'scripts' => ['/api/items/*/edit'], 'hosts' => []]]),
            $l(['method' => 'POST', 'path' => '/contact', 'form' => ['email' => 'text'], 'type' => 'application/x-www-form-urlencoded']),
            $l(['method' => 'GET', 'path' => '/.env', 'decided' => 'reject', 'status' => 404]),
            $l(['method' => 'GET', 'path' => '/gone', 'status' => 404]),
        ]) . "\n";
        $r = Replay::read($rec);
        same(['learning run', 2], [$r['format'], $r['skipped']], 'refused then, or failed by the site: left out');
        same([
            ['GET', 'https://www.example.org/products/?page=1&sort=word&q=two%20words', []],
            ['POST', 'https://www.example.org/contact', ['origin' => 'https://www.example.org', 'content-type' => 'application/x-www-form-urlencoded']],
            ['POST', 'https://www.example.org/contact', ['origin' => 'https://www.example.org', 'content-type' => 'application/x-www-form-urlencoded']],
            ['GET', 'https://www.example.org/search?q=1', []],
            ['GET', 'https://www.example.org/products/?page=1&sort=1', []],
            ['GET', 'https://www.example.org/old/page', []],
            ['GET', 'https://www.example.org/api/items/1/edit', []],
        ], array_map(static fn (array $x): array => [$x['method'], $x['url'], $x['headers']], $r['requests']), 'clicked, then found: forms, links, script addresses');
        $dir = replayDir();
        try {
            file_put_contents("$dir/site.rules", REPLAY_RULES);
            $s = Settings::from(RuleFile::switchedOn(["$dir/site.rules"]));
            $kinds = [];
            foreach (Replay::run($r['requests'], static fn (string $h): Settings => $s, null)['results'] as $x) {
                $kinds[$x['method'] . ' ' . (string) preg_replace('#^https://[^/]+#', '', $x['url'])] = $x['kind'] . ($x['kind'] !== 'pass' && $x['rule'] !== null ? ' ' . $x['rule'] : '') . ($x['found'] ? ' (found)' : '');
            }
            same(['GET /products/?page=1&sort=word&q=two%20words' => 'refused APP-STRICT', 'POST /contact' => 'pass', 'GET /search?q=1' => 'refused APP-STRICT (found)',
                'GET /products/?page=1&sort=1' => 'pass (found)', 'GET /old/page' => 'refused APP-WATCH (found)', 'GET /api/items/1/edit' => 'pass (found)'], $kinds,
                'the rules against the run: clicked and only found, each with its rule; POST /contact clicked once -- clicked');
            file_put_contents("$dir/learned.jsonl", "{\"cut off\n" . $rec);
            [$out, $code] = replayCli(escapeshellarg("$dir/site.rules") . ' ' . escapeshellarg("$dir/learned.jsonl") . ' --junit=' . escapeshellarg("$dir/r.xml"));
            truthy($code === 1 && strpos($out, '(learning run)') !== false && strpos($out, 'left out: 2 refused or failed in the run') !== false
                && preg_match('/✕ GET \/products\/\?page=1&sort=word&q=two%20words\s+404 by APP-STRICT/u', $out) === 1 && preg_match('/\? GET \/search\?q=1\s+404 by APP-STRICT/u', $out) === 1
                && strpos($out, '2 only offered on a page would be refused') !== false, 'a clicked one fails, the only found ones are notes -- and a first line cut off is no matter: ' . $out);
            $xml = (string) file_get_contents("$dir/r.xml");
            truthy(strpos($xml, 'failures="1"') !== false && substr_count($xml, '<skipped message="only offered on a page') === 2, 'JUnit: one failure, two skipped -- ' . $xml);
            file_put_contents("$dir/ok.jsonl", str_replace(',"q":"text"', '', $rec));         // the clicked page without its unknown parameter; what it offered stays
            same(0, replayCli(escapeshellarg("$dir/site.rules") . ' ' . escapeshellarg("$dir/ok.jsonl"))[1], 'no clicked one refused: exit 0, the found ones are notes');
            same(1, replayCli(escapeshellarg("$dir/site.rules") . ' ' . escapeshellarg("$dir/learned.jsonl") . ' --found=fail')[1], '--found=fail: they count');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-04 request-shield replay: the refused with their rule, exit 1; none refused, exit 0; a check is a note; JUnit for CI; a recording that cannot be read, exit 2' => function (): void {
        $dir = replayDir();
        try {
            file_put_contents("$dir/site.rules", REPLAY_RULES);
            file_put_contents("$dir/bad.txt", "/products/?page=2\nPOST /cart/add\n");
            file_put_contents("$dir/good.txt", "/products/?page=2\nPOST /contact\n");
            [$out, $code] = replayCli(escapeshellarg("$dir/site.rules") . ' ' . escapeshellarg("$dir/bad.txt") . ' --junit=' . escapeshellarg("$dir/r.xml"));
            truthy($code === 1 && preg_match('/✕ POST \/cart\/add\s+405 by APP-FORMS/u', $out) === 1 && strpos($out, '2 different requests: 1 pass, 1 refused.') !== false, $out);
            $xml = (string) file_get_contents("$dir/r.xml");
            truthy(strpos($xml, 'tests="2" failures="1"') !== false && strpos($xml, '<failure message="405 by APP-FORMS"/>') !== false, $xml);
            [$out, $code] = replayCli(escapeshellarg("$dir/site.rules") . ' ' . escapeshellarg("$dir/good.txt"));
            truthy($code === 0 && strpos($out, 'get the browser check') !== false && strpos($out, '! POST /contact') !== false, 'a POST without Origin: the check, a note -- ' . $out);
            [$out, $code] = replayCli(escapeshellarg("$dir/site.rules") . ' ' . escapeshellarg("$dir/none.har"));
            same(2, $code, $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
