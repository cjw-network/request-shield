<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Request;

return [
    'ranges: IPv4 and IPv6 CIDR, single addresses' => function (): void {
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
    'bucket: IPv4 whole, IPv6 by its /64' => function (): void {
        same('203.0.113.7', IpAddress::bucket('203.0.113.7'));
        same('2001:db8:1:2::/64', IpAddress::bucket('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        same(IpAddress::bucket('2001:db8:1:2::1'), IpAddress::bucket('2001:db8:1:2:ffff::9'), 'same /64, same bucket');
        same('2001:db8::/48', IpAddress::bucket('2001:db8:0:5::1', 48));
    },
    'forwarded headers only from a trusted proxy' => function (): void {
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
    'X-Forwarded-For is read right to left, past trusted hops only' => function (): void {
        $r = Request::fromServer(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 203.0.113.7, 10.0.0.9'], ['10.0.0.0/8']);
        same('203.0.113.7', $r->clientIp, 'a client cannot put an address in front of its own');
        $r = Request::fromServer(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'garbage, 203.0.113.7'], ['10.0.0.0/8']);
        same('203.0.113.7', $r->clientIp, 'stops at an invalid hop');
    },
    'path, query and parameter names' => function (): void {
        $r = Request::fromServer(['REQUEST_URI' => '/x/y?a=1&b[c]=2&&d#frag', 'REQUEST_METHOD' => 'get']);
        same('/x/y', $r->path);
        same('a=1&b[c]=2&&d', $r->query);
        same(['a', 'b', 'd'], $r->queryNames());
        same('GET', $r->method);
    },
];
