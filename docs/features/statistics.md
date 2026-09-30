# Statistics: what the shield did, and what the crawlers did

## What it does

With `set stats on` the shield counts, per hour, while requests pass:

- **requests** — let through, checked, told to wait, refused; the rule behind
  each that was not a plain "let through"; the answer's **status code** (the
  site's own, at the end of the request, and the shield's own refusals); what
  monitor mode would have done;
- **crawlers** — for each known crawler ([0011](known-crawlers.md)): how
  often it came, verified or only claiming the name, let through / checked /
  refused / told to wait, whether it read `robots.txt`, its top pages (50 a day),
  its last visit and address;
- **not-found** — pages the site answered with 404 or 410 (the top 50 a day), and
  **where the links to them are**: a page of the site itself (a broken link to
  fix) or another site's host;
- **sitemaps** — every request for a sitemap (`sitemap.xml`, `sitemap_index.xml`,
  `sitemap-news.xml`, … also `.gz`) with the site's answer (which exist: 200,
  which not: 404), and which verified crawler read which, how often and when last
  — useful after a content update: has Googlebot read the new sitemap yet?
- **bots** — other clients that say they are tools, not browsers, by family:
  `python`, `curl`, `wget`, `go`, `java`, `node`, `php`, `perl`, `headless`,
  `scrapy`, `empty` (no User-Agent), `other`.

Nobody has to read the log for it. It answers questions such as *did GPTBot
crawl the site this week, or was it refused?*, *which pages are missing, and who
links to them?*, *how many requests did the shield turn away?*

```text
$ php bin/request-shield stats site.rules
Last 7 day(s), 2026-09-24 to 2026-09-30:

  48,213 requests: 46,990 let through, 312 checked, 18 told to wait, 893 refused
  answers: 200: 45,120, 301: 410, 403: 221, 404: 1,142, 429: 18

Rules that decided most:
       612  SCAN-HIDDEN
       221  SITE-ADMIN

Not found:
        88  /old-shop   linked from: /news/2025/summer (61), partner.example (27)

Known crawlers                  seen  verified claimed allowed checked refused  last
  CRAWL-GOOGLE                 3,120     3,020     100   3,020       0       0  2026-09-30 09:12
  CRAWL-GPTBOT                 1,204     1,204       0       0       0   1,204  2026-09-30 08:55

In short:
  Google: search, AI Overviews and AI Mode (CRAWL-GOOGLE) came 3,020× in the last 7 days: every time let through; it read robots.txt.
  OpenAI's training crawler (CRAWL-GPTBOT) came 1,204× in the last 7 days: every time refused (as set: block).
  100 requests only pretended to be Google … (CRAWL-GOOGLE) — treated as ordinary visitors.
  Broken link: /news/2025/summer links to /old-shop, which was not found (61×).
```

`--days=30` for a longer look, `--json` for a CMS or a dashboard. The rules page
shows each known crawler's last 7 days next to its row.

## The statistics page

![](../explained/stats-page.png)

`Report\StatsPage::render($settings, ['action' => '/stats'])` prints the page:
tiles with a curve of the last 48 hours (requests, people, crawlers, bots,
checked, refused, not found), stacked bars per hour, day, week or month for
**who came** (people, crawlers, bots) and **what the shield did**, the answers
as a ring, the sentences, a bar per crawler (let through, checked, refused, only
claimed), the sitemaps, pages not found with their referrers, the rules and the
bot families. Charts are inline SVG and CSS — no script library, no external
file; a tooltip on every bar; dark mode; **English and German** (the browser's
language, or `'lang' => 'de'`); it refreshes itself every minute
(`'fragment' => true` returns only the content). Print it where only the site's
people see it — behind the CMS's login, or at a path restricted to some
addresses. The demo has it at `/stats`.

## Days, weeks, months, years

```text
$ php bin/request-shield stats site.rules --from=2026-01-01 --to=2026-09-30 --by=month
                       cacheable     uncached      checked       waited      refused
  2026-01                 41,203        2,110          188            4          610
  2026-02                 38,950        1,987          201            0          702
  …
$ php bin/request-shield stats site.rules --from=2026-01-01 --to=2026-12-31 --by=month --crawler=CRAWL-GPTBOT
                          visits  let through      checked      refused      claimed
  2026-01                    812            0            0          812           14
```

- `--by=day|week|month|year` (weeks as ISO weeks, `2026-W40`), `--from`/`--to`
  for any period, `--crawler=<ID>` for one crawler; with `--json` the same as
  `periods` for a CMS.
- Hours are kept `stats-hours` days (7), days `stats-days` days (400); then a
  day is added to its month's file (`m-<yyyymm>.json`), kept `stats-months`
  months (default 0: for good). A year is twelve small files — but a period
  that reaches back past `stats-days` is only there by whole months.

## Switching it on — and parts of it

```text
set stats on                          # off (default) | on | the parts:
set stats requests crawlers           #   requests, crawlers, not-found, bots
set stats-hours 7                     # days the hours are kept (default 7)
set stats-days 400                    # days the day totals are kept (default 400), then summed into months
set stats-months 0                    # months kept (default 0: for good)
set stats-flush 60s                   # with APCu: written to disk this often (default 60 s, 0: only hourly)
```

Without `crawlers`, a crawler's request is not even looked at (no verification
cost). A per-crawler log is a separate switch (below).

## Where the numbers live — and that they survive a restart

- **With APCu** (the store `auto` or `apcu`): a count is one `apcu_inc()`.
  Every `stats-flush` seconds one request writes what APCu holds into the
  hour's file and takes exactly that much out of APCu — counts added meanwhile
  stay. A restart of PHP-FPM (which empties APCu) loses at most that much.
