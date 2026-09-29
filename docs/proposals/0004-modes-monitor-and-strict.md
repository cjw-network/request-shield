# 0004 — Modes: monitor first, strict when attacked

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-29 |
| Affects | Shield::protect(), rule files, the log |

## Summary

One setting for how hard the shield acts — `set mode off | monitor | enforce |
strict` — plus `monitor` for single rules, so a new rule can be watched in the
log before it refuses anyone, and a site under attack can tighten everything
with one line. A fresh challenge per path (`challenge /login max-age 5m`)
completes it.

## Motivation

- A site owner putting a WAF in front of a live site wants to see first what it
  **would** do: which visitors, which URLs, which rule. Web application
  firewalls call this detection-only (ModSecurity `DetectionOnly`) or report
  mode.
- A new rule on a running site ("block `/old-api/**`") should be testable
  without risk.
- Under attack, an administrator wants one switch — not ten edited limits.
- An emergency switch that turns the shield off without removing the include.

## The modes

| Mode | What happens |
|---|---|
| `off` | Nothing: no checks, no counting, no log. The include stays. |
| `monitor` | Everything is checked and counted and **logged as it would be decided**, but every request passes. `X-Request-Shield: monitor reject blocked path; rule=…`. Only the cache marking stays (`allow-uncached` never refuses anyone). |
| `enforce` | As today (the default). |
| `strict` | `enforce` with tighter defaults for budgets and the challenge, for a site under attack (below). Rules in the files still win where they set a value. |

**`strict`**, proposed values:

- every budget without its own `challenge-at` challenges at a **quarter** of its
  limit (`requests 600/min` → the check from 150 requests a minute);
- the pass cookie lives **15 minutes** instead of an hour;
- the difficulty starts at twice `difficulty-min`;
- `allow-uncached` requests (unknown URLs and parameters) count **double**
  against the budgets — the pattern of cache-busting floods;
- search engines stay welcome (verified by DNS).

## Monitoring single rules

```text
monitor block     /old-api/**        # logged as "monitor reject", not refused
monitor restrict  /admin/** to 192.0.2.0/24
monitor limit     searches 10/min on-demand
```

A `monitor` rule decides nothing; the log shows what it would have done, with
its line. When the log shows no false hits, remove the word.

## The log levels (implemented with rule files)

`set log-level off | stop | flag | all` — see
[the log](../features/log-and-rule-ids.md). In `monitor` mode the would-be
decisions are logged at their level (`stop` shows what would have been
refused).

Suggested later: `set log-format json` (one JSON object per line, for log
shippers), and an optional second log for `all` with sampling
(`set log-sample 1/100`) to see normal traffic without a write per request.

## A fresh check for sensitive paths

```text
set        pass-ttl 1h
challenge  /login  /checkout/**  max-age 5m
```

The pass cookie already carries its time; `max-age` asks for one issued in the
last five minutes on those paths, while the rest of the site keeps the long
pass. No state: the cookie's issue time is signed.

## Cost

`off` and `enforce`: nothing. `monitor`: the rule lookup and a log write for
requests that would have been stopped (as the log today). `strict`: none per
request (other numbers only).

## Open questions

1. Should `monitor` also skip the budgets' counting (to not affect the numbers
   once switched to `enforce`), or count as `enforce` would (proposed: count)?
2. `strict` values: are a quarter, 15 minutes, double for uncached right?
3. Should `strict` switch on by itself when a site-wide rate crosses a
   threshold (an "under attack" detector), or stay a manual switch?
4. `max-age`: per `challenge` line (proposed), or per path in a separate rule?
