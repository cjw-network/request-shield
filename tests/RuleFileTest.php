<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\Pattern;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** A directory with the given files; returns its path. */
function ruleDir(array $files): string
{
    $dir = sys_get_temp_dir() . '/rshield-rules-' . getmypid() . '-' . mt_rand();
    foreach ($files as $name => $text) {
        @mkdir(dirname("$dir/$name"), 0700, true);
        file_put_contents("$dir/$name", $text);
    }
    return $dir;
}

function rulesFrom(string $text): Settings
{
    $dir = ruleDir(['site.rules' => $text]);
    try {
        return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

/** The rule text must fail, with a message starting "$at: " and containing $part. */
function rulesFail(array $files, string $at, string $part): void
{
    $dir = ruleDir($files);
    try {
        RuleFile::read(["$dir/site.rules"]);
    } catch (RuleFileException $e) {
        truthy(strncmp($e->getMessage(), "$at: ", strlen($at) + 2) === 0, "starts with $at: " . $e->getMessage());
        truthy(strpos($e->getMessage(), $part) !== false, "says \"$part\": " . $e->getMessage());
        return;
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
    throw new TestFailure("accepted: " . json_encode($files));
}

function decideFor(Settings $s, string $path, array $server = []): string
{
    $shield = new Shield($s, new MemoryStore());
    $d = $shield->decide(Request::fromServer($server + ['REQUEST_URI' => $path, 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'www.example.org'], $s->trustedProxies), 1000.0);
    return trim($d->action . ' ' . $d->reason);
}

return [
    'patterns: * within a segment, ** across, ? one character; no leading /: anywhere' => function (): void {
        $cases = [
            ['/wp-admin/**', '/wp-admin', true], ['/wp-admin/**', '/wp-admin/x/y.php', true], ['/wp-admin/**', '/wp-adminx', false],
            ['/page/*', '/page/about', true], ['/page/*', '/page/a/b', false], ['/page/*', '/page', false],
            ['*.sql', '/dump.sql', true], ['*.sql', '/a/b/dump.sql', true], ['*.sql', '/dump.sqlx', false],
            ['/file?.txt', '/file1.txt', true], ['/file?.txt', '/file12.txt', false], ['/file?.txt', '/file/.txt', false],
            ['/', '/', true], ['/', '/x', false], ['/a.b', '/aXb', false], ['/(x)', '/(x)', true],
            ['**/admin/**', '/admin', true], ['**/admin/**', '/demo/admin/x', true], ['**/admin/**', '/demo/administrator', false],
        ];
        foreach ($cases as [$glob, $path, $match]) {
            same($match, preg_match(Pattern::fromGlob($glob), $path) === 1, "$glob ~ $path");
        }
        same(1, preg_match(Pattern::fromRegex('^/a#b'), '/a#b'), 'a # in a regex');
    },
    'the format: every keyword fills its setting' => function (): void {
        $s = rulesFrom(<<<'RULES'
            # a comment
            host        www.example.org Example.org     # trailing comment
            trust       10.0.0.0/8 2001:db8::/32
            method      put
            block       /wp-admin/**  *.sql
            block regex ^/(phpmyadmin|adminer)
            cache-query page offset
            cache-path  /  /news/**
            limit       requests 600/min challenge-at 300
            limit       misses   60/min  on-demand
            limit       burst    20/10s
            challenge   /login
            challenge-exempt /api/**
            exempt      192.0.2.50
            set         debug-header on
            set         pass-ttl 30m
            set         ipv6-prefix 56
            set         store file
            set         text.title Einen Moment, bitte
            RULES);
        same(['www.example.org', 'example.org'], $s->hosts);
        same(['10.0.0.0/8', '2001:db8::/32'], $s->trustedProxies);
        same(['GET', 'HEAD', 'POST', 'OPTIONS', 'PUT'], $s->methods);
        truthy(in_array('#^/wp\-admin(?:/.*)?$#', $s->blockedPaths, true), 'glob block');
        truthy(in_array('#^/(phpmyadmin|adminer)#', $s->blockedPaths, true), 'regex block');
        truthy(count($s->blockedPaths) === 3 + 5, 'added to the scanner paths');
        same(['page', 'offset'], $s->cacheableQuery);
        same(['#^/$#', '#^/news(?:/.*)?$#'], $s->cacheablePaths);
        same([600, 60, 300, false], [$s->budgets['requests']->limit, $s->budgets['requests']->window, $s->budgets['requests']->challengeAt, $s->budgets['requests']->onDemand]);
        same([60, 60, true], [$s->budgets['misses']->limit, $s->budgets['misses']->window, $s->budgets['misses']->onDemand]);
        same([20, 10], [$s->budgets['burst']->limit, $s->budgets['burst']->window]);
        same(['#^/login$#'], $s->challenge->alwaysPaths);
        same(['#^/api(?:/.*)?$#'], $s->challenge->exemptPaths);
        same(['127.0.0.1', '::1', '192.0.2.50'], $s->exemptIps);
        same(true, $s->debugHeader);
        same(1800, $s->challenge->passTtl);
        same(56, $s->ipv6Prefix);
        same('file', $s->store);
        same(['title' => 'Einen Moment, bitte'], $s->challenge->texts);
    },
    'taking back: none, any, unblock, no-limit' => function (): void {
        $s = rulesFrom("block /x/**\nunblock /x/**\nunblock @scanners\nexempt none 192.0.2.1\nno-limit requests\ncache-path /a\ncache-path any\nmethod none GET\n");
        same([], $s->blockedPaths, 'the scanner set and the block taken back');
        same(['192.0.2.1'], $s->exemptIps, 'none empties the defaults too');
        same([], $s->budgets, 'no-limit switches the default budget off');
        same(null, $s->cacheablePaths, 'any: every path');
        same(['GET'], $s->methods);
        same([], rulesFrom("cache-query none\n")->cacheableQuery, 'none: no parameter');
        truthy(count(rulesFrom("block @wordpress\n")->blockedPaths) === 5 + 2, 'the WordPress set');
    },
    'the decisions follow the rules' => function (): void {
        $s = rulesFrom("host www.example.org\nblock /wp-admin/**\ncache-path / /page/*\ncache-query page\nchallenge /login\n");
        same('reject blocked path', decideFor($s, '/wp-admin/install.php'));
        same('reject blocked path', decideFor($s, '/backup.sql'), 'the scanner paths stay');
        same('allow', decideFor($s, '/page/about?page=2'));
        same('allow-uncached path not cacheable', decideFor($s, '/page/a/b'));
        same('allow-uncached query parameter', decideFor($s, '/?utm=1'));
        same('challenge always', decideFor($s, '/login'));
        same('reject host', decideFor($s, '/', ['HTTP_HOST' => 'evil.example']));
    },
    'environment variables: ${NAME}, ${NAME:-default}, an error when unset' => function (): void {
        putenv('RSHIELD_TEST_SECRET=' . str_repeat('s', 40));
        same(str_repeat('s', 40), rulesFrom("set secret \${RSHIELD_TEST_SECRET}\n")->challenge->secret);
        putenv('RSHIELD_TEST_SECRET');
        rulesFail(['site.rules' => "set secret \${RSHIELD_TEST_SECRET}\n"], 'site.rules:1', 'RSHIELD_TEST_SECRET is not set');
        same('/srv/x', rulesFrom("set store-dir \${RSHIELD_TEST_DIR:-/srv/x}\n")->storeDir, 'a default');
        $dir = ruleDir(['site.rules' => "set store-dir \${RSHIELD_TEST_DIR:-/srv/x}\n"]);
        try {
            same('/srv/x', Settings::load("$dir/site.rules", "$dir/cache")->storeDir);
            putenv('RSHIELD_TEST_DIR=/srv/y');
            same('/srv/y', Settings::load("$dir/site.rules", "$dir/cache")->storeDir, 'rebuilt when the variable changes');
        } finally {
            putenv('RSHIELD_TEST_DIR');
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'errors name file and line' => function (): void {
        rulesFail(['site.rules' => "host a.example\n\nblok /x\n"], 'site.rules:3', 'unknown rule "blok" (did you mean "block"?)');
        rulesFail(['site.rules' => "block regex ^/(a\n"], 'site.rules:1', 'not a valid regular expression');
        rulesFail(['site.rules' => "limit requests 600/fortnight\n"], 'site.rules:1', 'not a rate');
        rulesFail(['site.rules' => "limit requests 600/min challenge-at\n"], 'site.rules:1', 'limit <name>');
        rulesFail(['site.rules' => "set debug-header maybe\n"], 'site.rules:1', 'on or off');
        rulesFail(['site.rules' => "set debug-heder on\n"], 'site.rules:1', 'did you mean "debug-header"');
        rulesFail(['site.rules' => "set pass-ttl soon\n"], 'site.rules:1', 'a duration');
        rulesFail(['site.rules' => "set text.titel x\n"], 'site.rules:1', 'unknown text');
        rulesFail(['site.rules' => "trust 10.0.0/8x\n"], 'site.rules:1', 'not an address');
        rulesFail(['site.rules' => "block @joomla\n"], 'site.rules:1', 'unknown set');
        rulesFail(['site.rules' => "challenge @scanners\n"], 'site.rules:1', 'only works with block');
        rulesFail(['site.rules' => "unblock /never-blocked\n"], 'site.rules:1', 'nothing to unblock');
        rulesFail(['site.rules' => "host\n"], 'site.rules:1', 'needs at least one value');
        rulesFail(['site.rules' => "include rules.d/10.rules\n", 'rules.d/10.rules' => "host a\nmethod GET!\n"], 'rules.d/10.rules:2', 'not a method');
    },
    'the settings check still applies: a short secret' => function (): void {
        $dir = ruleDir(['site.rules' => "set secret tooshort\n"]);
        try {
            Settings::load("$dir/site.rules", "$dir/cache");
            throw new TestFailure('accepted a short secret');
        } catch (InvalidArgumentException $e) {
            truthy(strpos($e->getMessage(), 'challenge.secret') !== false, $e->getMessage());
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'include: in alphabetical order, relative to the file, not above it, no loops' => function (): void {
        $dir = ruleDir([
            'site.rules' => "include rules.d/*.rules\nblock /site\n",
            'rules.d/90-late.rules' => "limit x 9/min\n",
            'rules.d/10-early.rules' => "limit x 1/min\nblock /early\n",
            'rules.d/50-other.txt' => "this is not read\n",
        ]);
        try {
            $read = RuleFile::read(["$dir/site.rules"]);
            $s = Settings::from($read['config']);
            same(9, $s->budgets['x']->limit, 'the later file wins');
            same(['#^/early$#', '#^/site$#'], array_slice($s->blockedPaths, -2), 'in the order read');
            same('rules.d/90-late.rules:1', $s->origin('budgets', 'x'), 'origin relative to the main file');
            same('site.rules:2', $s->origin('blockedPaths', '#^/site$#'));
            same('default @scanners', $s->origin('blockedPaths', \CjwNetwork\RequestShield\Config::scannerPaths()[0]));
            truthy(isset($read['seen']["$dir/rules.d"]), 'the include directory is watched');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        rulesFail(['site.rules' => "include ../../etc/*.rules\n"], 'site.rules:1', 'is outside');
        rulesFail(['site.rules' => "include missing.rules\n"], 'site.rules:1', 'cannot read');
        rulesFail(['site.rules' => "include a.rules\n", 'a.rules' => "include site.rules\n"], 'a.rules:1', 'includes itself');
        $empty = rulesFrom("include none.d/*.rules\n");
        truthy($empty->budgets !== [], 'a glob matching nothing is fine');
    },
    'sources: several files, the main file last' => function (): void {
        $dir = ruleDir([
            'site.rules' => "limit x 5/min\n",
            'ext/shop/settings/request-shield.rules' => "limit x 1/min\nchallenge /shop/checkout\n",
            'ext/blog/settings/request-shield.rules' => "cache-path /blog/**\n",
        ]);
        try {
            $s = Settings::load("$dir/site.rules", "$dir/cache", ["$dir/ext/*/settings/request-shield.rules"]);
            same(5, $s->budgets['x']->limit, 'the site has the last word');
            same(['#^/shop/checkout$#'], $s->challenge->alwaysPaths);
            same('ext/shop/settings/request-shield.rules:2', $s->origin('challenge.alwaysPaths', '#^/shop/checkout$#'));
            same(['#^/blog(?:/.*)?$#'], $s->cacheablePaths);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'load(): compiled once; a change in any file is noticed (recheck 0)' => function (): void {
        $dir = ruleDir(['site.rules' => "set recheck 0\ninclude rules.d/*.rules\n", 'rules.d/a.rules' => "host a.example\n"]);
        try {
            same(['a.example'], Settings::load("$dir/site.rules", "$dir/cache")->hosts);
            same(1, count(glob("$dir/cache/settings-*.php")), 'compiled');
            // Another size: mtime has whole seconds, so a change of the same
            // size within the same second is not seen (documented).
            file_put_contents("$dir/rules.d/a.rules", "host bb.example\n");
            same(['bb.example'], Settings::load("$dir/site.rules", "$dir/cache")->hosts, 'an included file changed');
            file_put_contents("$dir/rules.d/b.rules", "host c.example\n");
            touch("$dir/rules.d", time() + 2);
            same(['bb.example', 'c.example'], Settings::load("$dir/site.rules", "$dir/cache")->hosts, 'a file added to the include directory');
            // A broken edit is an error, not the old settings quietly kept.
            file_put_contents("$dir/rules.d/b.rules", "hots cc.example\n");
            try {
                Settings::load("$dir/site.rules", "$dir/cache");
                throw new TestFailure('a broken file was accepted');
            } catch (RuleFileException $e) {
                truthy(strpos($e->getMessage(), 'rules.d/b.rules:1') !== false, $e->getMessage());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'load(): with APCu, other files are checked only every "recheck" seconds; the main file without it' => function (): void {
        $dir = ruleDir(['site.rules' => "set recheck 60\ninclude rules.d/*.rules\n", 'rules.d/a.rules' => "host a.example\n"]);
        try {
            same(['a.example'], Settings::load("$dir/site.rules", "$dir/cache")->hosts);
            file_put_contents("$dir/rules.d/a.rules", "host bb.example\n");
            $apcu = function_exists('apcu_enabled') && apcu_enabled();
            same(['a.example'], Settings::load("$dir/site.rules", "$dir/cache")->hosts,
                $apcu ? 'within the interval: not even a stat()' : 'without APCu: only the main file is checked');
            file_put_contents("$dir/site.rules", "set recheck 60\ninclude rules.d/*.rules\n# touched\n");
            if ($apcu) {
                apcu_clear_cache();
            }
            same(['bb.example'], Settings::load("$dir/site.rules", "$dir/cache")->hosts, 'the main file changed: everything read again');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'restrict: paths only for some addresses; //, %61, case and /./ do not get past it' => function (): void {
        $s = rulesFrom("restrict /admin/** /api/** to 192.0.2.0/24 2001:db8::/32\n");
        $from = static fn (string $ip, string $path): string => decideFor($s, $path, ['REMOTE_ADDR' => $ip]);
        foreach (['/admin', '/admin/', '/admin/users', '//admin/', '/%61dmin/', '/ADMIN/x', '/./admin/', '/api/v1'] as $path) {
            same('reject restricted', $from('198.51.100.7', $path), "outside: $path");
        }
        same('allow-uncached path not cacheable', rulesFrom("cache-path /\nrestrict /admin/** to 192.0.2.0/24\n") !== null ? decideFor(rulesFrom("cache-path /\nrestrict /admin/** to 192.0.2.0/24\n"), '/administrator') : '', 'a longer name is another path');
        same('allow', $from('192.0.2.10', '/admin/users'), 'inside the range');
        same('allow', $from('2001:db8::5', '//admin/'), 'IPv6 range');
        // Behind a trusted proxy: the address it vouches for, not the header anyone sends.
        $p = rulesFrom("trust 10.0.0.1\nrestrict /admin/** to 192.0.2.0/24\n");
        same('allow', decideFor($p, '/admin/', ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.0.2.10']));
        same('reject restricted', decideFor($p, '/admin/', ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '192.0.2.10']), 'a forged header');
        rulesFail(['site.rules' => "restrict /admin/**\n"], 'site.rules:1', 'restrict <paths> to');
        rulesFail(['site.rules' => "restrict /admin/** to office\n"], 'site.rules:1', 'not an address');
    },
    'allow: a method only on some paths, anywhere else 405' => function (): void {
        $s = rulesFrom("allow POST /edit /contact\nallow PUT DELETE /api/**\n");
        same(['GET', 'HEAD', 'POST', 'OPTIONS', 'PUT', 'DELETE'], $s->methods, 'the methods are allowed at all');
        $as = static fn (string $method, string $path): string => decideFor($s, $path, ['REQUEST_METHOD' => $method]);
        same('allow-uncached method', $as('POST', '/edit'));
        same('allow-uncached method', $as('POST', '//Contact'), 'as the application routes it');
        same('reject method not allowed here', $as('POST', '/page/about'));
        same('reject method not allowed here', $as('PUT', '/edit'));
        same('allow-uncached method', $as('DELETE', '/api/items/5'));
        same('allow', $as('GET', '/page/about'), 'other methods are not affected');
        rulesFail(['site.rules' => "allow POST\n"], 'site.rules:1', 'allow <METHODS> <paths>');
    },
    'the rule behind a decision: file and line, default, built-in, or the setting' => function (): void {
        $dir = ruleDir(['site.rules' => "# comment\nblock /x/**\nrestrict /admin/** to 192.0.2.1\nallow POST /edit\nlimit requests 2/min\nchallenge /login\n"]);
        try {
            $s = Settings::load("$dir/site.rules", "$dir/cache");
            $explain = static function (string $path, array $server = []) use ($s): ?string {
                $shield = new Shield($s, new MemoryStore());
                $r = Request::fromServer($server + ['REQUEST_URI' => $path, 'REMOTE_ADDR' => '198.51.100.7']);
                return $shield->explain($shield->decide($r, 1000.0), $r);
            };
            same('site.rules:2', $explain('/x/y'));
            same('default @scanners', $explain('/.env'));
            same('site.rules:3', $explain('/admin/'));
            same('site.rules:4', $explain('/page', ['REQUEST_METHOD' => 'POST']));
            same('site.rules:6', $explain('/login'));
            same('built-in', $explain('/a/%2e%2e/b'));
            same(null, $explain('/'), 'allowed: no rule');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        // PHP array settings: the setting and the index.
        $shield = new Shield(['blockedPaths' => ['#^/a#', '#^/b#'], 'budgets' => ['requests' => ['limit' => 1, 'window' => 60]]], new MemoryStore());
        $r = Request::fromServer(['REQUEST_URI' => '/b', 'REMOTE_ADDR' => '198.51.100.7']);
        same('blockedPaths[1]', $shield->explain($shield->decide($r, 1000.0), $r));
    },
    'bin/request-shield: check, show, reload' => function (): void {
        if (!function_exists('exec')) {
            skip('no exec');
        }
        $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/request-shield');
        $dir = ruleDir(['site.rules' => "block /x/**\nlimit requests 5/min\n", 'ext/a.rules' => "challenge /login\n", 'bad.rules' => "blok /x\n"]);
        try {
            exec("$bin check " . escapeshellarg("$dir/site.rules") . ' --source=' . escapeshellarg("$dir/ext/*.rules") . ' 2>&1', $out, $code);
            same(0, $code, implode("\n", $out));
            truthy(strpos(implode("\n", $out), 'ok: 2 file(s)') !== false, implode("\n", $out));
            $out = [];
            exec("$bin check " . escapeshellarg("$dir/bad.rules") . ' 2>&1', $out, $code);
            same(1, $code);
            same('bad.rules:1: unknown rule "blok" (did you mean "block"?)', $out[0] ?? '');
            $out = [];
            exec("$bin show " . escapeshellarg("$dir/site.rules") . ' --source=' . escapeshellarg("$dir/ext/*.rules") . ' 2>&1', $out, $code);
            $shown = implode("\n", $out);
            truthy(preg_match('~^block regex \^/x\(\?:/\.\*\)\?\$ +# site\.rules:1$~m', $shown) === 1, $shown);
            truthy(preg_match('~^challenge regex \^/login\$ +# ext/a\.rules:1$~m', $shown) === 1, 'the extension\'s rule, with its origin');
            truthy(strpos($shown, 'set secret (generated in store-dir)') !== false, 'a secret is never shown');
            touch("$dir/site.rules", time() - 100);
            exec("$bin reload " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            clearstatcache();
            truthy(filemtime("$dir/site.rules") >= time() - 5, 'reload marks the main file changed');
            chmod("$dir/site.rules", 0666);
            $out = [];
            exec("$bin check " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            same(3, $code, 'a file anyone can change');
            truthy(strpos(implode("\n", $out), 'can be changed by anyone') !== false, implode("\n", $out));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
