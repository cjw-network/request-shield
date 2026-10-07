# 0041 — A risk score per request, shown in the live view

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-07 |
| Affects | the live view (`Waf\LivePage`, `Live::push()`), the log line (one more field), later perhaps the check's difficulty |
| Relates to | [RSF06-02 live view and lists](../features/RSF06-02-live-and-lists.md) · [RSF03-01 budgets](../features/RSF03-01-budgets.md) · [0038 invisible signals](0038-checks-without-friction.md) · [0042 a harder task for forms](0042-pow-v2-for-forms.md) |

## The idea

ALTCHA Sentinel (a paid, self-hosted service in front of forms and APIs)
shows each request in its dashboard with **a risk score and the signals
behind it**. The shield's live view already says *what* happened and *why*
(the rule, the list entry, the budget) -- but only for requests it stopped
or checked, and only as yes or no. What an operator cannot see:

- **how close** a request came: a client at 90 % of its budget looks like
  one at 5 % until it crosses the line;
- **which clients are suspicious without being stopped**: a "Googlebot" that
  is not Google's, an address on a feed in `monitor`, a client that left two
  checks unsolved -- each alone below every limit;
- **what to tighten next:** which signal comes up most often in the rows
  just below the line.

The proposal: one number from 0 to 100 per row, made from what the shield
**already knows** when it decides, with the signals as small labels next to
it.

```text
  Live                                        [ risk ≥ 50 ▾ ]  [ pause ]

  12:04:31  203.0.113.7    GET /login        checked      ██████████░  82
            pace 9/10 a minute · 2 checks unsolved · no Accept-Language
  12:04:29  198.51.100.4   GET /             let through  ██████░░░░░  55   (watched)
            claims Googlebot, not Google's · on a feed: example-list
  12:04:22  192.0.2.10     POST /contact     refused      ███████████ 100
            on the deny list: scraper
```

## Where the number comes from

Signals the request path has **already worked out**, plus two that are
read only when a row is written -- nothing on a request the live view does
not keep:

| Signal | Already known from | Weight (first guess) |
|---|---|---|
| the fullest budget's fill | the budget check: `Decision::$level`, set **only** on a check or a pause (`Decision::challenge()`, `spent()`); a request let through gets the shared `Decision::allow()` with no fill -- for watched rows, see *What it costs* | 0–40, rising with the fill |
| checks asked for and not solved by this client | **new:** a counter per bucket, raised on the check page (a path that is slow anyway), cleared by a pass | 10 each, up to 30 |
| claims to be a crawler, is not | the crawler check (`Decision::$claimed`) | 30 |
| on a feed ([0025](0025-blocklist-feeds.md)) that does not refuse on its own | the IP table | 25 |
| a `monitor` rule matched | the rules | 20 |
| banned before today | the bans' count per day (the lists page's "banned 3 times today"), read from the store when the row is written | 15 |
| a plain request shape (no `Accept-Language`, no `Accept`) | the headers, read only when the row is written | 5 each |
| later: honeypot filled, sent too fast ([0038](0038-checks-without-friction.md)) | the form's signals | 40 / 25 |

The sum is capped at 100; a refusal by a list or a rule is 100. The weights
are one table in the code, shown on the rules page, not settings -- an
operator who disagrees with a weight tells us, rather than tuning a number
nobody else understands. (*Open question 2.*)

## Which rows

- **As today:** every request the shield stopped or checked, now with its
  number.
- **New, "watched":** requests the shield **let through** with a number at
  or above a threshold (`set live-watch 50`, off by default). These are the
  near misses -- the rows that show what to tighten.

## What it costs

- **Live view off** (the default): nothing. The number is made in the live
  view's sink, not in the decision.
- **Live view on, only stopped requests:** a few additions per row that is
  written anyway (well under a microsecond).
- **"Watched" on:** the number for **every request that passes** -- this is
  the one part that runs on the passing path, so it is opt-in and measured
  with `bench/overhead.php` before it is offered. Its new work: reading two
  headers, and **carrying the budgets' fill on a request let through** --
  today the budget check counts but does not hand the fill on, and a
  passing request gets the one shared `Decision::allow()`. With
  `live-watch` on, the budget check keeps its highest fill (one float, set
  where it compares the counts anyway) for the sink to read; with it off,
  nothing changes. Without that, a watched row would miss its strongest
  signal, the client at 90 % of its budget.

## Not in this proposal

- **The number deciding anything.** First it is shown, so its weights can
  be judged against real traffic. A later step could let it choose the
  check's difficulty (between `difficulty min` and `max`, as the budget's
  fill does today) -- what Sentinel calls "adaptive". That gets its own
  proposal, with the evidence from this one. Fail safe: a number never
  refuses a request on its own.
- **Machine learning, outside reputation services, fingerprints.** Sentinel
  scores with its own models and threat feeds; the shield stays a file on
  the server that sends nothing anywhere. Feeds the operator chose
  ([0025](0025-blocklist-feeds.md)) are the outside knowledge it has.
- **Tracking mouse and keyboard** (Sentinel's "Human Interaction
  Signature"): more than the shield wants to know about a visitor; 0038's
  honeypot and time already cover most of what it catches in forms.

## Plan

1. The weight table and the number in `Live::push()`, one field `risk` in
   the row and the log line (`LogStats::parse()` reads it, an old line has
   none); unit tests per signal, an end-to-end test through the live page.
2. The live view: the bar, the labels, a filter "risk ≥ …", sorting by it.
3. `set live-watch <n>`: the budgets' fill carried on the passing path
   (only with it on), the watched rows, with the benchmark before and after
   -- on and off.
4. Afterwards, from what the numbers show: the proposal for letting them
   choose the difficulty.

## Open questions for the owner

1. **Watched rows at all?** They are the useful part, and the only one with
   a cost on the passing path. *Recommendation: yes, opt-in, threshold 50.*
2. **Fixed weights or settings?** *Recommendation: fixed, shown on the rules
   page; settings once there is evidence that sites need different ones.*
3. **The number in the log file too,** or only in the live view?
   *Recommendation: in the log -- the statistics can then count "near
   misses" per day.*
