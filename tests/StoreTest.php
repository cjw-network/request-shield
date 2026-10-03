<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Store\ApcuStore;
use CjwNetwork\RequestShield\Store\FileStore;
use CjwNetwork\RequestShield\Store\MemoryStore;
use CjwNetwork\RequestShield\Store\Store;

function slidingWindowChecks(Store $s): void
{
    $t = 1000 * 60.0;                       // start of a 60 s window
    for ($i = 1; $i <= 10; $i++) {
        same((float) $i, $s->hit('k', 60, $t + 1), "hit $i");
    }
    same(0.0, $s->peek('other', 60, $t + 1), 'another key is separate');
    // Half into the next window: 10 * 0.5 from the previous one, plus this hit.
    same(6.0, $s->hit('k', 60, $t + 90), 'previous window weighted');
    // Two windows later nothing is left.
    same(1.0, $s->hit('k', 60, $t + 300), 'old windows forgotten');
}

return [
    'RSF03-01 memory store: sliding window' => function (): void {
        slidingWindowChecks(new MemoryStore());
    },
    'RSF03-01 file store: sliding window' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-test-' . getmypid() . '-' . mt_rand();
        slidingWindowChecks(new FileStore($dir, 0.0));
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'RSF03-01 file store: concurrent processes lose no count' => function (): void {
        if (!function_exists('pcntl_fork')) {
            skip('no pcntl');
        }
        $dir = sys_get_temp_dir() . '/rshield-test-' . getmypid() . '-' . mt_rand();
        $store = new FileStore($dir, 0.0);
        $now = 5000 * 60.0 + 1;
        $children = [];
        for ($p = 0; $p < 8; $p++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                for ($i = 0; $i < 250; $i++) {
                    $store->hit('shared', 60, $now);
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        same(2000.0, $store->peek('shared', 60, $now), '8 processes x 250 hits');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'RSF03-01 file store: sweep removes only past windows' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-test-' . getmypid() . '-' . mt_rand();
        $store = new FileStore($dir, 0.0);
        $store->hit('old', 60, 100 * 60.0);
        $store->hit('new', 60, 200 * 60.0);
        same(1, $store->sweep(200 * 60.0 + 1), 'one file removed');
        same(1.0, $store->peek('new', 60, 200 * 60.0 + 1), 'current window kept');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'RSF03-01 file store: an unwritable directory never blocks' => function (): void {
        same(0.0, (new FileStore('/proc/no-such-dir', 0.0))->hit('k', 60, 1.0));
    },
    'RSF03-01 apcu store: sliding window (when APCu is enabled here)' => function (): void {
        if (!ApcuStore::usable()) {
            skip('APCu not enabled (php -d apc.enable_cli=1)');
        }
        slidingWindowChecks(new ApcuStore('rshield-test-' . mt_rand() . ':'));
    },
];
