<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Live;
use CjwNetwork\RequestShield\Log;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Sink;
use CjwNetwork\RequestShield\Tests\RsTestExtension;
use CjwNetwork\RequestShield\Tests\SinkPlugin;

/**
 * The Sink capability (0031 B.9): Log::note() hands its record to the live
 * view and to every plugin with the capability; one that throws is left out
 * and noted, the log is written all the same. Uses ruleDir() from
 * RuleFileTest.php and withDashboard() from DashboardTest.php.
 */

function sinkSettings(string $rules, string $dir): Settings
{
    @mkdir($dir, 0700, true);
    file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\n" . $rules);
    return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
}

function sinkRequest(string $path = '/wp-login.php'): Request
{
    return Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'HTTP_HOST' => 'a.example', 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Test'], []);
}

return [
    'RSF06-04 hooks: a plugin with the capability is recorded as a sink; Live is a Sink itself' => function (): void {
        $dir = ruleDir([]);
        try {
            $s = sinkSettings("plugin CjwNetwork\\RequestShield\\Tests\\SinkPlugin\n", $dir);
            same(['sink' => [SinkPlugin::class]], $s->hooks, 'recorded by instanceof under its hook name');
            truthy(new Live($s) instanceof Sink, 'the live view is the first sink');
            same([], sinkSettings("host a.example\n", $dir)->hooks, 'no plugin: no hook');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-04 Log::note(): the record reaches the log file, the live view and the plugin sinks' => function (): void {
        $dir = ruleDir([]);
        try {
            $s = sinkSettings("set log $dir/shield.log\nset live on\nplugin CjwNetwork\\RequestShield\\Tests\\SinkPlugin\n", $dir);
            SinkPlugin::$heard = [];
            Log::note($s, sinkRequest(), Decision::reject(404, 'blocked path'), 'SCAN-WP', microtime(true));
            same([['action' => 'reject', 'status' => 404, 'rule' => 'SCAN-WP', 'monitor' => false, 'path' => '/wp-login.php']], SinkPlugin::$heard, 'the plugin sink heard it');
            truthy(strpos((string) file_get_contents("$dir/shield.log"), ' reject 404 "blocked path" rule=SCAN-WP ') !== false, 'the log file has it');
            $live = Live::read($s, null);
            same(1, count($live['rows']), 'the live view has it');
            same('203.0.113.7', $live['rows'][0]['client'], 'with the full address, as the live view keeps it');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-04 a sink that throws is left out for that request and noted once a minute; the log and the live view are written all the same; monitor is passed on' => function (): void {
        Vocabulary::forget();
        $dir = ruleDir([]);
        try {
            Vocabulary::offer(RsTestExtension::class);
            $s = sinkSettings("set log $dir/shield.log\nset live on\nplugin CjwNetwork\\RequestShield\\Tests\\SinkPlugin\nset fail-at sink\n", $dir);
            SinkPlugin::$heard = [];
            $log = ini_set('error_log', "$dir/php-errors.log");
            try {
                Log::note($s, sinkRequest(), Decision::reject(404, 'blocked path'), 'SCAN-WP', microtime(true), true);
            } finally {
                ini_set('error_log', (string) $log);
            }
            same([], SinkPlugin::$heard, 'the failing sink heard nothing it could keep');
            truthy(strpos((string) file_get_contents("$dir/shield.log"), ' monitor-reject 404 ') !== false, 'the log file has the record, with monitor');
            same('monitor-reject', Live::read($s, null)['rows'][0]['action'] ?? null, 'the live view too');
            $errors = (string) @file_get_contents("$dir/php-errors.log");
            truthy(strpos($errors, 'SinkPlugin failed, the record went to the others: the sink failed, as asked (' . $s->storeDir . ')') !== false, 'noted in PHP\'s error log: ' . $errors);
        } finally {
            Vocabulary::reset();
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-04 end to end: a failing sink changes nothing for the visitor -- the refusal stands, the log is written' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withDashboard("set log __DIR__/shield.log\nplugin CjwNetwork\\RequestShield\\Tests\\SinkPlugin\nset fail-at sink\n", function (callable $get, string $dir): void {
            $r = $get('/index.php/.env');
            same(404, $r['status'], 'refused as always');
            truthy(strpos((string) file_get_contents("$dir/shield.log"), ' reject 404 "blocked path" ') !== false, 'the log has the refusal');
            same(200, $get('/page')['status'], 'a passing request: untouched');
        });
    },
];
