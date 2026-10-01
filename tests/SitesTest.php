<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;

/** Rules per website (proposal 0024): site blocks in one rule file. */

const SITES_RULES = <<<'RULES'
set store-dir %DIR%/store
[BASE-PACE] limit requests 300/min
[BASE-OLD] block **/old/**

site shop.a.de a.de {
  match /admin/** {
    [SHOP-ADM] restrict to 192.0.2.0/24     # the office only
  }
  query strict
  no-limit requests
  [SHOP-PACE] limit shop 120/min
}

site *.b.de {
  include b.rules
}

site default {
  set mode strict
}
RULES;

function sitesDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-sites-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    file_put_contents("$dir/main.rules", str_replace('%DIR%', $dir, SITES_RULES));
    file_put_contents("$dir/b.rules", "[B-NEWS] challenge **/login\n");
    return $dir;
}

return [
    'reading: the base for every website, a site block added to it -- each website its own settings' => function (): void {
        $dir = sitesDir();
        try {
            $base = Settings::from(RuleFile::read(["$dir/main.rules"])['config']);
            same(['shop.a.de' => 'shop.a.de', 'a.de' => 'shop.a.de', '*.b.de' => '*.b.de', 'default' => 'default'], $base->sites, 'the names, each to its block');
            same([null, 'enforce', [], false], [$base->site, $base->mode, $base->restricted, $base->queryStrict], 'the base: no block\'s rules');
            truthy(isset($base->budgets['requests']) && !isset($base->budgets['shop']), 'the base\'s pace');
            $shop = Settings::from(RuleFile::read(["$dir/main.rules"], 'shop.a.de')['config']);
            same(['shop.a.de', 1, true], [$shop->site, count($shop->restricted), $shop->queryStrict], 'the shop: its admin area, strict parameters');
            truthy(!isset($shop->budgets['requests']) || $shop->budgets['requests']->limit === 0, 'the base\'s pace switched off for the shop (no-limit)');
            truthy(isset($shop->budgets['shop']) && $shop->budgets['shop']->limit === 120, 'its own budget');
            truthy($shop->origin('at', 'BASE-OLD') !== null && count($shop->blockedPaths) === count($base->blockedPaths), 'the base\'s rules apply to the shop too');
            $news = Settings::from(RuleFile::read(["$dir/main.rules"], '*.b.de')['config']);
            same(['B-NEWS'], array_values(array_filter(array_keys($news->origins['at'] ?? []), static fn (string $id): bool => strncmp($id, 'B-', 2) === 0)), 'its include, read inside the block');
            same('strict', Settings::from(RuleFile::read(["$dir/main.rules"], 'default')['config'])->mode, 'default: its own mode');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'which website: the exact name, *.<rest> (one label), default; case, port and a trailing dot do not matter' => function (): void {
        $dir = sitesDir();
        try {
            $s = Settings::from(RuleFile::read(["$dir/main.rules"])['config']);
            foreach (['shop.a.de' => 'shop.a.de', 'A.DE:443' => 'shop.a.de', 'a.de.' => 'shop.a.de', 'news.b.de' => '*.b.de', 'x.news.b.de' => 'default',
                'b.de' => 'default', 'other.org' => 'default', '' => 'default'] as $name => $site) {
                same($site, $s->siteFor(['SERVER_NAME' => $name]), "\"$name\"");
            }
            same(null, Settings::from([])->siteFor(['SERVER_NAME' => 'a.de']), 'no site blocks: the settings as they are');
            file_put_contents("$dir/nodefault.rules", "site a.de {\n  set mode strict\n}\n");
            same(null, Settings::from(RuleFile::read(["$dir/nodefault.rules"])['config'])->siteFor(['SERVER_NAME' => 'other.org']), 'no default block: the base');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'which name decides: the web server\'s (server-name) whatever Host says; with site-from host the Host, X-Forwarded-Host only from a trusted proxy' => function (): void {
        $dir = sitesDir();
        try {
            $s = Settings::from(RuleFile::read(["$dir/main.rules"])['config']);
            same('server-name', $s->siteFrom, 'the default');
            same('shop.a.de', $s->siteFor(['SERVER_NAME' => 'shop.a.de', 'HTTP_HOST' => 'news.b.de']), 'a Host header cannot pick another website\'s rules');
            file_put_contents("$dir/host.rules", "set site-from host\ntrust 10.0.0.1\n" . substr((string) file_get_contents("$dir/main.rules"), (int) strpos((string) file_get_contents("$dir/main.rules"), 'site shop')));
            $h = Settings::from(RuleFile::read(["$dir/host.rules"])['config']);
            same('*.b.de', $h->siteFor(['SERVER_NAME' => 'shop.a.de', 'HTTP_HOST' => 'news.b.de']), 'site-from host: the Host header');
            same('shop.a.de', $h->siteFor(['HTTP_HOST' => 'news.b.de', 'HTTP_X_FORWARDED_HOST' => 'shop.a.de', 'REMOTE_ADDR' => '10.0.0.1']), 'from a trusted proxy: X-Forwarded-Host');
            same('*.b.de', $h->siteFor(['HTTP_HOST' => 'news.b.de', 'HTTP_X_FORWARDED_HOST' => 'shop.a.de', 'REMOTE_ADDR' => '203.0.113.9']), 'from anyone else: ignored');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'mistakes in site blocks name their line' => function (): void {
        $dir = sitesDir();
        try {
            $cases = [
                "site a.de {\n  limit x 1/min\n}\nlimit y 2/min\n" => 'a rule for every website after a site block',
                "site a.de {\n  trust 10.0.0.1\n}\n" => 'trust is about the server',
                "site a.de {\n  set store-dir /tmp\n}\n" => 'set store-dir is about the server',
                "site a.de {\n  set site-from host\n}\n" => 'set site-from is about the server',
                "site a.de {\n}\nsite b.de a.de {\n}\n" => 'a.de is in two site blocks -- already at bad.rules:1',
                "site a.de {\n  site b.de {\n  }\n}\n" => 'a site block holds no site blocks',
                "site a.de {\n  block **/x/**\n" => 'site without its }',
                "match /x/** {\n  site a.de {\n  }\n}\n" => 'site goes outside match blocks',
                "site a_b.de {\n}\n" => 'is not a website\'s name',
                "site **.a.de {\n}\n" => 'is not a website\'s name',
                "[X-1] site a.de {\n}\n" => 'IDs go on the rules inside a block',
                "site a.de\n" => 'site <names> {',
                "set site-from somewhere\n" => 'site-from is server-name',
            ];
            foreach ($cases as $text => $says) {
                file_put_contents("$dir/bad.rules", $text);
                try {
                    $read = RuleFile::read(["$dir/bad.rules"]);
                    foreach (array_unique(array_values((array) ($read['config']['sites'] ?? []))) as $site) {
                        RuleFile::read(["$dir/bad.rules"], (string) $site);
                    }
                    throw new TestFailure('accepted: ' . json_encode($text));
                } catch (RuleFileException $e) {
                    truthy(strpos($e->getMessage(), $says) !== false && strpos($e->getMessage(), 'bad.rules:') === 0, $e->getMessage());
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'loading: every website compiled with the base; a site\'s settings taken with the base\'s check; an included file noticed with the main file' => function (): void {
        $dir = sitesDir();
        try {
            $run = static function () use ($dir): string {
                $code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
                    . '$b = CjwNetwork\RequestShield\Settings::load(' . var_export("$dir/main.rules", true) . ', ' . var_export("$dir/cache", true) . ');'
                    . '$s = CjwNetwork\RequestShield\Settings::load(' . var_export("$dir/main.rules", true) . ', ' . var_export("$dir/cache", true) . ', [], $b->siteFor(["SERVER_NAME" => "news.b.de"]));'
                    . 'echo $s->site, " ", implode(",", array_values(array_filter(array_keys($s->origins["at"] ?? []), fn ($i) => strncmp($i, "B-", 2) === 0)));';
                exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out);
                return implode("\n", $out);
            };
            same('*.b.de B-NEWS', $run(), 'built: the base and every website');
            same(4, count(glob("$dir/cache/settings-*.php") ?: []), 'the base and three websites, compiled');
            same('*.b.de B-NEWS', $run(), 'from the compiled files');
            $for = static fn (string $name): ?string => Settings::loadFor("$dir/main.rules", ['SERVER_NAME' => $name], "$dir/cache")->site;
            same(['shop.a.de', '*.b.de', 'default'], [$for('a.de'), $for('news.b.de'), $for('elsewhere.org')], 'loadFor(): the website\'s settings, picked before any are made');
            file_put_contents("$dir/plain.rules", "set store-dir $dir/store\n");
            same(null, Settings::loadFor("$dir/plain.rules", ['SERVER_NAME' => 'a.de'], "$dir/cache")->site, 'without site blocks: the settings as they are');
            sleep(1);
            file_put_contents("$dir/b.rules", "[B-LOGIN] challenge **/login\n");
            touch("$dir/main.rules");                                   // as bin/request-shield reload does
            same('*.b.de B-LOGIN', $run(), 'a file only a site block includes: noticed with the main file');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'the real path: one rule file, three websites -- each request gets its website\'s rules; check lists them, trace takes the website from the address' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $dir = sitesDir();
        mkdir("$dir/docroot");
        file_put_contents("$dir/docroot/index.php", '<?php echo "ok";');
        file_put_contents("$dir/host.rules", "set store-dir $dir/store\nset site-from host\nhost shop.a.de a.de news.b.de other.org\nexempt none\n" . substr((string) file_get_contents("$dir/main.rules"), (int) strpos((string) file_get_contents("$dir/main.rules"), '[BASE-PACE]')));
        $port = freePort();
        $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1', escapeshellarg("$dir/host.rules"),
            escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__) . '/bootstrap.php'), $port, escapeshellarg("$dir/docroot")), [], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        try {
            $get = static function (string $host, string $uri) use ($port): string {
                $ctx = stream_context_create(['http' => ['header' => "Host: $host\r\nUser-Agent: Mozilla/5.0 Firefox/136.0\r\n", 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]]);
                @file_get_contents("http://127.0.0.1:$port$uri", false, $ctx);
                return substr((string) ($http_response_header[0] ?? ''), 9, 3);
            };
            same(['403', '200', '200'], [$get('shop.a.de', '/admin/users'), $get('news.b.de', '/admin/users'), $get('other.org', '/admin/users')], 'the admin area is the shop\'s rule');
            same(['404', '404'], [$get('shop.a.de', '/old/x'), $get('news.b.de', '/old/x')], 'the base\'s block on every website');
            same(['404', '200'], [$get('shop.a.de', '/?unknown=1'), $get('news.b.de', '/?unknown=1')], 'query strict: the shop only');
            same('429', $get('news.b.de', '/login'), 'the browser check of *.b.de (from its include)');
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/request-shield');
            exec("$bin check " . escapeshellarg("$dir/main.rules") . ' 2>&1', $out, $code);
            truthy($code === 0 && strpos(implode("\n", $out), 'sites: shop.a.de (a.de) · *.b.de · default; picked by server-name') !== false, implode("\n", $out));
            $out = [];
            exec("$bin trace " . escapeshellarg("$dir/main.rules") . ' "GET https://shop.a.de/admin/x" 2>&1', $out, $code);
            truthy($code === 4 && strpos(implode("\n", $out), 'site: shop.a.de') !== false && strpos(implode("\n", $out), 'Decided by SHOP-ADM') !== false, implode("\n", $out));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
'budgets: the base\'s count across every website; one written in a site block counts on that website only -- equal names in two blocks never share a counter' => function (): void {
        $dir = sitesDir();
        try {
            file_put_contents("$dir/budgets.rules", "set store memory\n[BASE-PACE] limit requests 5/min\n"
                . "site a.de {\n  [A-SEARCH] limit search 2/min on-demand\n}\nsite b.de {\n  [B-SEARCH] limit search 2/min on-demand\n}\n");
            $base = Settings::from(RuleFile::read(["$dir/budgets.rules"])['config']);
            $a = Settings::from(RuleFile::read(["$dir/budgets.rules"], 'a.de')['config']);
            $b = Settings::from(RuleFile::read(["$dir/budgets.rules"], 'b.de')['config']);
            same(['requests', 'requests', 'requests'], [$base->budgets['requests']->counter(), $a->budgets['requests']->counter(), $b->budgets['requests']->counter()], 'the base\'s counter: one for every website');
            same(['a.de@search', 'b.de@search'], [$a->budgets['search']->counter(), $b->budgets['search']->counter()], 'a website\'s own: its own counter');
            $store = new \CjwNetwork\RequestShield\Store\MemoryStore();
            $req = static fn (string $host): \CjwNetwork\RequestShield\Request => \CjwNetwork\RequestShield\Request::fromServer(['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => $host, 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/136.0']);
            $actions = [];
            foreach ([[$a, 'a.de'], [$b, 'b.de'], [$a, 'a.de'], [$b, 'b.de'], [$a, 'a.de'], [$b, 'b.de']] as $i => [$s, $host]) {
                $shield = new \CjwNetwork\RequestShield\Shield($s, $store);
                $r = $req($host);
                $actions[] = $shield->decide($r, 1000.0 + $i)->action;
                $shield->consume('search', $r, 1000.0 + $i);
            }
            same(['allow', 'allow', 'allow', 'allow', 'allow', 'throttle'], $actions, 'the base\'s 5 a minute across both websites: the sixth request is one too many');
            $shield = new \CjwNetwork\RequestShield\Shield($a, $store);
            same('throttle', $shield->consume('search', $req('a.de'), 1010.0)->action, 'a.de: its third search in the minute');
            same([4.0, 3.0], [$store->peek('a.de@search:203.0.113.9', 60, 1010.0), $store->peek('b.de@search:203.0.113.9', 60, 1010.0)], 'counted apart: a.de 4, b.de 3 -- not 7 on one');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
