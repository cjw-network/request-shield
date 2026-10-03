<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;
use CjwNetwork\RequestShield\Tests\HandlerPlugin;
use CjwNetwork\RequestShield\Tests\RsTestExtension;

/**
 * The Handler capability (0031 C.4): a plugin answers a passing request
 * itself, after every rule and the shield's own pages; null or a failure
 * means the application runs. Uses ruleDir() from RuleFileTest.php and
 * withDashboard() from DashboardTest.php.
 */

function handlerSettings(string $rules, string $dir): Settings
{
    @mkdir($dir, 0700, true);
    file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nhost a.example\n" . $rules);
    return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
}

function handlerRequest(string $uri): Request
{
    return Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'A.Example', 'REMOTE_ADDR' => '203.0.113.7', 'HTTPS' => 'on'], []);
}

return [
    'RSF6.4 Request::cacheKey(): scheme and host in lower case, the routed path, the parameters sorted; nothing of the client' => function (): void {
        same('https://a.example/news/2026/?a%5Bb%5D=1&a=2&page=3', handlerRequest('/news//2026/./?page=3&a[b]=1&a=2')->cacheKey(), 'sorted byte-wise, encoded, the path collapsed');
        same(handlerRequest('/x?b=1&a=2')->cacheKey(), handlerRequest('/x?a=2&b=1')->cacheKey(), 'the order of the parameters does not matter');
        same('https://a.example/x', handlerRequest('/x')->cacheKey(), 'no parameters: no question mark');
        same('https://a.example/x?q=caf%C3%A9%20au%20lait', handlerRequest('/x?q=caf%C3%A9+au+lait')->cacheKey(), 'one encoding for one value');
        truthy(handlerRequest('/x')->cacheKey() === Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'HTTP_HOST' => 'a.example', 'REMOTE_ADDR' => '198.51.100.9', 'HTTPS' => 'on', 'HTTP_COOKIE' => 'rsp=x'], [])->cacheKey(), 'another client, a cookie: the same key');
    },
    'RSF6.4 hooks: a plugin with the capability is recorded; Shield::handle() asks it for a passing request -- its answer, or null; a failing one is skipped and noted' => function (): void {
        Vocabulary::forget();
        $dir = ruleDir([]);
        try {
            Vocabulary::offer(RsTestExtension::class);
            $s = handlerSettings("plugin CjwNetwork\\RequestShield\\Tests\\HandlerPlugin\n", $dir);
            same(['handler' => [HandlerPlugin::class]], $s->hooks);
            $shield = new Shield($s, new MemoryStore());
            $r = $shield->handle(handlerRequest('/cached'), Decision::allow());
            same([200, 'from the handler: https://a.example/cached'], [$r->status ?? null, $r->body ?? null], 'answered by the plugin');
            truthy(in_array('X-Handled: yes', $r->headers ?? [], true) && in_array('X-Cacheable: yes', $r->headers ?? [], true), 'with its own headers, told whether the answer may be kept');
            same(null, $shield->handle(handlerRequest('/page'), Decision::allow()), 'another address: on to the application');
            same([], handlerSettings("host a.example\n", $dir)->hooks, 'no plugin: no hook');
            $failing = new Shield(handlerSettings("plugin CjwNetwork\\RequestShield\\Tests\\HandlerPlugin\nset fail-at handler\n", $dir), new MemoryStore());
            $log = ini_set('error_log', "$dir/php-errors.log");
            try {
                same(null, $failing->handle(handlerRequest('/cached'), Decision::allow()), 'a handler that throws: skipped, the application runs');
            } finally {
                ini_set('error_log', (string) $log);
            }
            truthy(strpos((string) @file_get_contents("$dir/php-errors.log"), 'HandlerPlugin failed to answer, the application runs: the handler failed, as asked (') !== false, 'noted');
        } finally {
            Vocabulary::forget();
            Vocabulary::offer(\CjwNetwork\RequestShield\Stats\StatsExtension::class);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF6.4 end to end: the handler answers /cached before the application, a refusal never reaches it, a failing handler changes nothing for the visitor' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withDashboard("exempt none\nplugin CjwNetwork\\RequestShield\\Tests\\HandlerPlugin\n", function (callable $get): void {
            $r = $get('/cached?b=2&a=1');
            same(200, $r['status']);
            same('from the handler: http://127.0.0.1/cached?a=1&b=2', $r['body'], 'the plugin\'s answer, the cache key in it');
            truthy((bool) preg_grep('/^X-Handled: yes$/i', $r['headers']), 'its header');
            same(0, strncmp($get('/page')['body'], 'site allow /page', 16), 'another address: the application');
            same(404, $get('/index.php/.env')['status'], 'a refusal: the shield\'s, the handler is never asked');
        });
        withDashboard("exempt none\nplugin CjwNetwork\\RequestShield\\Tests\\HandlerPlugin\nset fail-at handler\n", function (callable $get): void {
            same(0, strncmp($get('/cached')['body'], 'site allow /cached', 18), 'the handler failed: the application answers');
        });
    },
];
