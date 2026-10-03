<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Access;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\PageHook;
use CjwNetwork\RequestShield\Pages;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Responder;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;
use CjwNetwork\RequestShield\Tests\PagesPlugin;
use CjwNetwork\RequestShield\Tests\RsTestExtension;

/**
 * The Pages capability (0031 B.10): a plugin draws the refusal page, the
 * check page and the dashboard's login form in the site's look; null or a
 * failure means the shield's own page. Uses ruleDir() from RuleFileTest.php
 * and withDashboard() from DashboardTest.php.
 */

function pagesSettings(string $rules, string $dir): Settings
{
    @mkdir($dir, 0700, true);
    file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\n" . $rules);
    return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
}

function pagesRequest(string $path = '/x', array $more = []): Request
{
    return Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'HTTP_HOST' => 'a.example', 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Test'] + $more, []);
}

return [
    'RSF6.4 hooks: a plugin with the capability is recorded; without one every page is the shield\'s' => function (): void {
        $dir = ruleDir([]);
        try {
            same(['pages' => [PagesPlugin::class]], pagesSettings("plugin CjwNetwork\\RequestShield\\Tests\\PagesPlugin\n", $dir)->hooks);
            $plain = pagesSettings("host a.example\n", $dir);
            same(null, PageHook::ask($plain, Pages::ERROR, ['status' => 404]), 'no plugin: null, the core draws');
            truthy(strpos((new Responder())->body(Decision::reject(404, 'blocked path'), null, [], null, PageHook::asker($plain), pagesRequest()), '<h1>') !== false, 'the shield\'s small page');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF6.4 the three kinds: the refusal page (Responder), the check page (Gate), the login form (Access) come from the plugin, with the context each needs; the headers stay the shield\'s' => function (): void {
        Vocabulary::forget();
        $dir = ruleDir([]);
        try {
            Vocabulary::offer(RsTestExtension::class);
            $s = pagesSettings("plugin CjwNetwork\\RequestShield\\Tests\\PagesPlugin\ndashboard-access * sha256:" . str_repeat('a', 64) . "\n", $dir);
            // error
            $body = (new Responder())->body(Decision::reject(404, 'blocked path'), null, ['lang' => 'de'], '/', PageHook::asker($s), pagesRequest());
            truthy(strpos($body, '<title>PAGES:error:404</title>') !== false && strpos($body, 'lang="de"') !== false, 'the refusal page, with status and language: ' . substr($body, 0, 120));
            // challenge: through the shield's gate, as a request would get it
            $shield = new Shield($s, new MemoryStore());
            $r = $shield->settle(Decision::challenge('requests'), pagesRequest('/page'), microtime(true));
            same(Decision::CHALLENGE, $r['decision']->action);
            truthy(strpos((string) $r['page'], '<title>PAGES:challenge:429</title>') !== false && strpos((string) $r['page'], 'data-field="rss"') !== false, 'the check page, with the task\'s field name: ' . substr((string) $r['page'], 0, 160));
            // access-login: the dashboard asks who is reading
            $g = Access::gate($s, pagesRequest('/rs/stats/visitors'), [], [], ['lang' => 'en']);
            same(401, $g['status']);
            truthy(strpos((string) $g['body'], '<title>PAGES:access-login:401</title>') !== false, 'the login form: ' . substr((string) $g['body'], 0, 120));
            truthy((bool) preg_grep('/^Cache-Control: private, no-store$/', $g['headers']) && (bool) preg_grep('/^X-Robots-Tag: noindex, nofollow$/', $g['headers']), 'the headers stay the shield\'s');
            // a kind left to the core: null
            $core = pagesSettings("plugin CjwNetwork\\RequestShield\\Tests\\PagesPlugin\nrs-test-mark core:error\n", $dir);
            truthy(strpos((new Responder())->body(Decision::reject(404, 'blocked path'), null, [], null, PageHook::asker($core), pagesRequest()), '<h1>') !== false, 'null from the plugin: the shield\'s page');
        } finally {
            Vocabulary::forget();
            Vocabulary::offer(\CjwNetwork\RequestShield\Stats\StatsExtension::class);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF6.4 a plugin that throws: the shield\'s own page goes out, and PHP\'s error log hears it once a minute' => function (): void {
        Vocabulary::forget();
        $dir = ruleDir([]);
        try {
            Vocabulary::offer(RsTestExtension::class);
            $s = pagesSettings("plugin CjwNetwork\\RequestShield\\Tests\\PagesPlugin\nset fail-at pages\n", $dir);
            $log = ini_set('error_log', "$dir/php-errors.log");
            try {
                $body = (new Responder())->body(Decision::reject(404, 'blocked path'), null, [], null, PageHook::asker($s), pagesRequest());
            } finally {
                ini_set('error_log', (string) $log);
            }
            truthy(strpos($body, '<h1>') !== false && strpos($body, 'PAGES:') === false, 'the shield\'s page');
            truthy(strpos((string) @file_get_contents("$dir/php-errors.log"), 'PagesPlugin failed to draw the error page, the shield\'s own went out: the pages plugin failed, as asked (' . $s->storeDir . ')') !== false, 'noted');
        } finally {
            Vocabulary::forget();
            Vocabulary::offer(\CjwNetwork\RequestShield\Stats\StatsExtension::class);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF6.4 end to end: a refusal and a check page in the site\'s look; a failing plugin changes nothing for the visitor; a passing request pays nothing' => function (): void {
        if (!function_exists('proc_open')) {
            skip('no proc_open');
        }
        withDashboard("exempt none\nplugin CjwNetwork\\RequestShield\\Tests\\PagesPlugin\nchallenge /login\n", function (callable $get): void {
            $r = $get('/index.php/.env');
            same(404, $r['status'], 'refused, the status is the shield\'s');
            truthy(strpos($r['body'], '<title>PAGES:error:404</title>') !== false, 'the page is the site\'s');
            $r = $get('/login');
            same(429, $r['status'], 'the check');
            truthy(strpos($r['body'], '<title>PAGES:challenge:429</title>') !== false, 'the check page is the site\'s');
            same(0, strncmp($get('/page')['body'], 'site allow /page', 16), 'a passing request: the site, untouched');
        });
        withDashboard("plugin CjwNetwork\\RequestShield\\Tests\\PagesPlugin\nset fail-at pages\n", function (callable $get): void {
            $r = $get('/index.php/.env');
            same(404, $r['status']);
            truthy(strpos($r['body'], '<h1>') !== false && strpos($r['body'], 'PAGES:') === false, 'the plugin failed: the shield\'s own page');
        });
    },
];
