# 0046 — How long the site took: response times in the statistics

| | |
|---|---|
| Status | **Draft** -- steps 1 to 3 built (2026-10-08), the "under load" mark later: [how fast the site answered](../features/RSF06-03-statistics.md#how-fast-the-site-answered) |
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
  are answered before the site starts; the callback comes for them too. The
  cache already says which it was in a header of the answer (`X-RS-Cache: hit`
  or `miss`, `plugins/cache/src/CachePlugin.php`), and the callback already
  gets the answer's headers -- so the statistics tell the kinds apart
  without a new interface. The cache sets `miss` *before* the site answers,
  so a miss alone does not say whether the answer was kept; the statistics ask
  the cache's own rule (`CachePlugin::refusal()`, which `keep()` uses too) with
  the answer's status and headers: **hit** (from the cache), **miss** (asked
  the site, the answer may be kept), **nostore** (asked the site, the answer
  may not be kept -- `Set-Cookie`, `private`, a 404 … and that reason
  counted), **past** the cache (no such header: the cache off, a POST, a
  visitor who is not anonymous, a page that is not cacheable). A miss's body
  that turns out too large or thrown away (`ob_clean`) is only known later,
  after the measurement: counted as a miss. A hit's 1-5 ms never pull the
  site's median down: each kind has its bands. (With G.4 part 2 a logged-in
  visitor's role page can be a hit or a miss too.)
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
   there. Load shows as both lines rising together; a slow database as p95
   rising while the requests stay flat. (Who is who is known only when
   `crawlers`, `bots` or `pages` is on -- the User-Agent is looked at then;
   with `stats requests times` alone every request counts as a person's.
   `times` does not look at it by itself.)
2. **Whether the HTTP cache works** -- with the cache plugin on: the share of
   hits per hour ("68 % from the cache"); the hits' median against the
   misses' ("2 ms instead of 180 ms"), and from both what the cache saved
   ("about 40 minutes of server time today"). A hit at 25 ms instead of 1-5 ms
   says the cache's store is slow (files where APCu was meant).
3. **The slowest pages** (step 2) -- per page (the `pg:` keys the page views
   already have) the average, the slow end and its share of cache hits; only
   pages with enough views (20 an hour), so one odd request is no "slow page".
   A slow page that never comes from the cache is the first to look at (a
   cookie, a missing `shared`).
4. **Errors and time together** -- 5xx answers are counted already; next to
   the time it shows whether the site slows down before it breaks.
5. **The slow log** (`set stats-slow 2s`, on with `times`; `0` switches it
   off) -- one line per request slower than that, like MySQL's slow query
   log: time, method, path (no query string), status, ms, the request's peak
   memory (`memory_get_peak_usage()`: "/export 4.2 s, 380 MB"), cache kind,
   kind of visitor. **No address**, no query: it is about pages, not people.
   Kept as long as the hours (`stats-hours`, 7 days: its lines are details,
   like them), then gone. The p95 says *that* it is slow; the slow log
   says *which* page at 14:03.

```
Statistics · Speed                     today
───────────────────────────────────────────────────────────────────────
Requests/h      ▁▂▃▅▇█▇▅▃▂            1,840
Median (p50)    ▂▂▂▂▃▃▃▂▂▂            120 ms
Slow end (p95)  ▂▂▃▅█▇▅▃▂▂            640 ms   ← at 14:00, with the most requests: load
HTTP cache      68 % hits · hit 2 ms · miss 180 ms · saved ≈ 40 min
Shield          0.02 ms (0.02 %)

Slowest pages (≥ 20 views)      views   median    p95   from cache
/shop/search                      310   410 ms  2.1 s          0 %
/news/archive                      95   280 ms  0.9 s         71 %
```

## How it is counted

The statistics are counters, added up per hour, day, month (they survive a
restart, and they add across PHP workers). A time fits that as a **histogram**:
a counter per time band, plus the sum of the milliseconds for the average.

The bands are **fixed** -- not a setting: hours are added up into days and
months, which works only while the bands stay the same (a site that changed
its bands could not compare this month with the last). They are fine at the
low end, where the cache's answers lie:

