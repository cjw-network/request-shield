# 0018 — Audience statistics: where visitors come from, on which devices — from what the browser sends anyway

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-30 |
| Affects | the page counter of [0015](0015-page-statistics.md), the counters of [0012](0012-dashboard.md), the dashboard, the JSON API; optionally a beacon endpoint |

## Summary

[0015](0015-page-statistics.md) counts **which page, how often**. This
proposal adds, for the same page views and at the same moment, the questions a
site owner asks next: **where did visitors come from** (a search engine, a
social network, an AI assistant, another site, a page of the site itself),
**on which kind of device and browser** (phone or desktop, Chrome or Safari),
and — only if the site wants it and asks for consent — **how wide the screen
was**. Everything except the screen size comes from headers the browser sends
with every request anyway: no script, no cookie, no identifier, nothing that
an ad blocker could block. Each answer is one more counter per page view.

It can **partly** replace a tracking tool like Matomo or etracker — for the
questions *which pages, from where, on which devices, broken links, crawlers*
— and it sees more page views than a script-based tool, because nothing is
blocked. It cannot answer questions that need a visit or a person to be
followed (sessions, bounce rate, funnels, goals); it does not fingerprint and
does not try to. Which questions the dashboard answers with it, for SEO and
GEO — and what time per visit would cost — is [0019](0019-seo-geo-dashboard.md).

## In one picture

![A page view reaches the shield; from its headers alone three coarse counters: where it came from (search, social, AI assistant, another site's host, a page of the site, direct), the device class (mobile, tablet, desktop) and the browser family; an optional beacon adds a screen-width bucket, only with consent; all kept as counters per day, no cookie, no address, no identifier](0018-audience-statistics.svg)

## Motivation

- A site owner wants to know *"do people find us through Google, or through
  ChatGPT?"* and *"is the site read more on phones?"* — and installs a tracker
  for it, with a cookie banner, a script in every page and data leaving the
  site.
- Script-based trackers miss the visitors who block them (browser defaults,
  ad blockers, privacy extensions) — often a large share. The shield sees
  every page view that reaches PHP.
- The shield already tells people from crawlers and bots (0011, 0014): the
  numbers are about people, not inflated by bots that run scripts.
- **GEO** (being read and quoted by AI answers): visitors who clicked a link in
  an AI assistant arrive with that assistant's host as referrer
  (`chatgpt.com`, `perplexity.ai`, `claude.ai`, `copilot.microsoft.com`,
  `gemini.google.com`, …). Counting them shows whether being quoted brings
  visitors — next to the crawler statistics of 0014 showing whether the
  assistants read the site at all.

## Design

Counted with the page view of 0015 — at the end of the request, only for a
**200 HTML answer to a GET, from a person** (no known crawler, no bot family).
Each part is one counter in the same store and the same day files (0012), with
the limits the counters already have: at most 50 values per group and day,
the rest counted as "(other)".

### Where visitors come from (the `Referer` header)

| Group | Counted as | From |
|---|---|---|
| Search engines | `search:google`, `search:bing`, `search:duckduckgo`, `search:ecosia`, `search:qwant`, `search:yahoo`, `search:yandex`, `search:baidu`, … | a short, shipped list of hosts per engine (all country domains of Google, …) |
| AI assistants | `ai:chatgpt`, `ai:perplexity`, `ai:claude`, `ai:copilot`, `ai:gemini`, … | the same kind of list; maintained with the crawler list (0011) |
| Social networks | `social:facebook`, `social:instagram`, `social:linkedin`, `social:x`, `social:reddit`, `social:youtube`, `social:mastodon`, … | the same kind of list (including their link shorteners: `t.co`, `lnkd.in`) |
| Other sites | `site:<host>` — the host only | anything not in the lists |
| The site itself | `page:<from path>` → counted for the page viewed: *which page leads here* | the site's own host(s) |
| Direct | `direct` | no `Referer` (typed, bookmarked, or the referring site sends none) |

- **Only the host for other sites** — never the whole foreign URL: it can carry
  personal data (a search term, an e-mail address, a session token), and every
  distinct URL would be one more counter.
- **Internal navigation** is the path of the site's own page (without its
  query), per page viewed, top 50 per page and day — enough to see *"most
  visitors reach the contact page from the prices page"*.
- **Campaigns:** with `include @tracking` (0009) and a page view that carries
  them, `utm_source`, `utm_medium` and `utm_campaign` are counted by value
  (`utm:newsletter`, capped at 50 values a day, values cut to 40 characters
  and reduced to letters, digits and `-_.`). No other parameter is ever
  counted.
- Many referrers are shortened by the browser itself (`strict-origin-when-cross-origin`
  is the default): from other sites the host is usually all that arrives
  anyway.

### Device class and browser family (User-Agent, Client Hints)

- **Device class:** `mobile`, `tablet`, `desktop`. From the User-Agent (a few
  expressions: `Mobi`, `Android` without `Mobile` for tablets, `iPad`, …) and,
  where Chromium sends it, `Sec-CH-UA-Mobile: ?1` (which settles it).
- **Browser family:** `chrome`, `safari`, `firefox`, `edge`, `samsung`, `opera`,
  `other`. From `Sec-CH-UA` where sent, else the User-Agent. Deliberately
  **coarse**: no versions (at most the major one, and not in the first step),
  no operating-system versions, no device models — cheaper, sturdier against
  User-Agent changes, and less identifying.
- **Operating system family** (optional, same coarseness): `android`, `ios`,
  `windows`, `macos`, `linux`, `other`.
- **Bots are not in these numbers:** the shield already recognises known
  crawlers (0011) and bot families (`Stats::botFamily()`); a page view from
  them never reaches these counters.
- The parser is a handful of `strpos()` checks and two short expressions,
  measured with the rest; a new browser is a line in it.

### Screen and window size — what is possible, and what it costs in consent

- **Without JavaScript: nothing reliable.** The browser does not send the
  screen or window size. Client Hints (`Sec-CH-Viewport-Width`, `Sec-CH-Width`)
  are sent only after the site asks for them (`Accept-CH`), only by
  Chromium-based browsers, and only from the second request on — a partial,
  skewed picture. They are not used.
- **With a tiny script — optional, off by default:** a first-party beacon of
  a few lines (`navigator.sendBeacon()` to the shield's own endpoint, like the
  check inside the form, 0010) sends **only a width bucket** —
  `<576`, `576–991`, `992–1439`, `≥1440` px — once per page view. No cookie, no
  ID, no address kept; the endpoint adds one to a counter and answers
  `204 No Content`.
- **Consent:** reading the window size runs code in the visitor's browser and
  reads information from the device. That falls under **§ 25 TDDDG** (formerly
  TTDSG) in Germany and **Art. 5(3) of the ePrivacy Directive**, which in
  general require consent unless the access is *strictly necessary* for the
  service the visitor asked for — and statistics usually are not. So: the
  beacon only when the site's consent manager (the CMS's cookie banner) has
  switched it on for this visitor, or not at all. *This is not legal advice;
  to be confirmed by the site's data protection officer.*

