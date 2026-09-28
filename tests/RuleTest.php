<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Config;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

function req(string $uri, string $method = 'GET', array $extra = []): Request
{
    return Request::fromServer($extra + ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method,
        'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '203.0.113.7']);
}

function decide(array $config, Request $r, float $now = 1000.0): Decision
{
    return (new Shield($config, new MemoryStore()))->decide($r, $now);
}

return [
    'a normal page passes and is cacheable' => function (): void {
        $d = decide([], req('/news/article'));
        same(Decision::ALLOW, $d->action);
        truthy($d->cacheable(), 'cacheable');
    },
    'methods' => function (): void {
        same(405, decide([], req('/', 'TRACE'))->status);
        same(405, decide([], req('/', 'PUT'))->status);
        same(Decision::ALLOW_UNCACHED, decide([], req('/', 'POST'))->action, 'POST passes, never cached');
    },
    'sizes' => function (): void {
        same(414, decide([], req('/' . str_repeat('a', 5000)))->status);
        same(400, decide([], req('/?' . implode('&', array_map(fn ($i) => "p$i=1", range(1, 80)))))->status);
        same(431, decide([], req('/', 'GET', ['HTTP_COOKIE' => str_repeat('x', 20000)]))->status);
    },
    'path sanity' => function (): void {
        foreach (['/a/../etc/passwd', '/a/%2e%2e/b', '/a/%252e%252e/b', '/a%00b', '/a%zz', '/a%c3%28', '/a\\..\\b'] as $bad) {
            same(Decision::REJECT, decide([], req($bad))->action, $bad);
        }
        foreach (['/a/b..c', '/%C3%A4rger', '/a.b/c'] as $good) {
            same(Decision::ALLOW, decide([], req($good))->action, $good);
        }
    },
    'hosts' => function (): void {
        $c = ['hosts' => ['www.example.org', '*.cdn.example.org']];
        same(Decision::ALLOW, decide($c, req('/'))->action);
        same(Decision::ALLOW, decide($c, req('/', 'GET', ['HTTP_HOST' => 'img.cdn.example.org:443']))->action);
        same(404, decide($c, req('/', 'GET', ['HTTP_HOST' => 'evil.example']))->status);
        same(404, decide($c, req('/', 'GET', ['HTTP_HOST' => 'cdn.example.org']))->status, 'the bare suffix is not a subdomain');
        same(404, decide($c, req('/', 'GET', ['HTTP_HOST' => 'x.cdn.example.org.evil']))->status);
    },
    'scanner paths' => function (): void {
        foreach (['/.env', '/.git/config', '/backup.sql', '/site.tar.gz', '/phpinfo.php', '/vendor/phpunit/x', '/phpmyadmin/'] as $p) {
            same(404, decide([], req($p))->status, $p);
        }
        same(Decision::ALLOW, decide([], req('/.well-known/acme-challenge/x'))->action, 'ACME stays open');
        same(Decision::ALLOW, decide([], req('/wp-login.php'))->action, 'WordPress paths only when asked');
        same(404, decide(['blockedPaths' => array_merge(Config::scannerPaths(), Config::wordpressPaths())], req('/wp-login.php'))->status);
    },
    'cacheable definition: query parameters and paths' => function (): void {
        $c = ['cacheable' => ['query' => ['page'], 'paths' => ['#^/(news|about)(/|$)#']]];
        same(Decision::ALLOW, decide($c, req('/news/x?page=2'))->action);
        same('query parameter', decide($c, req('/news/x?utm_source=1'))->reason);
        same(Decision::ALLOW_UNCACHED, decide($c, req('/random-' . mt_rand()))->action, 'unknown path: answered, not cached');
    },
    'cacheable definition: an adapter index decides first' => function (): void {
        $known = fn (Request $r) => $r->path === '/known' ? true : ($r->path === '/gone' ? false : null);
        $shield = new Shield(['cacheable' => ['paths' => ['#^/fallback$#']]], new MemoryStore(), $known);
        same(Decision::ALLOW, $shield->decide(req('/known'), 1.0)->action);
        same('unknown url', $shield->decide(req('/gone'), 1.0)->reason);
        same(Decision::ALLOW, $shield->decide(req('/fallback'), 1.0)->action, 'no opinion: the path patterns');
    },
    'budget: challenge, then 429, per client; exempt addresses never' => function (): void {
        $shield = new Shield(['budgets' => ['requests' => ['limit' => 10, 'window' => 60, 'challengeAt' => 5]]], new MemoryStore());
        $now = 60.0 * 100;
        $seen = [];
        for ($i = 1; $i <= 12; $i++) {
            $seen[$i] = $shield->decide(req('/'), $now)->action;
        }
        same(Decision::ALLOW, $seen[5]);
        same(Decision::CHALLENGE, $seen[6]);
        same(Decision::CHALLENGE, $seen[10]);
        same(Decision::THROTTLE, $seen[11]);
        $d = $shield->decide(req('/'), $now);
        truthy($d->retryAfter >= 1, 'Retry-After');
        same(Decision::ALLOW, $shield->decide(req('/', 'GET', ['REMOTE_ADDR' => '198.51.100.1']), $now)->action, 'another client has its own budget');
        for ($i = 0; $i < 50; $i++) {
            $d = $shield->decide(req('/', 'GET', ['REMOTE_ADDR' => '127.0.0.1']), $now);
        }
        same(Decision::ALLOW, $d->action, 'exempt');
    },
    'budget: a rotating IPv6 client inside one /64 shares one budget' => function (): void {
        $shield = new Shield(['budgets' => ['requests' => ['limit' => 5, 'window' => 60]]], new MemoryStore());
        $d = null;
        for ($i = 1; $i <= 6; $i++) {
            $d = $shield->decide(req('/', 'GET', ['REMOTE_ADDR' => '2001:db8:1:2::' . dechex($i)]), 600.0);
        }
        same(Decision::THROTTLE, $d->action);
    },
    'consume(): budgets counted only on demand (cache misses)' => function (): void {
        $shield = new Shield(['budgets' => ['requests' => ['limit' => 0], 'misses' => ['limit' => 3, 'window' => 60, 'onDemand' => true]]], new MemoryStore());
        $r = req('/');
        for ($i = 0; $i < 10; $i++) {
            same(Decision::ALLOW, $shield->decide($r, 60.0)->action, 'decide() does not count an on-demand budget');
        }
        $seen = [];
        for ($i = 1; $i <= 4; $i++) {
            $seen[] = $shield->consume('misses', $r, 60.0)->action;
        }
        same([Decision::ALLOW, Decision::ALLOW, Decision::ALLOW, Decision::THROTTLE], $seen);
        same(Decision::ALLOW, $shield->consume('no-such-budget', $r, 60.0)->action);
    },
    'a rejection stops the checks: nothing is counted for it' => function (): void {
        $store = new MemoryStore();
        $shield = new Shield(['budgets' => ['requests' => ['limit' => 1, 'window' => 60]]], $store);
        $shield->decide(req('/.env'), 60.0);
        same(0.0, $store->peek('requests:203.0.113.7', 60, 60.0));
    },
];
