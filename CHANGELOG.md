# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Rule files: the settings one rule per line (`host`, `trust`, `block`,
  `cache-path`, `limit`, `challenge`, `restrict`, `allow`, `set`, `include`,
  …), from a main file, its includes and further sources such as a CMS
  extension's; merged in order, checked when read (errors name `file:line`),
  compiled for OPcache and, with APCu, checked for changes every 10 seconds
  without a `stat()` in between (~5.5 µs setup). `${NAME:-default}` for
  environment variables. `bin/request-shield check|show|reload`
  ([docs](docs/features/rule-files.md), proposal 0003).
- Access rules: `restrict <paths> to <addresses>` (403 for everyone else) and
  `allow <METHODS> <paths>` (405 elsewhere), matched against the path as the
  application routes it — `//admin`, `/%61dmin` and case do not get past
  ([docs](docs/features/access-rules.md)).
- Rule IDs: every decision that stops or flags a request names its rule
  (`SITE-10`, `site.rules:12`, `SCAN-BACKUP`, `built-in`) in `X-Request-Shield`,
  `$_SERVER['REQUEST_SHIELD_RULE']` and `Shield::currentRule()`; looked up only
  for such requests.
- An optional log (`set log`, `log-level stop|flag|all|off`, `log-ip
  masked|full`, one rotation at `log-max-size`), one line per request the
  shield stopped or flagged, with the full URL; the address first, anonymised
  by default and written as its network (`198.51.100.0/24`)
  ([docs](docs/features/log-and-rule-ids.md)).
- `Shield::active()`: the shield `protect()` ran with, so the application
  counts on-demand budgets against the same settings and request
  (`Shield::active()->consume('misses')`); refusals are logged.
- The active rules page (`Report\RulesPage`): the rules in plain words with
  their origin and how often each decided in the last 24 hours, the latest
  activity, and a check that tries any address step by step without counting
  it; refreshes itself. `bin/request-shield trace`
  ([docs](docs/features/active-rules-page.md)).
