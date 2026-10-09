# 0048 — Query parameters and the PHP caches: fewer keys, more hits

| | |
|---|---|
| Status | **Draft** -- part 3 of G.6 built (the three kinds in the shield, its HTTP cache) |
| Proposed | 2026-10-09 |
| Affects | the cacheable definition (`cache-query`, `Request::cacheKey()`), a new `cache-ignore` and `set cache-unknown-query`, `@tracking`, the request the application sees (`$_GET`, `QUERY_STRING`, `REQUEST_URI`), `$_SERVER['REQUEST_SHIELD…']`, the HTTP cache plugin, the statistics, `check`; upstream: Exponential 6's `exphttpcache` |
| Relates to | [RSF04-01 the cacheable definition](../features/RSF04-01-cacheable-definition.md) · [RSF04-03 the HTTP cache](../features/RSF04-03-http-cache.md) · [0039 a page cache that speaks the known dialects](0039-cache-compatible.md) · [0016 rule advisor](0016-rule-advisor.md) (`learn`, `advise`) · [0031 step G.6](0031-steps.md) |

## The question

*"Wenn die WAF sagt, die Seite nur ohne Query-Parameter cachen, soll der
Exponential-Cache das auch verstehen. Ich möchte die Anzahl der
HTTP-Cache-Keys minimal halten -- zusätzliche Tracking-Parameter
`?lajljdlsd`, die durchgelassen werden, einfach nicht als Cache-Key nutzen.
Das geht dann aber nur mit den PHP-HTTP-Caches."* (owner, 2026-10-09)

## Today

Without `cache-query` every parameter is cacheable (`cacheable.query` is
`null`: any) -- `?utm_source=…` is then part of the key, and each value is a
page of its own. Once `cache-query page sort` names the parameters a
cacheable address may carry, any other parameter makes the request `allow-uncached`
(`$_SERVER['REQUEST_SHIELD']`): the application renders it, no cache keeps
it. That is safe -- made-up parameters never fill a cache -- but every link
from a newsletter or an ad (`?utm_source=…`, `?fbclid=…`) is a miss, although
the very same page is in the cache.

## Proposed: three kinds of parameters

| Kind | Rule | In the key | The application sees it | A hit | Kept |
|---|---|---|---|---|---|
| **key** | `cache-query page sort` (as today) | yes | yes | yes | yes |
| **ignored** (tracking) | `cache-ignore utm_* gclid fbclid …` -- `cache-ignore @tracking` adds the well-known tracking names | **no** | **no** -- taken out before the cache and the application run | yes: the page without it | yes: as the page without it |
| **unknown** | `set cache-unknown-query hit-only` (new; the default stays `uncached`) | **no** | yes | yes, when the page without it is kept already | **no** |

```
GET /news?utm_source=nl&x=7
  the shield:  utm_source → ignored (taken out)      x → unknown
               $_SERVER['REQUEST_URI']                  = /news?x=7
               $_SERVER['REQUEST_SHIELD']               = allow-uncached   (because of x: never kept)
               $_SERVER['REQUEST_SHIELD_CACHE_LOOKUP']  = /news            (but this page may answer it)
  a PHP cache: a hit for /news → answered
               a miss → the application renders /news?x=7, nothing is kept
```

- **Ignored** is what Varnish configurations do with `utm_*`: the shield takes
  the parameters out of `$_GET`, `$_REQUEST`, `QUERY_STRING` and
  `REQUEST_URI` before any PHP cache and the application run. A page rendered
  for such a link therefore never carries `utm_source` in its links or forms,
  and it can be kept for everyone. Analytics in the browser still reads them
  from the address bar; tracking on the server no longer sees them -- unless
  open question 2 is answered with the copy (documented; that is the choice
  the site makes with `cache-ignore`).
