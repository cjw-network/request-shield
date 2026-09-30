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

## Design

### The list

A shipped rule file, `rules/crawlers.rules`, versioned like the others
([0005](0005-versioned-rule-sets.md)), one entry per crawler:

```text
ids CRAWL required
version 2026.10.1

[CRAWL-GOOGLE@1]   crawler search  ua /Googlebot|GoogleOther/  dns .googlebot.com .google.com      # Google
[CRAWL-BING@1]     crawler search  ua /bingbot/                 dns .search.msn.com                # Bing
[CRAWL-GPTBOT@1]   crawler ai      ua /GPTBot/                  ranges @gptbot                     # OpenAI's crawler
…
```

- `search` or `ai` (a crawler for AI training, search or answers); more kinds if
  needed.
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
crawlers search allow          # the default, as today
crawlers ai     allow          # proposed default: see below
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

1. The default for AI crawlers: `allow` (the pages are public; a crawler at a
   normal pace costs little; being found in AI answers is wanted by many) or
   `check` (as today where a site checks)? Proposed: `allow`.
2. Which crawlers go on the first list — only those whose operators publish a
   way to verify them?
3. `crawlers update`: shipped ranges plus an update command (proposed), or
   ranges only from the update, or only DNS where an operator offers it?
4. Should a verified crawler get a budget of its own (its operator crawls from
   many addresses, each counted on its own today)?
