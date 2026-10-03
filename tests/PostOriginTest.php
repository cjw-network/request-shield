<?php

declare(strict_types=1);

/**
 * post-origin same (proposal 0028, phase 1): forms only from the website's own
 * pages -- Origin, else Referer; neither: the browser check by default.
 */

use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** Every example of a rule text passes (its own; the built-in ones too). */
function postOriginExamples(string $text): void
{
    $run = examplesOf($text);
    same([], array_values(array_filter($run['lines'], static fn (string $l): bool => strncmp($l, 'pass ', 5) !== 0)), 'every example');
    truthy(count($run['lines']) >= 1, 'examples ran: ' . count($run['lines']));
}

return [
    'RSF02-04 the website\'s own names: the host rule\'s, *.domain one label; Origin first, else Referer; "null" is nothing' => static fn () => postOriginExamples(<<<'RULES'
host www.example.org example.org *.shop.example
method GET HEAD POST PUT DELETE
[F-ORIGIN] post-origin same
expect POST /contact header Origin:https://www.example.org                  answered
expect POST /contact header Origin:https://example.org:8443                 answered   # the port does not matter
expect POST /contact header Origin:https://WWW.Example.org.                 answered   # case and a trailing dot neither
expect POST /contact header Origin:https://a.shop.example                   answered   # *.shop.example: one label
expect POST /contact header Origin:https://a.b.shop.example                 403        # two labels: not the website
expect POST /contact header Origin:https://shop.example                     403        # the domain itself is not *.domain
expect POST /contact header Origin:https://www.example.org.evil.example     403        # a name that only starts like it
expect POST /contact header Origin:https://evil.example header Referer:https://www.example.org/contact   403   # Origin first
expect POST /contact header Referer:https://www.example.org/contact          answered   # no Origin: the Referer
expect POST /contact header Origin:null header Referer:https://evil.example/ 403        # "null" counts as nothing
expect POST /contact header Origin:not-an-address                           403        # unreadable: not the website
expect PUT /contact header Origin:https://evil.example                      403        # PUT, PATCH, DELETE too
expect DELETE /contact header Origin:https://evil.example                   403
expect GET /contact header Origin:https://evil.example                      answered   # not a form
RULES),

    'RSF02-04 neither header: the check by default (a pass gets through), allow, or refuse' => function (): void {
        postOriginExamples(<<<'RULES'
[F-ORIGIN] post-origin same
expect POST /contact             check
expect POST /contact with pass   answered   # a browser checked before: through
RULES);
        postOriginExamples("[F-ORIGIN] post-origin same missing allow\nexpect POST /contact answered\nexpect POST /contact header Origin:https://evil.example 403\n");
        postOriginExamples("[F-ORIGIN] post-origin same missing refuse\nexpect POST /contact 403\n");
    },

    'RSF02-04 never for the exceptions, the API, addresses let in; without a host rule the name the request was sent to' => static fn () => postOriginExamples(<<<'RULES'
api-path /api/**
exempt 192.0.2.50
[F-ORIGIN] post-origin same
expect POST https://www.example.org/contact header Origin:https://www.example.org   answered   # no host rule: the request's own name
expect POST https://www.example.org/contact header Origin:https://example.org       403        # … and only that one
[F-ORIGIN-X] post-origin except /pay/notify /sso/**
expect POST /pay/notify header Origin:https://payments.example     answered   # a payment provider's callback
expect POST /sso/acs header Origin:https://idp.example             answered   # single sign-on, posted from the identity provider
expect POST /api/orders header Origin:https://evil.example         answered   # the API authenticates otherwise
expect POST /contact from 192.0.2.50 header Origin:https://evil.example   answered   # an address let in
expect POST /contact header Origin:https://evil.example            403 by F-ORIGIN
RULES),

    'RSF02-04 in a site block: that website\'s names; monitor post-origin: logged, not enforced; switched on in test' => function (): void {
        postOriginExamples(<<<'RULES'
site shop.example www.shop.example {
  [S-ORIGIN] post-origin same
  expect POST /cart header Origin:https://www.shop.example   answered
  expect POST /cart header Origin:https://blog.example       403
}
RULES);
        $dir = ruleDir(['site.rules' => "[F-ORIGIN] monitor post-origin same\n"]);
        try {
            $s = Settings::load("$dir/site.rules", "$dir/cache");
            truthy($s->postOrigin === null && $s->monitor !== null && $s->monitor->postOrigin !== null, 'watched: not in the enforced settings, in the watched ones');
            $r = Request::fromServer(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact', 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'www.example.org', 'HTTP_ORIGIN' => 'https://evil.example']);
            same('allow-uncached', (new Shield($s, new MemoryStore()))->decide($r, 1000.0)->action, 'enforced: nothing (a POST is never cached)');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },

    'RSF02-04 PHP settings, the reasons, the rule named; the trace says why' => function (): void {
        $s = Settings::from(['hosts' => ['www.example.org'], 'postOrigin' => ['missing' => 'check', 'except' => ['#^/pay/#']]]);
        same(['missing' => 'check', 'except' => ['#^/pay/#']], $s->postOrigin, 'from a PHP array');
        same(null, Settings::from([])->postOrigin, 'off by default');
        $shield = new Shield($s, new MemoryStore());
        $r = Request::fromServer(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact', 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_HOST' => 'www.example.org', 'HTTP_ORIGIN' => 'https://evil.example']);
        $d = $shield->decide($r, 1000.0);
        same([403, 'cross-site', 'postOrigin'], [$d->status, $d->reason, $shield->explain($d, $r)], 'refused, and named');
        same('a form sent from another website', \CjwNetwork\RequestShield\Report\Describe::reason('cross-site'), 'in words');
        same('ein Formular, von einer anderen Website aus gesendet', \CjwNetwork\RequestShield\Report\Describe::reason('cross-site', 'de'), 'in German');
        try {
            Settings::from(['postOrigin' => ['missing' => 'maybe']]);
            throw new TestFailure('accepted missing maybe');
        } catch (InvalidArgumentException $e) {
            truthy(strpos($e->getMessage(), 'postOrigin.missing') !== false, $e->getMessage());
        }
        $dir = ruleDir(['site.rules' => "host www.example.org\n[F-ORIGIN] post-origin same   # forms from here only\n"]);
        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' trace ' . escapeshellarg("$dir/site.rules") . ' "POST https://www.example.org/contact" 2>&1', $out);
            truthy(strpos(implode("\n", $out), 'Where forms come from') !== false && strpos(implode("\n", $out), 'neither Origin nor Referer: the browser check  [F-ORIGIN]') !== false, implode("\n", $out));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },

    'RSF02-04 post-origin in a rule file: mistakes name the line; except alone switches nothing on' => function (): void {
        rulesFail(['site.rules' => "post-origin\n"], 'site.rules:1', 'post-origin same');
        rulesFail(['site.rules' => "post-origin other\n"], 'site.rules:1', 'post-origin same');
        rulesFail(['site.rules' => "post-origin same missing maybe\n"], 'site.rules:1', 'missing check|allow|refuse');
        rulesFail(['site.rules' => "post-origin same now\n"], 'site.rules:1', '"now"');
        rulesFail(['site.rules' => "post-origin except\n"], 'site.rules:1', 'which paths?');
        rulesFail(['site.rules' => "match /a/** {\n  post-origin same\n}\n"], 'site.rules:2', 'for the whole website');
        same(null, rulesFrom("post-origin except /pay/**\n")->postOrigin, 'except without same: off');
        same(['missing' => 'refuse', 'except' => ['#^/pay(?:/.*)?$#i', '#^/sso$#i']], rulesFrom("post-origin except /pay/**\npost-origin same missing refuse except /sso\n")->postOrigin, 'in any order, the exceptions added up');
    },
];
