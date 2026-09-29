# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `challenge.alwaysPaths`: paths every visitor has to pass the browser check
  for (once per pass cookie), whatever the budgets say — for a login or admin
  page; a POST without a pass gets 429
  ([docs](docs/features/browser-challenge.md)).
- A demo site, `examples/demo/`: one example per feature, the check included;
  `php -S 127.0.0.1:8080 examples/demo/router.php`, or in any subdirectory
  of a web server, with rewrite rules (`.htaccess`) or as `index.php/…`; its
  counters and secret stay outside the document root. It shows the full URL,
  the request's headers (those the shield removed struck out), the answer's
  headers, and each example's status and headers in place. Tested end to end, at
  the root and in a subdirectory.
- PHP 8.0 support (the Red Hat Enterprise Linux 9 baseline): no `readonly`
  properties at runtime any more — public ones are marked `@readonly`, which
  PHPStan enforces —, no string-key unpacking, `array_is_list()` and `xxh128`
  only where PHP has them. CI tests PHP 8.0 too. Cost on PHP 8.1 unchanged.

### Changed
- The README opens with what the shield does for a website, in plain words (a
  mini web application firewall); the package description and keywords follow.
- Composer and release archives contain only what runs on a server (`src/`,
  `bootstrap.php`, `config/`, license and readme); tests, benchmarks, docs and
  tool settings stay in the repository (`.gitattributes`).

## [0.2.0] — 2026-09-28

### Added
- The browser challenge: an ALTCHA-compatible proof of work for clients past a
  budget's `challengeAt`, a signed pass cookie bound to client and User-Agent,
  single-use solutions, difficulty growing towards the limit, verified search
  engine crawlers and exempt paths never challenged
  ([docs](docs/features/browser-challenge.md)).
- `Shield::protectFile()`: settings checked once and compiled into a PHP file
  OPcache serves, rebuilt when the file changes; `bootstrap.php` uses it
  ([docs](docs/features/settings.md)).
- Typed settings: a wrong type is an error naming the key.
- Documentation: features, use cases, proposals, architecture decisions.
- Tests report skipped cases; the challenge page's script is tested in Node.
- CI on GitHub: tests on PHP 8.1–8.5 with and without APCu (a skipped test
  fails the APCu jobs), a smoke benchmark, PHPStan (level max), Psalm taint
  analysis uploaded to code scanning, `composer validate`/`audit`, Dependabot.
- `SECURITY.md`, `CONTRIBUTING.md`, `AGENTS.md`.

### Fixed
- A settings file rewritten within the same second was compiled with its old
  contents (OPcache judged it by the unchanged mtime) and kept them; it is now
  invalidated in OPcache before it is read again. Found by CI, which runs the
  tests with OPcache on.

### Changed
- `Request` reads headers lazily from `$_SERVER` (about 40 % less time per
  request).

## [0.1.0] — 2026-09-28

### Added
- The core: trusted proxies and client identity (IPv6 /64), hard rejects
  (methods, sizes, path sanity, hosts, scanner paths), the cacheable
  definition, per-client budgets with 429, on-demand budgets via
  `Shield::consume()`, APCu, file and memory stores, `bootstrap.php` for
  `auto_prepend_file`.

[Unreleased]: https://github.com/cjw-network/request-shield/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/cjw-network/request-shield/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/cjw-network/request-shield/releases/tag/v0.1.0
