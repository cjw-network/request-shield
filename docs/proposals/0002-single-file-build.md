# 0002 — The single-file build: `wget`, three lines, protected

| | |
|---|---|
| Status | **Draft** (part of [0031](0031-robust-core-plugins.md), phase E) |
| Proposed | 2026-10-03 (the number was reserved for this since 0.3.0) |
| Affects | the build, `bootstrap.php`, `Rules\RuleFile`/`Feeds`/`CrawlerLists`/`Settings` (the reads of `rules/`), the CLI, the tests, CI, the release |
| Relates to | [0031](0031-robust-core-plugins.md) · [0012 dashboard](0012-dashboard.md#in-the-single-file-0002) · [0017 XSS](0017-detecting-xss.md) · [ADR 0006](../adr/0006-core-and-plugins.md) |

## Summary

One PHP file, `request-shield.php`, is the whole **mini** edition: the checks,
the decision, the answers, the browser check, the log, the rule compiler and
the shipped rule sets, embedded. It is downloaded from one stable URL, verified
by checksum and signature, placed outside the document root and switched on
with one `auto_prepend_file` line. The same file is the command line
(`php request-shield.php check site.rules`). Nothing else is needed: PHP ≥ 8.0,
no Composer, no extension beyond the ones PHP always has.

```
wget https://github.com/cjw-network/request-shield/releases/latest/download/request-shield.php -O /var/www/request-shield.php
php /var/www/request-shield.php init --app=plain --out=/var/www/request-shield.rules
echo 'auto_prepend_file=/var/www/request-shield.php' >> /var/www/html/.user.ini
```

A fourth, optional line: `php /var/www/request-shield.php check /var/www/request-shield.rules`.

## Motivation

- "Upload, include, done" is the README's promise; today it takes four steps
  and a directory of 67 files, and `bootstrap.php` does not even find a
  `.rules` file on its own (it falls back to `config/request-shield.php` only).
- Shared hosting has no Composer and often no shell. One file to upload is the
  form of distribution those sites can actually use.
- An AI agent installing the shield for an application needs **one stable URL**
  and one verifiable artifact, not a clone.
- A single file is also the simplest unit to sign, to pin and to roll back.

## Design

### What goes in

| In the mini file | Why |
|---|---|
| `Shield`, `Request`, `Decision`, `Rule/*`, `Budget`, `IpAddress`, `IpTable`, `Seen`, `Texts`, `Responder`, `Challenge/*`, `Store/*`, `Log`, `Live` | the request path |
| `Config`, `Settings`, `ChallengeSettings`, `Rules/*` (`RuleFile`, `Pattern`, `Lists`, `Feeds`, `CrawlerLists`, `Examples`, `FeedExport`) | the rule compiler runs **on the server**, at the first request after a change; `Examples` is `request-shield test` |
| the CLI (`bin/request-shield`, as a class `Cli`) | the file is the tool |
| `Rules\Shipped` with the data (below) | the shipped rule sets |

| Not in the mini file | Where instead |
|---|---|
| `Report/*` (the dashboard pages), `Access` as their door | `request-shield-waf.php` (the WAF edition; 0031 "Editions") |
| the statistics plugin | `request-shield-stats.php` |
| the API plugin | `request-shield-api.php` (also inside the WAF edition) |
| `rules/crawlers.php` (330 KB) | not embedded: it only serves `Shield::protect(array)` per request without a compile; the file documents `protectFile()` |
| `config/request-shield.dist.php`, the demos, the docs, the tests | the repository |

### The data: `Rules\Shipped`

Today six places read `rules/` relative to `__DIR__` (`Settings::RULES_DIR`,
`Settings.php:392`, `RuleFile::shipped()`, `RuleFile.php:345/411/2240`,
`Feeds.php:55`, `bin:625`). All of them call one class instead:

```php
final class Shipped
{
    public const VERSION = '…';            // from the tag; 'dev' in the repository
    public const BUILD = '…';              // tag + short commit; no timestamp (reproducible)
    public const PUBKEY = '…';             // the release key (minisign / Ed25519), for verify and self-update
    /** @var array<string, string> name => contents of rules/<name>.rules */
    public const RULES = [];               // empty in the repository: then read from rules/
    public const FEEDS = '';               // feeds.json
    /** @var array<string, string> name => JSON */
    public const CRAWLER_LISTS = [];

    public static function rules(string $name): ?string;      // '@scanners' → contents
    public static function feeds(): string;
    public static function crawlerList(string $name): ?string;
    public static function isShipped(string $path): bool;     // for `check`: "a shipped set, not a site file"
}
```

In the repository the constants are empty and the methods read `rules/`; the
build replaces the class body with the embedded data as nowdoc heredocs
(`<<<'RS'`), so no escaping can go wrong. Shipped sets are version-pinned
content: the compiled settings record them with `[0, strlen]` instead of
`[mtime, size]`, so no `stat()` is spent on them.

### The build: `build/single-file.php`

A PHP script, no dependency, deterministic (two runs give byte-identical
files):

1. **Order.** `src/**/*.php` sorted by path, then a topological sort on
   `extends`/`implements` so interfaces and parents come first. A `use` of a
   class defined later is fine in PHP.
2. **Transform each file.** Strip `<?php`, the licence docblock and
   `declare(strict_types=1);`; wrap in `namespace X { … }` with the file's `use`
   lines inside; refuse the build if `token_get_all` finds top-level code other
   than declarations.
3. **Head.** One `declare(strict_types=1);`, the licence once, the **install
   guide as the header comment** (so an agent that has only the file knows what
   to do), then `if (class_exists(\CjwNetwork\RequestShield\Shield::class, false)) { return; }`
   for a box with both a Composer install and the file.
4. **Data.** The generated `Shipped` body.
5. **Minify** the heredoc constants that reach visitors (the challenge page's
   CSS and script, the widget script): whitespace and comments only, no
   renaming; the test compares behaviour, not bytes.
6. **Tail.** The bootstrap block (below).

A manifest per edition (`mini`, `api`, `waf`, `stats`, later `full`) lists the
directories; the same script builds every edition.

### The bootstrap block — and the fix in `bootstrap.php`

```php
(static function (): void {
    if (defined('REQUEST_SHIELD_DONE')) { return; }
    define('REQUEST_SHIELD_DONE', true);
    if (PHP_SAPI === 'cli') {
        if (realpath($_SERVER['argv'][0] ?? '') === __FILE__) { exit(\CjwNetwork\RequestShield\Cli::main($_SERVER['argv'])); }
        return;                                           // required by a test runner or a script: no effect
    }
    $named = defined('REQUEST_SHIELD_CONFIG') ? constant('REQUEST_SHIELD_CONFIG') : getenv('REQUEST_SHIELD_CONFIG');
    foreach ([$named, __DIR__ . '/request-shield.rules', __DIR__ . '/config/request-shield.rules', __DIR__ . '/config/request-shield.php'] as $f) {
        if (is_string($f) && $f !== '' && is_file($f)) { \CjwNetwork\RequestShield\Shield::protectFile($f); return; }
    }
})();
```

The same search order goes into `bootstrap.php` now (phase A), which repairs
the README story on its own. Cost: up to three `is_file()` on a site without
`REQUEST_SHIELD_CONFIG`; the constant avoids them, and the docs say so.

### Size, OPcache, speed

Expected: ~0.5 MB of source, ~12k lines for the mini edition. OPcache compiles
one script once; on PHP ≥ 8.1 the classes are linked in shared memory. The
expectation is that one file is **faster** per request than ~25 autoloaded
`require`s (each a hash lookup and, with `validate_timestamps=1`, a `stat()`),
at the price of a few MB of OPcache. Measured and reported in the PR:
`opcache_get_status(false)['scripts'][$file]['memory_consumption']`, and a
variant of `bench/overhead.php` that times `include` of the built file against
the autoloader. `opcache.max_file_size` defaults to 0 (unlimited);
`opcache.interned_strings_buffer` is mentioned in the docs for the heredocs.

### Verified, always

- **The suite runs against the file.** `tests/run.php` and the nine end-to-end
  tests take the entry point from `REQUEST_SHIELD_ENTRY` (helper `rsEntry()`);
  `RuleFileTest`'s "library update" case copies the built file when that is
  the entry.
- **`tests/SingleFileTest.php`** builds into a scratch directory and asserts:
  `php -l` passes; exactly one `declare`; no `__DIR__ . '/..` left; the version
  equals the changelog's top version (or `dev`); two builds are byte-identical;
  the file loads twice under the `class_exists` guard without error; the mini
  edition contains no `Report\` class.
- **CI.** A `single` leg builds and runs the suite against the file on every
  PR (PHP 8.4, APCu) and across the whole matrix on main and nightly; `php -l`
  on PHP 8.0–8.5.
- **Static analysis** stays on `src/`; the build is a transform.

### Release, signature, version

- `release.yml` on a `v*` tag: the tag is on `main`, `Shield::VERSION` equals
  the tag and the changelog has the section → build twice and `cmp` → the suite
  against the file → `SHA256SUMS` → a **minisign** signature (Ed25519; users
  verify with `minisign` or in pure PHP via `sodium`) with the trusted comment
  `request-shield vX.Y.Z sha256:<hex>` → `actions/attest-build-provenance` →
  the GitHub release with `request-shield.php`, `request-shield.php.minisig`,
  `SHA256SUMS`, `SHA256SUMS.minisig`, later `request-shield-{api,waf,stats}.php`.
  The signing key lives in the `release` environment with a required reviewer;
  a tag ruleset lets only maintainers create `v*` tags. The public key is in
  `README.md`, `SECURITY.md` and `Shipped::PUBKEY`.
- Stable URLs: `…/releases/latest/download/request-shield.php` and
  `…/releases/download/vX.Y.Z/request-shield.php` for pinning. The asset name
  never changes.
- **`request-shield version`** prints version, build, PHP, the store in use,
  the hosting tier, the rule-set versions and the key id. Never in a response
  header.
- **`request-shield verify <file>`** checks `SHA256SUMS` and the signature
  (pure PHP with `sodium`; checksum only, with a clear note, without it).
- **`request-shield self-update [--check] [--to=vX.Y.Z]`** — CLI only, never
  from a web request, never automatic (a cron line is the owner's explicit
  choice; `--check` exits 10 when a newer version exists): download next to
  the current file, verify signature and checksum with the embedded key,
  `php -l`, refuse a downgrade or a new major without `--major`, keep
  `request-shield.php.prev`, atomic `rename()`, print the changelog section.
  The docs say that PHP-FPM sees the new file after `opcache.revalidate_freq`
  or a reload.

## Security

- The build is reproducible and signed; the signature is checked with a key
  embedded in the running file, so a tampered download cannot pass as an
  update of itself.
- The header comment tells the installer to keep the file, its rules and its
  `store-dir` **outside the document root**; `check` warns when they are not.
- `self-update` and `verify` are CLI commands; the web SAPI has no update path.
- Nothing in the file reveals rule names to a visitor; the debug header stays
  opt-in as today.

## Cost

Zero on the pass path beyond what the source tree costs; expected lower (one
script instead of many). Up to three `is_file()` in the bootstrap without
`REQUEST_SHIELD_CONFIG`.

## Definition of done

- Steps E.1–E.7 in [0031-steps.md](0031-steps.md): `Shipped`, the build script
  with manifests and `SingleFileTest`, `REQUEST_SHIELD_ENTRY` in the suite, the
  CI `plan` job with the `single` leg, `release.yml` with signing, `version`,
  `verify`, `self-update`, `init`, and `docs/llm/install.md` followed literally
  in a fresh container.
- Numbers in the PR: bytes of the file, OPcache memory, `include` time against
  the autoloader, the bench before and after.
- ADR 0010 "single file and editions" accepted.

## Open questions

1. Is a `full` edition (mini + api + waf + stats) wanted from the start, or only
   when asked for? (0031, open question 1.)
2. Should `self-update` exist at all in the first release, or only `verify`
   plus the documented `wget`? Leaning: both, `self-update` clearly CLI-only.
3. The release key: one long-lived minisign key with offline backup, or a key
   per major version signed by the previous one? Leaning: one key, rotation
   documented in `SECURITY.md`.
