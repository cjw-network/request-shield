# 0011 — Known crawlers: search engines and AI crawlers that behave

| | |
|---|---|
| Status | **Implemented** 2026-09-30 (see [known crawlers](../features/RSF01-04-known-crawlers.md)) |
| Proposed | 2026-09-30 |
| Affects | the browser check, budgets, rule files, the rules page |

## Summary

Crawlers that behave — search engines and AI crawlers fetching at a normal
pace — should get the site's pages even where the site checks browsers. Today
only the major search engines are recognised (verified by their DNS names) and
never checked; AI crawlers are not, so a site that switches the browser check
on shuts them out, since they run no JavaScript. This proposal adds a list of
**known crawlers, verified by their operators' published addresses or DNS
names**, and a **policy per site**: let them through (only the limit applies),
check them like everyone else, or refuse them.

## In one picture

![A crawler says who it is; the shield checks its address -- not theirs: an ordinary visitor; verified: the site's policy decides -- through (only the limit), like everyone, or no access](0011-known-crawlers.svg)

## Motivation

- Many sites want to be found in AI search and answers, not only in classic
  search engines. A crawler that fetches a few pages a second is no threat; a
  browser check it cannot pass shuts it out for good.
- A crawler's user agent proves nothing: anyone can send `GPTBot`. Letting
  crawlers through by name alone would open the door to every scraper that
  borrows one. So: only where the address proves it.
- Site owners differ: some want AI crawlers, some do not. The choice belongs
  to the site, as it does in `robots.txt`.

## Today

| | Normal pace | Past `challenge-at` / a checked page | Past the limit |
|---|---|---|---|
| Verified search engines | through | through (never checked) | 429 with `Retry-After` |
| AI crawlers, other bots | through | **the check — they cannot pass** | 429 with `Retry-After` (or the check, with `on-exceeded challenge`) |

The default settings check nobody (600 requests a minute, no `challenge-at`),
so a site that changes nothing already lets well-behaved AI crawlers through.
The gap is where a site checks.

## The crawlers that matter now (checked 2026-09-30)

**SEO** — being found in search engines' results. **GEO** (generative engine
optimisation) — being read, quoted and linked in AI answers: ChatGPT search,
Perplexity, Claude, Google's AI Overviews and Gemini, Microsoft Copilot. Both
depend on the crawlers below reaching the site.

Checked on each operator's own page on 2026-09-30. Crawlers come and go and
lists move: the shipped list is re-checked for every release, and each entry
names its source.

### Search engines (SEO — and for GEO too)

| Crawler | Operator | Also feeds | How to verify |
|---|---|---|---|
| `Googlebot` (and `GoogleOther`, `Google-InspectionTool`) | Google | AI Overviews, AI Mode | DNS: `googlebot.com`, `google.com`, `googleusercontent.com`; address lists: `common-crawlers.json`, `special-crawlers.json`, `user-triggered-fetchers*.json` |
| `bingbot` | Microsoft | Copilot, and the searches built on Bing's index | DNS: `search.msn.com`; list: `bing.com/toolbox/bingbot.json` (Microsoft advises DNS) |
| `Applebot` | Apple | Siri, Spotlight, Safari suggestions | DNS: `applebot.apple.com`; list: `search.developer.apple.com/applebot.json` |
| `DuckDuckBot` | DuckDuckGo | | list: `duckduckgo.com/duckduckbot.json` |
| `YandexBot`, `Baiduspider`, `SeznamBot`, `Qwantbot` | Yandex, Baidu, Seznam, Qwant | | DNS (as today) — for sites with visitors in those markets |

### AI crawlers (GEO)

| Crawler | Operator | Kind | How to verify |
|---|---|---|---|
| `OAI-SearchBot` | OpenAI | AI search: ChatGPT's search results | list: `openai.com/searchbot.json` |
| `ChatGPT-User` | OpenAI | fetches a page when a user asks | list: `openai.com/chatgpt-user.json` |
| `GPTBot` | OpenAI | training | list: `openai.com/gptbot.json` |
| `Claude-SearchBot` | Anthropic | AI search | list: `claude.com/crawling/bots.json` |
| `Claude-User` | Anthropic | fetches a page when a user asks | the same list |
| `ClaudeBot` | Anthropic | training | the same list |
| `PerplexityBot` | Perplexity | AI search (says: not for training) | list: `perplexity.com/perplexitybot.json` |
| `Perplexity-User` | Perplexity | fetches a page when a user asks — **ignores robots.txt**, says Perplexity | list: `perplexity.com/perplexity-user.json` |
| `DuckAssistBot` | DuckDuckGo | AI answers | list: `duckduckgo.com/duckassistbot.json` |
| `Amazonbot` | Amazon | Alexa and Amazon's AI | DNS: `crawl.amazonbot.amazon` |
| `CCBot` | Common Crawl | an open archive many AI models are trained on | DNS on its own address ranges (IPv4) |
| `meta-externalfetcher`, `meta-externalagent` | Meta | user actions, training | Meta names allow-listing by address; the source of its addresses is to be checked before listing |
| `MistralAI-User` | Mistral | fetches a page when a user asks | no published way to verify found — not listed until there is one |

