<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Tier;

/**
 * The tiers (ADR 0013): PHP is the only requirement. S0 -- no writable
 * directory, no APCu -- still decides with the stateless rules; S1 counts in
 * files; S2 in APCu. The same requests, the same decisions.
 */

/**
 * @param callable(callable(string): array{status: int, body: string}, string): void $body gets a GET function and the directory
 * @param bool $readOnly S0: nothing can be made next to the rules
 * @param bool $apcu S2: APCu on in the server (the built-in server has it unless told otherwise)
 */
function withTier(string $rules, callable $body, bool $readOnly = false, bool $apcu = false): void
{
    $dir = sys_get_temp_dir() . '/rs-tier-' . getmypid() . '-' . mt_rand();
    mkdir("$dir/docroot", 0700, true);
    mkdir("$dir/site", 0700);
    file_put_contents("$dir/docroot/index.php", '<?php echo "ok " . ($_SERVER["REQUEST_SHIELD"] ?? "-");');
    file_put_contents("$dir/site/site.rules", "set recheck 0\ntrust 127.0.0.1\n" . $rules);     // trust: the client is the X-Forwarded-For below, not the loopback (never counted)
    if ($readOnly) {
        chmod("$dir/site", 0500);                 // nothing can be made next to the rules: no cache, no store
    }
    $port = freePort();
    $proc = proc_open(sprintf('REQUEST_SHIELD_CONFIG=%s exec %s -d apc.enabled=%d -d auto_prepend_file=%s -d log_errors=1 -d error_log=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
        escapeshellarg("$dir/site/site.rules"), serverPhp(), $apcu ? 1 : 0, escapeshellarg(rsEntry()), escapeshellarg("$dir/php-errors.log"), $port, escapeshellarg("$dir/docroot")), [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body(static function (string $uri) use ($port): array {
            $out = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10, 'header' => "X-Forwarded-For: 198.51.100.7\r\n"]]));
            return ['status' => (int) substr((string) (($http_response_header ?? [])[0] ?? ''), 9, 3), 'body' => $out];
        }, $dir);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        @chmod("$dir/site", 0700);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

function rootHere(): bool
{
    $probe = sys_get_temp_dir() . '/rs-root-' . getmypid() . '-' . mt_rand();
    mkdir($probe, 0500);
    $root = is_writable($probe);
    rmdir($probe);
    return $root;
}

