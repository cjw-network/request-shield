<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\ChallengePage;

/** Shared by the tests: running the challenge page's script in Node. */

function nodeBinary(): ?string
{
    foreach (['node', 'nodejs'] as $name) {
        $path = trim((string) shell_exec('command -v ' . $name . ' 2>/dev/null'));
        if ($path !== '') {
            return $path;
        }
    }
    return null;
}

/** Runs the page's script in Node on $challenge; returns [payload, took ms, hashes/s]. */
function solveInNode(array $challenge): array
{
    $node = nodeBinary();
    $file = sys_get_temp_dir() . '/rshield-js-' . getmypid() . '-' . mt_rand() . '.js';
    // Node before 16 has no btoa; browsers always do.
    $js = "if (typeof btoa === 'undefined') { global.btoa = function (s) { return Buffer.from(s, 'binary').toString('base64'); }; }\n"
        . 'var RS = ' . json_encode(['c' => $challenge]) . ";\n" . ChallengePage::SCRIPT . "\n"
        . "var t = Date.now(); RS.solve(RS.c, function (n, took) { var ms = Date.now() - t; console.log(JSON.stringify({ payload: n < 0 ? null : RS.payload(RS.c, n, took), ms: ms, n: n })); });\n";
    file_put_contents($file, $js);
    $out = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);
    $r = json_decode(trim((string) $out), true);
    if (!is_array($r)) {
        throw new TestFailure('node: ' . trim((string) $out));
    }
    return [$r['payload'], (int) $r['ms'], (int) $r['n']];
}

/**
 * A port nobody listens on, from the operating system -- a guessed one can
 * belong to another service on the machine, which then answers the test.
 */
function freePort(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    if ($probe === false) {
        throw new RuntimeException('no free port');
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * PHP for a server a test starts (php -S): the binary, and -- with
 * TESTS_HOSTING=minimal (the CI leg "minimal hosting", ADR 0013) -- the
 * restrictions of a tight shared host: no APCu, no allow_url_fopen, the shell
 * functions disabled, 64 MB. The shield must decide the same under them.
 */
function serverPhp(): string
{
    $php = escapeshellarg(PHP_BINARY);
    if (getenv('TESTS_HOSTING') === 'minimal') {
        $php .= ' -d apc.enabled=0 -d allow_url_fopen=0 -d memory_limit=64M'
            . ' -d disable_functions=exec,shell_exec,proc_open,popen,system,passthru,putenv,set_time_limit,dl';
    }
    return $php;
}
