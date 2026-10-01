# 0022 — The visitors page: one calm page, from the data the shield has

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-01 |
| Affects | the statistics page (`Report\StatsPage`, the view "Visitors & pages"), page statistics ([0015](0015-page-statistics.md)), audience statistics ([0018](0018-audience-statistics.md)), the SEO and GEO dashboard ([0019](0019-seo-geo-dashboard.md)), campaign counting ([0020](0020-cache-keys-without-tracking.md)), privacy ([docs/privacy.md](../privacy.md)) |

## Summary

An editor opening the statistics wants a few answers at a glance: how many
people came, how many pages they read, is it more or less than before, where
did they come from — which country, which part of the company network —, what
did they read, on which devices, did the contact form get sent — and, for this
shield, who read the site for search engines and AI assistants. The best answer
is **one calm page**: a row of numbers, one chart, six cards, every number
compared with the period before.

This proposal gives the **"Visitors & pages"** view that shape, **built from
what the shield sees anyway** — no script in the page, no cookie:

- what it counts today (page views, pages, sections, crawlers, what it
  stopped, what was not found) — **on**;
- small new counters (sources, campaigns, devices, goals, "now") — **on with
  statistics**;
- **unique visitors and visits** (visitors, visits, pages per visit, bounce
  rate, visit duration, entry pages) — **an option**, `set stats visitors on`,
  counted the way server-log statistics have always counted them: by address
  and browser, per day — but with a hash that changes every day and is never
  written, and a sketch instead of a list;
- **countries** — an option, with a GeoIP file;
- **networks** — the areas of an intranet, by name or by their first two or
  three octets, only for private addresses or ranges the site names.

## In one picture

