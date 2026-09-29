# cjw-network/request-shield

[![Tests](https://github.com/cjw-network/request-shield/actions/workflows/tests.yml/badge.svg)](https://github.com/cjw-network/request-shield/actions/workflows/tests.yml)
[![Static analysis and security](https://github.com/cjw-network/request-shield/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/cjw-network/request-shield/actions/workflows/static-analysis.yml)
![PHP](https://img.shields.io/badge/php-8.0%20%E2%80%93%208.5-777bb4)
![License: MIT](https://img.shields.io/badge/license-MIT-green)

A request shield for PHP sites. It runs **before the application** — before the
framework, its autoloader and its database — and decides in a few microseconds
whether a request reaches the application, and whether the answer may be cached.

- **Trusted proxies:** `X-Forwarded-For`, `-Proto` and `-Host` are believed only
  from your load balancer or reverse proxy, and removed from `$_SERVER`
  otherwise, so neither the shield nor the application can be told another
  client, scheme or host.
- **Hard rejects:** methods, sizes, broken or traversing paths, unknown hosts and
  the paths only scanners ask for (`/.env`, `/.git/`, backups, `phpinfo.php`, …)
  never reach the application.
- **A definition of what may be cached:** URLs outside it are answered, but
  marked uncacheable, so random paths and parameters cannot fill a page cache.
- **Budgets per client** (an IPv4 address, an IPv6 /64): requests per window,
  and budgets the application counts itself (cache misses, failed sign-ins).
  Above a threshold a client can be challenged, above the limit it gets
  `429 Too Many Requests` with `Retry-After`.
- **No dependencies, no services:** counters in APCu, or in plain files on hosting
  without APCu. PHP ≥ 8.0 (the Red Hat Enterprise Linux 9 baseline).

It is meant as a small DoS guard that works on shared hosting too. It turns an
expensive request (framework, database, rendering: 100–200 ms) into a cheap one
(well under 0.1 ms) — it does not replace protection in front of PHP (the
hoster's, a CDN's, CloudLinux, Imunify360): a flood still occupies web server
and PHP slots, just very briefly.

## Status

- **0.1.0:** the core.
- **0.2.0:** the browser challenge (proof of work), settings checked once and
  compiled for OPcache, documentation, CI.
- **Next:** earning back a spent budget with a challenge, for forms and APIs
  ([proposal 0001](docs/proposals/0001-earn-back-a-spent-budget.md)); adapters
  for Exponential, WordPress and Ibexa; exporting the rules to nginx, Apache
  and Varnish.

Documentation: [docs/](docs/README.md) — features, use cases, proposals,
architecture decisions. Changes: [CHANGELOG.md](CHANGELOG.md).

## Installation

### Without Composer (shared hosting)

Put the directory somewhere outside the document root, copy
`config/request-shield.dist.php` to `config/request-shield.php`, and prepend it:

```ini
; .user.ini in the document root (PHP-FPM, LiteSpeed LSAPI)
auto_prepend_file = /home/you/request-shield/bootstrap.php
```

```apache
# .htaccess (Apache mod_php, LiteSpeed)
php_value auto_prepend_file /home/you/request-shield/bootstrap.php
```

The settings file can also be named by a constant or an environment variable,
`REQUEST_SHIELD_CONFIG`. Without a settings file the shield does nothing.

### With Composer

```bash
composer require cjw-network/request-shield
```

and call it first thing in the front controller:

```php
CjwNetwork\RequestShield\Shield::protectFile(__DIR__ . '/../config/request-shield.php');
```

`protectFile()` checks the settings once and keeps them compiled for OPcache;
`protect($array)` checks them on every call.

- **WordPress:** at the top of `wp-config.php`, or as `auto_prepend_file`.
- **Ibexa / Symfony:** at the top of `public/index.php`.
- **Exponential:** in `config.php`, before the HTTP cache's early exit.

## Configuration

Every key, with its default, is in `src/Config.php`; `config/request-shield.dist.php`
is a starting point. The most important ones:

```php
return [
    'trustedProxies' => ['10.0.0.0/8'],
    'hosts' => ['www.example.org', 'example.org'],
    'cacheable' => ['query' => ['page'], 'paths' => null],
    'budgets' => [
        'requests' => ['limit' => 600, 'window' => 60],
        'misses' => ['limit' => 60, 'window' => 60, 'onDemand' => true],
    ],
];
```

## Using the decision in the application

```php
$decision = CjwNetwork\RequestShield\Shield::current();   // or $_SERVER['REQUEST_SHIELD']
if ($decision && !$decision->cacheable()) {
    // answer normally, but do not store the page
}
```

A page cache that misses can count the miss against the client:

```php
$shield = new CjwNetwork\RequestShield\Shield($config);
$request = CjwNetwork\RequestShield\Request::fromServer($_SERVER, $config['trustedProxies']);
if (!$shield->consume('misses', $request)->passes()) {
    // too many renders from this client: answer 429, or a stale copy
}
```

## Cost

`php -d apc.enable_cli=1 bench/overhead.php` — a passing request with eleven
headers behind a trusted proxy, every check on:

| PHP 8.1 | per passing request |
|---|---|
| checks, APCu store | ~12 µs |
| checks, file store | ~42 µs |
| settings (compiled, OPcache) | ~8 µs |
| challenge page / solution check / pass cookie (challenged clients only) | ~12 / ~9 / ~5 µs |

## Tests and checks

```bash
php tests/run.php            # no framework needed, PHP 8.0+
composer install && composer phpstan && composer taint
```

The tests include an end-to-end run through PHP's built-in server with
`auto_prepend_file`, and the challenge page's own script run in Node against
the PHP check. CI runs them on every supported PHP version, with and without
APCu, plus PHPStan (level max) and Psalm's taint analysis.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) (and [AGENTS.md](AGENTS.md) for AI
coding agents). Security issues: [SECURITY.md](SECURITY.md).

## Copyright & license

Copyright (C) 2026 JAC Systeme GmbH, part of [CJW Network](https://cjw-network.com).
Released under the MIT license, see `LICENSE`.