- `unblock [<what>] at <paths> [for <addresses>]`: blocked paths let through at
  some paths only — an admin's file reader that has to open `.env` or a
  backup — optionally only for some addresses; the path check is never lifted;
  `check` warns about exceptions for everyone
  ([docs](docs/features/access-rules.md#exceptions-an-admins-file-reader)).
- Rule IDs of one's own: `[SITE-10]` before a rule names it in decisions,
  the log, the rules page and `trace` instead of `file:line`; `ids <NS>
  [required]` gives a file its number block; an ID used twice is an error
  naming both places; the comment after a rule is its description.
- The built-in blocks are rule files shipped with the library
  (`rules/scanners.rules`, `rules/wordpress.rules`, IDs `SCAN-…`, `WP-…`):
  `include @wordpress`, `unblock [SCAN-CGI]`, `unblock @scanners`
  ([docs](docs/features/rule-files.md#the-built-in-rules)).
- Versioned rule sets: `version <word>` per rule file, revisions per rule
  (`[SCAN-BACKUP@1]`); a rule that takes back, replaces or opens another names
  the revision reviewed, and `check` and the rules page warn when a library
  update changed it. `replace [ID@n] <rule>` swaps a rule in one line and keeps
  its ID. The built-in rules have `version 2026.09.1` and `rules/CHANGELOG.md`
  (proposal 0005).
- The browser check explained in plain words (`docs/explained/browser-check.md`),
  on the active rules page and, after passing it, in the demo.
- What visitors read is in their language: the check page and the shield's
  own answers in English or German by `Accept-Language` (else English, or
  fixed with `set language de`); own texts per language
  (`set text.de.title …`, `'texts' => ['de.title' => …]`), further languages by
  their texts; `Vary: Accept-Language`.
- The site asks for the browser check: `Shield::active()->requirePass([fresh])`
  before acting on sent content — without a pass the check, and the form is
  sent again by itself afterwards (fields carried in the page, the form token
  too; not files or over 256 KB; a button without JavaScript) — and the
  response header `X-Request-Shield-Challenge: required` on a page
  (`set app-challenge on`). Proposal 0006
  ([docs](docs/features/app-challenges.md)).
- `set home /`: the shield's own pages (404, 403, a pause, the check page) link
  back to the site ("To the home page", in the visitor's language); the rules
  page takes a link back too. The demo leads back to its front page from
  everywhere.
- Diagrams, drawn as SVG without a library: the path of a request through the
  checks on the rules page, the browser check step by step, and how the shield
  sits in front of a site (README, `docs/explained/`).
- Proposal 0004: modes (`off`, `monitor`, `enforce`, `strict`), `monitor` for
  single rules, a fresh check per path.
- `challenge.alwaysPaths`: paths every visitor has to pass the browser check
  for (once per pass cookie), whatever the budgets say — for a login or admin
  page; a POST without a pass gets 429
  ([docs](docs/features/browser-challenge.md)).
- A demo site, `examples/demo/`: one example per feature, the check included;
  `php -S 127.0.0.1:8080 examples/demo/router.php`, or in any subdirectory
  of a web server, with rewrite rules (`.htaccess`) or as `index.php/…`; its
  counters and secret stay outside the document root. It shows the full URL,
  the request's headers (those the shield removed struck out), the answer's
  headers, and each example's status and headers in place; it runs on a rule
  file, with a search page (its own budget), an edit form (POST only there),
  an admin area and an API restricted by address, a pass that expires after a
  minute, and the shield's log. Tested end to end, at
  the root and in a subdirectory.
- PHP 8.0 support (the Red Hat Enterprise Linux 9 baseline): no `readonly`
  properties at runtime any more — public ones are marked `@readonly`, which
  PHPStan enforces —, no string-key unpacking, `array_is_list()` and `xxh128`
  only where PHP has them. CI tests PHP 8.0 too. Cost on PHP 8.1 unchanged.
- Attack rules look inside the request: `block query|header <Name>|headers|anywhere
  <regex>`, matched on the normalised values (decoded twice, lower case, SQL
  comments out) and answered with 403 "attack"; `unblock`, `unblock at` and
  `replace` work for them. The rules are one combined expression per target,
  checked when read — a passing request without them does nothing extra. The
  rules page, `bin/request-shield show|check|trace` and `Shield::explain()`
  name them like every other rule
  ([docs](docs/features/rule-files.md#attack-patterns)).
- `rules/attacks.rules` (`include @attacks`): a reviewed set against SQL
  injection, cross-site scripting, code and shell injection, file inclusion,
  Log4Shell and the known attack tools and exploit paths, after the OWASP Core
  Rule Set's first level (IDs `ATK-…`, `version 2026.09.1`).

### Fixed
- `unblock regex <expression>` and `unblock … at <paths>` did not find a
  content rule: its pattern is kept case-insensitive and the lookup missed it.
- `rules/attacks.rules` blocked `/hnap1` and `/gponform/**` never: the paths
  were written with capital letters while the path is matched lower-cased.
- `block query @scanners` (or an `[ID]`) silently became a pattern matching its
  own name; it is an error now.
- `trace` and the rules page's check had no step for the attack rules: a
  refused request looked unblocked there.

### Changed
- Faster: the blocked paths are matched as one expression (compiled once), so
  a clean request costs one match however many blocks there are (7.7 instead of
  8.2 µs per request with the defaults); attack rules skip a target when its
  raw value cannot hold what every one of its patterns starts with (Log4Shell:
  `${`), and white space is only normalised where there is any. With
  `include @attacks` a clean request costs about 8.6 µs more instead of 10.5
  (PHP 8.1, measured end to end).
- The end-to-end tests take their ports from the operating system: a guessed
  port could belong to another service, which then answered the test.
- The compiled settings record every source file and the environment
  variables used (format 4: rebuilt once after the update); `Settings` has the
  log, the access rules and the rules' origins. `Shield::consume()` takes the
  request from `protect()` when none is given.
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
