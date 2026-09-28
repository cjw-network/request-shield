<?php
// Settings for cjw-network/request-shield. Copy to request-shield.php and adapt;
// only what differs from the defaults (src/Config.php) needs to be here.
return [
    // Your load balancer or reverse proxy; only these may send X-Forwarded-*.
    'trustedProxies' => [
        // '10.0.0.0/8',
    ],
    // The site's own hosts ("*.example.org" for every subdomain); empty: any host.
    'hosts' => [
        // 'www.example.org', 'example.org',
    ],
    'methods' => ['GET', 'HEAD', 'POST', 'OPTIONS'],
    // Paths only scanners ask for. Add \CjwNetwork\RequestShield\Config::wordpressPaths()
    // on every site that is not WordPress.
    'blockedPaths' => \CjwNetwork\RequestShield\Config::scannerPaths(),
    // What may be cached: other URLs are answered, but marked uncacheable.
    'cacheable' => [
        'paths' => null,              // e.g. ['#^/[a-z0-9_/-]*$#']
        'query' => ['page'],          // parameter names a cacheable URL may carry
    ],
    // Requests per client (IPv4 address, IPv6 /64) and window.
    'budgets' => [
        'requests' => ['limit' => 600, 'window' => 60, 'challengeAt' => null],
        // Counted only by the application or its cache, via Shield::consume():
        'misses' => ['limit' => 60, 'window' => 60, 'onDemand' => true],
    ],
    'exempt' => ['ips' => ['127.0.0.1', '::1']],
    // 'store' => 'file', 'storeDir' => __DIR__ . '/../var',
];
