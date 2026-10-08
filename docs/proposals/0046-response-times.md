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

Yes, and cheaply: the statistics already listen at the end of nearly every
request the shield lets through. That is the one moment the time can be
measured.

## What can be measured

The shield runs first, the site after it. With statistics on, the shield
already asks PHP to call it back at the end (`register_shutdown_function` in
`Shield::record()`, then `StatsPlugin::ended()` -- that is how a page view gets
its status 200 or 404). The call comes for every request the shield lets
through, except the paths not counted (`stats-skip`) and the dashboard's own
pages -- as long as one counting part is on; `times` would be one more such
part. From that callback:

```
request arrives     shield decides        site answers, its main script ends    ended()   the site's shutdown work
      │ REQUEST_TIME_FLOAT │ (µs)                           │                      │       (WordPress "shutdown",
      ├────────────────────┼────────────────────────────────┼──────────────────────┤        destructors, session
      │◄─ shield ─►│◄──────────── the site (WordPress, Symfony, …) ───────────────►│        write) -- not in it
      │◄─────────────────── response time (what this proposal keeps) ────────────►│
```

- **Response time** = the time at `ended()` (one `microtime()` more: the time
  the callback gets today is the request's start) − `$_SERVER['REQUEST_TIME_FLOAT']`
  (the web server's own start time of the request). It holds the shield's
  microseconds and the site's work in its main script: database, templates, API
  calls, and what the front controller does after the answer went out (Symfony's
  `kernel.terminate`, work after `fastcgi_finish_request()`).
- **Not in it:** PHP calls the shutdown functions in the order they were
  registered, and the shield's comes first (it registers before the site
  runs). So the site's own shutdown work -- WordPress's `shutdown` hook, where
  many plugins do their work after the answer, destructors, the session's
  write -- runs after the measurement. Neither the network nor the browser's
  rendering is in it either (that is the visitor's side; it would take a script in the
  page).
- **Answers from the HTTP cache** (the cache plugin, a hit) pass the shield and
  are answered before the site starts; the callback comes for them too. Their
  sub-millisecond times would pull the median down -- they are counted apart
  (a band set of their own, "from the cache"), never mixed with the site's.
- **A refused request** never reaches the site -- its time is the shield's
  alone and not counted as a page's.
- **The shield's own share** ("of 180 ms, the shield took 0.02 ms") needs a
  second `microtime()` when the shield is done -- only with `times` on. It
  would start where the shield's clock starts today (after reading the
  settings and the request), so it says a little less than the shield costs;
  `bench/overhead.php` remains the number for that.

**What the number is, then:** how long the site needed until its main script
was done -- the right number for slow pages and for load; for "how long the
visitor waited" an upper bound when the site answers early, and for "how long
the worker was busy" a lower bound when it has much shutdown work.

## What the statistics would show

1. **Per hour: how fast** -- the median and the slow end (p50, p95), by
   people, crawlers and bots, next to the requests per hour that are already
   there. (Who is who is known only when `crawlers`, `bots` or `pages` is on --
   the User-Agent is looked at then; with `stats requests times` alone every
   request counts as a person's. `times` does not look at it by itself.) Load shows as both lines rising together; a slow database as p95
   rising while the requests stay flat.
2. **The slowest pages** -- per page (the `pg:` keys the page views already
   have) the average and the slow end, only pages with enough views (say 20 an
   hour), so one odd request is no "slow page".
3. **Right now (live view)** -- requests in the last minute (there already,
   with APCu, people only) and their median time, with the same limits; a mark when the slow end of the last 5 minutes is
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
of single times. Pages get the same limit as the page views (`Stats::TOP`: 100 per kind of
visitor and hour, the rest `(other)`), so a site with a million URLs keeps a
fixed size. That needs code of its own: today's limit (`Stats::group()`) knows
only the page views' keys, and with APCu it keeps the first pages seen in an
hour, cut to the most visited at the roll-up -- the time table must keep the
same pages as the views, or "views" and "median" would not match.

Counting a sum is new too: `count()` adds 1 per key today. A variant adds an
amount (APCu: `apcu_inc($key, $ms)`; files: `rs:people*180`, the form a flush
already writes and the roll-up reads).

## Cost

- **Off by default**, a part of its own: `set stats requests pages times`.
  Without `times` nothing changes -- not one call on any request (AGENTS.md:
  nothing a feature needs on the path when the feature is not used).
- **With it:** at the end of the request one `microtime()` and two counters
  more (band and sum; APCu about 0.2 µs each). Estimated +1 µs with APCu for
  step 1; per page (step 2) two or three APCu calls more. Measured before it
  goes in (`bench/overhead.php`, a case with `times`).
- **Files:** with `requests` on, the end of a request writes a line anyway --
  it gets two fields more (four with the pages). With `times` alone it would be
  a line of its own, 15-25 µs (as in RSF06-03's cost table): `times` is meant
  next to `requests`.
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
