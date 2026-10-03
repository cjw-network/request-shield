<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php bench/single-file.php [requests]
 *
 * The single file against the source tree (proposal 0002, "Size, OPcache,
 * speed"): the same rules, the same page, on PHP's built-in server with
 * OPcache and APCu -- one server with the built request-shield.php as
 * auto_prepend_file, one with bootstrap.php (the autoloader), one with
 * nothing. Prints the time per request of each (the server's own share is in
 * all three; the differences are the shield's), the file's size and the
 * OPcache memory it takes.
 */

declare(strict_types=1);

$n = max(100, (int) ($argv[1] ?? 2000));
$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/rs-bench-single-' . getmypid();
mkdir("$dir/docroot", 0700, true);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/build/single-file.php") . ' --out=' . escapeshellarg("$dir/request-shield.php") . ' --build=bench', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, implode("\n", $out) . "\n");
    exit(1);
}
file_put_contents("$dir/request-shield.rules", "host 127.0.0.1\n");
file_put_contents("$dir/docroot/index.php", '<?php echo "ok";');
file_put_contents("$dir/docroot/opcache.php", '<?php $s = opcache_get_status(true); $m = 0; foreach ($s["scripts"] ?? [] as $path => $x) { if (substr($path, -18) === "request-shield.php") { $m = $x["memory_consumption"]; } } echo json_encode(["on" => $s !== false && $s["opcache_enabled"], "memory" => $m]);');

/** @return array{0: resource, 1: int} */
function server(string $prepend, string $docroot, array $env): array
{
    $port = (static function (): int {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int) substr((string) strrchr((string) $name, ':'), 1);
    })();
    $flags = '-d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d apc.enable_cli=1' . ($prepend !== '' ? ' -d auto_prepend_file=' . escapeshellarg($prepend) : '');
    $envs = '';
    foreach ($env as $k => $v) {
        $envs .= $v === null ? " -u $k" : " $k=" . escapeshellarg($v);
    }
    $proc = proc_open("exec env$envs " . escapeshellarg(PHP_BINARY) . " $flags -S 127.0.0.1:$port -t " . escapeshellarg($docroot) . ' > /dev/null 2>&1', [], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    return [$proc, $port];
}

function timed(int $port, int $n): float
{
    for ($i = 0; $i < 200; $i++) {
        file_get_contents("http://127.0.0.1:$port/");          // warm: OPcache, the compiled rules, APCu
    }
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        file_get_contents("http://127.0.0.1:$port/");
    }
    return (hrtime(true) - $t) / $n / 1000;
}

$runs = [
    'nothing' => ['', []],
    'source tree (bootstrap.php)' => ["$root/bootstrap.php", ['REQUEST_SHIELD_CONFIG' => "$dir/request-shield.rules"]],
    'single file' => ["$dir/request-shield.php", ['REQUEST_SHIELD_CONFIG' => null]],
];
echo 'PHP ' . PHP_VERSION . ", $n requests each, PHP's built-in server with OPcache and APCu\n";
$base = null;
foreach ($runs as $name => [$prepend, $env]) {
    [$proc, $port] = server($prepend, "$dir/docroot", $env);
    $us = timed($port, $n);
    $base ??= $us;
    $extra = '';
    if ($name === 'single file') {
        $o = json_decode((string) file_get_contents("http://127.0.0.1:$port/opcache.php"), true);
        $extra = sprintf('; %d bytes, OPcache %s', filesize("$dir/request-shield.php"), is_array($o) && $o['on'] ? round($o['memory'] / 1048576, 1) . ' MB' : 'off');
    }
    printf("  %-28s %7.1f µs per request (%+.1f µs)%s\n", $name, $us, $us - $base, $extra);
    proc_terminate($proc);
    proc_close($proc);
}
exec('rm -rf ' . escapeshellarg($dir));
