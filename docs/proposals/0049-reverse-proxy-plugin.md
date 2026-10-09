# 0049 — A reverse proxy plugin: first-party addresses for other services

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-09 |
| Affects | a new plugin `plugins/proxy` (words `proxy …`, a `Handler`), `Http` (a request with a body, no redirects, a size cap), the HTTP cache (the proxied scripts kept), the statistics, `check`, the docs; nothing in the core's request path |
| Relates to | [RSF04-03 the HTTP cache](../features/RSF04-03-http-cache.md) · [RSF06-04 plugins](../features/RSF06-04-plugins.md) · [privacy](../privacy.md) · [0016 rule advisor](0016-rule-advisor.md) (`found`: other hosts a site's pages call) · [0043 the decision in the server's log](0043-decisions-in-the-server-log.md) |

## The question

*"Könnten wir nicht auch ein neues Plugin Reverse Proxy implementieren? Damit
könnte man z. B. den Matomo-Tracking-Code über den Server durchschleifen."*
(owner, 2026-10-09)

## What it would do

The shield already answers some addresses itself before the application
starts (its dashboard, the HTTP cache's hits). A proxy plugin would answer a
few **fixed** addresses of the site by asking another server and handing its
answer on:

```
browser ──GET /stats/m.js────────▶ shield ──GET https://matomo.example.org/matomo.js──▶ Matomo
browser ──POST /stats/t (hit)────▶ shield ──POST https://matomo.example.org/matomo.php─▶ Matomo
            (the site's own host)          rules, budgets, bots first; IP masked; no cookies
```

```text
plugin proxy
proxy GET  /stats/m.js  to https://matomo.example.org/matomo.js   cache 1h
proxy GET POST /stats/t to https://matomo.example.org/matomo.php  ip masked  timeout 2s
```

- **Fixed pairs only:** an address of the site to one address elsewhere,
  written in the rules -- never a target taken from the request (no open
  proxy, no SSRF). (A whole path below another, `/stats/*` to `https://…/`,
  is an open question below: it takes part of the target from the request.)
- **The shield's checks first:** rules, budgets, the attack sets, crawler
  verification -- the proxied endpoint is a path like any other. A bot that
  hammers the tracking endpoint is slowed or refused before it reaches
  Matomo: the statistics there get cleaner.
- **What goes out:** the method, the path's query (or only named
  parameters), the body (up to a cap), `User-Agent` and `Accept-Language`
  (the tracker needs them), the visitor's address as configured -- `ip
  full`, `ip masked` (the network, as the shield's log masks it) or `ip none`.
  **Never** the site's cookies, `Authorization`, or its session, unless a
  cookie is named (`pass-cookie <name>` -- Matomo needs none: its tracker
  sends the visitor id in the query).
- **What comes back:** status, body, and an allowlist of headers
  (`Content-Type`, `Cache-Control`, `ETag`, `Last-Modified`, `Content-Encoding`);
  no `Set-Cookie` from elsewhere unless named; hop-by-hop headers never.
- **Cached when it may be:** a script (`matomo.js`) through the HTTP cache
  (`cache 1h`), so the other server is asked once an hour, not per page view.
- **Fail safe:** the other server slow or down -- the answer within
  `timeout` is an empty `204` for a tracking hit (the page goes on), a `502`
  for anything else; nothing is retried, a pause after failures (as the
  user-hash lookup has).

## Matomo, in particular

Matomo's own [tracker proxy](https://github.com/matomo-org/tracker-proxy)
is a small PHP script for exactly this. The plugin would do the same in the
shield's rule file:

- `/stats/m.js` → `matomo.js` (kept by the HTTP cache), `/stats/t` →
  `matomo.php`; the site's tracking code names the two first-party addresses
  (`_paq.push(['setTrackerUrl', '/stats/t'])`, the script's `src`).
- **The visitor's address:** Matomo records the address it sees -- the
  shield's server, unless told otherwise. Two ways, both documented:
  Matomo trusts the proxy's `X-Forwarded-For` (`proxy_client_headers[]` in
  its config, the shield's address in `proxy_ips[]`), or the plugin adds
  `cip` with a `token_auth` (a secret in the rule file, `${MATOMO_TOKEN}`;
  never shown). With `ip masked` Matomo gets the network only -- the
  anonymisation done before the data leaves the site.
- **Consent stays the site's:** a first-party address is no permission. The
  site's consent banner decides whether the tracking code runs; the proxy
  changes where it sends, not whether.

## Who wants this

- **Privacy:** the visitor's browser talks to the site only; what reaches
  the analytics server is what the site decided (masked address, no
  third-party cookies, no referrer of the other host).
- **Shared hosting:** no `proxy_pass` in an nginx or Apache configuration
  the customer cannot touch -- a rule line.
- **Cleaner statistics:** bots the shield knows never become visits.
- **Fewer hosts in the CSP:** the tracker's host leaves `script-src`,
  `connect-src` and `img-src` (Matomo's image fallback) -- `'self'` covers them.
- Other services the same way: a font or script host kept first-party, a
  map tile server behind a cache, an API key kept on the server
  (`header X-Api-Key ${KEY}` added to the request, never in the page).

## Against it -- the risks and costs

| Risk | What the design does about it |
|---|---|
| An open proxy / SSRF | fixed pairs from the rules; no target from the request; no redirect followed (one to an internal host would be SSRF); the target's address checked **when the request is made**, after DNS: a private, loopback or link-local result is refused unless named (`allow-private`) -- a name that resolves elsewhere later (DNS rebinding) is caught there, and the connection uses the address that was checked |
| PHP workers held while the other server answers | a short `timeout` (default 2 s), a size cap, a pause after failures; the docs say: for heavy traffic, `proxy_pass` in the web server is better |
| A cache poisoned through the proxy | only answers the other server marks cacheable, under the site's address; `Vary` and cookies as the HTTP cache already handles them |
| Leaking the site's cookies or credentials | never forwarded unless a name is listed; `Set-Cookie` from elsewhere dropped unless listed |
| Evading ad blockers against the visitor's will | out of scope by design: consent stays with the site; the docs say so plainly |
| Large or streamed bodies, uploads, WebSockets | not proxied: a body cap (64 KB by default), no streaming, no upgrade -- the plugin is for small requests |
| Maintenance (HTTP corner cases: chunked, compression, HEAD, 304) | a small fixed scope: GET/HEAD/POST, whole bodies, `Content-Encoding` passed through or decoded, conditional requests passed on |
| Legal: the visitor's address goes to another service | `ip masked` / `ip none`; the docs' privacy page names the processor relationship |

## Cost

- Not used: nothing -- the plugin is not loaded.
- Used: one array lookup per request for the proxied paths (the routes
  table, as the dashboard has it); a proxied request costs the upstream
  round trip, or a cache hit.

## Steps (when built)

1. `plugins/proxy`: words, compile-time checks (an https target, fixed
   pairs, sizes), a `Handler` that answers matching paths after the rules;
   `Http::request()` (method, body, headers, no redirects, a size cap, a
   timeout) resolves the target, refuses a private, loopback or link-local
   result unless the pair says `allow-private`, and connects to the address
   it checked.
2. The HTTP cache for proxied answers that may be kept.
3. Matomo: `ip masked`, `cip` + `token_auth`, a ready rule block
   (`include @proxy-matomo` with two variables), docs with the tracking code
   to change.
4. Statistics: proxied requests counted per target (hits, failures, time).

## Tests (when built)

- A fixed pair answers through a local test server; another path, another
  host, `..`, an encoded `/` -- never proxied.
- A target name that resolves to a private address, and a redirect from the
  target to one: refused.
- Cookies and `Authorization` never reach the target; a named cookie does;
  `Set-Cookie` from the target dropped unless named.
- `ip full|masked|none` in `X-Forwarded-For`; `cip`/`token_auth` added, the
  token never in a log line or page.
- The target down or slow: `204` for a tracking hit within the timeout, a
  pause afterwards; a `502` for others.
- A refused request (rules, budgets) never reaches the target.
- `matomo.js` kept by the HTTP cache: one upstream request for many page views.
- Bench: a request that is not proxied unchanged.

## Open questions for the owner

1. **Scope:** only Matomo first (a ready block), or the general `proxy`
   word from the start? *Proposed: the general word, Matomo as its first
   documented use.*
2. **Plugin or core?** *Proposed: a plugin (`plugins/proxy`), not in the
   mini edition -- most sites never need it.*
3. **The visitor's address:** default `ip masked`? *Proposed: yes -- the
   privacy-friendly default; `full` only when written.*
4. **Matomo's `cip` with `token_auth`** (a powerful token in the rule
   file), or only `X-Forwarded-For` with Matomo's trusted proxies? *Proposed:
   `X-Forwarded-For` by default, `cip` as an option with a write-only token.*
5. **A whole path below another** (`/stats/*` to `https://…/`): leave out,
   or allow with a strict normalisation (`..`, `%2f`, `//`, another host
   refused)? *Proposed: leave out -- fixed pairs cover Matomo.*
6. **Other services in the first version** (fonts, maps, an API key kept on
   the server), or later? *Proposed: later -- the general word allows them,
   the docs show Matomo first.*
