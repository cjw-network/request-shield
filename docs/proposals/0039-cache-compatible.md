# 0039 — A page cache that speaks the known dialects: tags, purges, roles, memory

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-04 |
| Affects | the cache plugin ([RSF04-03](../features/RSF04-03-http-cache.md)): what it reads from the application's answers, what purge requests it accepts, how it keys an answer per role, where it keeps answers |
| Relates to | [RSF04-03 a page cache for small sites](../features/RSF04-03-http-cache.md) · [RSF04-01 what a cache may keep](../features/RSF04-01-cacheable-definition.md) · [use case Exponential](../use-cases/exponential.md) |

## The idea

The page cache of step G.2 is a **performance shield for small sites**: a
site with no cache of its own switches it on; a large one has a Varnish, a
CDN or LiteSpeed in front and leaves it off (the default -- nothing of it
loads then). Between the two it should be **exchangeable**: a site that grows
puts a Varnish in front and switches the shield's cache off, and nothing in
the application changes. That works when the shield's cache understands what
the applications already send to the caches they know:

- **tags** on an answer (`xkey: c52 l2 p2`), so that one edit throws away
  every page that shows the content;
- **purges** -- the CMS's own cache plugin says "throw away tag c52" or
  "this address", in the answer or as a request of its own;
- **roles** -- a page that differs for editors and for anonymous visitors is
  kept once per role, not refused for everyone with a login;
- **memory** -- with APCu, small answers stay in RAM, the disk only takes
  what does not fit, and the cache cleans up by itself.

## What the applications send (analysed)

| System | Tags in the answer | Purge | Per role |
|---|---|---|---|
| **Ibexa 4** (FOSHttpCache), Varnish mode | `xkey`, space-separated (`c52 l2 p2 ct3 ez-all`; with several repositories a numeric prefix) | `PURGEKEYS /` with `xkey-softpurge` (or `xkey-purge`), space-separated; `PURGE <address>`; from an address list or with `X-Invalidate-Token` | `X-User-Context-Hash`: the proxy asks `GET /_fos_user_context_hash` (`Accept: application/vnd.fos.user-context-hash`, only the `eZSESSID*` cookies and `Authorization`), keeps the hash 600 s; pages `Vary: X-User-Context-Hash` |
| **Ibexa 4**, "local" (Symfony `AppCache`) | `X-Cache-Tags`, comma-separated | in-process; or `PURGE` with `X-Cache-Tags`, or `PURGE <address>`, from 127.0.0.1 / ::1; answers 200 "Purged" / "Not found" | the same hash, asked in-process |
| **Exponential Platform** (`se7enxweb/ezplatform-http-cache`, the eZ Platform 2.x bundle on FOSHttpCache 1.x; its `AppCache` is the PHP cache of the new Exponential) | `xkey`, space-separated, also in its local store (`content-52 location-2 path-2 content-type-3 ez-all`; `X-Location-Id` deprecated, read as `location-<id>`) | `PURGE /` with `key: <tags>` (`key: ez-all` for everything), plain `PURGE <address>`, `PURGE` with `X-Location-Id: *` / `123` / `(1\|2\|3)`, `BAN` with `X-Location-Id` (old); from 127.0.0.1 / ::1 or with `x-invalidate-token`; answers 200 "Purged", 404 "Not purged", 405 for others | **`X-User-Hash`** (not `-Context-`): the same lookup at `/_fos_user_context_hash`, only `eZSESSID*` cookies, kept 600 s; the hash answer itself is tagged `ez-user-context-hash`, so a change of roles purges it. Pages `Vary: X-User-Hash`; before they leave, `AppCache` takes `xkey` and that Vary out and makes them `private` |
| **eZ Publish legacy / Exponential 6** | none | none of its own; with the Netgen legacy bridge its "content/cache" events go to Ibexa's purge client (node ids -- seemingly without the `l` prefix, so only "purge all" (`ez-all`) is sure to match; not verified at run time) | none: `eZSESSID*` session cookie, `is_logged_in=true` for registered users |
| **LiteSpeed** (LSCache plugins: WordPress, Joomla, Drupal, Magento, PrestaShop, OpenCart, XenForo, Shopware …) | `X-LiteSpeed-Tag`, comma-separated | **in the answer**: `X-LiteSpeed-Purge: tag=…, *, /path`, on any answer, a POST's too | `X-LiteSpeed-Vary: cookie=…, value=…` and the `_lscache_vary` cookie the plugin sets per role |
| Varnish elsewhere (TYPO3, Shopware 6, "Proxy Cache Purge" for WordPress) | `xkey` or `X-Cache-Tags` | `PURGE <address>`, `BAN` with a tag pattern, xkey purges | mostly none: a login skips the cache |
| Fastly · Cloudflare · Akamai | `Surrogate-Key` · `Cache-Tag` · `Edge-Cache-Tag` | the provider's API, not a request to the server | -- |
| Magento 2 | `X-Magento-Tags` | `PURGE` with `X-Magento-Tags-Pattern` (a pattern) | `X-Magento-Vary` cookie |
| nginx (`proxy_cache`, "Nginx Helper") | -- (`X-Accel-Expires` for the time) | `GET /purge/<address>` | -- |

