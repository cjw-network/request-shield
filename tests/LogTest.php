<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Log;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Settings;

function logDir(): string
{
    return sys_get_temp_dir() . '/rshield-log-' . getmypid() . '-' . mt_rand();
}

return [
    'addresses are shortened: IPv4 /24, IPv6 /48' => function (): void {
        same('198.51.100.0', Log::mask('198.51.100.7'));
        same('2001:db8:1::', Log::mask('2001:db8:1:2:3::5'));
        same('-', Log::mask('not an address'));
    },
    'levels: stop, flag, all, off' => function (): void {
        $d = [Decision::allow(), Decision::allowUncached('x'), Decision::challenge('x'), Decision::throttle('x', 5), Decision::reject(404, 'x')];
        $row = static fn (string $level): array => array_map(static fn (Decision $x): bool => Log::wants($level, $x), $d);
        same([false, false, true, true, true], $row('stop'));
        same([false, true, true, true, true], $row('flag'));
        same([true, true, true, true, true], $row('all'));
        same([false, false, false, false, false], $row('off'));
    },
    'a line: address first, decision, rule, request, user agent -- nothing forged' => function (): void {
        $dir = logDir();
        try {
            $s = Settings::from(['log' => ['file' => "$dir/shield.log"]]);
            $r = Request::fromServer(['REQUEST_URI' => "/x\"\n2026-01-01 1.2.3.4 allow", 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'example.org',
                'HTTP_USER_AGENT' => "bot\r\nforged"]);
            Log::write($s, $r, Decision::reject(404, 'blocked path'), 'site.rules:4', 1790000000.0);
            $lines = file("$dir/shield.log", FILE_IGNORE_NEW_LINES);
            same(1, count($lines), 'one line, whatever the request holds');
            truthy(preg_match('#^\S+ 198\.51\.100\.0 reject 404 "blocked path" rule=site\.rules:4 "GET example\.org/x\'\?2026-01-01 1\.2\.3\.4 allow" "bot\?\?forged"$#', $lines[0]) === 1, $lines[0]);
            same('0640', substr(sprintf('%o', fileperms("$dir/shield.log")), -4));
            $full = Settings::from(['log' => ['file' => "$dir/full.log", 'ip' => 'full']]);
            Log::write($full, $r, Decision::reject(404, 'x'), null);
            truthy(strpos((string) file_get_contents("$dir/full.log"), ' 198.51.100.7 reject') !== false, 'log-ip full');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'one rotation when it gets large' => function (): void {
        $dir = logDir();
        try {
            $s = Settings::from(['log' => ['file' => "$dir/shield.log", 'maxSize' => 4096]]);
            $r = Request::fromServer(['REQUEST_URI' => '/' . str_repeat('a', 200), 'REMOTE_ADDR' => '198.51.100.7']);
            for ($i = 0; $i < 60; $i++) {
                Log::write($s, $r, Decision::reject(404, 'x'), null);
            }
            truthy(is_file("$dir/shield.log.1"), 'rotated');
            truthy(filesize("$dir/shield.log") <= 4096 + 400, 'the current file stays small');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'settings: a wrong level or ip is an error; off writes nothing' => function (): void {
        foreach ([['level' => 'verbose'], ['ip' => 'half'], ['file' => '']] as $wrong) {
            try {
                Settings::from(['log' => $wrong]);
                throw new TestFailure('accepted ' . json_encode($wrong));
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), "'log.") !== false, $e->getMessage());
            }
        }
        same(null, Settings::from(['log' => ['file' => '/tmp/x.log', 'level' => 'off']])->logFile);
    },
];
