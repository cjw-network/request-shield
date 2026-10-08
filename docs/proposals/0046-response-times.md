# 0046 — How long the site took: response times in the statistics

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-08 |
| Affects | the statistics plugin (`plugins/stats`: `StatsPlugin::ended()`, `Stats`, the statistics page, the live view), `stats` parts, `RSF06-03` |
| Relates to | [RSF06-03 statistics](../features/RSF06-03-statistics.md) · [0022 visitors page](0022-visitors-page.md) · [0041 risk score](0041-risk-score.md) (a busy server could raise the bar) · [0039 cache compatible](0039-cache-compatible.md) |

## The question

*"Could the response time of a request be kept too -- to see load on the
server, or slow pages, later in the statistics?"* (owner, 2026-10-08)

Yes, and cheaply: the statistics already listen at the end of every request
that reaches the site. That is the one moment the time can be measured.

## What can be measured

The shield runs first, the site after it. With statistics on, the shield
already asks PHP to call it back once the site is done (`register_shutdown_function`,
`StatsPlugin::ended()` -- that is how a page view gets its status 200 or 404).
From that callback:

```
request arrives            shield decides         site answers            PHP is done
      │ REQUEST_TIME_FLOAT       │ (µs)                 │                        │ ended()
      ├──────────────────────────┼──────────────────────┼────────────────────────┤
      │◄── shield ──►│◄─────────────── the site (WordPress, Symfony, …) ────────►│
      │◄──────────────────────────── response time (what this proposal keeps) ──►│
```

- **Response time** = end of PHP − `$_SERVER['REQUEST_TIME_FLOAT']` (the web
  server's own start time of the request). It holds the shield's
  microseconds and the site's whole work: database, templates, API calls.
- **The shield's own share** is known too (its start and end), so the page can
  say "of 180 ms, the shield took 0.02 ms" -- a number worth showing.
- **Not in it:** the network and the browser's rendering (that is the
  visitor's side; it would take a script in the page). And a request the
  shield refuses never reaches the site -- its time is the shield's alone and
  is not counted as a page's.

**A limit to say plainly:** a site that hands the answer to the visitor early
(`fastcgi_finish_request()`, Symfony's `kernel.terminate`) works on after
that; the time measured is then **how long the PHP worker was busy** -- the
right number for load, an upper bound for "how long the visitor waited".

## What the statistics would show

1. **Per hour: how fast** -- the median and the slow end (p50, p95), by
   people, crawlers and bots, next to the requests per hour that are already
   there. Load shows as both lines rising together; a slow database as p95
   rising while the requests stay flat.
2. **The slowest pages** -- per page (the `pg:` keys the page views already
   have) the average and the slow end, only pages with enough views (say 20 an
   hour), so one odd request is no "slow page".
3. **Right now (live view)** -- requests in the last minute (there already)
   and their median time; a mark when the slow end of the last 5 minutes is
   well above the hour before ("the server is under load").
4. **Errors and time together** -- 5xx answers are counted already; next to
   the time it shows whether the site slows down before it breaks.
5. **Optional: a slow log** (`set stats-slow 2s`) -- one line per request
   slower than that, like MySQL's slow query log: time, method, path (no
   query string), status, ms, kind of visitor. **No address**, no query: it
   is about pages, not people. Kept `stats-days`, then gone.

```
Statistics · Speed                     today       p50 ▁▂▂▃▅▇▅▃▂   p95
───────────────────────────────────────────────────────────────────────
Requests/h      ▁▂▃▅▇█▇▅▃▂            1,840
Median (p50)    ▂▂▂▂▃▃▃▂▂▂            120 ms
Slow end (p95)  ▂▂▃▅█▇▅▃▂▂            640 ms   ← at 14:00, with the most requests: load
Shield          0.02 ms (0.02 %)

Slowest pages (≥ 20 views)      views   median    p95
/shop/search                      310   410 ms  2.1 s
/news/archive                      95   280 ms  0.9 s
```

## How it is counted

The statistics are counters, added up per hour, day, month (they survive a
restart, and they add across PHP workers). A time fits that as a **histogram**:
a counter per time band, plus the sum of the milliseconds for the average.

| band | key (per hour, per kind of visitor) |
|---|---|
| ≤ 50 ms, ≤ 100, ≤ 250, ≤ 500, ≤ 1 s, ≤ 2.5 s, ≤ 10 s, more | `rt:people|3` … |
| the sum, in ms | `rs:people` |
| per page: band and sum | `pt:people|/shop/search|4`, `ps:people|/shop/search` |

p50 and p95 are read from the bands (to the band's width: "under 250 ms",
or interpolated within it) -- exact enough for "is it slow" and free of a list
of single times. Pages follow the page views' existing limit (`Stats::TOP`, the 100 most
visited per kind of visitor and hour; the rest is `(other)`), so a site with a
million URLs keeps a fixed size.

## Cost

- **Off by default**, a part of its own: `set stats requests pages times`.
  Without `times` nothing changes -- not one call on any request (AGENTS.md:
  nothing a feature needs on the path when the feature is not used).
- **With it:** at the end of the request one `microtime()` and two or three
  counters more (APCu: about 0.2 µs each; files: the line that is written
  anyway gets one field more). Estimated +1 µs with APCu; measured before it
  goes in (`bench/overhead.php`, a case with `times`).
- The callback at the end is there already whenever the statistics count a
  request that reaches the site; `times` adds no second one.
- The slow log: one appended line per slow request only.

## Privacy

Times, paths and the kind of visitor -- no address, no cookie, no query
string. The same as the page views counted today ([privacy](../privacy.md)
needs one sentence more).

## Steps

1. `times`: the bands and the sum per hour and kind; p50/p95 on the
   statistics page next to the requests; the shield's own share. Tests:
   the bands, a site that sleeps 300 ms gives the 500 ms band; benchmark
   with and without.
2. Per page: the slowest pages table.
3. Live view: the last minutes' median and the "under load" mark.
4. Optional: `stats-slow` and its log, its tab in the dashboard.
5. Later, with [0041](0041-risk-score.md): when the server is under load,
   the shield could be stricter with what is not a person (crawlers that
   are not needed, a lower budget) -- the shield as a pressure valve. Only an
   idea here; it needs its own proposal.

## Open questions (owner)

1. **Bands:** the ones above, or the site's own (`set stats-times 100ms 500ms 2s`)?
2. **Slow log** in step 1 or later? And its threshold's default (2 s)?
3. **"Under load" mark:** worth it in the live view, or only the lines in the
   statistics?
4. **Memory** too? `memory_get_peak_usage()` at the end costs nothing more
   and shows pages that need a lot (an export, a search) -- same bands idea.
5. **`Server-Timing` header** for developers (`Server-Timing: shield;dur=0.02`)?
   It can only carry the shield's own time (the site's is not over when the
   headers go out) -- small, but nice in the browser's network tab.