The rows for Ibexa, Exponential Platform and legacy are read from their code
(Ibexa 4.x, `ibexa/http-cache`, `friendsofsymfony/http-cache(-bundle)`;
`se7enxweb/ezplatform-http-cache` as of 2026-03; eZ Publish legacy with the
Netgen bridge); the others from their documentation. Only the protocol is
taken over -- headers, methods, answers --, no code (theirs is GPL).

**What follows:** three dialects cover nearly everything a CMS plugin does
-- Varnish/FOS (`xkey`, `X-Cache-Tags`, `PURGE`, `PURGEKEYS`; Ibexa and Exponential Platform speak it), LiteSpeed
(`X-LiteSpeed-*`, in the answer), and the CDNs' tag headers (read, never
purged by request). Legacy (Exponential 6) sends nothing; it gains from purge by
address and from roles told by an adapter (below).

## Proposed

### 1. Tags: read what the answer carries

- The cache reads the tags of an answer from the headers it knows -- `xkey`,
  `X-Cache-Tags`, `X-LiteSpeed-Tag`, `Surrogate-Key`, `Cache-Tag`,
  `X-Magento-Tags` -- split at spaces and commas, as Ibexa's own handler
  does. `public:` in a LiteSpeed tag is dropped; a `private:` tag means the
  answer is not kept.
- The tag headers are taken out of the answer the visitor gets (as Ibexa's
  VCL does with `xkey`): they name content ids.
- A setting for an odd header: `set http-cache-tag-headers xkey X-My-Tags`.

**Purge by tag without an index: generations.** Each tag has a counter (APCu,
or a small file without APCu). An answer stores the counters of its tags as
they were; purging a tag is one increment; a hit whose stored counter is
behind is a miss. One write per purge whatever the number of pages, nothing to
search, and "purge everything" (`*`, `ez-all`) is one global counter. The
files left behind are cleaned up as expired (4.).

### 2. Purges: in the answer, and as requests