| | |
|---|---|
| bands | ≤ 1 ms, ≤ 5, ≤ 10, ≤ 25, ≤ 50, ≤ 100, ≤ 250, ≤ 500 ms, ≤ 1 s, ≤ 2.5 s, ≤ 10 s, more (12) |
| band key, per hour | `rt:<who>|<cache>|<band>` -- who: people, crawlers, bots; cache: hit, miss, nostore, past |
| sum key, in microseconds | `rs:<who>|<cache>` (milliseconds would make a hit's 1-5 ms too coarse) |
| per page (step 2) | `pt:<who>|<path>|<band>`, `ps:<who>|<path>`, `pc:<who>|<path>` (its hits) |

More bands cost nothing per request: each request raises exactly one band
counter, however many there are; only an hour's file holds a few keys more.

p50 and p95 are read from the bands (to the band's width: "under 250 ms",
or interpolated within it) -- exact enough for "is it slow" and free of a list
of single times. Pages get the same limit as the page views (`Stats::TOP`: 100 per kind of
visitor and hour, the rest `(other)`), so a site with a million URLs keeps a
fixed size. That needs code of its own: today's limit (`Stats::group()`) knows
only the page views' keys, and with APCu it keeps the first pages seen in an
hour, cut to the most visited at the roll-up -- the time table must keep the
same pages as the views, or "views" and "median" would not match.

Counting a sum is new too: `count()` adds 1 per key today. A variant adds an
amount (APCu: `apcu_inc($key, $us)`; files: `rs:people|hit*1800`, the form a
flush already writes and the roll-up reads).

## Cost

- **Off by default**, a part of its own: `set stats requests pages times`.
  Without `times` nothing changes -- not one call on any request (AGENTS.md:
  nothing a feature needs on the path when the feature is not used).
- **With it:** at the end of the request one `microtime()`, one look for the
  cache's header in the headers the callback has anyway (under 0.1 µs), and
  three counters more (band, sum, the shield's share). Estimated +1 µs;
  **measured for step 1: about +2 µs** with APCu (StatsPlugin, decided() and
  ended(): +0.1 µs deciding, +0.9 µs the clock and the band, +0.9 µs the
  counters). Per page (step 2) two or three APCu calls more.
- **Files:** with `requests` on, the end of a request writes a line anyway --
  it gets three fields more (band, sum, the shield's share; more with the pages). With `times` alone it would be
  a line of its own, 15-25 µs (as in RSF06-03's cost table): `times` is meant
  next to `requests`.
- The callback at the end is there already whenever the statistics count a
  request that reaches the site; `times` adds no second one.
- The slow log: a comparison per request; one appended line per slow request
  only (which has cost seconds already). Its memory figure costs nothing on
  a fast request: it is read only for the line.
- The shield's own share: one `microtime()` more when the shield is done,
  only with `times`.

## Privacy

Times, paths and the kind of visitor -- no address, no cookie, no query
string. The same as the page views counted today ([privacy](../privacy.md)
needs one sentence more).

## Steps

1. `times`: the twelve bands and the sum per hour, kind of visitor and cache
   kind (hit, miss, nostore with its reason, past); p50/p95 and the cache's share and saving on the
   statistics page next to the requests; the shield's own share; the slow log
   (`stats-slow`, 2 s, with peak memory). Tests: the bands, a site that sleeps
   300 ms gives the 500 ms band, a cache hit lands in "hit", a slow request
   in the slow log without address or query; benchmark with and without.
2. Per page: the slowest pages table, with each page's share of cache hits.
3. `Server-Timing: shield;dur=0.02` -- only with `debug-header on` (which sets
   `X-RS` today): for developers in the browser's network tab, nothing in
   normal operation. It can carry only the shield's own time (the site's is
   not over when the headers go out); for every visitor it would tell that a
   shield is in front and cost bytes on every answer.
4. Later: the live view's "under load" mark -- the hourly lines answer "did we
   have load?"; a live alarm needs per-minute counters (APCu only),
   thresholds and tests against false alarms. Worth it together with the
   pressure valve: with [0041](0041-risk-score.md), when the server is under
   load, the shield could be stricter with what is not a person (crawlers that
   are not needed, a lower budget). That needs its own proposal.

## Decided (owner, 2026-10-08)

1. **Bands:** fixed, not a setting (the months must add up); fine at the low
   end for the HTTP cache (≤ 1, ≤ 5, ≤ 10 ms …).
2. **The HTTP cache:** hits, misses and past it counted apart from step 1 --
   its share of hits and what it saves are the first thing to see once it is
   on.
3. **Slow log:** in step 1, 2 s by default.
4. **Memory:** only in the slow log's line, no bands of its own.
5. **`Server-Timing`:** only with `debug-header on`.
6. **"Under load" mark:** later, with the pressure valve.
