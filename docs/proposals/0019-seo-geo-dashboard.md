# 0019 — The SEO and GEO dashboard: twelve questions, and what visit duration would cost

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-30 |
| Affects | the dashboard ([0012](0012-dashboard.md)), crawler statistics ([0014](0014-crawler-statistics.md)), page and audience statistics ([0015](0015-page-statistics.md), [0018](0018-audience-statistics.md)), the Exponential adapter (a URL index), a few new counters |

## Summary

The shield sees every request — people, search engines, AI crawlers, bots —
with its status, its referrer and, at the end, how long the site took. That is
most of what a site owner needs for **SEO** (being found in search) and **GEO**
(being read and quoted by AI assistants), without a tracking script. This
proposal fixes the dashboard to **twelve questions**, each one tile or one small
table, ranked by usefulness and cost, each with its JSON endpoint for a CMS
widget. It also weighs **time per visit**, which was asked for: possible only
by linking page views of one visitor, and therefore not by default.

This is a proposal of its own (not part of 0018) because it is about *what the
dashboard shows*, across 0012, 0014, 0015 and 0018; 0018 stays about *what is
counted*.

## In one picture

![Twelve questions in four groups: are search and AI crawlers reading the site (visits per page, never-visited pages, freshness after publishing, crawlers refused by mistake); do people arrive from them (AI referrals, search referrals, landing pages, campaigns); is anything broken (404s and who links to them, redirects, slow pages); who reads it (mobile share); each a tile or small table, with day, week, month and year, filters by crawler, page and kind, and a JSON endpoint](0019-seo-geo-dashboard.svg)

## The twelve questions (ranked by usefulness, then cost)

| # | Question | Shows | Data | New per request |
|---|---|---|---|---|
| 1 | **Which pages do search and AI crawlers read — and how often?** | per crawler (or kind): top pages, visits per day | 0014 (`p:<crawler>:<path>`) | — |
| 2 | **Which pages have they never read?** | the site's pages (URL index or sitemap) minus crawled pages, per crawler kind | 0014 + the CMS adapter's URL index (or `sitemap.xml`) | — (compared when the dashboard opens) |
| 3 | **Are crawlers refused or checked by mistake?** | verified crawlers that got 403, 429 or the check, per page and rule — a GEO risk | 0014 (`c:<crawler>:refused/checked/throttled`) + the rule; per page: new | +1 counter, only for such a crawler request |
| 4 | **Do AI assistants send visitors — to which pages?** | referrals from chatgpt.com, perplexity.ai, claude.ai, copilot, gemini … per page: GEO success | 0018 sources, per page: `src:<group>:<path>` | +1 counter per page view with such a referrer |
| 5 | **What is broken?** | pages not found (404/410) with the pages and sites linking to them; hit by crawlers or people | built (`n:`, `nr:`, `s:`) + a crawler flag | +1 counter when a crawler hits a 404 |
| 6 | **How fresh is the crawl after publishing?** | per new or changed page: time to the first Googlebot, Bingbot, OAI-SearchBot … visit | 0014 first-visit time per page + the CMS's publish time | one `apcu_add()` per crawler page visit (first one of a page only is stored) |
| 7 | **Where do search visitors land?** | search referrals per page (Google, Bing, …), top landing pages | 0018 sources per page (as #4) | (as #4) |
| 8 | **Which pages are slow?** | server time per page: share under 200 ms / 200 ms–1 s / over 1 s, slowest pages — for people and for crawlers | new: time at the end of the request (below) | +1 counter per page view (~0.3 µs) |
| 9 | **Did robots.txt change, and who read it?** | fetches per crawler, the file's last change (checksum on each fetch) | 0014 (`c:<crawler>:robots`) + a checksum | one `md5` of a small file per robots.txt fetch |
| 10 | **Redirects crawlers follow** | 301/302 answers to crawlers, per page, and chains (a redirect to a redirect) | built (`s:3xx`) + per page for crawlers: new | +1 counter per redirect to a crawler |
| 11 | **How much is read on phones?** | mobile / tablet / desktop share, per page for the top pages | 0018 device class | (0018) |
| 12 | **Which campaigns bring readers — to which pages?** | `utm_source` / `utm_campaign` per landing page | 0018 campaigns, per page | +1 counter per page view with a campaign |

Each is one tile (a number and a trend) or one small table (ten rows, "more"
opens the list) — nothing else. Questions 1, 3, 5, 9 are useful from day one
with what 0014 already counts; 2 and 6 need the CMS adapter; 4, 7, 11, 12 need
0018; 8 and 10 need two small new counters.

### Server time per page (#8)

The shield runs first and registers the shutdown function that counts the page
view (0015); at that moment it knows how long the request took:
`microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']`. Counted as a bucket per
page — `t:<path>:<200ms|1s|slow>` — and overall, not as exact values: three
counters instead of a list of times, enough for "the 10 slowest pages" and "how
often is a crawler kept waiting over a second". About 0.3 µs with APCu. It is
the server's time (what search engines' crawl budgets and time-to-first-byte
see), not the browser's rendering time.

