# 0020 — One cached page for every campaign link: cache keys without tracking parameters

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-30 |
| Affects | known parameters ([0009](0009-typed-query-parameters.md), `@tracking`), what a cache may keep (`cache-path`, `cache-query`), the request the site sees (`$_GET`, `QUERY_STRING`, `REQUEST_URI`), page and campaign statistics ([0015](0015-page-statistics.md), [0018](0018-audience-statistics.md)), the Exponential adapter |

## Summary

A newsletter link is `/news/article?utm_source=newsletter&utm_campaign=october`,
an ad `/?gclid=Cj0KCQ…`. Every value is new, so every such click is a page of
its own for a cache: **a miss, and a full render** — ~150 ms here instead of a
0.4 ms hit, exactly when a mailing goes out and thousands click at once. Today
the shield even says so on purpose: a tracking parameter is known (`@tracking`),
but "answered, not cached", so that made-up values cannot fill a cache.

This proposal lets the shield **take the tracking parameters out before the
site sees the request**: `$_GET`, `QUERY_STRING` and `REQUEST_URI` no longer
carry them, so the site, its links and **every cache behind the shield** see
one address — `/news/article` — and the thousandth click is a hit. The browser
keeps its address, so script-based analytics still read the campaign. What was
taken out stays available to the site from the shield (never from the client),
and the shield can count campaigns itself before it drops them.

It makes sense, with one strict limit: **only parameters that never change the
page** (the shipped `@tracking` list by default), and only for caches that run
**after** the shield in PHP. Caches in front of PHP (a CDN, Varnish, LiteSpeed,
the Qbix parent) never see the shield and need the same rule in their own
language — the planned rule export.

## Where the shield sits, and which caches it can help

```
browser → [CDN / Varnish / LSCache / Qbix cache] → PHP: request-shield → [PHP page cache] → application
           ↑ before the shield: not helped                               ↑ after it: helped
```

| Cache | Runs | Helped by this |
|---|---|---|
| Exponential HTTP cache (early exit, `config.php`) | in PHP, after `auto_prepend_file` | **yes** |
| Symfony HttpCache, WordPress `advanced-cache.php`, other PHP page caches | in PHP, after the shield | **yes** |
| the application's own caches (view cache, cache-blocks) keyed by the URL | in PHP | **yes** |
| Varnish, a CDN, nginx `proxy_cache`, LiteSpeed LSCache, the Qbix response cache | before PHP | no — needs the same rule exported (`req.url` rewrite, "ignore query string" lists) |

## Three ways to do it

### A. Take them out (recommended)

```
cache-ignore @tracking                        # the shipped list: utm_*, gclid, fbclid, …
cache-ignore ref mc_cid at **/news/**         # own names, only at some paths
```

Before the site runs, for a request whose path a cache may keep:

- the listed parameters are removed from `$_GET`, `$_REQUEST`, `$_SERVER['QUERY_STRING']`
  and `$_SERVER['REQUEST_URI']` (the rest keeps its order and its encoding);
- what was removed goes to `$_SERVER['REQUEST_SHIELD_IGNORED']` (the original query
  string) and `Shield::ignored()` (name => value), for a site that wants it;
- the shield's own cacheable check then looks at the clean address, so the
  answer is no longer "not cached" because of a tracking tag.

Every PHP cache and the application key on the clean address without knowing
about the shield. Nothing needs to cooperate.

### B. Only say which key to use

The request stays as it is; the shield adds the key a cache should use:
`$_SERVER['REQUEST_SHIELD_CACHE_KEY'] = '/news/article'` (path and the kept
parameters, in their order). A cache that knows it (an adapter: Exponential,
Symfony) uses it; any other cache does not benefit.

**It cannot be set by a client** because it is not a header: a client can only
produce `$_SERVER['HTTP_…']` entries, and the shield's key has no `HTTP_`
prefix. As a second line of defence the shield removes every incoming
`Request-Shield-*` header (`HTTP_REQUEST_SHIELD_*`) on every request, as it
already removes `X-Forwarded-*` from untrusted clients — so a cache that reads a
header by mistake cannot be steered either. (A real HTTP header only makes sense
for a cache in another process behind the shield, which this setup does not
have; a response header does not help a cache look up the request.)

