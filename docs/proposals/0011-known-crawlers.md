# 0011 — Known crawlers: search engines and AI crawlers that behave

| | |
|---|---|
| Status | **Draft** |
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

### Cost

A request whose user agent names no known crawler: one expression over the
user agent (all crawlers' patterns in one), about half a microsecond. One that
names one: the address check against its ranges (a few comparisons), or the DNS
lookup, remembered for a day as today.

## On the rules page

A group "Crawlers": each known crawler, its kind, the site's policy for it,
and — from the log — how often it came in the last 24 hours, and how often
someone only claimed to be it.

## Open questions

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
