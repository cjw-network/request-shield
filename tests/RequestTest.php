<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Request;

return [
    'RSF01-01 ranges: IPv4 and IPv6 CIDR, single addresses' => function (): void {
        truthy(IpAddress::inRanges('10.1.2.3', ['10.0.0.0/8']), '10/8');
        truthy(!IpAddress::inRanges('11.1.2.3', ['10.0.0.0/8']), 'not 10/8');
        truthy(IpAddress::inRanges('192.168.1.200', ['192.168.1.128/25']), '/25');
        truthy(!IpAddress::inRanges('192.168.1.100', ['192.168.1.128/25']), 'not /25');
        truthy(IpAddress::inRanges('127.0.0.1', ['127.0.0.1']), 'single');
        truthy(IpAddress::inRanges('2001:db8::1', ['2001:db8::/32']), 'v6 /32');
        truthy(!IpAddress::inRanges('2001:db9::1', ['2001:db8::/32']), 'not v6 /32');
        truthy(!IpAddress::inRanges('10.0.0.1', ['2001:db8::/32']), 'v4 in v6 range');
        truthy(!IpAddress::inRanges('junk', ['0.0.0.0/0']), 'not an address');
    },
    'RSF01-01 bucket: IPv4 whole, IPv6 by its /64' => function (): void {
        same('203.0.113.7', IpAddress::bucket('203.0.113.7'));
        same('2001:db8:1:2::/64', IpAddress::bucket('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        same(IpAddress::bucket('2001:db8:1:2::1'), IpAddress::bucket('2001:db8:1:2:ffff::9'), 'same /64, same bucket');
        same('2001:db8::/48', IpAddress::bucket('2001:db8:0:5::1', 48));
    },
    'RSF01-01 forwarded headers only from a trusted proxy' => function (): void {
        $server = ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_HOST' => 'exp:8080', 'REQUEST_URI' => '/a?b=1',
                   'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'evil.example'];
        $r = Request::fromServer($server, ['10.0.0.0/8']);
        same('198.51.100.9', $r->clientIp, 'untrusted peer keeps its own address');
        same('http', $r->scheme);
        same('exp', $r->host, 'Host header, port removed');
        same(false, $r->viaTrustedProxy);

        $r = Request::fromServer(['REMOTE_ADDR' => '10.0.0.5'] + $server, ['10.0.0.0/8']);
        same('6.6.6.6', $r->clientIp, 'client from X-Forwarded-For');
        same('https', $r->scheme);
        same('evil.example', $r->host);
        same(true, $r->viaTrustedProxy);
    },
    'RSF01-01 X-Forwarded-For is read right to left, past trusted hops only' => function (): void {
        $r = Request::fromServer(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 203.0.113.7, 10.0.0.9'], ['10.0.0.0/8']);
        same('203.0.113.7', $r->clientIp, 'a client cannot put an address in front of its own');
        $r = Request::fromServer(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'garbage, 203.0.113.7'], ['10.0.0.0/8']);
        same('203.0.113.7', $r->clientIp, 'stops at an invalid hop');
    },
    'RSF01-01 path, query and parameter names' => function (): void {
        $r = Request::fromServer(['REQUEST_URI' => '/x/y?a=1&b[c]=2&&d#frag', 'REQUEST_METHOD' => 'get']);
        same('/x/y', $r->path);
        same('a=1&b[c]=2&&d', $r->query);
        same(['a', 'b', 'd'], $r->queryNames());
        same('GET', $r->method);
    },
    'RSF01-01 content(): what the attack rules see -- decoded twice, lower case, comments out' => function (): void {
        $r = Request::fromServer([
            'REQUEST_URI' => '/A%20Path?x=UnIoN%2F%2A%2A%2FSeLeCt%25201&y=a+b',
            'HTTP_USER_AGENT' => 'Bad%20Bot',
            'HTTP_COOKIE' => 'sid=secret',
            'HTTP_X_THING' => 'a    b',
        ]);
        same('x=union select 1&y=a b', $r->content('query'), 'decoded twice, "+" a space, /**/ a space, lower case');
        same('id=1 union select 1', Request::fromServer(['REQUEST_URI' => '/?id=1%2F%2A!50000UnIoN%2A%2F%2F%2A!SELECT%2A%2F1'])->content('query'),
            'a MySQL versioned comment is code to MySQL: its body stays, the comment marks go');
        same('id=1 union select 1', Request::fromServer(['REQUEST_URI' => '/?id=1/*M!100100UNION*/SELECT/**/1'])->content('query'),
            'MariaDB\'s /*M! too');
        same('id=1 union select', Request::fromServer(['REQUEST_URI' => '/?id=1/*!/**/union*/select'])->content('query'), 'a comment inside one: MySQL runs the body as code');
        same('id=1 union select ', Request::fromServer(['REQUEST_URI' => '/?id=1/*!union/*!select*/*/'])->content('query'), 'one inside another');
        same('id=1 x', Request::fromServer(['REQUEST_URI' => '/?id=1/*a/*!union*/x'])->content('query'), 'inside a plain comment: never run, gone with it');
        same('bad bot', $r->content('header:user-agent'), 'one header by name');
        same('', $r->content('header:x-missing'), 'a header not sent');
        truthy(strpos($r->content('headers'), 'a b') !== false, 'headers: every HTTP_ value, white space collapsed');
        truthy(strpos($r->content('headers'), 'sid=secret') === false, 'the Cookie header is not in "headers"');
        truthy(strpos($r->content('headers'), "\x1e") !== false && strpos($r->content('headers'), ' ' . "\x1e") === false, 'the values joined by \\x1e, no white space: a pattern sees where a value starts');
        truthy(strpos($r->content('header:cookie'), 'sid=secret') !== false, 'but can be asked for by name');
        $all = $r->content('anywhere');
        truthy(strpos($all, '/a path') !== false && strpos($all, 'union select') !== false && strpos($all, 'a b') !== false,
            'anywhere is path + query + headers');
        same('', Request::fromServer(['REQUEST_URI' => '/'])->content('query'), 'no query: empty');
        same('', Request::fromServer([])->content('headers'), 'no headers: empty');
    },
    'RSF01-01 mayHold(): the raw value holds a text (any case) or something encoded' => function (): void {
        $r = Request::fromServer(['REQUEST_URI' => '/p?a=1', 'HTTP_USER_AGENT' => 'Mozilla/5.0', 'HTTP_X_ONE' => 'A ${Thing}', 'HTTP_COOKIE' => '${cookie}']);
        same(true, $r->mayHold('headers', ['${thing']), 'any case');
        same(false, $r->mayHold('query', ['${']));
        same(false, $r->mayHold('header:user-agent', ['${']));
        same(true, Request::fromServer(['REQUEST_URI' => '/p?a=%24%7Bx']) ->mayHold('query', ['${']), 'encoded: it could be');
        same(true, Request::fromServer(['REQUEST_URI' => '/p/%2524']) ->mayHold('anywhere', ['${']), 'the path counts for anywhere');
        same(false, Request::fromServer(['REQUEST_URI' => '/p', 'HTTP_COOKIE' => '${x}'])->mayHold('headers', ['${']), 'cookies are not looked at');
    },
];
