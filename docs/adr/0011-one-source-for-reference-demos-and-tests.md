# ADR 0011 — One source for the reference, the demos and the tests: feature ids

- Status: proposed (2026-10-03; accepted with [0031](../proposals/0031-robust-core-plugins.md) phase F)

## Context

The rule vocabulary was kept by hand in four places inside `RuleFile`; the
demos' tables were PHP arrays; docs, demos and tests had nothing in common to
find a feature by.

## Decision

- Every feature has an id **`RSF<gg>-<nn>`** ("request-shield feature", two digits each: `RSF02-06`),
  never reassigned. Groups follow the question a request answers, in the order
  the shield asks: RSF01 who is asking · RSF02 what is asked · RSF03 how often ·
  RSF04 what a cache may keep · RSF05 operating · RSF06 watching & connecting.
- A feature exists only with **docs** (`docs/features/<slug>.md`, H1 with the
  id, ≥ 1 diagram), a **demo** (a `# demo: RSF… ` group of `expect` lines in
  the demo rule file), **tests** whose names begin with the id (end-to-end for
  the request path), a **help link** in every UI that shows it.
  `tests/FeatureContractTest.php` enforces all of it.
- `src/Rules/Vocabulary.php` is the single declarative source of the rule
  words and `set` keys; the reference docs, error messages, setup page and
  help links are generated from it.
- Demos are generated from `expect` lines; `request-shield test`, the demo
  page, the docs' example tables and the recorded static demo read the same
  lines.

## Consequences

- `php tests/run.php RSF02-06` runs exactly one feature's tests; `trace` reads in
  the order of the docs.
- Adding a feature demo means adding `expect` lines, not PHP.