- **Without APCu**: a request appends one short line to the hour's file
  (`O_APPEND`, lock-free).
- **Every hour**, the first request after it is over moves the finished hours
  into one small JSON file per day, `store-dir/stats/d-<yyyymmdd>.json` —
  readable by anything. Hours are kept for `stats-hours` days, then summed into
  the day's total; day files are removed after `stats-days` days.
- The hours' files are plain text (`name` or `name*count` per word), the day
  files JSON: nothing to install, easy to back up, easy to read for other tools.

## One log per crawler (optional)

```text
set crawler-log /var/log/request-shield/crawlers    # <dir>/CRAWL-GPTBOT/2026-09-30.log
set crawler-log-kinds ai-search ai-user ai-training # default: all kinds
set crawler-log-days 30                             # kept (default 30)
set crawler-log-query off                           # leave the query string out
```

Every request of a known crawler, in the format of the shield's log: what it
asked for, when, and what it got. A verified crawler's address is its operator's
and is written in full; a request that only claims the name may be a person
and is masked like the log's addresses (`claimed=CRAWL-…` in the line).

## For code

```php
$shield->record($request, $decision, $rule, $now);          // after decide()/settle(), for code that runs them itself (protect() does it)
$report = CjwNetwork\RequestShield\Report\StatsReport::build($settings, null, 7);   // the numbers, and 'sentences'
echo json_encode($report);
```

`StatsReport::build($settings, null, 7, null, ['from' => '20260101', 'to' => '20260930', 'by' => 'month', 'crawler' => 'CRAWL-GPTBOT'])`
for a period, grouped and filtered (`periods`). It returns the totals, each day and the last 48 hours as
five numbers (passed, uncached, checked, throttled, refused), the rules that
decided most, the status codes, the pages not found with their referrers, each
crawler (with its pages and last visit), the other bots, and the sentences.

## Cost

Measured with OPcache, a request passing the shield (µs):

| | host, PHP 8.1 | container, PHP 8.4 |
|---|---|---|
| statistics off | 7.7 | 15.8 |
| on, APCu | — | 22.7 (+7) |
| on, files | 34.8 (+27) | 48.4 (+33) |
| a known crawler's request, APCu | — | +21 (verified, its page, its last visit) |

- With APCu a count is about 0.2 µs; most of the rest is naming the hour and
  looking at the User-Agent (crawler, bot family: one expression each).
- Without APCu the cost is one appended line — on this machine's disk 15–25 µs;
  the file store for budgets pays the same. APCu is the store to use.
- The hourly roll-up and a flush run once per interval, in one request.
- A site's 404s cost one or two counters more, at the end of the request.

## Large sites and intranets

Measured on this workstation (a busy desktop, 8 threads; PHP 8.4 in a
container, nginx + PHP-FPM), the shield alone, no application behind it:

| | requests a second | 99 % of requests within |
|---|---|---|
| statistics off | 5,600–6,400 | 15 ms |
| on, APCu | 5,300–5,800 (−5 to −10 %) | 18 ms |
| on, files | 5,000–5,400 (−10 to −15 %) | 17 ms |

- **Exact under load:** 62,000 requests, 32 at a time, counted 62,000 times —
  with APCu (flushing to disk every 5 seconds during the test) and with files.
- **Per request:** a few microseconds with APCu. At 1,000 requests a second that
  is well under 1 % of one CPU core — beside an application that needs
  10–200 ms a page, nothing. The number of users does not matter (an intranet
  with 100,000 people): nothing is kept per visitor, only per hour and kind.
- **Memory:** a few thousand APCu entries an hour (about 1 MB): every list
  with a limit (pages, pages not found, referrers, sitemaps) stops at it, also
  under a flood of made-up addresses.
- **Housekeeping after the answer:** the flush (every minute) and the roll-up
  (every hour) look through all of APCu — 22 ms / 38 ms with 100,000 other
  entries, 48 ms / 107 ms with 500,000. They run after the visitor has the
  page (PHP-FPM, LiteSpeed), so nobody waits for them.
- **Files at high load:** about 14 bytes a request — at 1,000 requests a second
  some 50 MB an hour, whose roll-up takes about 4 seconds of CPU once an hour
  (after the answer). It works, but **above some 50 requests a second use APCu**
  (the default store `auto` does when APCu is there).
- **Several servers:** with APCu each server counts its own; they write into
  the day files of a shared `store-dir` under a file lock — on NFS, whose locks
  are unreliable, give each server its own `store-dir` (adding them up there is
  planned).
- **Several sites on one PHP-FPM pool:** each statistics directory has its own
  APCu names, so the sites do not count into each other.
- **Reading:** the page of a week reads 7 day files (~20 ms), of a year 365
  (~120 ms).

## Privacy

See also [privacy and the GDPR](../privacy.md) for every feature.

The counters hold actions, rule IDs, status codes, paths, crawler IDs and
bot families — **no visitors' addresses**. A crawler's last address is kept
only for verified crawlers (an operator's server). A referrer is kept only for
pages not found: from the site itself as a path, from elsewhere only as the
host (a full foreign address can carry personal data). The per-crawler logs keep
addresses as described above; they are removed after `crawler-log-days`.

## Limits

- Pages answered before PHP (a CDN, Varnish, a full-page cache) are not counted.
- With APCu and several servers, each server counts its own; the day files in
  a shared `store-dir` add them up.
- Without the dashboard (proposal [0012](../proposals/0012-dashboard.md), next
  step) the numbers are read with `bin/request-shield stats`, the JSON, or the
  rules page.
