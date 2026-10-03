<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Report\Inspector;
use CjwNetwork\RequestShield\Report\RulesPage;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rule\QueryRule;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** Known query parameters (proposal 0009): rule files, types, strict, what the attack patterns see. */

function queryDir(string $rules): string
{
    $dir = sys_get_temp_dir() . '/rshield-query-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    file_put_contents("$dir/site.rules", $rules);
    return $dir;
}

function querySettings(string $rules): Settings
{
    $dir = queryDir($rules);
    try {
        return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

function queryFails(string $rules, string $at, string $part): void
{
    $dir = queryDir($rules);
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

function queryReq(string $uri): Request
{
    return Request::fromServer(['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '203.0.113.7']);
}

/** [action, status, reason, rule] of a request under these rules. */
function queryDecide(Settings $s, string $uri): array
{
    $shield = new Shield($s, new MemoryStore());
    $r = queryReq($uri);
    $d = $shield->decide($r, 1000.0);
    return [$d->action, $d->status, $d->reason, $shield->explain($d, $r)];
}

// A harmless marker stands in for an attack: what matters is which part of the
// query the attack patterns get to see, not what they look for.
const QUERY_RULES = "block query rsmarker\n"
    . "query page int   sort word   ids list   price number   key id   code /[a-z]{2}-[0-9]{3}/\n"
    . "query q text   tag any   utm_* any\n"
    . "query SearchText text at /content/search\n"
    . "match /news/** {\n  query year int\n}\n";

return [
    'types: what each one lets through' => function (): void {
        $cases = [
            ['int', '12', true], ['int', '-3', true], ['int', '1.5', false], ['int', '', true], ['int', '1e3', false], ['int', str_repeat('9', 19), false],
            ['number', '1.5', true], ['number', '-0.25', true], ['number', '1.', false], ['number', '1,5', false],
            ['word', 'price-asc', true], ['word', 'Größe_2.x', true], ['word', 'a b', false], ['word', str_repeat('a', 65), false], ['word', "a'", false],
            ['id', 'ab_C-9', true], ['id', 'a.b', false], ['id', 'ä', false],
            ['list', 'a,b,c', true], ['list', 'a', true], ['list', 'a,,b', false], ['list', 'a, b', false],
            ['text', "anything ' < >", true], ['any', "anything ' < >", true],
            ['word', '', true], ['list', '', true], ['#^(?:[a-z]{2})$#', '', true],     // an empty field of a form: of every type
        ];
        foreach ($cases as [$type, $value, $fits]) {
            same($fits, QueryRule::fits($type, $value), "$type: " . json_encode($value));
        }
    },
    'rule files: query <name> <type>, at <paths>, globs, regex types, match blocks, strict' => function (): void {
        $s = querySettings(QUERY_RULES . "query strict\n");
        same(true, $s->queryStrict);
        same(4, count($s->queryParams), 'one entry per line (the block one included)');
        same(['page' => 'int', 'sort' => 'word', 'ids' => 'list', 'price' => 'number', 'key' => 'id'], array_diff_key($s->queryParams[0]['exact'], ['code' => 1]));
        same(true, QueryRule::fits($s->queryParams[0]['exact']['code'], 'ab-123') && !QueryRule::fits($s->queryParams[0]['exact']['code'], 'ab-1234'), 'a regex type is anchored');
        same(null, $s->queryParams[0]['paths'], 'everywhere');
        same('any', QueryRule::typeOf($s->queryParams, 'utm_campaign', '/'), 'a glob');
        same(null, QueryRule::typeOf($s->queryParams, 'SearchText', '/'), 'only on its path');
        same('text', QueryRule::typeOf($s->queryParams, 'SearchText', '/content/search'));
        same('int', QueryRule::typeOf($s->queryParams, 'year', '/news/2026/x'), 'inside a match block: that area');
        same(null, QueryRule::typeOf($s->queryParams, 'year', '/blog'));
        $own = querySettings("query page int\nquery page any at /all/**\n");
        same('any', QueryRule::typeOf($own->queryParams, 'page', '/all/x'), 'a path\'s own rule before the one for every path');
        same('int', QueryRule::typeOf($own->queryParams, 'page', '/x'));
    },
    'rule files: mistakes are errors naming the line' => function (): void {
        queryFails("query page\n", 'site.rules:1', 'query <name> <type>');
        queryFails("\nquery page int sort\n", 'site.rules:2', 'query <name> <type>');
        queryFails("query page integer\n", 'site.rules:1', '"integer" is not a type');
        queryFails("query pa<ge int\n", 'site.rules:1', 'is not a parameter name');
        queryFails("query page /(/\n", 'site.rules:1', 'is not a valid regular expression');
        queryFails("query page int at\n", 'site.rules:1', 'query <name> <type>');
        queryFails("match /x/** {\n  query strict\n}\n", 'site.rules:2', 'strict');
    },
    'strict: an unknown parameter or a value not of its type is 404, with the rule that says so' => function (): void {
        $s = querySettings(QUERY_RULES . "[SITE-Q] query strict\n");
        same([Decision::ALLOW, 200], array_slice(queryDecide($s, '/list?page=2&sort=price-asc&utm_source=mail'), 0, 2), 'all known and of their type');
        same([Decision::REJECT, 404, 'unknown parameter', 'SITE-Q'], queryDecide($s, '/list?page=2&other=1'), 'unknown');
        same([Decision::REJECT, 404, 'unknown parameter', 'SITE-Q'], queryDecide($s, '/list?page=two'), 'not of its type');
        same(404, queryDecide($s, '/list?page=1&page=x')[1], 'every occurrence is checked, not only the last');
        same(Decision::ALLOW, queryDecide($s, '/list?page%5B%5D=2&pa%67e=3')[0], 'page[] and an encoded name are "page", as PHP reads them');
        same(404, queryDecide($s, '/list?SearchText=x')[1], 'only on its path');
        same(Decision::ALLOW, queryDecide($s, '/content/search?SearchText=a+b&page=1')[0]);
        same(Decision::ALLOW, queryDecide($s, '/news/x?year=2026')[0], 'the area of the match block');
        same(404, queryDecide($s, '/blog?year=2026')[1]);
        same(Decision::ALLOW, queryDecide($s, '/list')[0], 'no query string: nothing to check');
        same(Decision::ALLOW, queryDecide($s, '/list?&')[0], 'empty pairs are nothing');
        same(Decision::ALLOW, queryDecide($s, '/list?page=&sort=&code=')[0], 'empty fields of a form pass');
    },
    'without strict: nothing is refused for being unknown -- answered, not cached' => function (): void {
        $s = querySettings("cache-query page\nquery page int\n");
        same(Decision::ALLOW, queryDecide($s, '/list?page=2')[0], 'known and cacheable');
        same(Decision::ALLOW_UNCACHED, queryDecide($s, '/list?other=1')[0], 'unknown: answered, not cached (the cache rule)');
        same(Decision::ALLOW, queryDecide($s, '/list?page=x')[0], 'a wrong type alone refuses nothing');
    },
    'the attack patterns see only what could hold an attack: text, unknown, not of its type -- names included' => function (): void {
        $s = querySettings(QUERY_RULES);
        same([Decision::REJECT, 403], array_slice(queryDecide($s, '/list?q=rsmarker'), 0, 2), 'text is scanned');
        same([Decision::REJECT, 403], array_slice(queryDecide($s, '/list?other=rsmarker'), 0, 2), 'an unknown parameter is scanned');
        same([Decision::REJECT, 403], array_slice(queryDecide($s, '/list?rsmarker=1'), 0, 2), 'and its name too');
        same([Decision::REJECT, 403], array_slice(queryDecide($s, '/list?page=rsmarker'), 0, 2), 'a value not of its type is scanned');
        same([Decision::REJECT, 403], array_slice(queryDecide($s, '/list?q=rs%256Darker'), 0, 2), 'decoded as always (twice)');
        same([Decision::REJECT, 403], array_slice(queryDecide($s, '/list?q=x+rsmarker'), 0, 2), '"+" is a space, as always');
        same(Decision::ALLOW, queryDecide($s, '/list?sort=rsmarker')[0], 'a word is of its type: not scanned');
        same(Decision::ALLOW, queryDecide($s, '/list?tag=rsmarker&utm_term=rsmarker')[0], 'any: never scanned');
        same(Decision::ALLOW, queryDecide($s, '/list?page=2&sort=a')[0]);
        $none = querySettings("block query rsmarker\n");
        same(403, queryDecide($none, '/list?sort=rsmarker')[1], 'without query rules: the whole query, as before');
        $anywhere = querySettings("block anywhere rsmarker\nquery sort word\n");
        same(Decision::ALLOW, queryDecide($anywhere, '/list?sort=rsmarker')[0], 'anywhere rules see the same part of the query');
        same(403, queryDecide($anywhere, '/rsmarker?sort=a')[1], 'and still the path');
    },
    '@tracking: marketing tags known, taken, never scanned' => function (): void {
        $s = querySettings("include @tracking\nquery page int\nquery strict\n");
        foreach (['utm_source=news', 'utm_whatever=1', 'gclid=abc', 'fbclid=x', 'msclkid=1', '_ga=2.1', 'mc_cid=1', 'mkt_tok=x', 'ttclid=1'] as $q) {
            same(Decision::ALLOW, queryDecide($s, "/?page=1&$q")[0], $q);
        }
        same(404, queryDecide($s, '/?page=1&utmsource=x')[1], 'only its names');
        same([Decision::REJECT, 404, 'unknown parameter', 'site.rules:3'], queryDecide($s, '/?x=1'), 'the site\'s strict line');
    },
    'the rules page, trace and show name the known parameters' => function (): void {
        $s = querySettings(QUERY_RULES . "query strict\n");
        $html = RulesPage::render($s);
        truthy(strpos($html, 'Known parameters') !== false, 'a group of its own');
        truthy(strpos($html, 'SearchText') !== false && strpos($html, 'utm_*') !== false, 'the names as written');
        $i = new Inspector($s, new MemoryStore());
        $steps = [];
        foreach ($i->trace(Inspector::request('GET', 'https://www.example.org/list?page=two', '198.51.100.7'), 1000.0)['steps'] as $step) {
            $steps[$step['check']] = $step;
        }
        same('stop', $steps['Known parameters']['state']);
        truthy(strpos($steps['Known parameters']['text'], '"page" is not of the type int') !== false, $steps['Known parameters']['text']);
        if (!function_exists('exec')) {
            skip('no exec');
        }
        $dir = queryDir(QUERY_RULES . "query strict\n");
        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' show ' . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            $shown = implode("\n", $out);
            same(0, $code, $shown);
            truthy(preg_match('~^query page int  sort word  ids list  price number  key id  code /\[a-z\]\{2\}-\[0-9\]\{3\}/ +# \(site\.rules:2\)$~m', $shown) === 1, $shown);
            truthy(preg_match('~^query q text  tag any  utm_\* any +# \(site\.rules:3\)$~m', $shown) === 1, 'globs as written');
            truthy(preg_match('~^query SearchText text at regex \S+ +# \(site\.rules:4\)$~m', $shown) === 1, 'a path');
            truthy(preg_match('~^query strict +# \(site\.rules:8\)$~m', $shown) === 1, 'strict');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
