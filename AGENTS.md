# AGENTS.md

Instructions for AI coding agents working on this repository. Humans: see
`CONTRIBUTING.md`, which says the same in more words.

## What this is

`cjw-network/request-shield`: a dependency-free PHP (≥ 8.0) library that runs
before the application (`auto_prepend_file` or the first line of a front
controller) and decides whether a request reaches it and may be cached.
Entry points: `src/Shield.php` (`protect()`, `protectFile()`), `bootstrap.php`.
Settings: `src/Config.php` (defaults) → `src/Settings.php` (checked, compiled).

## Rules

- **Correct and fast, always.** Every change: tests (`php tests/run.php`), a
  benchmark before and after (`php -d apc.enable_cli=1 -d opcache.enable_cli=1
  bench/overhead.php`), numbers in the pull request. Nothing a feature needs may
  run on the passing path when the feature is not used. A `stat()` costs more
  than most checks — avoid them on the request path.
- **Tests:** unit tests plus an end-to-end case for request-path changes; the
  challenge page's script is tested in Node (`tests/ChallengeJsTest.php`).
  Confirm a new test fails without the change. Use `skip()` when a test cannot
  run here; never let it pass silently.
- **Compatibility:** PHP 8.0 syntax and functions only (RHEL 9 baseline): no
  `readonly` (mark public properties `@readonly`; PHPStan enforces it), no
  enums, no `never`, no string-key unpacking, no `array_is_list()`, no `xxh*`
  hashes — or guard them with `PHP_VERSION_ID`; no runtime
  dependencies; `composer phpstan` (level max) and `composer taint` stay clean.
- **Fail safe:** unsure → let the request through; refuse only clear cases.
- **Docs in the same change:** `docs/features/`, `docs/proposals/` (new ideas,
  status Draft), `docs/adr/` (decisions), `CHANGELOG.md` (*Unreleased*).
- **Security:** never log or expose the secret; escape everything a page
  embeds; compare MACs with `hash_equals()`.
- **Commits:** one change each; "Added:/Fixed:/Updated: <what the code does now>".
