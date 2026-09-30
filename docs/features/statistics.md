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

## Switching it on — and parts of it

```text
set stats on                          # off (default) | on | the parts:
set stats requests crawlers           #   requests, crawlers, not-found, bots
set stats-hours 7                     # days the hours are kept (default 7)
set stats-days 400                    # days the day totals are kept (default 400)
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

`StatsReport::build()` returns the totals, each day and the last 48 hours as
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

## Privacy

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
