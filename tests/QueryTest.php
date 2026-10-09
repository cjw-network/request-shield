<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\Waf\RulesPage;
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
    'RSF02-05 types: what each one lets through' => function (): void {
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
    'RSF02-05 rule files: query <name> <type>, at <paths>, globs, regex types, match blocks, strict' => function (): void {
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
    'RSF02-05 rule files: mistakes are errors naming the line' => function (): void {
        queryFails("query page\n", 'site.rules:1', 'query <name> <type>');
        queryFails("\nquery page int sort\n", 'site.rules:2', 'query <name> <type>');
        queryFails("query page integer\n", 'site.rules:1', '"integer" is not a type');
        queryFails("query pa<ge int\n", 'site.rules:1', 'is not a parameter name');
        queryFails("query page /(/\n", 'site.rules:1', 'is not a valid regular expression');
        queryFails("query page int at\n", 'site.rules:1', 'query <name> <type>');
        queryFails("match /x/** {\n  query strict\n}\n", 'site.rules:2', 'strict');
    },
    'RSF02-05 strict: an unknown parameter or a value not of its type is 404, with the rule that says so' => function (): void {
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
    'RSF02-05 drop: an unknown parameter or a value not of its type is left out, not refused -- still scanned; the request may be cached without it' => function (): void {
        $s = querySettings(QUERY_RULES . "cache-query page\nquery drop\n");
        $shield = new Shield($s, new MemoryStore());
        $r = queryReq('/list?page=2&other=1&sort=x%20y&rsx=abc');
        $d = $shield->decide($r, 1000.0);
        same(Decision::ALLOW, $d->action, 'nothing refused, and the answer may be kept: the dropped ones are no cache key');
        same(['other' => true, 'sort' => true, 'rsx' => true], $r->dropped(), 'the unknown one and the value not of its type, by their names in the query');
        same([Decision::REJECT, 403], array_slice(queryDecide($s, '/list?other=rsmarker'), 0, 2), 'an attack in a dropped parameter is refused all the same');
        same([], (function (): array { $r = queryReq('/list?page=2&sort=a'); (new Shield(querySettings(QUERY_RULES . "query drop\n"), new MemoryStore()))->decide($r, 1000.0); return $r->dropped(); })(), 'all known and of their type: nothing dropped');
    },
    'RSF02-05 drop in an area: query drop at <paths> or in a match block; strict everywhere else; the rule file and show say so' => function (): void {
        $s = querySettings("query page int\nquery strict\nmatch /magazin/** {\n  query drop\n}\nquery drop at /shop/**\n");
        same(Decision::ALLOW, queryDecide($s, '/magazin/1?id=xyz')[0], 'the block: dropped');
        same(Decision::ALLOW, queryDecide($s, '/magazin?page=x')[0], 'the block\'s own path too, a wrong type dropped');
        same(Decision::ALLOW, queryDecide($s, '/shop/a?cb=1')[0], 'at <paths>');
        same([Decision::REJECT, 404], array_slice(queryDecide($s, '/news?id=xyz'), 0, 2), 'elsewhere: strict');
        same(2, count($s->queryDrop), 'two areas');
        queryFails("query drop at\n", 'site.rules:1', 'query drop at <paths>');
        queryFails("match /x/** {\n  query drop at /y\n}\n", 'site.rules:2', 'the block is where');
    },
    'RSF02-05 drop leaves strict as it is for other methods and in monitor mode; a PHP array\'s queryDrop must be a list; show names each area\'s line' => function (): void {
        $s = querySettings("query page int\nquery strict\nmatch /magazin/** {\n  [MAG-DROP] query drop\n}\n[SHOP-DROP] query drop at /shop/**\n");
        $post = Request::fromServer(['REQUEST_URI' => '/magazin/1?zz=1', 'REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '203.0.113.7']);
        $d = (new Shield($s, new MemoryStore()))->decide($post, 1000.0);
        same([Decision::REJECT, 404], [$d->action, $d->status], 'a POST: nothing is taken out of it, so strict refuses as before');
        $watch = querySettings("set mode monitor\nquery page int\nquery drop\n");
        $r = queryReq('/list?zz=1');
        (new Shield($watch, new MemoryStore()))->decide($r, 1000.0);
        same([], $r->dropped(), 'set mode monitor: nothing left out of the request');
        try {
            Settings::from(['queryDrop' => '#^/x#']);
            throw new TestFailure('a string for queryDrop was taken');
        } catch (InvalidArgumentException $e) {
            truthy(strpos($e->getMessage(), 'queryDrop') !== false, 'the mistake names the setting: ' . $e->getMessage());
        }
        $dir = queryDir("query page int\nmatch /magazin/** {\n  [MAG-DROP] query drop\n}\n[SHOP-DROP] query drop at /shop/**\n");
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' show ' . escapeshellarg("$dir/site.rules") . ' 2>&1', $out);
        exec('rm -rf ' . escapeshellarg($dir));
        $lines = array_values(array_filter($out, static fn (string $l): bool => strpos($l, 'query drop at') !== false));
        truthy(count($lines) === 2 && strpos($lines[0], 'MAG-DROP') !== false && strpos($lines[1], 'SHOP-DROP') !== false, 'each area with its own line: ' . implode(' / ', $lines));
    },
    'RSF02-05 the name PHP gives a parameter is PHP\'s own, for every spelling: brackets, dots, spaces, NUL bytes' => function (): void {
        same(['page', 'page', 'a_b', 'q', 'utm_source', 'items_per_page'], array_map([Request::class, 'phpName'], ["page\0x", ' page', 'a.b', 'q', 'utm source', 'items[per[page']));
        // Every short name of these characters: the key the shield takes out is the one PHP fills, from the raw pair and from queryPairs().
        $chars = ['a', 'b', '[', ']', '.', ' ', '%00', '+', '_'];
        $names = [''];
        for ($len = 1; $len <= 4; $len++) {
            $next = [];
            foreach ($names as $n) {
                foreach ($chars as $c) {
                    $next[] = $n . $c;
                }
            }
            $names = $next;
            foreach ($names as $n) {
                parse_str($n . '=1', $php);
                $want = (string) array_key_first($php);
                $r = Request::fromServer(['REQUEST_URI' => '/x?' . $n . '=1', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '203.0.113.7']);
                foreach ($r->queryPairs() as [$name]) {
                    if (strpos($n, ']') === false && strpos($n, '%00') === false) {        // an array name's brackets are queryPairs()' to cut; a NUL is kept there (unknown, scanned)
                        same($want, Request::phpName($name), "phpName of \"$n\"");
                    }
                }
            }
        }
    },
    'RSF02-05 a name PHP reads otherwise is no known name: page%00x and page[x] are scanned, strict refuses page%00x' => function (): void {
        $s = querySettings("block query rsmarker\nquery page int\nquery strict\n");
        same([Decision::REJECT, 404], array_slice(queryDecide($s, '/list?page%00rsmarker=1'), 0, 2), 'page%00…: not "page" -- unknown');
        same([Decision::REJECT, 403], array_slice(queryDecide($s, '/list?page[rsmarker]=1'), 0, 2), 'page[…]: the brackets are scanned');
        $d = querySettings("block query rsmarker\nquery page int\nquery drop\n");
        same([Decision::REJECT, 403], array_slice(queryDecide($d, '/list?page%00rsmarker=1'), 0, 2), 'dropped, and scanned all the same');
        same(Decision::ALLOW, queryDecide($d, '/list?page[1]=2')[0], 'harmless brackets pass');
    },
    'RSF04-01 PHP splitting the query on another separator too (arg_separator.input): never kept' => function (): void {
        $code = 'require ' . var_export(rsEntry(), true) . ';'
            . '$r = \\CjwNetwork\\RequestShield\\Request::fromServer(["REQUEST_URI" => "/x?page=2;evil=1", "REQUEST_METHOD" => "GET", "HTTP_HOST" => "www.example.org", "REMOTE_ADDR" => "203.0.113.7"]);'
            . '$d = (new \\CjwNetwork\\RequestShield\\Rule\\CacheableRule(null, null))->check($r, 1.0); echo $d === null ? "kept" : $d->reason;';
        same('query separator', trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('arg_separator.input=&;') . ' -r ' . escapeshellarg($code) . ' 2>&1')), 'PHP splits on ";" too');
        same('kept', trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d arg_separator.input=\& -r ' . escapeshellarg($code) . ' 2>&1')), 'only "&": ";" is part of a value');
    },
    'RSF04-01 more parameters than PHP reads (max_input_vars): never kept -- $_GET would hold fewer than the query' => function (): void {
        $code = 'require ' . var_export(rsEntry(), true) . ';'
            . '$r = \\CjwNetwork\\RequestShield\\Request::fromServer(["REQUEST_URI" => "/x?" . implode("&", array_map(static fn ($i) => "p$i=1", range(1, 30))), "REQUEST_METHOD" => "GET", "HTTP_HOST" => "www.example.org", "REMOTE_ADDR" => "203.0.113.7"]);'
            . '$d = (new \\CjwNetwork\\RequestShield\\Rule\\CacheableRule(null, null))->check($r, 1.0); echo $d === null ? "kept" : $d->reason;';
        same('too many parameters', trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d max_input_vars=25 -r ' . escapeshellarg($code) . ' 2>&1')), '30 parameters, PHP reads 25');
        same('kept', trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d max_input_vars=1000 -r ' . escapeshellarg($code) . ' 2>&1')), 'PHP reads them all');
        same('too many parameters', trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d max_input_vars=2 -r ' . escapeshellarg(str_replace('range(1, 30)', 'range(1, 3)', $code)) . ' 2>&1')), 'a small limit too: 3 parameters, PHP reads 2');
    },
    'RSF04-01 $_REQUEST rebuilt as PHP merges it: the later source wins, arrays under one name merged' => function (): void {
        $merge = new ReflectionMethod(Shield::class, 'mergeInput');
        $merge->setAccessible(true);
        same(['a' => ['x' => '1', 'y' => '2'], 'b' => '3', 'c' => '4'], $merge->invoke(null, ['a' => ['x' => '1'], 'b' => 'old', 'c' => '4'], ['a' => ['y' => '2'], 'b' => '3']));
        same(['a' => 'flat'], $merge->invoke(null, ['a' => ['x' => '1']], ['a' => 'flat']), 'a value replaces an array');
    },
    'RSF02-05 without strict: nothing is refused for being unknown -- answered, not cached' => function (): void {
        $s = querySettings("cache-query page\nquery page int\n");
        same(Decision::ALLOW, queryDecide($s, '/list?page=2')[0], 'known and cacheable');
        same(Decision::ALLOW_UNCACHED, queryDecide($s, '/list?other=1')[0], 'unknown: answered, not cached (the cache rule)');
        same(Decision::ALLOW, queryDecide($s, '/list?page=x')[0], 'a wrong type alone refuses nothing');
    },
    'RSF02-05 the attack patterns see only what could hold an attack: text, unknown, not of its type -- names included' => function (): void {
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
    'RSF02-05 @tracking: marketing tags known, taken, never scanned' => function (): void {
        $s = querySettings("include @tracking\nquery page int\nquery strict\n");
        foreach (['utm_source=news', 'utm_whatever=1', 'gclid=abc', 'fbclid=x', 'msclkid=1', '_ga=2.1', 'mc_cid=1', 'mkt_tok=x', 'ttclid=1'] as $q) {
            same(Decision::ALLOW, queryDecide($s, "/?page=1&$q")[0], $q);
        }
        same(404, queryDecide($s, '/?page=1&utmsource=x')[1], 'only its names');
        same([Decision::REJECT, 404, 'unknown parameter', 'site.rules:3'], queryDecide($s, '/?x=1'), 'the site\'s strict line');
    },
    'RSF02-05 query strict leaves the shield\'s own addresses alone: widget.js?v=<version> under widget-path loads' => function (): void {
        $s = querySettings("query page int\nquery strict\nset widget-path /rs-check\n");
        same(Decision::ALLOW, queryDecide($s, '/rs-check/widget.js?v=0123456789')[0], 'the check\'s script, as Widget::html() names it');
        same(404, queryDecide($s, '/rs-check-other?v=1')[1], 'a path that only starts like it: the site\'s rules');
        same(404, queryDecide($s, '/page?v=1')[1], 'v elsewhere: unknown, as before');
        same(Decision::ALLOW, queryDecide(querySettings("query strict\nset widget-path /rs-check\n"), '/rs-check/widget.js?v=1')[0], 'also with no parameter declared at all');
    },
    'RSF02-05 the rules page, trace and show name the known parameters' => function (): void {
        needsPlugins();         // the shipped plugins' pages (the WAF's, the statistics'): not in the core single file
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
