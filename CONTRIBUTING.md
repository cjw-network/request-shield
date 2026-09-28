# Contributing

Thank you for helping. The shield runs before every request of a site, so two
things come first: **it must be right, and it must be fast.**

## Rules

1. **Tests for every change.** `php tests/run.php` — no framework needed. Add
   unit tests and, where the change touches the request path, an end-to-end
   case (`tests/ProtectTest.php` runs PHP's built-in server with
   `auto_prepend_file`). Check that a new test fails without the change.
2. **Measure.** `php -d apc.enable_cli=1 -d opcache.enable_cli=1 bench/overhead.php`
   before and after; the passing path must not get slower without a reason
   stated in the pull request. Keep work a feature needs off the passing path.
3. **PHP 8.1 to the newest release.** CI runs every version; no syntax or
   functions newer than 8.1 unless guarded.
4. **Static analysis stays clean.** `composer install`, then
   `composer phpstan` (level max) and `composer taint` (Psalm taint analysis).
5. **No runtime dependencies.** Development tools go in `require-dev`.
6. **Document it.** A feature gets `docs/features/<name>.md` (what it does, use
   cases, configuration, cost, limits); a planned one a proposal in
   `docs/proposals/`; a design decision an ADR in `docs/adr/`; every change a
   line in `CHANGELOG.md` under *Unreleased* — in the same pull request.
7. **Fail safe.** When the shield is unsure (a store that cannot be written,
   an index that may be stale), it lets the request through rather than
   blocking a real user; only clear cases are refused.

## Commits

One change per commit; the subject says what the code does now, in the
present tense ("Added: …", "Fixed: …", "Updated: …").

## Security issues

Not as issues — see `SECURITY.md`.
