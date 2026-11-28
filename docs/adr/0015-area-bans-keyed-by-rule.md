# ADR 0015 — An area's ban is kept under its rule: `ban:<client>@<rule>`

- Status: accepted (2026-11-28, with [proposal 0050](../proposals/0050-area-bans-and-spent-budgets.md))

## Context

A ban was one store mark per client, `ban:<client>`, looked up first on every
request. A ban written inside a `match` block must refuse only on the block's
paths. Two clients' bans, or one client's ban from two areas, must not see
each other; the lists page, the API, `ban-keep file` and the restore after an
APCu restart all list bans by the prefix `ban:`.

## Decision

- An area's ban is the mark `ban:<client>@<rule>` (its growth counter
  `bans:<client>@<rule>`), the rule being the ban's ID. The site's ban stays
  `ban:<client>`.
- The ban step (`BanRule`) looks up `ban:<client>` only when a ban outside any
  block exists, and `ban:<client>@<rule>` only for a request on that ban's
  paths. Without area bans it does exactly what it did.
- `Shield::liftBan()` lifts the site's mark and every area's mark of the
  address. The lists page shows the key's client part with `@<rule>`.
- A spent on-demand budget of a block is a budget step that only peeks at the
  counter (`Store::peek()`), so waiting does not count.

## Consequences

- No new store, no new format for the stored marks; everything that lists
  `ban:` sees area bans.
- Renaming an area ban's ID forgets its running bans (they end by themselves);
  a ban inside a block needs an ID to read well on the lists page.
- The compiled settings carry `bans[].paths` and `budgets.<name>.refuseSpent`:
  format 68.