B is always set when A is on, so an adapter can use one way for both.

### C. Redirect to the clean address

`301` to `/news/article`. Works for every cache, also a CDN in front — but costs
every visitor a second request, and **analytics lose the campaign** (the browser
never shows the tagged address to the script). Not recommended; left out.

## Advantages and disadvantages

| | A: take them out | B: key only | C: redirect |
|---|---|---|---|
| Hits for campaign links in PHP caches | **yes, every cache** | only caches that read the key | yes, every cache |
| Caches in front of PHP (CDN, Varnish) | no | no | yes (after the redirect) |
| Needs the cache to cooperate | no | yes (an adapter) | no |
| Script-based analytics (Matomo, GA: read in the browser) | keep working | keep working | **lose the campaign** |
| Server-side code that reads `$_GET['utm_…']` | must ask `Shield::ignored()` | keeps working | loses it |
| Tracking tags in the page (links, canonical, `og:url`) | **gone** — built from the clean address | stay | gone |
| Extra requests | none | none | one per click |
| Cache busting with made-up tag values (`?utm_x=<random>`) | **closed**: one entry | closed for caches that read the key | closed |
| Cost | a few µs, only with a query string | ~1 µs | a round trip |

**What speaks for it:**

- The load of a mailing or a campaign goes to the cache, not the application:
  on this machine 25–50 rendered pages a second against 2,000–7,000 hits.
- A made-up tracking value can no longer force a render — a flood of
  `?utm_source=<random>` becomes one cache entry (today: "not cached", every one
  rendered, only the pace limit stops it).
- Links and canonical addresses in the page no longer carry the visitor's tags
  (they are not passed on, not indexed by search engines).
- The shield can **count campaigns before it removes them** (0018's campaign
  counter): which newsletter brought how many people, without a tracking script.

**What speaks against it, and how to limit it:**

- **A parameter that does change the page must never be listed** — else every
  visitor gets the first one's page. The default is only the shipped `@tracking`
  list (values of type `any`, meaning nothing to a site); `check` warns when a
  listed name is also declared with a type of its own, named in `cache-query`,
  or used in a `match` block.
- Server-side code that reads a tag itself (a newsletter plugin that records
  `mc_cid`) must read it from `Shield::ignored()`; the demo and the docs show
  it, and `cache-ignore … at <paths>` can leave such paths alone.
- The request the site sees is no longer byte for byte what the client sent.
  Logs keep the original (the shield's log, and the web server's); the rule
  tester shows both.
- It does nothing for caches in front of PHP — for those the rule export writes
  the equivalent (Varnish `regsuball(req.url, …)`, LSCache "drop query
  string", nginx `$args`).

## Where it sits in the checks

After *known parameters* (the tags are declared and typed there, `strict` still
refuses unknown ones) and before *what a cache may keep* — which then sees the
clean address. Only on paths a cache may keep (`cache-path`): elsewhere nothing
is cached, so nothing needs to be taken out.

## Cost

Only for a request with a query string on a cacheable path: the pairs are
already split for the known parameters, taking some out and building the new
strings is a few microseconds. A request without a query string: one `=== ''`.

## Open questions

1. **The name:** `cache-ignore` (says what it is for) or `strip-query` (says what
   it does)? *Recommendation: `cache-ignore`.*
2. **Only on cacheable paths, or everywhere?** Everywhere also cleans links on
   pages nothing caches. *Recommendation: cacheable paths only — the smallest
   change to what a site sees.*
3. **A and B, or only B** (the request untouched, adapters use the key)?
   *Recommendation: A with B always set — it helps every cache at once.*
4. **Count the campaigns** (`utm_source`, `utm_medium`, `utm_campaign`, first
   value each, 50 an hour) when statistics are on? *Recommendation: yes, as
   part `campaigns`, off by default.*
