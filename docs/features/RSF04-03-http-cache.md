# RSF04-03 The HTTP cache

## What it does

![A page the shield finds cacheable is answered from the cache before the application starts; the first time, the application's public answer is kept](../diagrams/http-cache.svg)

A plugin (`plugins/cache`, the edition `request-shield-cache.php`) that keeps
the application's public answers and gives them out again before the
application starts: no framework, no database, no rendering for a page that
was built a minute ago. It keeps only the addresses
[a cache may keep](RSF04-01-cacheable-definition.md) -- made-up paths and
parameters never fill it -- and never a page that may be someone's own.

- **A hit:** the kept answer, with `Age` and `X-RS-Cache: hit`; `If-None-Match`
  with its ETag gets `304`.
- **A miss:** the application runs as always (`X-RS-Cache: miss`); its answer
  is kept when it may be kept by anyone.
- **Kept:** status 200, 301 or 308; no `Set-Cookie`; no `private`, `no-store`
  or `no-cache`; no `Vary` but on the encoding; at most
  `http-cache-max-object`. For the answer's own `s-maxage` or `max-age`, else
  `http-cache-ttl`.
- **Never asked:** a request with a cookie that is not named harmless (a
  session, a cart, a login), with `Authorization`, a POST, or an address the
  rules found not cacheable.
- **Off by default.** Off, the plugin is not even loaded: a request pays nothing.

## Use cases

- **A CMS without a page cache of its own** on simple hosting: the news, the
  product pages and the start page come from files, not from PHP and the
  database.
- **A flood of real addresses** (a newsletter, a link on a big site): the
  first visitor builds the page, everyone else gets it from the cache.
- **Together with the shield's definition:** a bot that asks for
  `/?x=1`, `/?x=2` … gets answers, but none of them is kept -- the cache only
  holds the site's real pages.

## Configuration

```text
set http-cache on
set http-cache-ttl 5m                       # when the answer says nothing (its s-maxage or max-age wins)
set http-cache-cookies _ga* _pk_* rsp       # cookies that do not make a page someone's own (default: analytics, the pass)
set http-cache-max-object 1M                # the largest answer kept
set http-cache-dir /var/cache/request-shield   # default: <store-dir>/http-cache
cache-query page sort                       # the parameters a page may have (RSF04-01)
```

Emptying it -- after a deploy, or a CMS after it published a page:

```bash
php bin/request-shield cache site.rules                    # how many answers, how many bytes
php bin/request-shield cache site.rules purge --path=/news/
php bin/request-shield cache site.rules expired            # cron: remove what has run out
```

The same in the [API](RSF06-05-api.md): `GET /rs/api/v1/cache`, `POST
/rs/api/v1/cache/purge` (a write: `set api-write on`). `request-shield check`
warns when `http-cache` is on and every query parameter is cacheable (no
`cache-query`).

## Cost

| | |
|---|---|
| off | nothing: the plugin is not loaded |
| on, a hit | the shield's decision and one file read -- instead of the application |
| on, a miss | one output buffer and, when kept, one file written after the answer |

## Limits

- **One server's files:** several servers keep their own unless
  `http-cache-dir` is shared.
- **No size limit on the folder:** what is kept is bounded by the site's real
  addresses (the definition keeps made-up ones out); `cache … expired` from
  cron removes what has run out.
- **Pages that differ by language or device** (`Vary: Accept-Language`,
  `Vary: Cookie`) are not kept: one address, one answer.
- **Not for logged-in users:** a session cookie means the site answers.
