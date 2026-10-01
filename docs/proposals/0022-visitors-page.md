# 0022 — The visitors page: one calm page, from the data the shield has

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-01 |
| Affects | the statistics page (`Report\StatsPage`, the view "Visitors & pages"), page statistics ([0015](0015-page-statistics.md)), audience statistics ([0018](0018-audience-statistics.md)), the SEO and GEO dashboard ([0019](0019-seo-geo-dashboard.md)), campaign counting ([0020](0020-cache-keys-without-tracking.md)), privacy ([docs/privacy.md](../privacy.md)) |

## Summary

An editor opening the statistics wants a few answers at a glance: how many
pages were read, is it more or less than before, where did people come from,
what did they read, on which devices — and, for this shield, who read the site
for search engines and AI assistants. The best answer is **one calm page**: a
row of numbers, one chart, four cards, every number compared with the period
before.

This proposal gives the **"Visitors & pages"** view that shape, **built only
from what the shield counts or can count cheaply** — no script in the page, no
cookie, no visitor identity. Most of the page works today; two cards need the
small counters of proposals 0018 and 0020. Numbers that would need telling
visitors apart (unique visitors, visits, bounce rate, visit duration, entry and
exit pages) are not shown, and the page says so instead of guessing.

## In one picture

![The visitors page: the period and "compare with before"; six numbers with their change — page views by people, requests by people, crawler visits, bot requests, stopped by the shield, pages not found — and "now": people's requests in the last 5 minutes; a line chart of page views per day with the period before dashed; four cards — top sources (channels, sources, campaigns), pages (pages, sections, stopped, not found), crawlers and AI (search, AI search, training), devices (device class, browser, operating system); and goals. Green dots: counted today; blue: a small new counter.](0022-visitors-page.svg)

A green dot marks what the shield counts today, a blue one what a small new
counter adds.

## Question by question — what the shield has

| What an editor asks | The shield | How |
|---|---|---|
| How many pages were read? | **page views by people** ✓ | `pg:people|…` (part `pages`): GET, 200, HTML, not a crawler or bot — it also sees visitors who block scripts, and no bot that runs scripts inflates it |
| More or less than before? | **every number with its change**, the chart with the period before ✓ | the period before is read like the period itself |
| What happens right now? | **requests by people in the last 5 minutes** (new, tiny) | one APCu counter per minute; it says "requests", not "visitors" |
| Over time? | **page views by people per hour / day / week / month** ✓ | the counters per hour and day |
| Which period? | ✓ today, 7/30 days, 12 months; **+ this month, last month, a range** | the report already takes `from`/`to`; the page gets the pills and a date field |
| Where did people come from? | **channels and sources** (new, small: 0018 step 1) | the referrer's class at each page view: search, AI assistant, social, other sites, own site, direct — and the host for the top 50 |
| Which campaign? | **campaigns** (new, small: 0020, part `campaigns`) | `utm_source`/`utm_medium`/`utm_campaign`, counted before 0020 removes them |
| What did they read? | **pages** ✓, **sections** ✓ (subtree, `stats-depth`) | `pg:`, `pd:` |
| What went wrong? | **stopped** ✓ and **not found** ✓, as tabs beside the pages | `pb:`, `n:` with their referrers |
| Who reads the site for search and AI? | **crawlers & AI** ✓ — search, AI search, AI training, each with its last visit and the sitemap it read | 0011, 0014 |
| On which devices? | **device class, browser, OS** (new, small: 0018) | from the User-Agent at the page view |
| Did the page do its job? | **goals** (new, small) | `goal <name> at <path>`: page views of a page that counts as a goal (a "thank you" page), "per 100 page views"; `Stats::goal()` from code |
| Only this section / this crawler? | **filter by page or section** ✓, **by crawler** ✓, **by source → its landing pages** (new) | see "Filters" |

## The page, from top to bottom

1. **The period and "compare with before".** Today (by hour), 7 days, 30 days,
   this month, last month, 12 months (by month), a range. Compare is on by
   default: every number shows its change in per cent, the chart the period
   before as a dashed grey line.
2. **Six numbers** (tiles, each with its change):
   page views by people · requests by people · crawler visits · bot requests ·
   stopped by the shield · pages not found. Beside the site's name:
   **now** — people's requests in the last 5 minutes (refreshed every 30 s).
3. **The chart**: page views by people, one line; hover shows the day and
   both periods' numbers. Clicking a tile switches the chart to that number.
4. **Four cards**, each with tabs and a "more" link to the full list:
   - **Top sources** — channels | sources | campaigns *(0018, 0020)*
   - **Pages** — pages | sections | stopped | not found *(there today)*
   - **Crawlers & AI** — search | AI search | AI training *(there today)*
   - **Devices** — device class | browser | operating system *(0018)*
5. **Goals** — the configured goals, each with its count and "per 100 page
   views".

The other views stay: **Protection** (what the shield did), **Rules & setup**,
the **overview**.

## What the page does not show — and why

**Unique visitors, visits, views per visit, bounce rate, visit duration, entry
and exit pages** all need to tell one visitor from another. Without a cookie
that means a hash of the address and the browser string, salted anew every day.
The shield **does not do this by default**: it would mean processing every
visitor's address for statistics, which [docs/privacy.md](../privacy.md) keeps
out of the default and 0018/0019 discuss as an **opt-in** (`set stats visitors
on`, a daily salt in memory only, never written). If a site switches it on, the
page gains those numbers and the tabs *entry* and *exit pages* — the layout
leaves room for them. Without it, the page shows **what it counts, under the
name of what it counts** ("page views", "requests"), never an estimate called
"visitors".

**Countries** need a GeoIP database (DB-IP Lite, CC BY; GeoLite2 needs an
account). Country only, looked up at the page view and never stored with the
address, ~10–40 µs a page view. Possible as an option (`set stats geoip
<file.mmdb>`); not in the first version — the card place goes to crawlers & AI.

**Screen size, time on page, scroll depth** need a script in the page; out of
scope (0018's beacon, with consent, if ever).

## Filters

The shield keeps **counters**, one dimension each, not every page view with all
its details. So it filters only along what it counts together:

- **a page or a section** → the chart and the numbers for it (page views by
  people, crawlers, bots; stopped there) ✓ — the existing subtree filter
- **one crawler** → its pages and visits ✓
- **a source** → its **landing pages** (new: `src:<class>|<path>` for the top
  50 pages per class) — the one cross-count worth its cost
- anything else (a device *and* a page) — not offered; a card that cannot
  follow a filter says so ("not by page")

This keeps memory bounded (every list stops at its limit) and the cost per
page view at a few counters.

## Cost

Per page view by a person, with APCu: today one `pg:` and up to `stats-depth`
`pd:` counters. New: the referrer class and host (2 counters), the landing page
per source (1), device, browser, OS (3), a goal when the path is one (a
precompiled expression, ~1 µs), the minute counter for "now" (1). About
**+2–4 µs** a page view, in line with the measurements in
[statistics.md](../features/statistics.md#cost). Reading: the page reads the
period and the period before — twice the day files (a week: ~40 ms).

## Phases

1. **The layout with today's data**: the tiles with their change, the chart
   with the period before, the pages card with its four tabs, the crawlers & AI
   card, "now", the extra periods and a range. *No new counters except the
   minute one.*
2. **Sources and devices** (0018 step 1) and **campaigns** (0020): the two blue
   cards fill; landing pages per source.
3. **Goals**: `goal <name> at <path>` and `Stats::goal()`.
4. *Optional, later*: GeoIP countries; the opt-in visitor count (with visits,
   bounce rate, duration, entry and exit pages).

## Open questions

1. **Replace "Visitors & pages", or a fifth view?** *Recommendation: replace —
   the same audience (editors), one page instead of two.*
2. **Compare on by default?** *Recommendation: yes.*
3. **Goals in the rule file, or only from code?** *Recommendation: both — `goal
   … at …` for pages, `Stats::goal()` for events.*
4. **GeoIP countries in phase 4, or never?** *Recommendation: phase 4, off by
   default, country only.*
5. **The opt-in visitor count:** build it at all? *Recommendation: decide after
   phases 1–3 — most questions are answered without it.*
