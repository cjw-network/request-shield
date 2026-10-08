<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Rules\Advise;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;

/** The rule advisor's suggestions from a learning run (proposal 0016, step 2: request-shield advise). */

/** A learning run as `learn` records it: one shape per line. */
function adviseRun(): string
{
    $lines = [
        ['method' => 'GET', 'host' => 'www.example.org', 'path' => '/news/', 'query' => ['page' => 'int', 'sort' => 'word'], 'form' => [], 'type' => null, 'decided' => 'allow', 'status' => 200,
            'found' => ['forms' => [['action' => '/account/login', 'method' => 'POST', 'fields' => ['user' => 'text', 'password' => 'password']]],
                'links' => ['/news/?page&sort&lang', '/about'], 'scripts' => ['/api/v1/messages?since'], 'hosts' => []]],
        ['method' => 'GET', 'host' => 'www.example.org', 'path' => '/search', 'query' => ['q' => 'text'], 'form' => [], 'type' => null, 'decided' => 'allow', 'status' => 200],
        ['method' => 'GET', 'host' => 'www.example.org', 'path' => '/news/', 'query' => ['page' => 'number'], 'form' => [], 'type' => null, 'decided' => 'allow', 'status' => 200],
        ['method' => 'POST', 'host' => 'www.example.org', 'path' => '/contact/send', 'query' => [], 'form' => ['email' => 'text', 'message' => 'text'], 'type' => 'application/x-www-form-urlencoded', 'decided' => 'allow', 'status' => 303],
        ['method' => 'POST', 'host' => 'www.example.org', 'path' => '/node/123/edit', 'query' => [], 'form' => ['title' => 'text'], 'type' => 'application/x-www-form-urlencoded', 'decided' => 'allow', 'status' => 200],
        ['method' => 'GET', 'host' => 'www.example.org', 'path' => '/x', 'query' => ['evil' => 'text', 'at' => 'int', 'we ird' => 'int'], 'form' => [], 'type' => null, 'decided' => 'reject', 'status' => 403],
        ['method' => 'GET', 'host' => 'www.example.org', 'path' => '/y', 'query' => ['at' => 'int', 'bad"name' => 'int'], 'form' => [], 'type' => null, 'decided' => 'allow', 'status' => 200],
    ];
    return implode("\n", array_map(static fn (array $l): string => (string) json_encode($l + ['t' => 1791446553]), $lines)) . "\n";
}

/** @return array<string, string> id => rule line */
function adviseRules(array $suggestions): array
{
    $out = [];
    foreach ($suggestions as $a) {
        $out[$a['id']] = $a['rule'];
    }
    return $out;
}

return [
    'RSF05-04 advise: from a learning run -- the parameters and their types, query strict and @tracking, the forms\' methods and where, post-origin, the API; only what the site\'s clicks did, never what was refused' => function (): void {
        $got = adviseRules(Advise::suggest(Advise::read(adviseRun()), Settings::from([])));
        same([
            'ADV-PARAMS' => 'query lang text  page number  q text  since text  sort word',
            'ADV-TRACKING' => 'include @tracking',
            'ADV-STRICT' => 'monitor query strict',
            'ADV-POST' => 'monitor allow POST /account/login /contact/send /node/*/edit',
            'ADV-ORIGIN' => 'post-origin same',
            'ADV-API' => 'api-path /api/**',
        ], $got, 'page: int and number seen, so number; lang only offered in a link: text; "evil" was refused in the run; "at" and odd names never stand in a line; /node/123 as /node/*');
    },
    'RSF05-04 advise: what the rules say already is not suggested again' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-adv-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/site.rules", "include @tracking\nquery page int  q text\nquery strict\n[S-F] allow POST /contact/send\npost-origin same\napi-path /api/**\n");
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same(['ADV-PARAMS' => 'query lang text  since text  sort word'], adviseRules(Advise::suggest(Advise::read(adviseRun()), $s)), 'only the parameters not declared; strict, POST, origin and API are there');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-04 advise: a path\'s numbers and keys as "*"' => function (): void {
        same(['/node/*/edit', '/item/*', '/u/*/x', '/news/2026x/'], [Advise::general('/node/123/edit'), Advise::general('/item/0f3a9c2b7d1e4f5a'),
            Advise::general('/u/123e4567-e89b-12d3-a456-426614174000/x'), Advise::general('/news/2026x/')]);
    },
    'RSF05-04 request-shield advise: prints each suggestion with its line, replays the run with them enforced; --write keeps them for an include, --json for tools' => function (): void {
        if (!function_exists('exec')) {
            skip('no exec');
        }
        $dir = sys_get_temp_dir() . '/rshield-adv-' . getmypid() . '-' . mt_rand();
        mkdir("$dir/store", 0700, true);
        try {
            file_put_contents("$dir/site.rules", "set store-dir $dir/store\nrestrict /rs/** to 127.0.0.1 ::1\n");
            file_put_contents("$dir/store/learned.jsonl", adviseRun());
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli());
            exec("$bin advise " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            $shown = implode("\n", $out);
            same(0, $code, $shown);
            truthy(strpos($shown, "[ADV-PARAMS] query lang text  page number  q text  since text  sort word") !== false && strpos($shown, '[ADV-POST] monitor allow POST') !== false, 'the lines: ' . $shown);
            // The click with "at" and a name with a quote: no line can declare them -- strict would refuse it, and says so.
            truthy(preg_match('/With all of them enforced .*: 1 refused -- leave those lines watched, or widen them:\n  ✕ GET \/y\?at=1&bad%22name=1 -- 404 by ADV-STRICT/', $shown) === 1, 'the run replayed with them enforced: ' . $shown);
            truthy(strpos($shown, 'only offered on a page') === false, 'the names a script\'s address offers are declared too (since)');
            truthy(!is_file("$dir/store/advice.rules"), 'nothing written without --write');
            $out = [];
            exec("$bin advise " . escapeshellarg("$dir/site.rules") . ' --write 2>&1', $out, $code);
            truthy($code === 0 && is_file("$dir/store/advice.rules"), 'written: ' . implode("\n", $out));
            file_put_contents("$dir/site.rules", "include store/advice.rules\n", FILE_APPEND);
            $out = [];
            exec("$bin check " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            same(0, $code, 'the written file, included, reads as rules: ' . implode("\n", $out));
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same(['POST'], $s->monitor !== null ? array_keys($s->monitor->methodPaths) : [], 'its allow line is read, watched');
            $out = [];
            exec("$bin advise " . escapeshellarg("$dir/site.rules") . ' --json 2>&1', $out, $code);
            $j = json_decode(implode("\n", $out), true);
            same([0, []], [$code, is_array($j) ? $j['suggestions'] : null], 'included, nothing is left to suggest: ' . implode("\n", $out));
            $out = [];
            exec("$bin advise " . escapeshellarg("$dir/site.rules") . ' ' . escapeshellarg("$dir/none.jsonl") . ' 2>&1', $out, $code);
            same(2, $code, 'no recording: says how to make one');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
