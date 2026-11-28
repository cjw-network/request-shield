# 0050 — A ban and an on-demand budget for one area: written inside a `match` block

| | |
|---|---|
| Status | **Accepted** 2026-11-28, implemented for 0.5.0 (see [bans](../features/RSF01-02-ip-lists.md#bans) and [budgets](../features/RSF03-01-budgets.md#a-budget-for-one-area)) |
| Proposed | 2026-11-28 |
| Affects | rule files (`ban` inside a `match` block; no new word), `bans[].paths` and `budgets.<name>.refuseSpent` in the settings (format 68), `BanRule`, `BudgetRule`, `Shield::consume()`, `Shield::liftBan()`, the lists page |
| Relates to | [0008 match blocks](0008-match-blocks.md) · [0013 IP lists](0013-ip-lists.md) (bans) · [0001 earn back a spent budget](0001-earn-back-a-spent-budget.md) · [ADR 0015](../adr/0015-area-bans-keyed-by-rule.md) |

## The question

An application guards one endpoint -- an MCP endpoint, an API path -- and
nothing else: a pace for it, failed sign-ins counted by the application
(`consume()`), and a ban for a client that keeps going past those limits.
Measured against 0.4.0, three things stood in the way:

1. **A ban is the server's.** `ban` inside a `match` block was an error
   ("not about paths"); written above it, a ban refuses the client on every
   page of every website -- for a few wrong tokens on one endpoint.
2. **A spent on-demand budget does not hold.** `decide()` never looks at an
   on-demand budget; after the eleventh failed sign-in of `limit auth 10/min
   on-demand` the next request -- a guess with another token -- passes again.
3. **An on-demand overrun is no `limits` signal.** `consume()` signals the
   budget's name, so `ban after 3 limits …` never sees failed sign-ins.

## What it does

Inside a `match` block, without a new word:

```text
match /api/** {
  api-path
  [API-RATE] limit api 120/min
  [API-AUTH] limit api-auth 10/min on-demand     # consume('api-auth') where a token was wrong
  [API-BAN]  ban after 3 limits in 15m for 30m   # banned from /api/** only
}
```

- (a) **The ban is the area's.** It counts only signals of requests to the
  block's paths, and refuses only there; the client still reaches the rest of
  the site. It grows (`ban-growth`) and ends (`ban-max`) on its own counter.
- (b) **A spent on-demand budget holds the area.** Once `consume()` took it
  past its limit, `decide()` answers the area's next requests with a pause
  (429, `Retry-After`; JSON on an `api-path`) until the count falls back
  within the window. Looked at, not counted: waiting does not prolong it.
  With `on-exceeded challenge` the check that frees the counter, as for any
  spent budget.
- (c) **An on-demand overrun is a `limits` signal** for the bans of the same
  block, and so is each request (b) refuses -- only for those: a ban outside
  the block, or of another block, counts neither.

Outside any block, a `ban` and an on-demand budget behave as in 0.4.0. That
includes an on-demand budget written with `at <paths>` (the sign-in use
case): it is an area's budget, but (b) and (c) are the block's -- the
compiled flag `refuseSpent` marks them, so `limit … on-demand at …` keeps
its meaning.

## Decisions

| Question | Decision | Why |
|---|---|---|
| New syntax? | **None.** `ban` may stand inside a block; `ban … at <paths>` stays an error outside one | The block already says "these rules are about this area"; a second way to write the same is a second thing to learn. |
| Spent means? | **Past the limit** (count > limit), as the budget itself refuses | `limit x 10/min` lets ten through, as everywhere; the eleventh is refused by `consume()` and from then on the area waits. |
| Where is an area's ban kept? | **`ban:<client>@<rule>`** next to the site's `ban:<client>` ([ADR 0015](../adr/0015-area-bans-keyed-by-rule.md)) | The lists page, `marks('ban:')`, `ban-keep file` and the restore after an APCu restart keep working unchanged; lifting an address lifts its areas' bans too. |
| `at <paths>` on-demand budgets? | **Unchanged** | They are documented and used (login backoff); their behaviour must not change under them. |
| A ban inside a `site` block? | **Still an error** | Bans are kept per server; an area's ban across websites is the next question, not this one. |

## Cost

Nothing new on the passing path when no block uses it: the ban step makes the
same single lookup, on-demand budgets outside blocks add no step. With it: one
pattern match per area's ban and per such budget on every request, a store
lookup only on their paths (bench numbers in the pull request).