![The visitors page: the period and "compare with before", "now"; six numbers with their change — unique visitors, visits, page views, pages per visit, bounce rate, visit duration — and the shield's own numbers below them; a chart of visitors or page views per day with the period before dashed; six cards — top sources, pages (with entry pages), where from (countries, networks), devices, crawlers and AI, goals. Green: counted today; blue: a small new counter or an option; violet: only with "set stats visitors on".](0022-visitors-page.svg)

## Question by question — what the shield has

| What an editor asks | The shield | How |
|---|---|---|
| How many pages were read? | **page views by people** ✓ | `pg:people|…` (part `pages`): GET, 200, HTML, not a crawler or bot — it also sees visitors who block scripts, and no bot that runs scripts inflates it |
| How many people? How many visits? | **unique visitors, visits, pages per visit, bounce rate, visit duration** (option) | see "Visitors and visits" |
| More or less than before? | **every number with its change**, the chart with the period before ✓ | the period before is read like the period itself |
| What happens right now? | **requests by people in the last 5 minutes** (new, tiny) | one APCu counter per minute; with the option: visitors with a page view in the last 5 minutes |
| Which period? | ✓ today, 7/30 days, 12 months; **+ this month, last month, a range** | the report already takes `from`/`to` |
| Where did people come from? | **channels and sources** (new, small: 0018 step 1) | the referrer's class at each page view: search, AI assistant, social, other sites, own site, direct — and the host for the top 50 |
| Which campaign? | **campaigns** (new, small: 0020, part `campaigns`) | `utm_source`/`utm_medium`/`utm_campaign`, counted before 0020 removes them |
| Which country? | **countries** (option, with a GeoIP file) | see "Countries" |
| Which site, plant, department network? | **networks** (new) | see "Networks" |
| What did they read, where did they start? | **pages** ✓, **sections** ✓, **entry pages** (with the visitor option) | `pg:`, `pd:`; the first page of a visit |
| What went wrong? | **stopped** ✓ and **not found** ✓, as tabs beside the pages | `pb:`, `n:` with their referrers |
| Who reads the site for search and AI? | **crawlers & AI** ✓ | 0011, 0014 |
| On which devices? | **device class, browser, OS** (new, small: 0018) | from the User-Agent at the page view |
| Did the page do its job? | **goals** (new, small) | `goal <name> at <path>` (a "thank you" page), `Stats::goal()` from code; per cent of visitors with the option, else per 100 page views |

## The page, from top to bottom

1. **The period and "compare with before"** (on by default) and **now**.
2. **Six numbers** with their change: unique visitors · visits · page views ·
   pages per visit · bounce rate · visit duration. Without the visitor option
   the row shows page views, requests by people, crawler visits, bot requests,
   stopped, not found. Below the row, in one line, the shield's own numbers.
3. **The chart**: visitors or page views (tabs), one line, the period before
   dashed; clicking a number switches the chart to it.
4. **Six cards**, each with tabs and a "more" link to the full list:
   **top sources** (channels | sources | campaigns) · **pages** (pages | entry |
   sections | stopped | not found) · **where from** (countries | networks) ·
   **devices** (device | browser | operating system) · **crawlers & AI**
   (search | AI search | AI training) · **goals**.

The other views stay: **Protection**, **Rules & setup**, the **overview**.

## Visitors and visits (`set stats visitors on`)

Statistics from web server logs have always counted unique visitors by the
address (and a visit as the same address coming back after a pause). The shield
can do the same live, without keeping addresses:

- **One visitor** is a hash of the address and the browser string
  (`HMAC-SHA256`), keyed with a salt that is made at midnight, **lives only in
  memory** (APCu) and is replaced the next day. The browser string keeps a whole
  office behind one address from being one visitor; the daily salt makes
  yesterday's hashes impossible to link to today's.
- **Unique visitors** go into a **HyperLogLog sketch** per day (4 KB, about 1 %
  error): no list of visitors at all, only registers. Sketches of days merge, so
  a week, a month, a year have their real unique visitors, not a sum of days —
  without keeping a salt longer than a day.
- **Visits**: an APCu entry per hash that disappears after 30 minutes without a
  page view (configurable: `stats-visit 30m`). No entry: a new visit begins, this
  page is its **entry page**. A second page view: the visit is not a bounce.
  The time since the last page view adds to the **visit duration**. From these
  counters: visits, pages per visit, bounce rate, average duration.
- **Not counted**: exit pages (an APCu entry does not say when it expires) and
  anything a script would have to measure (time on the last page, scroll
  depth).
- **Accuracy**, as with any count without a cookie: many people behind one
  proxy count as fewer (in a large intranet: let the CMS give the shield a
  stable id, hashed the same way — `Stats::visitor($userId)`); changing IPv6
  privacy addresses and mobile networks count as more; one person on two devices
  is two visitors.
- **An objection is honoured:** a browser that sends `Sec-GPC: 1` (Global
  Privacy Control) or `DNT: 1` is counted in the page views, but not as a
  visitor and not in visits (`stats-visitors-respect off` to count it anyway).

### Consent, legal basis — and the comparison with AWStats

*The common reading, not legal advice — the site's data protection officer
decides.*

- **No consent under the cookie rule** (TDDDG §25, Art. 5(3) ePrivacy
  directive) in the usual reading: that rule is about storing information on the
  user's device or reading it from there. The option sets no cookie, runs no
  script, reads nothing from the device — it uses the address and the browser
  string every browser sends with every request, as server-log statistics do.
  *The caveat:* the EDPB's guidelines 2/2023 read Art. 5(3) very broadly and
  include IP-based tracking in some cases. Contested, not common practice — but
  the reason nobody can promise "never consent".
- **A legal basis and a line in the privacy notice under the GDPR:** hashing the
  address is processing personal data, even if nothing is stored. The usual
  basis is the site's legitimate interest in measuring its reach, Art. 6(1)(f),
  with immediate anonymisation; visitors may object (Art. 21) — the GPC/DNT rule
  above. [docs/privacy.md](../privacy.md#what-to-put-in-your-privacy-notice)
  has a ready-made text, in English and German.
- **Compared with AWStats**, which counts unique visitors from the web server's
  log files and is commonly run without consent, the option processes less and
  keeps nothing:

  | | AWStats (from log files) | `set stats visitors on` |
  |---|---|---|
  | Source | the web server's log, processed later (cron) | the request itself, live |
  | IP address | in the log in full (as long as the logs are kept); hosts also in AWStats' monthly data files | **never stored** — hashed in memory, the salt changes daily and is never written |
  | Linking a person over time | possible as long as logs or data files exist | **within one day only** |
  | Unique visitors | distinct addresses per month (one office behind one address is one visitor) | address + browser per day, a sketch merges any period |
  | A visit | the same address again after an hour without requests | the same visitor again after 30 minutes (configurable) |
  | Bots | by User-Agent lists | verified crawlers by address, bot families, the shield's decisions |
  | Pages a cache in front of PHP answered | **counted** (they are in the log) | **not seen** — the shield runs in PHP |
  | Consent (common practice) | none | none |

  If a site runs log statistics without consent today, the option is at least
  as defensible — in practice more. The one point where logs count more: pages a
  cache in front of PHP (a CDN, Varnish, the web server's own cache) answers
  never reach the shield.
- **Default:** **off** in the library — it runs on sites in different countries
  with different privacy notices, each switches it on knowingly; **on** in the
  demo, with the notice text on its page. In an intranet consent is not the
  question; employee data protection and the works council are (see
  "Networks").

## Countries (`set stats geoip <file>`)

- A GeoIP country file: **DB-IP Lite** (CC BY 4.0 — the page shows "IP
  geolocation by DB-IP", as its licence asks) or GeoLite2 (needs an account).
  `bin/update-geoip` fetches the monthly file, like `bin/update-crawler-lists`.
- Looked up at a page view by a person, **country only**, never stored with the
  address. The result is cached per /24 (IPv6 /48) for a day in APCu, so most
  page views pay ~1 µs; a lookup in the file ~10–40 µs (faster with the
  `maxminddb` extension, which the shield uses when it is there).
- Private addresses count as **"Intranet"**, unknown ones as **"unknown"**.

## Networks: the areas of an intranet

A company network is usually built by site: `10.12.x.x` is the Hamburg plant,
`10.40.x.x` the head office, a VPN pool, the guest Wi-Fi. The shield can count
page views (and, with the option, visitors) **per network area**:

```
network "Hamburg plant"  10.12.0.0/16
network "Head office"    10.40.0.0/16 10.41.0.0/16
network "VPN"            10.250.0.0/16 fd00:250::/32
set stats-networks 16                 # any other private address: by its first two octets (8, 16 or 24; IPv6 32–64)
```

- **Named areas first** — the list shows "Hamburg plant", not `10.12.0.0/16`.
- **Other private addresses** (10/8, 172.16/12, 192.168/16, IPv6 fc00::/7) by
  their first two or three octets: `10.77.x.x`.
- **Public addresses never by their octets**, only by a named range (a
  partner's network) — for the internet a /24 is often a few households; the
  country is enough.
- The top 100 areas an hour, like every list.

**Two cautions for an intranet:**

- **Small areas identify people.** A /24 can be one department or one office.
  So the finest grouping is /24 (never single addresses), and an area with fewer
  than **5 visitors** in the period (with the option; else 20 page views) is
  shown as "other areas" (`stats-min-group 5`).
- **Employees' use of the intranet is a matter for the works council** in many
  countries (in Germany co-determination under §87(1) no. 6 BetrVG, as soon as
  data *could* be used to monitor performance or behaviour). The docs say so;
  per-area numbers are off until a site names `network`s or sets
  `stats-networks`.

## Filters

The shield keeps **counters**, one dimension each, not every page view with all
its details. So it filters only along what it counts together:

- **a page or a section** → the chart and the numbers for it ✓ (the subtree
  filter)
- **one crawler** → its pages and visits ✓
- **a source** → its **landing pages** (new: `src:<class>|<path>`, top 50 per
  class)
- **a network area** → its pages (new: `nwp:<area>|<path>`, top 50 per area)
- anything else (a device *and* a country) — not offered; a card that cannot
  follow a filter says so

## Cost

Per page view by a person, with APCu: today one `pg:` and up to `stats-depth`
`pd:` counters. New: referrer class and host (2), landing page (1), device,
browser, OS (3), a goal when the path is one (~1 µs), the minute counter (1):
**+2–4 µs**. With countries: ~1 µs (cached per /24), ~10–40 µs on a cache miss.
With networks: a range check against the named areas (one precompiled list,
~1 µs) and 1–2 counters. With visitors: one HMAC, one or two APCu calls, a
sketch update: **+3–5 µs**, and ~100 bytes per visitor active in the last 30
minutes plus 4 KB a day. Reading: the period and the period before (a week:
~40 ms).

## Phases

1. **The layout with today's data**: tiles with their change, the chart with
   the period before, the pages card with its tabs, the crawlers & AI card,
   "now", the extra periods and a range.
2. **Sources, devices, campaigns** (0018 step 1, 0020) and **goals**.
3. **Networks** (named areas, private ranges by octets, the minimum group).
4. **Visitors and visits** (the option): the sketch, the visit entries, entry
   pages, `Stats::visitor()`; docs/privacy.md.
5. **Countries** (the option): `bin/update-geoip`, the lookup, the cache.

## Open questions

1. **Replace "Visitors & pages", or a fifth view?** *Recommendation: replace.*
2. ~~The visitor option: off or on by default?~~ *Decided: off in the library,
   on in the demo; the privacy notice text in docs/privacy.md.*
3. **Visit timeout:** 30 minutes, or the hour server-log statistics often use?
   *Recommendation: 30 minutes, configurable.*
4. **Networks: grouping by /16 or /24 by default, and the minimum group of 5?**
   *Recommendation: /16 (two octets) and 5; /24 only when a site sets it.*
5. **Countries: DB-IP Lite as the default source?** *Recommendation: yes —
   free, monthly, CC BY; GeoLite2 for sites that have an account.*
6. **Goals in the rule file, or only from code?** *Recommendation: both.*
