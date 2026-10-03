# RSF05-07 The single file

request-shield can be one PHP file: `request-shield.php`, the **mini edition**.
It holds the checks, the answers, the browser check, the log, the rule
compiler, the command line and the shipped rule sets. A site without Composer
uploads it, puts its rules next to it and switches it on with one
`auto_prepend_file` line. The design is [proposal 0002](../proposals/0002-single-file-build.md);
the release with checksums and a signature, `verify`, `self-update` and `init`
follow in phase E of [0031](../proposals/0031-robust-core-plugins.md).

## Building it

```
php build/single-file.php                              # build/out/request-shield.php
php build/single-file.php --edition=stats              # build/out/request-shield-stats.php
php build/single-file.php --out=/tmp/rs.php --build=v0.4.0
```

| Edition | File | What is in it |
|---|---|---|
| `mini` | `request-shield.php` | `src/` with the command line (`Cli`), the rule sets and crawler lists of `rules/` embedded in `Rules\Shipped` |
| `stats` | `request-shield-stats.php` | `plugins/stats/src`, loaded after the mini file |
| `waf` | — | not yet: the dashboard's pages are still in the mini file; step G.3 moves them out |
| `api` | — | not yet: the API plugin comes with step G.0 |

The build is deterministic: the same sources and `--build` give the same
bytes. It refuses, and writes nothing, when a source file has code outside
its declarations, when `Rules\Shipped` or `Shield` no longer have the places
it fills, or when classes extend each other in a circle.

## What the file does

- **Included** (`auto_prepend_file`, or `require` in a front controller), it
  protects the request with the rules it finds: the constant or environment
  variable `REQUEST_SHIELD_CONFIG`, else `request-shield.rules` next to the
  file, `config/request-shield.rules`, `config/request-shield.php` --
  `bootstrap.php`'s order. Naming the file saves up to three `is_file()`.
- **Run directly** (`php request-shield.php check site.rules`), it is the
  command line, the same as `bin/request-shield`.
- **Required by a script on the command line**, it declares its classes and
  does nothing else.
- **Included twice**, or after another copy of the library, it does nothing
  the second time.

The shipped rule sets come from the file itself, not from `rules/`. The
compiled settings watch the file instead of `rules/*.rules`, so an update of
the file compiles the rules again. `rules/crawlers.php` is not embedded: with
settings from a PHP array and no `crawlers` key, the file builds the known
crawlers from its rule set once per process. A rule file (`protectFile()`)
never needs that.

## How it is put together

Each source file becomes a `namespace X { … }` block, parents and interfaces
first. Every declaration sits inside `if (true) { … }`. At the top level PHP
and OPcache declare a class before any code runs, so the guard that stops a
second include would come too late and the second include would be fatal. A
conditional declaration happens when the code reaches it, after the guard.
The challenge page's script and the widget's lose their indentation and
whole-line comments; nothing is renamed.

## Tested

`tests/SingleFileTest.php` builds both editions and checks the file: `php -l`,
one `declare`, two builds byte-identical, loading twice with and without
OPcache, the command line, a request through it, the minified scripts. The
whole suite also runs against the file:

```
php build/single-file.php && php build/single-file.php --edition=stats
REQUEST_SHIELD_ENTRY=$PWD/build/out/request-shield.php php tests/run.php
```

The runner, the end-to-end servers and the command-line tests then use the
built file (with the statistics file beside it) instead of `bootstrap.php`
and `bin/request-shield`. The demos and `ShippedTest` skip there: the demos
show the source tree's integration, and `ShippedTest` needs the source
tree's `Shipped`. CI runs it on PHP 8.4 with APCu for every pull request, on
8.0 and 8.4 for a push to main, on every leg nightly, and checks the built
file with `php -l` on every PHP version it runs.

## Starting, checking, updating

```
php request-shield.php init --app=plain --docroot=/var/www/html --out=/var/www/request-shield.rules
php request-shield.php verify request-shield.php          # SHA256SUMS next to it
php request-shield.php self-update --check                # exit 10: a newer release exists
```

- **`init --app=plain|wordpress|symfony|exponential`** writes a commented
  starter rule file (`rules/starter/<app>.rules`, embedded in the file) in
  monitor mode, with an `expect` line for each rule, so `test` passes on it
  at once and `check` has nothing to warn about. Without `--out` it prints
  the file. It refuses a file inside `--docroot`, and one that exists unless
  `--force`.
- **`verify <file>`** compares the file with `SHA256SUMS` (next to it, or
  `--sums=`). With a release key -- `Shipped::PUBKEY`, or `--key=` -- and
  sodium it also checks the minisign signature (`<file>.minisig`, or
  `--sig=`), whose trusted comment must name the same checksum. Without a key
  or without sodium it says that the signature was not checked. Exit 0: the
  released file; 1: not; 2: something it needs is missing.
- **`self-update [--check] [--to=vX.Y.Z] [--major]`** replaces the running
  single file, and `request-shield-stats.php` beside it, with a signed
  release. It reads the release's signature first: its trusted comment
  names the version and the checksum. Then it downloads every file, checks
  the signature, the checksum and `php -l`, and only then replaces them.
  The old files stay as `.prev`. It refuses a downgrade, the same version,
  and a new major version without `--major`. It runs on the command line
  only, never by itself, and not without the release key. In a source
  checkout it points to git or Composer.

The signature check is minisign's format, in pure PHP with sodium
(`Release\Minisign`). The tests hold it to signatures made by minisign 0.12
itself.

## Released

A tag `vX.Y.Z` on `main` builds the release (`.github/workflows/release.yml`):
`build/release-check.php` checks that `Shield::VERSION` and the changelog name
the version; every edition is built twice and compared byte for byte; the
suite runs against the built file; `SHA256SUMS` and a build provenance
attestation go with `request-shield.php` and `request-shield-stats.php` onto
the GitHub release. The stable address is
`https://github.com/cjw-network/request-shield/releases/latest/download/request-shield.php`.
A minisign signature is not there yet: it comes with the release key.

## Cost

Measured with `php bench/single-file.php` on PHP 8.3 with OPcache and APCu,
on PHP's built-in server, a page behind the same rule file:

| | per request, beyond a page without the shield |
|---|---|
| the source tree (`bootstrap.php`, the autoloader) | 250–450 µs |
| the single file | 50–150 µs |

The file has about 0.95 MB and takes about 2.5 MB of OPcache memory. The
ranges are two runs on a busy development machine; the built-in server's own
share is the same in both, and the order of the two has held in every run.

## Limits

- **Add-on editions are not switched on by the rules yet.** The statistics
  file loads after the mini file and declares its classes. A site that wants
  them needs the extension offered before the rules compile, its plugin made
  per request, its pages and its `stats` command. That wiring is open in
  0031's steps file.
- **Not together with a Composer install of the library.** If Composer has
  loaded `Shield` already, the file does nothing. If Composer has loaded some
  of the library's classes but not `Shield`, the file stops at the first one
  it declares again, a fatal error. Use one of the two.
- `self-update` refuses until the releases are signed: the release key comes
  last (0031 step H.2a). Until then `verify` checks the checksum only, and
  says so.
