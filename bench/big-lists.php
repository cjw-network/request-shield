<?php
/**
 * What a big deny list costs: building the compiled settings once, loading
 * them per request (from OPcache), and the lookup -- for list files of single
 * addresses spread over the internet, as a fail2ban-fed list looks.
 *
 *   php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 bench/big-lists.php [entries...]
 *
 * file_update_protection=0: OPcache otherwise leaves a file younger than 2 s
 * uncached, and the compiled settings here are seconds old.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CjwNetwork\RequestShield\IpTable;
use CjwNetwork\RequestShield\Settings;

$sizes = array_map('intval', array_slice($argv, 1)) ?: [0, 1000, 10000, 100000, 200000];
printf("PHP %s, OPcache %s\n", PHP_VERSION, function_exists('opcache_get_status') && opcache_get_status(false) !== false ? 'on' : 'OFF (load times mean nothing)');
foreach ($sizes as $n) {
    $dir = sys_get_temp_dir() . '/rshield-big-' . getmypid() . "-$n";
    exec('rm -rf ' . escapeshellarg($dir));
    mkdir("$dir/store/lists", 0750, true);
    mt_srand(42);
    $lines = [];
    $listed = '198.18.0.1';
    for ($i = 0; $i < $n; $i++) {
        $listed = mt_rand(1, 223) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
        $lines[] = "[LIST-D$i] deny $listed   # cli";
    }
    file_put_contents("$dir/store/lists/deny.rules", implode("\n", $lines) . "\n");
    file_put_contents("$dir/site.rules", "set store-dir $dir/store\n");
    $t = hrtime(true);
    Settings::load("$dir/site.rules", "$dir/cache");
    $build = (hrtime(true) - $t) / 1e6;
    $t = hrtime(true);
    Settings::load("$dir/site.rules", "$dir/cache");
    $first = (hrtime(true) - $t) / 1e6;
    $m = memory_get_usage();
    $t = hrtime(true);
    for ($i = 0; $i < 2000; $i++) {
        $s = Settings::load("$dir/site.rules", "$dir/cache");
    }
    $load = (hrtime(true) - $t) / 2000 / 1e3;
    $copied = (memory_get_usage() - $m) / 2000;
    $find = [];
    foreach ([$listed, '198.18.0.1'] as $ip) {
        $t = hrtime(true);
        for ($i = 0; $i < 100000; $i++) {
            $s->denyTable === [] || IpTable::find($ip, $s->denyTable);
        }
        $find[] = (hrtime(true) - $t) / 1e8;
    }
    $compiled = (glob("$dir/cache/settings-*.php") ?: [''])[0];
    printf("  %8d entries: build %7.0f ms, compiled %5.1f MB, first include %6.1f ms, load %5.1f µs/request (%d bytes copied), lookup %.2f µs (hit) %.2f µs (miss)\n",
        $n, $build, (int) @filesize($compiled) / 1048576, $first, $load, (int) $copied, $find[0], $find[1]);
    exec('rm -rf ' . escapeshellarg($dir));
}