- **Unknown** (`?lajljdlsd`): the page without the parameter answers when it
  is kept; on a miss the application renders with the parameter and nothing
  is kept. No page that may depend on a parameter ever lands in the cache;
  the number of keys stays as small as `cache-query` makes it, and random
  parameters cannot fill the cache. **The caveat:** an unknown parameter that
  does change the page (`?lang=en`, `?preview=1`) gets the plain page on a
  hit -- a wrong answer for that visitor, never a poisoned cache (nothing is
  kept). That is why it is opt-in, and why `check` names the parameters the
  learning runs (0016) saw the application use that `cache-query` does not.
- **Names as PHP sees them:** a parameter is matched by the name the
  application gets -- decoded, and with `.` and spaces as `_` (PHP's
  `utm.source` is `$_GET['utm_source']`), so no spelling slips past
  `cache-ignore`.
- `query strict` is unchanged: a site that refuses unknown parameters has
  no unknown ones to cache.

### In which order

1. **Decide and record on the original request:** the rules, the scans of
   the query (an attack in a tracking parameter is still found), the log, the
   statistics and the learning runs see every parameter.
2. **Then clean:** ignored parameters are taken out of `$_GET`, `$_REQUEST`,
   `QUERY_STRING` and `REQUEST_URI`; the cache key (`Request::cacheKey()`
   today takes every parameter -- the cache's caller gets a key without the
   ignored and unknown ones) and `REQUEST_SHIELD_CACHE_LOOKUP` are made from
   the cleaned request.
3. **The check page's resend** keeps the address the visitor asked for, with
   every parameter: a visitor who solves the check lands where the link
   pointed.

### What the shield hands on

- `$_SERVER['REQUEST_SHIELD']` as today: `allow` (may be kept) or
  `allow-uncached` (must not be kept).
- New: `$_SERVER['REQUEST_SHIELD_CACHE_LOOKUP']` -- the address (path and the
  `cache-query` parameters, sorted) a cache may answer the request from, set
  only when it differs from the request's own address. A cache that knows it
  looks up that key; one that does not simply misses, as today.

## Who understands it

| Cache | Ignored | Unknown (hit-only) |
|---|---|---|
| the shield's HTTP cache (RSF04-03) | yes (built with this proposal) | yes (`Request::cacheKey()` without them; no keep) |
| Exponential 6's `exphttpcache` (6.0.15 on) | **yes, unchanged**: its early exit sees the cleaned address | with a small upstream change: look up `REQUEST_SHIELD_CACHE_LOOKUP`, keep nothing when `allow-uncached` |
| Ibexa / Exponential Platform, Symfony `HttpCache` in PHP | yes, unchanged | a small store wrapper (later) |
| WordPress page-cache plugins (in PHP) | yes, unchanged | per plugin (later) |
| Varnish, a CDN in front | no -- they see the request before the shield runs | no |

For a proxy in front, the shield could later print the matching VCL or CDN
rule from `cache-ignore` (a proposal of its own).

## Exponential 6 (0031 step G.6, re-dedicated)

Exponential 6 has its own role-aware HTTP cache since 6.0.15
(`exphttpcache`: whole `content/view` pages, an early exit before the
kernel, anonymous, role and private contexts, the form token put back per
visitor, purge on `content/cache`, APCu before files, `X-Exp-Cache: HIT |
STALE | MISS | BYPASS`). A legacy extension that told the shield's cache the
role would duplicate it -- two caches in a row are worse than one. So G.6
becomes (owner, 2026-10-09):

1. **Working together, not twice:** for Exponential 6 from 6.0.15 the
   shield's HTTP cache stays off -- `check` says so when both are on; the
   statistics read `X-Exp-Cache` as they read `X-RS-Cache` (hit, stale, miss,
   bypass), so the dashboard shows the response times by hit and miss for
   Exponential's cache too; the rules for Exponential (0031 I.1) and the docs
   say who does what. With this proposal, `exphttpcache` also gets the
   ignored parameters for free.
