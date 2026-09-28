# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/cjw-network/request-shield/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/cjw-network/request-shield/releases/tag/v0.1.0