### What the header-based counts are, legally — and what we say about them

The `Referer`, the `User-Agent` and Client Hints are sent by the browser with
the request itself; the shield **processes** them, it does not read anything
from the device. Counted immediately into day totals — no address, no
identifier, no profile, nothing stored per visitor — this is the
privacy-friendly end of statistics, and in many readings needs no consent.
The docs will say exactly that and no more: *to be confirmed by the site's
data protection officer*. Whether a site then still needs a banner depends on
everything else it runs, not on the shield.

### Unique visitors without cookies — later, maybe, opt-in

A count of distinct visitors per day, without cookies, is possible the way
some privacy-friendly analytics tools do it: a hash of (address, User-Agent,
day, a secret salt that changes every day) is kept **only in memory** (APCu)
for the day, never written; distinct hashes are counted; at midnight the salt
and the hashes are gone.

- **For:** "visitors" next to "page views" is the number people ask for; no
  cookie, nothing on the device.
- **Against:** while it exists, the hash of an address is still personal data
  (it can be recomputed with the address and the day's salt); a hash per
  visitor in APCu costs memory on busy sites; several servers each count their
  own visitors (without shared memory the numbers add up wrong); people behind
  one address (an office, a mobile network) are counted once; and it makes the
  privacy statement longer.
- **Recommendation:** not in the first step. Page views, sources and devices
  answer the common questions; distinct visitors later as an opt-in, if sites
  ask for it.

**No fingerprinting**, now or later: no combining of headers, fonts, canvas or
screen data into an identifier. That is what makes the rest defensible.

### On the dashboard and through the API

- The "Pages" tab of 0015 gets three small tables next to the page list:
  **Sources** (grouped, with AI assistants as their own group), **Devices**
  (mobile / tablet / desktop as shares), **Browsers**. For one page: where its
  visitors came from and which page of the site led to it.
- Sentences at the top, like 0014's: *"42 % of page views came from phones.
  Google brought 1,208 visits, ChatGPT 61, Perplexity 12 — up from 3 last
  week."*
- JSON: `Panel::json($settings, 'audience', ['days' => 30, 'path' => …])` and
  `<panel-path>/api/audience`, with the read-only token of 0012.

### How it compares with a tracking tool

| | The shield (0015 + 0018) | Matomo, etracker (script-based) |
|---|---|---|
| Page views | **every one that reaches PHP**, also with scripts blocked | only where the script runs and is not blocked |
| People vs. crawlers and bots | **separated reliably** (verified crawlers, bot families) | bots that run scripts are counted as people |
| Sources (search, social, AI assistants, sites) | yes, by host | yes, with more detail (search terms mostly unavailable in both) |
| Campaigns (`utm_*`) | source, medium, campaign | yes, plus more |
| Devices, browsers, operating systems | coarse families | detailed, with versions and models |
| Screen size | only with the optional beacon and consent | yes (with consent) |
| Cookie banner needed | not for the header-based counts, in many readings (to be confirmed) | usually yes, or a special cookieless configuration |
| Visits, sessions, bounce rate, time on page | **no** (no cookie, no fingerprint) | yes |
| Funnels, events, goals, e-commerce, heatmaps | **no** | yes |
| Pages answered by a CDN or full-page cache before PHP | not seen, unless the cache calls the hook (0015) | seen (the script runs in the browser) |
| Data leaves the site | no | no (self-hosted Matomo) / yes (hosted services) |

So **"partly replace"**: for *which pages are read, where readers come from,
on which devices, which links are broken (0012's status counters), which
crawlers read the site (0014)* the shield is enough — and more complete. For
*how people move through the site, convert, or behave on a page* it is not,
and does not try to be.

## Cost

| | Per page view (with APCu; without: the same keys on the one line 0012 already appends) |
|---|---|
| `set page-stats off` (default) | nothing |
| the page counter of 0015 | ~1 µs |
| source (`Referer`: one `parse_url()`, one host lookup in a list) | +1 counter, ~1 µs |
| internal navigation | +1 counter (only when the referrer is the site itself) |
| campaign (`utm_*`, only when present) | +1 to 3 counters |
| device class + browser family (+ OS) | +2–3 counters, ~1 µs for the parser |
| the beacon (optional) | one tiny request per page view, answered by the shield (~10 µs) — plus the visitor's round trip |
| visitors (not in this step) | one hash and one APCu entry per visitor and day |

Nothing of this runs for assets, API calls, refusals, redirects or bots.

## Privacy

- **Stored:** counters per day — a source (host or group), a path of the site,
  a device class, a browser family, a campaign value. **Not stored:** addresses,
  full foreign URLs, User-Agent strings, queries (except the three `utm_`
  values), any identifier.
- **Not read from the device:** everything except the optional beacon comes
  from headers the browser sends with the request.
- **The beacon** is the only part that runs code in the browser and reads from
  the device: off by default, and only with consent.
- Retention as the counters of 0012 (400 days of day totals by default).
- All legal statements in this proposal and in the docs: *to be confirmed by
  the site's data protection officer* — the shield makes the privacy-friendly
  choices; it does not give legal advice.

## Open questions

1. Sources, devices and browsers **on by default** with `set page-stats on`?
   *Recommendation: yes — they come from headers already sent, cost a few
   counters, and are what page statistics are asked for; each can be switched
   off (`set page-stats-sources off`, …).*
2. Internal navigation: top 50 source pages per page and day (proposed)?
   *Recommendation: yes; a site with thousands of pages still keeps a small
   file.*
3. The screen-size beacon at all? *Recommendation: build it last, off by
   default, only switchable per visitor by the site's consent manager; a site
   that does not want a consent question leaves it off.*
4. The operating-system family too? *Recommendation: yes, coarse (six
   values); no versions.*
5. Distinct visitors per day (salted, in memory)? *Recommendation: not in the
   first step; later as an opt-in.*
6. The host lists (search engines, AI assistants, social networks): shipped
   and updated with the crawler list (0011, `bin/update-crawler-lists` before
   each release)? *Recommendation: yes — one list to maintain, one release step.*