return [
    'RSF5.2 the defaults: the compiled settings and the store live in .request-shield/ next to the rules, not in the temp dir' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-defaults-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/site.rules", "host example.org\n");
            $read = RuleFile::read(["$dir/site.rules"]);
            same((string) realpath($dir) . '/.request-shield/store', $read['config']['storeDir'] ?? null, 'the store next to the rules');
            same("$dir/.request-shield", Settings::cacheDirFor("$dir/site.rules"), 'the compiled settings next to the rules');
            $s = Settings::load("$dir/site.rules");
            truthy(glob("$dir/.request-shield/settings-*.php") !== [], 'compiled there');
            same((string) realpath($dir) . '/.request-shield/store', $s->storeDir);
            file_put_contents("$dir/own.rules", "set store-dir $dir/var\n");
            same("$dir/var", Settings::load("$dir/own.rules")->storeDir, 'set store-dir wins');
            file_put_contents("$dir/site.php", '<?php return ["hosts" => ["example.org"]];');
            same("$dir/.request-shield/store", Settings::load("$dir/site.php")->storeDir, 'a PHP settings file: next to it too');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF5.2 Tier::of names S0, S1, S2 and what is off' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-tierof-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        mkdir("$dir/ro", 0500);
        try {
            $s1 = Settings::from(['store' => 'file', 'storeDir' => "$dir/store"]);
            $t = Tier::of($s1, "$dir/cache");
            same('S1', $t['tier'], 'a writable directory, the file store: ' . implode('; ', $t['why']));
            same([], $t['off']);
            if (!rootHere()) {
                $t = Tier::of(Settings::from(['store' => 'file', 'storeDir' => "$dir/ro/store"]), "$dir/ro/cache");
                same('S0', $t['tier'], 'nothing writable: ' . implode('; ', $t['why']));
                same(2, count($t['off']), 'compiled every request, and nothing counted: ' . implode(' | ', $t['off']));
            }
            $t = Tier::of(Settings::from(['store' => 'apcu', 'storeDir' => "$dir/store"]), "$dir/cache");
            same('S2', $t['tier'], 'APCu asked for');
            $t = Tier::of(Settings::from(['store' => 'memory']), "$dir/cache");
            same('S0', $t['tier'], 'the memory store keeps nothing');
        } finally {
            @chmod("$dir/ro", 0700);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF5.2 S0 on the real path: rules in a directory nobody can write -- the stateless rules decide, the pace cannot count, the site answers' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        if (rootHere()) {
            skip('running as root: a read-only directory is still writable');
        }
        withTier("block /secret/**\nlimit requests 2/min\nhost 127.0.0.1\n", function (callable $get, string $dir): void {
            for ($i = 0; $i < 5; $i++) {
                same(404, $get('/secret/x')['status'], "request $i: a blocked path is refused -- stateless");
                same('ok allow', $get('/')['body'], "request $i: the pace cannot be counted, so it does not bite");
            }
            truthy(!is_dir("$dir/site/.request-shield"), 'nothing could be made next to the rules');
            same('', trim((string) @file_get_contents("$dir/php-errors.log")), 'no error: compiled on every request, quietly');
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' check ' . escapeshellarg("$dir/site/site.rules") . ' 2>&1', $out, $code);
            $text = implode("\n", $out);
            truthy(strpos($text, 'tier: S0') !== false, "check names the tier:\n$text");
            truthy(strpos($text, 'warning: nothing is counted') !== false, "and what is off:\n$text");
            same(3, $code, 'check exits 3: warnings');
        }, true);
    },
    'RSF5.2 S2 on the real path: APCu -- the pace counts in memory, no counter files' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        if (!extension_loaded('apcu') || getenv('TESTS_HOSTING') === 'minimal') {
            skip('no APCu extension here (or the minimal-hosting run)');
        }
        withTier("block /secret/**\nlimit requests 2/min\nhost 127.0.0.1\n", function (callable $get, string $dir): void {
            same(404, $get('/secret/x')['status']);
            same(200, $get('/')['status'], 'first');
            same(200, $get('/')['status'], 'second');
            same(429, $get('/')['status'], 'the third is one too many: counted in APCu');
            same([], glob("$dir/site/.request-shield/store/*/*.c"), 'no counter files: the memory counted');
        }, false, true);
    },
    'RSF5.2 the same requests, the same decisions at S0, S1 and S2 -- the stateless rules do not depend on the tier' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        $rules = "block /secret/**\nhost 127.0.0.1\nallow POST /contact\nrestrict /admin/** to 192.0.2.0/24\ncache-query page\n";
        $ask = ['/', '/secret/x', '/admin/users', '/contact', '/about?utm_source=x'];
        $decide = static function (callable $get) use ($ask): array {
            $out = [];
            foreach ($ask as $uri) {
                $r = $get($uri);
                $out[$uri] = $r['status'] . ' ' . $r['body'];
            }
            return $out;
        };
        $got = [];
        withTier($rules, function (callable $get) use (&$got, $decide): void { $got['S1'] = $decide($get); });
        if (!rootHere()) {
            withTier($rules, function (callable $get) use (&$got, $decide): void { $got['S0'] = $decide($get); }, true);
        }
        if (extension_loaded('apcu') && getenv('TESTS_HOSTING') !== 'minimal') {
            withTier($rules, function (callable $get) use (&$got, $decide): void { $got['S2'] = $decide($get); }, false, true);
        }
        same('200 ok allow', $got['S1']['/'], 'the page passes, cacheable');
        same('404 ', substr($got['S1']['/secret/x'], 0, 4), 'a blocked path');
        same('403', substr($got['S1']['/admin/users'], 0, 3), 'a restricted area');
        same('200 ok allow-uncached', $got['S1']['/about?utm_source=x'], 'an unknown parameter: uncached');
        foreach ($got as $tier => $decisions) {
            same($got['S1'], $decisions, "$tier decides as S1 does");
        }
        truthy(count($got) >= 2, 'at least two tiers compared: ' . implode(', ', array_keys($got)));
    },
    'RSF5.2 S1 on the real path: a writable directory, no APCu -- the pace counts in files' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withTier("block /secret/**\nlimit requests 2/min\nhost 127.0.0.1\n", function (callable $get, string $dir): void {
            same(404, $get('/secret/x')['status']);
            same(200, $get('/')['status'], 'first');
            same(200, $get('/')['status'], 'second');
            same(429, $get('/')['status'], 'the third is one too many: counted in files');
            truthy(is_dir("$dir/site/.request-shield/store"), 'the store next to the rules');
            truthy(glob("$dir/site/.request-shield/settings-*.php") !== [], 'the compiled settings next to the rules');
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' version ' . escapeshellarg("$dir/site/site.rules") . ' 2>&1', $out, $code);
            truthy(strpos(implode("\n", $out), 'tier: S1') !== false, "version names the tier:\n" . implode("\n", $out));
        });
    },
];