- **In the answer** (LiteSpeed's way, the cheapest -- no extra request): an
  answer with `X-LiteSpeed-Purge: tag=c52, /news/, *` purges before it is
  sent, whatever its method. The header is taken out.
- **As a request** (Varnish's and AppCache's way), answered by the shield
  before the application, `200 Purged` / `200 Not found` as AppCache does:
  - `PURGE <address>` -- the address;
  - `PURGE` with `X-Cache-Tags: a,b` -- tags (Ibexa local, FOS);
  - `PURGEKEYS` with `xkey-purge` or `xkey-softpurge: a b` -- tags (Ibexa
    with Varnish, xkey). A soft purge marks them stale (5.).
  - `PURGE` with `key: a b` -- tags (Exponential Platform; `key: ez-all`
    is everything); `PURGE` with `X-Location-Id: *`, `123` or `(1|2|3)` --
    everything, or the tags `location-<id>` (its older calls).
  - Only from `set http-cache-purgers <addresses>` (default `127.0.0.1 ::1`,
    as AppCache) or with `X-Invalidate-Token` equal to
    `set http-cache-purge-token` (compared with `hash_equals`). Anyone else:
    `405` as for any unknown method -- never a hint that a cache is there.
  - Not proposed: `BAN` with patterns (Magento, older Varnish setups) -- a
    pattern over all tags needs an index. Asked below.
- So **Ibexa's and Exponential's purge settings work unchanged**:
  `purge_type: varnish` with `purge_servers: [http://localhost]` sends their
  `PURGEKEYS` or `PURGE` + `key` to the shield. (`purge_type: local` would
  not: it calls its own store inside PHP.)

### 3. Roles: one page per role, not none for everyone with a login

Today a login cookie skips the cache. A page per role needs one thing the
shield cannot forge-proof read from the request alone: **which role this
visitor has**. Three ways were weighed:

- **A role cookie** (`_lscache_vary`, `X-Magento-Vary`): the CMS plugin sets
  a cookie whose value names the role, and the cache keys by it. **Not
  proposed:** the cookie is the visitor's own -- whoever learns or guesses
  the value an editor's browser sends (it is the same for every editor)
  sets it in their own browser and gets the editors' pages from the cache,
  without signing in. The cache would trust the client with the one thing
  it must not.
- **The PHP session, read before the application:** not possible in general
  -- the shield runs before the application has registered its session
  handler (a database, Redis), and opening the session itself would lock it
  and send its own cookie. WordPress has no PHP session at all: its login
  cookie (`wordpress_logged_in_…`: user, expiry, token, a MAC) is checked
  against keys in `wp-config.php` and a token in the database.
- **The application tells the shield, in the same PHP process** -- proposed.
  The shield and the application share the process and the store (APCu).
  While the application runs, it knows the user; an adapter (a WordPress
  plugin, an Exponential extension, a Symfony bundle) says so with one call:

  ```php
  Shield::active()?->cacheContext($roleKey);   // e.g. a hash of the user's roles, "editor+author"
  ```

  The shield keeps `MAC(secret, the session cookie's value) → $roleKey` in
  APCu for `http-cache-context-ttl` (default 10 minutes, refreshed on every
  request the application runs). On the next request it computes the MAC of
  the cookie the visitor sends and finds the role -- before the application
  starts. **Nothing to forge:** the key is the session cookie itself, the
  credential; whoever has it *is* that user. An unknown cookie (a new login,
  a forged value, a session ended elsewhere) finds nothing: the application
  runs, as today, and its adapter registers the role for the next one.
  Logging out removes the entry (`Shield::active()?->forgetContext()` in the
  logout hook); a change of roles purges the tag `rs-context` or lets the
  entries run out.

Which cookie is the session: `set http-cache-session-cookie
wordpress_logged_in_* eZSESSID* PHPSESSID`. Only an answer the application
marks as **the same for everyone with this role** is kept per role -- `Vary:
X-User-Hash` / `X-User-Context-Hash` (FOSHttpCache applications send it), or
the adapter's `cacheContext(..., shared: true)` for the page. Every other
answer to a signed-in visitor is not kept, as today. This matters: a
WordPress page for a signed-in user usually carries **nonces** (the admin
bar, the REST API's `wpApiSettings.nonce`) bound to that user and session --
kept for a role, the next editor's forms would fail. The WordPress adapter
therefore marks a page only for the roles a site names (a members' area for
`subscriber`, say) and only when no nonce was made during it
(`wp_create_nonce` is filterable: the adapter notices one).

For Ibexa and Exponential Platform the adapter (a bundle) does the same with
the hash FOSHttpCache already computes -- no extra request. Without an
adapter they can still use the lookup:

- **By the user context hash request** (`set http-cache-user-context on`;
  the header `X-User-Context-Hash`, or `set http-cache-user-hash-header
  X-User-Hash` for Exponential Platform): for a visitor with a session
  cookie the shield asks the application once -- `GET
  /_fos_user_context_hash`, `Accept: application/vnd.fos.user-context-hash`,
  only the session cookie and `Authorization` -- and keeps the hash in APCu,
  keyed by the MAC of the cookie as above, for its `max-age` (600 s). A
  visitor that sends the hash or that `Accept` itself is refused (as the
  VCLs do: 400). The lookup fails or times out → the cache is skipped for
  this request (fail safe). The kept hashes carry the tags of their answer
  (`ez-user-context-hash`), so the CMS's purge on a change of roles empties
  them too.

In both ways, before a page that varies by role leaves, its `Vary` on the
hash goes and it becomes `private` -- as Exponential's `AppCache` does, so no
cache behind the shield and no browser keeps one role's page for another.
Without APCu roles stay off -- `check` says so.

**Why adapters:** the role is the application's knowledge; one call from an
adapter gives it to the shield safely and without an extra request. That is
the strongest reason yet for the CMS adapters of 0031 phase I (WordPress,
Exponential, Symfony/Ibexa, Drupal): installed as a plugin from the CMS's own
directory, they bring the cache per role, the purges on publish (the
adapter calls the shield's purge directly -- no `PURGE` request needed) and
the check in the forms, and they are how most sites will find the shield.

### 4. Memory first, the disk when needed, and cleaning up by itself

- **With APCu:** answers up to `http-cache-memory-object` (default 256 KB) are
  kept in APCu with their time to live -- APCu drops them when they expire or
  when it needs room. The cache's share is limited
  (`http-cache-memory`, default 32 MB; above it, new answers go to the disk).
- **The disk** takes larger answers, and everything without APCu
  (`http-cache-dir`, as now).
- **Cleaning up by itself:** one store in a hundred also deletes up to fifty
  expired files (a few milliseconds, never on a hit); `http-cache-disk`
  (default 256 MB) caps the disk -- above it, the oldest go. `request-shield
  cache … expired` stays for cron.
- A hit stays one APCu read (memory) or one file read (disk) -- measured
  before and after.

### 5. Times, and stale answers

- The time to live, in this order: `X-LiteSpeed-Cache-Control`, `Surrogate-Control
  max-age`, `s-maxage`, `max-age`, `X-Accel-Expires`, `http-cache-ttl`.
- `stale-while-revalidate` / a soft purge: the stale page is served while
  one request renews it (a lock in APCu, so a hundred visitors do not all
  hit the application at once). `stale-if-error`: an answer of 5xx
  serves the stale page instead. Both only with APCu.

### Replacing Exponential Platform's `AppCache`

The new Exponential's PHP cache is Symfony's `HttpCache` with a tag-aware
file store (`AppCache`, `TagAwareStore`). The shield's cache can take its
place, roles included:

1. `public/index.php`: the kernel without `AppCache` (as with a Varnish in
   front; `SYMFONY_HTTP_CACHE=0`).
2. `ezplatform.http_cache.purge_type: varnish`, `purge_servers:
   [http://127.0.0.1]` -- its purges arrive as `PURGE` + `key` at the
   shield, which takes them from 127.0.0.1 (or with the token).
3. Rule file: `set http-cache on`, `http-cache-hosts …`,
   `set http-cache-user-context on`, `set http-cache-user-hash-header
   X-User-Hash`, `set http-cache-session-cookie eZSESSID`.

What it brings: the cached page is answered before Symfony starts (the
`AppCache` answers after the kernel is loaded), the answers sit in APCu, the
shield's statistics count hits, and the same rule file switches to a Varnish
later -- the application's headers and purges stay. What it does not: ESI
(`Surrogate-Control`, fragments) -- a page with ESI is not kept, and `check`
says so; asked below.

## When a Varnish or CDN is in front

Off -- the default. A Varnish in front makes the shield's cache a second
copy; `check` warns when `http-cache on` sees requests that came through a
cache (`X-Varnish`, `Via`, `CDN-Loop`). The application's tag and purge
headers then go to the real cache, unchanged -- that is the exchange.

## What it costs

- Off: nothing (the plugin is not loaded).
- On, a hit: the tag counters of the page (one `apcu_fetch` of an array)
  more than now.
- On, a miss: reading the tag headers from `headers_list()`, writing the
  counters' state.
- Roles by an adapter: one `apcu_fetch` and one MAC per request with a
  session cookie; nothing extra for the application.
- Roles by the hash request: one application request per session per
  `max-age`.

## Open questions for the owner

1. **Order:** 1 + 2 (tags, purges) and 4 (memory) first, roles (3) and stale
   answers (5) after? Proposed: yes -- tags and purges make a CMS plugin
   work, memory is what makes the cache worth having.
2. **`BAN` with patterns** (Magento, older Varnish): leave out, or keep a tag
   index for it?
3. **Adapters first?** Roles by `cacheContext()` need an adapter per CMS.
   Proposed: the core call and the cache's side with the tags (1, 2, 4), then
   the WordPress adapter (0031 I.3) as the first that uses it -- the widest
   reach -- and Exponential 6's legacy extension (I.2) next. The role cookie
   is dropped.
4. **The CDN tag headers** (`Surrogate-Key`, `Cache-Tag`): only read, or also
   left in the answer for a CDN in front of the shield?
5. **Exponential Platform first?** Its dialect (`xkey`, `PURGE` + `key`,
   `X-User-Hash`) is the one to build and test against first -- with an
   end-to-end test that sends what its purge client and its hash lookup send.
   Proposed: yes, then Ibexa 4 (`PURGEKEYS`, `X-User-Context-Hash`), then
   LiteSpeed.
6. **ESI:** leave pages with ESI to the application (not kept), or assemble
   fragments as `AppCache` does? Proposed: not kept -- ESI is rare on small
   sites, and assembling it is a cache of its own.
