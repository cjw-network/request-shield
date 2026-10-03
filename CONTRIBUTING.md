# Contributing

Thank you for helping. The shield runs before every request of a site, so two
things come first: **it must be right, and it must be fast.**

## Rules

1. **Tests for every change.** `php tests/run.php` — no framework needed —
   and once more with OPcache and APCu on, as production runs:
   `php -d apc.enable_cli=1 -d opcache.enable_cli=1 -d opcache.file_update_protection=0 tests/run.php`
   (that is how a settings file rewritten within one second was found to be
   compiled stale). Add
   unit tests and, where the change touches the request path, an end-to-end
   case (`tests/ProtectTest.php` runs PHP's built-in server with
   `auto_prepend_file`). Check that a new test fails without the change.
2. **Measure.** `php -d apc.enable_cli=1 -d opcache.enable_cli=1 bench/overhead.php`
   before and after; the passing path must not get slower without a reason
   stated in the pull request. Keep work a feature needs off the passing path.
3. **PHP 8.0 to the newest release.** PHP 8.0 is the baseline of Red Hat
   Enterprise Linux 9 and its rebuilds, so it stays supported. CI runs every
   version; no syntax or functions newer than 8.0 unless guarded (no
   `readonly` — use `@readonly`, which PHPStan enforces; no enums, no `never`,
   no string-key unpacking, no `array_is_list()`, no `xxh*` hashes).
4. **Static analysis stays clean.** `composer install`, then
   `composer phpstan` (level max) and `composer taint` (Psalm taint analysis).
5. **No runtime dependencies.** Development tools go in `require-dev`.
6. **Document it.** A feature gets `docs/features/<name>.md` (what it does, use
   cases, configuration, cost, limits); a planned one a proposal in
   `docs/proposals/`; a design decision an ADR in `docs/adr/`; every change a
   line in `CHANGELOG.md` under *Unreleased* — in the same pull request.
7. **The single file.** `php build/single-file.php` turns `src/` into one
   file ([the single file](docs/features/single-file.md)); `tests/SingleFileTest.php`
   builds it and puts a request through it. The whole suite runs against the
   built file too:
   `php build/single-file.php && php build/single-file.php --edition=stats`, then
   `REQUEST_SHIELD_ENTRY=$PWD/build/out/request-shield.php php tests/run.php`
   (CI legs with "single file" in their name). A test that starts a server or the command line takes
   them from `rsEntry()` and `rsCli()` (`tests/helpers.php`), never from
   `bootstrap.php` or `bin/request-shield` directly. A source file holds declarations
   only -- no code at its top level, or the build refuses. The shipped data is
   read through `Rules\Shipped` only, nothing else names `rules/`.
8. **What CI runs** depends on the event (`build/ci-plan.php`): a pull request
   3 legs (PHP 8.0 file store, 8.4 APCu, 8.4 APCu against the single file)
   plus minimal hosting and static analysis; a push to main 14; nightly and
   by hand all 24 and a check that two builds are byte-identical. Run the
   other legs locally when a change depends on the PHP version.
9. **Fail safe.** When the shield is unsure (a store that cannot be written,
   an index that may be stale), it lets the request through rather than
   blocking a real user; only clear cases are refused.

## Releasing

1. **Crawler address lists:** `php bin/update-crawler-lists` fetches the
   operators' published lists into `rules/crawlers/*.json` and regenerates
   `rules/crawlers.php`. It refuses a list that shrank to less than half (a
   broken download; `--force` takes it) and exits 1 when one failed. Look over
   the diff, re-check `rules/crawlers.rules` against the operators' pages when
   a crawler was added or renamed, raise its `version`, and commit. The tests
   fail when `rules/crawlers.php` does not match the lists
   (`php bin/update-crawler-lists --check`); a weekly workflow
   (`crawler-lists.yml`) fails when an operator's list has changed since.
2. `CHANGELOG.md`: *Unreleased* becomes the version with its date; the README's
   status.
3. Tests on every PHP version, PHPStan, Psalm (CI), then tag `vX.Y.Z` on `main`.

## Commits

One change per commit; the subject says what the code does now, in the
present tense ("Added: …", "Fixed: …", "Updated: …").

## Security issues

Not as issues — see `SECURITY.md`.