**Not crawlers, but switches in `robots.txt`:** `Google-Extended` (whether Google
may use pages for Gemini's training and grounding — AI Overviews are fed by
`Googlebot` itself) and `Applebot-Extended` (the same for Apple's models;
it does not crawl). The shield has nothing to verify there; they belong in the
site's `robots.txt`.

**Crawlers without a way to verify them** — for example ones known only by the
name they send — are not on the list: they stay ordinary visitors. A site that
does not want them refuses them by name (`block header User-Agent …`) or in
`robots.txt`.

## Design

### The list

A shipped rule file, `rules/crawlers.rules`, versioned like the others
([0005](0005-versioned-rule-sets.md)), one entry per crawler:

```text
ids CRAWL required
version 2026.10.1

[CRAWL-GOOGLE@1]   crawler search  ua /Googlebot|GoogleOther/  dns .googlebot.com .google.com      # Google
[CRAWL-BING@1]     crawler search  ua /bingbot/                 dns .search.msn.com                # Bing
[CRAWL-OAISEARCH@1] crawler ai-search   ua /OAI-SearchBot/  ranges https://openai.com/searchbot.json     # ChatGPT search
[CRAWL-GPTBOT@1]    crawler ai-training ua /GPTBot/         ranges https://openai.com/gptbot.json        # OpenAI's training crawler
…
```

- The kind: `search` (a search engine), `ai-search` (AI search and answers),
  `ai-user` (fetches a page when a user asks — often not bound by
  `robots.txt`, since a person asked), `ai-training` (collects for training).
  The kinds matter because sites decide differently: many want to be found and
  quoted (`search`, `ai-search`, `ai-user`) and weigh training separately.
- Verified by **DNS** (reverse and forward, as the search engines are today)
  or by **address ranges** the operator publishes.
- Which operators publish what has to be checked for every entry before it is
  shipped; an entry without a way to verify it is not listed.

Address ranges change. They are **not fetched per request**: `php
bin/request-shield crawlers update` loads the published lists into the store
directory (run by cron or on deploy); the shipped file names where each list
comes from. Without an update, the ranges shipped with the release are used.

### The policy

```text
crawlers search      allow     # the default, as today
crawlers ai-search   allow     # proposed default: see below
crawlers ai-user     allow
crawlers ai-training allow     # a site that does not want training: check, block -- or robots.txt
crawler  CRAWL-GPTBOT block    # one crawler differently
```

| Policy | A verified crawler of that kind |
|---|---|
| `allow` | is never checked (like search engines today); the limit still applies, answered with 429 and `Retry-After`, never with the check |
| `check` | is treated like any visitor |
| `block` | gets 403 — for a site that does not want it (`robots.txt` is the polite way; this is for those that ignore it) |