2. **Upstream (a pull request to Exponential):** `exphttpcache` sends, in
   addition to its own tags (`l<node>`, `pl<parent>`, `dq`), the tag pattern
   of Ibexa's HTTP cache, so a Varnish or CDN in front can use the purges it
   knows from Ibexa and Exponential Platform: on a page `ez-all`, `c<object>`,
   `ct<class>`, `l<main node>`, `l<node>`, `pl<parent>`, `p<each node of the
   path>`; on `content/cache` per object `c<id>` and `r<id>`, per node
   `l<id>`, `pl<id>` and `rl<id>`; `content/cache/all` purges `ez-all`, a
   class `ct<id>`, roles `ez-user-context-hash`. And the lookup of
   `REQUEST_SHIELD_CACHE_LOOKUP` for unknown parameters.
3. **The extension for older legacy sites** (eZ Publish 5.x, Exponential 6
   before 6.0.15) -- roles and purges for the shield's cache -- waits.

## Cost

- Nothing without `cache-ignore` and `hit-only`: one more array check where
  the cacheable definition already looks at the parameters.
- With them: the query's names compared once (as today); taking parameters
  out rewrites three `$_SERVER` entries and `$_GET` -- only for a request that
  has such a parameter.

## Tests (when built)

- `/news?utm_source=x` with `cache-ignore utm_*`: the application sees `/news`
  (`$_GET`, `QUERY_STRING`, `REQUEST_URI`), the answer is kept as `/news`; a
  second request with another `utm_` value is a hit.
- `/news?x=7` with `hit-only`: a hit when `/news` is kept; a miss renders with
  `x` and keeps nothing; `REQUEST_SHIELD_CACHE_LOOKUP` = `/news`,
  `REQUEST_SHIELD` = `allow-uncached`.
- The default (`uncached`) unchanged; `query strict` unchanged; a `cache-query`
  parameter stays in the key next to an ignored and an unknown one.
- An attack in an ignored parameter (`?utm_source=<script>…`) is still found
  by the scans and refused; the log and the statistics see the parameter.
- The check page's resend keeps the whole address, ignored parameters too.
- `utm.source` and `utm source` are matched as `utm_source`.
- `include @tracking` alone ignores nothing; `cache-ignore @tracking` ignores
  its names.
- `hit-only` with `?lang=en` gets the plain kept page; on a miss nothing is
  kept.
- `check`: `hit-only` without `cache-query` warns; parameters the learning
  runs saw the application use but that `cache-query` does not name are
  listed.
- End to end with a pseudo-`exphttpcache` early exit: ignored parameters are
  hits without any change to it.
- Bench: a request without parameters unchanged.

## Built (2026-10-09)

The three kinds in the shield and its HTTP cache, with the proposed answers
to the open questions below, which the owner may still change: `@tracking`
only with `cache-ignore @tracking` (1), the copy in `REQUEST_SHIELD_IGNORED`
(2), the name `set cache-unknown-query hit-only` (3). Not yet: the
statistics' count of hits through ignored and hit-only parameters (5), the
list of parameters the learning runs saw used but `cache-query` does not
name, the upstream parts (G.6 1 and 2).

## Open questions for the owner

1. **`@tracking`:** marks its parameters as ignored by default, or only with
   `cache-ignore @tracking`? Some of them carry a visitor's identity for the
   application (`mkt_tok`, `_hsenc`, `mc_eid`, `li_fat_id`: a mail tool's
   personal link). *Proposed: only with `cache-ignore @tracking`, and `check`
   lists any of them the learning runs saw the application read.*
2. **Ignored parameters on the server:** take them out for the application
   too (proposed: yes, else a rendered page may carry them), or keep them in
   a `$_SERVER['REQUEST_SHIELD_IGNORED']` copy for server-side tracking?
   *Proposed: the copy, as a JSON string, so nothing is lost.*
3. **The name** `cache-unknown-query hit-only` -- or `cache-query … others
   hit-only` on the same line?
4. **Upstream:** one pull request to Exponential (tags + lookup), or two?
   *Proposed: two -- the tags help every Varnish user at once.*
5. **The statistics:** count the requests that became hits through ignored
   and hit-only parameters (a part of their own)? *Proposed: yes, in the
   cache's part.*
