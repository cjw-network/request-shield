<?php

declare(strict_types=1);

/**
 * bootstrap.php as auto_prepend_file finds the rules on its own: next to it,
 * else in config/ -- the three-line install. A copy of the library in a
 * temporary directory, so the repository's own config/ plays no part.
 */

/**
 * @param array<string, string> $files relative to the library copy => contents
 * @param callable(callable(string): array{status: int, body: string}): void $body
 */
function withLibrary(array $files, ?string $env, callable $body): void
{
    $lib = sys_get_temp_dir() . '/rs-boot-' . getmypid() . '-' . mt_rand();
    mkdir("$lib/docroot", 0700, true);
    foreach (['src', 'rules', 'bootstrap.php'] as $part) {
        exec('cp -r ' . escapeshellarg(dirname(__DIR__) . "/$part") . ' ' . escapeshellarg($lib));
    }
    file_put_contents("$lib/docroot/index.php", '<?php echo "ok " . ($_SERVER["REQUEST_SHIELD"] ?? "-");');
    foreach ($files as $name => $contents) {
        if (!is_dir(dirname("$lib/$name"))) {
            mkdir(dirname("$lib/$name"), 0700, true);
        }
        file_put_contents("$lib/$name", str_replace('__LIB__', $lib, $contents));
    }
    $port = freePort();
    // env -u: whatever this test run has in REQUEST_SHIELD_CONFIG must not reach the server.
    // exec: the shell becomes env, env becomes PHP -- proc_terminate() then ends the server, not a shell around it.
    $proc = proc_open(sprintf('exec env -u REQUEST_SHIELD_CONFIG %s %s -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
        $env !== null ? 'REQUEST_SHIELD_CONFIG=' . escapeshellarg(str_replace('__LIB__', $lib, $env)) : '',
        serverPhp(), escapeshellarg("$lib/bootstrap.php"), $port, escapeshellarg("$lib/docroot")), [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    try {
        $body(static function (string $uri) use ($port): array {
            $out = (string) @file_get_contents("http://127.0.0.1:$port$uri", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
            return ['status' => (int) substr((string) (($http_response_header ?? [])[0] ?? ''), 9, 3), 'body' => $out];
        });
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        exec('rm -rf ' . escapeshellarg($lib));
    }
}

$rules = "set store file\nset store-dir __LIB__/var\nblock /secret/**\n";

return [
    'RSF5.1 request-shield.rules next to bootstrap.php is found without any setting -- the three-line install' => function () use ($rules): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withLibrary(['request-shield.rules' => $rules], null, function (callable $get): void {
            same(404, $get('/secret/x')['status'], 'the rules decide');
            same('ok allow', $get('/')['body'], 'the site answers, and knows');
        });
    },
    'RSF5.1 config/request-shield.rules is found too; a named file wins over both' => function () use ($rules): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withLibrary(['config/request-shield.rules' => $rules], null, function (callable $get): void {
            same(404, $get('/secret/x')['status'], 'config/request-shield.rules decides');
        });
        withLibrary(['request-shield.rules' => $rules, 'named.rules' => "set store file\nset store-dir __LIB__/var\nblock /named/**\n"], '__LIB__/named.rules', function (callable $get): void {
            same(404, $get('/named/x')['status'], 'the named file decides');
            same(200, $get('/secret/x')['status'], 'not the one next to bootstrap.php');
        });
    },
    'RSF5.1 without any settings file the shield does nothing; a named file that is missing means nothing too' => function () use ($rules): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withLibrary([], null, function (callable $get): void {
            same('ok -', $get('/secret/x')['body'], 'no rules, no shield');
        });
        withLibrary(['request-shield.rules' => $rules], '/nonexistent/named.rules', function (callable $get): void {
            same('ok -', $get('/secret/x')['body'], 'the named file is not there: nothing else is taken by surprise');
        });
    },
];