A request that **claims** to be a known crawler but does not come from its
addresses is an ordinary visitor — and the log says so (*"claims to be GPTBot;
the address does not match"*).

### Without internet: a DMZ

The server the shield runs on need not reach the internet at all:

- **Address lists are checked locally** — a comparison, no network. They come
  with each release; `php bin/request-shield crawlers update` runs where there
  is internet (a build server, CI, an admin's machine) and writes a file that
  is deployed with the site like any other. The server in the DMZ never calls
  out.
- **`set crawler-verify ranges`** — verify by address lists only, never by DNS.
  Recognised then: Google, Microsoft (Bing), Apple, DuckDuckGo, OpenAI,
  Anthropic, Perplexity — everyone on the list who publishes addresses.
- **Crawlers verifiable only by DNS** (Amazonbot, CCBot, Yandex, Baidu, …) are
  not recognised without DNS: they stay ordinary visitors — safe, and only
  relevant where the site checks.
- Where DNS is used, at most `dns-lookups` new lookups a minute (already
  built, default 30): DNS that does not answer never makes requests wait.
- `crawlers update` shows how old the lists are; `bin/request-shield check`
  warns when they are older than, say, 30 days, so a site in a DMZ notices
  that its lists need a new deploy.

```text
set crawler-verify ranges          # ranges | dns | both (default: both)
```

### Cost

A request whose user agent names no known crawler: one expression over the
user agent (all crawlers' patterns in one), about half a microsecond. One that
names one: the address check against its ranges (a few comparisons), or the DNS
lookup, remembered for a day as today.

## On the rules page

A group "Crawlers": each known crawler, its kind, the site's policy for it,
and — from the log — how often it came in the last 24 hours, and how often
someone only claimed to be it.

## Decisions (2026-09-30) — and why

| Question | Decision | Why |
|---|---|---|
| Defaults per kind | **`allow` for all four** (`search`, `ai-search`, `ai-user`, `ai-training`) | Found and quoted everywhere; crawlers at a normal pace get the pages. Whether a site feeds training is decided in `robots.txt`, as most do — the shield decides nothing else by accident. |
| Which crawlers on the list? | **Only those an operator lets verify** (published addresses or DNS) | Only real ones get through; a borrowed name gets nothing. Others stay ordinary visitors. |
| Address lists | **Shipped with each release, plus `crawlers update`** | Works offline (a DMZ), stays current where the update runs. |
| A budget of its own for a verified crawler? | **Not in the first step** | Counting per address is enough so far; a shared budget per operator later, if logs show the need. |

## As built — where it differs

- **The lists:** Google's `common-crawlers.json` is gone (404); Googlebot is
  verified by `googlebot.json` and `special-crawlers.json` (AdsBot,
  Storebot), plus DNS. DuckDuckBot and DuckAssistBot publish the same list;
  Anthropic one list for its three crawlers; Common Crawl a list
  (`index.commoncrawl.org/ccbot.json`) and DNS (`.crawl.commoncrawl.org`).
- **Syntax:** `crawler <kind> ua /<pattern>/ [dns …] [ranges <list names or ./files.json>]`;
  policies `crawlers <kind> <policy>` and `crawler <ID> <policy>`.
- **Verifying by list is a lookup of about half a microsecond** (the ranges
  grouped by their first two bytes, built when the settings are compiled), so
  only DNS answers are remembered; a normal request pays nothing — the name is
  looked at only when the check would come, or when some crawler is refused.
- **Updating:** `bin/request-shield crawlers <site.rules> update` for a site
  (into store-dir), `bin/update-crawler-lists` for a release, a weekly
  workflow that fails when a list changed; a list that shrank to less than
  half is refused.
- **The old `search-engines` setting stays:** `off` recognises no crawler;
  the PHP map of patterns to DNS suffixes still works (as search engines).
- A request that only claims a crawler's name is logged with `claimed=<ID>`
  when it is checked or stopped; the rules page counts these per crawler.

## Open questions (as proposed)

1. The defaults per kind: `allow` for all four (the pages are public; a crawler
   at a normal pace costs little; being found and quoted in AI answers is what
   many sites want; training is decided in `robots.txt` by most) — or `check`
   for `ai-training` by default?
2. Which crawlers go on the first list — only those whose operators publish a
   way to verify them?
3. `crawlers update`: shipped ranges plus an update command (proposed), or
   ranges only from the update, or only DNS where an operator offers it?
4. Should a verified crawler get a budget of its own (its operator crawls from
   many addresses, each counted on its own today)?

## Sources (checked 2026-09-30)

- Google: [verifying Google's crawlers](https://developers.google.com/search/docs/crawling-indexing/verifying-googlebot), [AI features and your website](https://developers.google.com/search/docs/appearance/ai-features)
- Microsoft: [how to verify Bingbot](https://www.bing.com/webmasters/help/how-to-verify-bingbot-3905dc26)
- Apple: [about Applebot](https://support.apple.com/en-us/119829)
- DuckDuckGo: [DuckDuckBot](https://duckduckgo.com/duckduckgo-help-pages/results/duckduckbot), [DuckAssistBot](https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot)
- OpenAI: [OpenAI's crawlers](https://developers.openai.com/api/docs/bots)
- Anthropic: [does Anthropic crawl the web](https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler)
- Perplexity: [Perplexity crawlers](https://docs.perplexity.ai/guides/bots)
- Amazon: [about Amazonbot](https://developer.amazon.com/amazonbot)
- Common Crawl: [CCBot](https://commoncrawl.org/ccbot)
- Meta: [Meta web crawlers](https://developers.facebook.com/documentation/sharing/webmasters/web-crawlers)
- Mistral: [Mistral crawlers](https://docs.mistral.ai/robots)