### Filters and time spans

- **Spans:** today, 7 days, 30 days, 12 months — from the hours (kept 7 days),
  days (400 days) and month files (kept for good) the counters already keep.
  Month files make a year view cheap: twelve small files.
- **Filters:** by crawler or kind (search, ai-search, ai-user, ai-training), by
  page (a path or a prefix: `/news/*`), people / crawlers / bots.
- **JSON:** one endpoint per question — `<panel-path>/api/crawled`, `never-crawled`,
  `refused-crawlers`, `ai-referrals`, `broken`, `freshness`, `landing`, `slow`,
  `robots`, `redirects`, `devices`, `campaigns` — each with `days`, `crawler`,
  `kind`, `path`; and `Panel::json($settings, '<question>', [...])` embedded. A CMS
  can put question 4 next to each page in its editor: *"quoted by AI assistants:
  12 visitors this month"*.

### Deliberately left out

Heatmaps, scroll depth, funnels, events, goals, e-commerce, per-visitor paths,
search terms (search engines do not send them; Search Console does) — they need
a script, a person to be followed, or data the shield does not have. For search
terms the dashboard links to Google Search Console and Bing Webmaster Tools.

## Time per visit: what it would take

A *visit* means linking several page views of the same visitor. The shield
counts page views; to measure duration it would need to know which views belong
together. Three ways:

| | A cookie (a visit ID) | A daily-salted hash of address + User-Agent | A script beacon (visibility and leave time) |
|---|---|---|---|
| How | a random ID in a first-party cookie, 30 min idle | hash kept in memory (APCu) for the day, never written; views within 30 min idle form a visit | a few lines of script send "visible for N seconds" when the page is hidden or left (`visibilitychange`, `sendBeacon`) |
| Pages per visit | accurate | good; wrong when many people share an address (offices, mobile networks) or one person changes networks | not by itself (needs one of the other two) |
| Duration | first to last view — **the last page has no end**, a one-page visit counts 0 s | the same, first to last view | **time on each page, including the last** — the only real duration |
| Tabs, background tabs | one visit across tabs | the same | counts only visible time — the most honest |
| Blocked by | cookie settings, private windows | nothing (headers) | script blockers |
| Bots | kept out (the shield knows them) | kept out | kept out |
| Cost per page view | a cookie read/write, one counter | one hash and one APCu entry per visitor and day (memory on busy sites) | one extra request per page view (~10 µs server side) |
| Several servers | fine | each server counts its own visits (wrong without shared memory) | fine |
| GDPR / TDDDG (*to be confirmed by the site's data protection officer*) | storing on the device: § 25 TDDDG / Art. 5(3) ePrivacy — **consent**, a banner | nothing stored on the device; the hash is personal data while it exists (a day), processed on legitimate interest in many readings | reading from the device: **consent** |
| Verdict | the most accurate visits, but a banner — the thing this is meant to avoid | the cookieless compromise: pages per visit and a **rough** duration | the only real time on page — with consent |

**Recommendation:** not by default, and not in the first step. If sites ask for
it: first the **opt-in daily-salted hash** (`set page-stats-visits on`), giving
*visits, pages per visit* and a *rough* duration between the first and the last
view — labelled as such on the dashboard ("the last page of a visit is not
timed"). A **consent-only beacon** (the same endpoint as 0018's screen-width
beacon, switched on per visitor by the site's consent manager) adds real time on
page for those who agreed. Never a cookie of the shield's own, never
fingerprinting.

For SEO and GEO the visit duration matters less than it seems: search engines
and AI assistants do not see it; server time (#8), what crawlers read (#1–#3)
and what they send back (#4, #7) are the numbers that move rankings and
citations.

## Recognising a visitor without a cookie

Could the shield tell visitors apart — or recognise one coming back — without
a cookie? Four ways, weighed honestly. *All legal statements here: to be
confirmed by the site's data protection officer; not legal advice.*

### Address + browser string

- **What it really counts:** combinations of an address and a User-Agent — not
  people. Many people share one address: offices, schools, hotels, and above
  all mobile networks, where carrier-grade NAT puts hundreds or thousands of
  customers behind one IPv4 address. The other way round, one person has
  several: a phone on mobile data and on Wi-Fi, a laptop at home and at work;
  IPv6 privacy addresses change daily or more often (the shield counts IPv6 per
  /64, which helps for one connection but not across networks). And a browser
  string is shared by everyone with the same browser version on the same
  system — the most common strings by a large share of all visitors, and
  browsers are reducing the User-Agent further (frozen, shortened strings).
- **Accuracy:** no dependable figure applies to every site; it depends on how
  many visitors come from mobile networks and companies. Qualitatively: a
  daily count of distinct combinations undercounts people behind shared
  addresses and overcounts people who switch networks — fine as a trend, wrong
  as an exact number, and useless for recognising *one* person over days.
- **Least intrusive form:** the daily-salted hash of 0018 — kept only in memory
  for one day, never written, the salt gone at midnight. The hash of an address
  is still personal data while it exists; with aggregation, a one-day life and
  no other use, many readings accept legitimate interest (Art. 6(1)(f) GDPR).

### The shield's own pass cookie

The pass cookie (`rsp`, set after the browser check, bound to the client's
address group and browser) *could* recognise a returning browser. It exists for
**security**: to spare a checked browser the check again. Using it for
statistics would change its purpose (purpose limitation, Art. 5(1)(b) GDPR)
and would very likely turn a cookie that needs no consent — because it is
strictly necessary for the security of the service the visitor asked for —
into one that does. **Recommendation: never use it for statistics.** The
dashboard counts passes and checks, never pass holders.

### "A small check" that reads the device — fingerprinting

A script that reads device properties — canvas or WebGL rendering, installed
fonts, screen, audio processing, time zone — and combines them into an
identifier is **browser fingerprinting**. It reads information from the
visitor's device, which § 25 TDDDG and Art. 5(3) of the ePrivacy Directive
allow only with consent, unless strictly necessary for the requested service;
tracking is not. The EDPB's Guidelines 2/2023 on the technical scope of
Art. 5(3) read that article broadly — reaching also some information a browser
sends by itself when it is collected to track; how far is contested and still
moving. Besides the law: fingerprinting is what privacy-minded browsers
actively fight, and it would betray the trust the shield's check depends on.
The shield's proof of work reads no properties of the device — it computes a
hash and sends back a number (the check page keeps only its loop guard in the
tab's `sessionStorage`). **Recommendation: never fingerprint.**

### Summary

| Method | Accuracy for "visitors" | Cost | Likely legal basis | Consent (§ 25 TDDDG)? | Recommendation |
|---|---|---|---|---|---|
| Page views only (0015) | exact — for views | ~1 µs | legitimate interest (aggregates) | no, in many readings | **the default** |
| Daily-salted hash of address + User-Agent, in memory | rough trend; under- and overcounts | a hash + an APCu entry per visitor and day | legitimate interest, if aggregated, one day, no other use | no, in many readings — contested | opt-in, later |
| A statistics cookie of its own | good (per browser) | a cookie per visitor | consent | **yes** | not in the shield |
| The pass cookie for statistics | good for checked browsers only | none | purpose would change | would need it | **never** |
| Fingerprinting ("a small check" reading the device) | high, until browsers counter it | a script, a request | consent | **yes** | **never** |

*To be confirmed by the site's data protection officer; not legal advice.* The
shield's privacy notes in general: [Privacy and the GDPR](../privacy.md).

## Cost

| | Per request |
|---|---|
| questions 1, 3, 5, 9 (from 0014 and the built counters) | nothing new, except the per-page counter for refused crawlers and 404s hit by crawlers (only for those requests) |
| 2 and 6 (URL index, publish times) | nothing per request; compared when the dashboard or the API is asked; one `apcu_add()` per crawler page visit for the first-visit time |
| 4, 7, 11, 12 | the counters of 0018 |
| 8 server time | +1 counter per page view (~0.3 µs) |
| 10 redirects to crawlers | +1 counter per such redirect |
| visits (opt-in, later) | one hash + one APCu entry per visitor and day |
| opening the dashboard | reads day and month files; question 2 reads the URL index once |

## Privacy

Everything here is counted per page, per day, per crawler or group — no
addresses, no identifiers. The only parts that would link a person's page views
(visits) or read from the device (beacon) are opt-in and described above; all
legal statements are *to be confirmed by the site's data protection officer*.

## Open questions

1. The twelve questions as the dashboard's fixed set (proposed), or
   configurable? *Recommendation: fixed, in this order; a site hides tiles it
   does not need.*
2. Question 2 (never crawled) without a CMS adapter: read `sitemap.xml`?
   *Recommendation: yes — the sitemap is what the site tells search engines
   anyway.*
3. Server time in three buckets (proposed) or five (100 ms, 200 ms, 500 ms, 1 s,
   more)? *Recommendation: five for the overall chart, three per page.*
4. Visits: opt-in daily-salted hash, consent-only beacon, both or neither?
   *Recommendation: neither in the first step; then the hash as opt-in; the
   beacon only with consent.*
5. First crawler visit after publishing: the CMS reports publishing (adapter
   hook), or the shield notices a new path? *Recommendation: the adapter hook —
   a changed page keeps its path.*
