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
  or `no-cache` (nor `Pragma: no-cache`, nor an `Expires` gone by); not
  encoded by the application (`Content-Encoding`); no `Vary` but on the
  encoding -- every line of a header counts; at most `http-cache-max-object`
  (a larger answer is not held in memory either). For the answer's own
  `s-maxage` or `max-age`, else `http-cache-ttl`.
- **Only whole answers:** what the application throws away with `ob_clean()`,
  an answer it ends before the script does (`ob_end_flush()` of every buffer,
  `fastcgi_finish_request()`), or a fatal error -- not kept.
- **Only the site's names:** the host as the visitor sent it, port and all,
  must be on `http-cache-hosts`. An application that builds links or a
  redirect from the `Host` header would otherwise keep a page made for
  `www.example.org:1337` for everyone, and made-up names would fill the
  cache.
- **One key, one answer:** an address with a parameter twice (`?a=1&a=2`) or
  an encoded `/`, `?`, `#` in its path is not kept.
- **Never asked:** a request with a cookie that is not named harmless (a
  session, a cart, a login), with `Authorization`, a POST, or an address the
  rules found not cacheable.
- **Off by default.** Off, the plugin is not even loaded: a request pays nothing.

### Tags and purges: what the CMS already sends

The cache understands the headers and requests a CMS sends to the caches
it knows ([proposal 0039](../proposals/0039-cache-compatible.md)), so its
cache plugin works unchanged -- and a site that grows puts a Varnish in
front and switches this cache off, with nothing to change in the
application.

- **Tags in the answer:** `xkey` (Varnish: Ibexa, Exponential Platform),
  `X-Cache-Tags` (FOSHttpCache), `X-LiteSpeed-Tag`, `Surrogate-Key`,
  `Cache-Tag`, `Edge-Cache-Tag`, `X-Magento-Tags`, Exponential's older
  `X-Location-Id` (as `location-<id>`), and one of your own with
  `http-cache-tag-headers`. Split at spaces and commas, kept with the answer
  and **taken out of what the visitor gets** -- they name content ids. A
  LiteSpeed tag `private:…` means the answer is not kept; `public:` is
  dropped. More than 500 tags: not kept.
- **Every answer also has the tag of its address** (path and query, any
  host), so `PURGE /news/?page=2` purges it.
- **Purge requests** -- answered before the rules, `200 Purged`:

  | Request | Purges | Sent by |
  |---|---|---|
  | `PURGE <address>` | that address | any purge client |
  | `PURGE /` with `key: a b` (`key: ez-all`: everything) | tags | Exponential Platform |
  | `PURGE /` with `X-Cache-Tags: a,b` | tags | FOSHttpCache, Ibexa "local" |
  | `PURGE /` with `X-Location-Id: *`, `12` or `(1\|2\|3)` | everything, or `location-12` … | Exponential's older calls |
  | `PURGEKEYS /` with `xkey-purge: a b` or `xkey-softpurge: a b` | tags (a soft purge purges, for now) | Ibexa with Varnish |

  Only from `http-cache-purgers` (default `127.0.0.1 ::1`, as Symfony's
  `AppCache`) or with `X-Invalidate-Token` equal to `http-cache-purge-token`.
  A request from such an address that came through a proxy the shield does
  not trust (it carries `X-Forwarded-For`, `Forwarded`, `X-Real-IP` or
  `Via`) does not count. Anyone else meets the rules: `405`, as for any
  method the site does not take -- no hint that a cache is there.
- **Purges in the answer** (LiteSpeed's way, any method, a POST's too):
  `X-LiteSpeed-Purge: tag=c52, /news/, *` purges tags, addresses or
  everything before the answer leaves, and is taken out.
- **How a purge works:** no list of pages is searched. Each tag remembers
  when it was purged last; a kept answer remembers when its request began.
  A hit whose tag -- or everything -- was purged since is a miss, and the
  page is made and kept anew. A purge is one small file written (and its
  copy in APCu), whatever the number of pages; a hit asks first when
  anything was purged at all, so a page made after the last purge costs one
  read more, not one per tag.

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
set http-cache-hosts www.example.org example.org   # the site's names (required: nothing is kept without)
set http-cache-ttl 5m                       # when the answer says nothing (its s-maxage or max-age wins)
set http-cache-cookies _ga* _pk_* rsp       # cookies that do not make a page someone's own (default: analytics, the pass)
set http-cache-max-object 1M                # the largest answer kept
set http-cache-dir /var/cache/request-shield   # default: <store-dir>/http-cache
set http-cache-purgers 127.0.0.1 ::1        # who may send PURGE / PURGEKEYS (default: this machine)
set http-cache-purge-token …                # or anyone with this X-Invalidate-Token (16 characters or more)
set http-cache-tag-headers X-My-Tags        # a tag header besides the known ones
cache-query page sort                       # the parameters a page may have (RSF04-01)
```

Emptying it -- after a deploy, or a CMS after it published a page:

```bash
php bin/request-shield cache site.rules                    # how many answers, how many bytes
php bin/request-shield cache site.rules purge --path=/news/
php bin/request-shield cache site.rules purge --tag=c52,l2   # the answers with one of the tags
php bin/request-shield cache site.rules expired            # cron: remove what has run out
```

The same in the [API](RSF06-05-api.md): `GET /rs/api/v1/cache`, `POST
/rs/api/v1/cache/purge` with `path` or `tags` (a write: `set api-write on`).
A purge by tag from the command line reaches the web server's copy in APCu
within 10 seconds (the command line's APCu is its own; the files are what
counts). `request-shield check`
warns when `http-cache` is on without `http-cache-hosts`, and when every query
parameter is cacheable (no `cache-query`). A purge below a path compares
literally: `--path=/news` takes `/newsletter` too.

## Cost

| | |
|---|---|
| off | nothing: the plugin is not loaded |
| on, a hit | the shield's decision, one file read, and the time of the last purge (one APCu read, or one small file) -- instead of the application; the tags' times only when something was purged since the page was made |
| on, a miss | one output buffer, a callback before the headers go out (tags and purges taken out), and, when kept, one file written after the answer |
| a purge | one small file per tag and one for "anything", with APCu their copies |

## Limits

- **One server's files:** several servers keep their own unless
  `http-cache-dir` is shared.
- **No size limit on the folder yet:** the definition keeps made-up paths and
  parameters out, but a parameter it lets through takes any value
  (`?page=1` … `?page=99999`). One store in a hundred removes what has
  expired in a 256th of the folder; `cache … expired` from cron removes the
  rest. A cap, and answers in APCu, are [proposal
  0039](../proposals/0039-cache-compatible.md).
- **Redirects are kept for everyone:** a 301 or 308 that sends visitors to
  different places by language or device without saying `Vary` is kept as
  the first visitor got it -- send such redirects with `Cache-Control:
  private`.
- **Concurrent misses** each run the application; the last one written is
  kept.
- **One header callback:** PHP allows one `header_register_callback()` per
  request. An application that registers its own replaces the cache's: its
  tag headers then reach the visitor and its `X-LiteSpeed-Purge` is not
  followed (the tags are still kept with the answer; purges by request
  work).
- **Not yet:** `BAN` with patterns, a soft purge that serves the stale page
  while one request renews it, one page per role, answers in APCu -- the
  further parts of [proposal 0039](../proposals/0039-cache-compatible.md).
- **Pages that differ by language or device** (`Vary: Accept-Language`,
  `Vary: Cookie`) are not kept: one address, one answer.
- **Not for logged-in users:** a session cookie means the site answers.
