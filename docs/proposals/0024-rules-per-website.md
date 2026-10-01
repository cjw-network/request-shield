# 0024 — Rules per website: one rule file for a server with many websites

| | |
|---|---|
| Status | **Phase 1 implemented** (2026-10-01): site blocks, compiled per website, `site-from`; phases 2–4 accepted, to come |
| Proposed | 2026-10-01 |
| Affects | rule files (a `site` block; [0003](0003-human-readable-rule-files.md), match blocks [0008](0008-match-blocks.md)), compiled settings ([ADR 0005](../adr/0005-settings-compiled-for-opcache.md)), budgets, the rules pages and the rule tester, statistics per website ([0023](0023-plugins-hosts-customers.md)), rules from a CMS ([0021](0021-rules-from-the-cms.md)) |

## Summary

On a server with many websites — 50 projects in one PHP-FPM pool, a shop, a
blog, an API, each a different application — one set of rules does not fit
all: the shop needs an admin area for the office and strict parameters, the API
must never meet the browser check, the blog is fine with the defaults. Today
that needs a rule file per website and a prepend per pool.

This proposal adds **`site` blocks** to the rule file: rules outside any block
apply to **every** website (the base), a `site <names> { … }` block **adds** the
rules of some websites — and may set their own values. When the files are read,
each website becomes **settings of its own** (the base plus its block),
compiled for OPcache like today; per request, the website is looked up and only
its settings are loaded. Inside a website everything works as today, `match`
blocks for paths included.

The one thing to get right is **which name decides**: the `Host` header is
written by the visitor. The rules follow the name the web server answers as —
by default the name of the server block (nginx), so nobody can ask for a more
lenient website's rules.

## In one picture

![One rule file for the server: the base for every website (scanners, known crawlers, a pace for the whole server); site blocks for shop.a.de and a.de (an admin area for the office, strict parameters, rules from the CMS), *.b.de (its own include), api.c.de (no browser check, its own limit), and default for unknown names (strict). Read once, compiled per website; a request is looked up by its website — the exact name, then *.domain, then default — in about 1 µs, and only that website's settings are loaded (about 5 µs per request in all). Which name decides: the server block's name (nginx), not blindly the Host header. Inside a website nothing changes.](0024-rules-per-website.svg)

## The syntax

```text
# For every website (the base)
include @scanners @tracking
crawlers ai-training block
[BASE-PACE] limit requests 300/min challenge-at 150     # the whole server: one flood is one flood

site shop.a.de a.de www.a.de {                          # Customer A's shop
  ids SHOP
  match /admin/** {
    [SHOP-ADM] restrict to 192.0.2.0/24                 # the office only
  }
  [SHOP-PACE] limit requests 120/min challenge-at 60     # counted in the shop only
  query strict
  include-app cms-a.rules                               # 0021: what its CMS pushes
}

site *.b.de {                                           # every subdomain of b.de
  include b.rules
}

site api.c.de {
  challenge-exempt /**                                  # programs, never the browser check
  no-limit requests                                     # not the base's pace …
  [API-CALLS] limit calls 600/min                       # … but its own
}

site default {                                          # a name no block lists
  set mode strict
}
```

- **`site <names> {` … `}`** — one or more names; a name is exact (`a.de`) or
  `*.domain` (exactly one more label: `*.b.de` is `news.b.de`, not `b.de`, not
  `x.news.b.de`). `default` is the block for names no block lists.
- **The base applies to every website.** A block **adds** rules, and may:
  - set its own values: `set mode`, `set pass-ttl`, `set log`, `set stats …`,
    `crawlers …` / `crawler …` policies;
  - switch off a rule of the base for itself, with the words that exist:
    `no-limit <budget>`, `unblock [ID]`, `challenge-exempt <paths>`, `replace [ID]`.
- **Inside a block:** every rule that works in a rule file today, `match`
  blocks and `include` included. **Not** inside a block — they are about the
  server, not a website: `trust` (which proxies — the name may come from them),
  `set store`, `set store-dir`, `set secret`, `set recheck`, `set dns-lookups`,
  `set ipv6-prefix`. An error names the line.
- **A name in two blocks** is an error (naming both lines); `*.b.de` and
  `news.b.de` may both exist — the exact name wins.
- `site` inside `match` is an error; `match` inside `site` is fine.

## How a request finds its website

1. The name (see the next section), normalised: lower case, without the port
   and a trailing dot.
2. Looked up: the exact name → `*.` + the name without its first label →
   `default` → the base alone.
3. Its compiled settings are loaded.

The lookup is a small PHP array compiled with the settings (names → file): two
`isset()`, no regular expression — about 1 µs with the call. Each website's
settings are a PHP file of their own that OPcache keeps; a request builds
exactly one, as today. 50 websites are 50 files on disk, not 50 in every request.
*Measured in phase 1:* about **5 µs more** per request on a website with a block
of its own (the second compiled file and its settings: 13–20 µs against 9–13 µs);
smaller per-website files (the crawler lists kept once) could take most of it
back later.

## Which name decides

The visitor writes the `Host` header. If the rules followed it blindly, anyone
could send `Host: api.c.de` to get the API's rules — no browser check — wherever
that lands.

```
set site-from server-name     # the default
set site-from host            # the Host header (behind a trusted proxy: X-Forwarded-Host)
```

- **`server-name`** (the default): `$_SERVER['SERVER_NAME']` — with nginx the
  name of the `server` block that answers (`fastcgi_param SERVER_NAME
  $server_name`), from the web server's configuration, not from the visitor.
  A request with `Host: api.c.de` that nginx routes to the shop's server block
  gets the shop's rules. With Apache, `UseCanonicalName On` gives the same.
- **`host`**: the `Host` header — right when the web server routes by that same
  name and has no catch-all that serves an application for any name (then a
  made-up name reaches `default`, which is why `default` should be strict). Behind
  a load balancer: `X-Forwarded-Host`, only from trusted proxies, as today.
- `bin/request-shield check` warns when `site` blocks exist and `site-from` is
  `host` without a `host` rule (a list of accepted names).

## Budgets: per server, or per website

- A **budget of the base** counts **across all websites** — one address that
  floods 50 websites on one server is one flood, and the server is what suffers.
- A **budget inside a `site` block** counts **on that website only** (its name
  in the counter's key): the shop's 120 a minute are the shop's.
- A website can drop a base budget for itself (`no-limit`), e.g. an API whose
  clients are programs with their own limits.

## What else follows the website

- **The browser check's pass** is a cookie of the website's own name — a pass of
  `a.de` is never sent to `b.de`. Nothing changes.
- **The log** may be per website (`set log` inside the block); the base log
  otherwise, each line with the website.
- **Statistics** (0023): every name of a `site` block is a statistics host, so
  `stats-hosts` is rarely needed; groups name sites or hosts.
- **Rules from a CMS** (0021): `include-app` inside a `site` block — the pushed
  rules apply to that website only, within its limits.
- **The rules page and "Rules & setup"**: a switch per website; the rule tester
  takes the website from the address it tests (`https://news.b.de/x` →
  `*.b.de`) and says which block applied.
- **Customers** (0023): later, a customer may see the rules of its own websites,
  read only.

## Cost

- Reading: the files once, as today; one compiled file per website plus the
  name map. 50 websites with a few rules each: about a second of work after an
  edit, once.
- Per request: the name lookup (~1 µs) and a second compiled file — measured about 5 µs in all; the settings load
  as today (one OPcache file). A website's own budgets: counted as any budget.

## Compatibility

A rule file without `site` blocks is the base, alone — exactly today's
behaviour. A file with `site` blocks cannot be read by older versions (an error
names the `site` line).

## Phases

1. `site` blocks, the name map, compiled settings per website, `site-from`,
   `default`; the base/block rules above; tests with nginx-like and Host-based
   names.
2. Budgets per website; logs per website.
3. The rules page, "Rules & setup" and the rule tester per website.
4. With 0023 phase 2: site names as statistics hosts; with 0021: `include-app`
   per website.

## Decisions (2026-10-01)

1. **`site-from server-name` is the default;** `host` for setups that route by it.
2. **Wildcards: one label** (`*.b.de` = `news.b.de`).
3. **A base budget may be dropped per website** (`no-limit` inside a block).
4. **One file or a directory** — `include sites/*.rules` with a `site` block in
   each file.
5. **No `default` block: the base alone;** a `host` rule refuses unknown names.

Phase 1 as built: the reader skips other websites' blocks and reads its own in
place; the base must come first (after the first `site` block only `site`,
`include`, `ids`, `version`); `Settings::load()` compiles the base and every
website together (every file any of them reads is in the base's check), and a
website's settings are loaded with the base's check — one more include from
OPcache. `bin/request-shield check` reads every block and lists them; `trace`
takes the website from its address. Details: [rule files](../features/rule-files.md#site-blocks-rules-per-website).

## Open questions (answered above)

1. **`site-from server-name` as the default?** *Recommendation: yes — safe
   whatever the visitor sends; `host` for setups that route by it.*
2. **Wildcards:** exactly one label (`*.b.de` = `news.b.de`), or any depth?
   *Recommendation: one label; `**.b.de` later if needed.*
3. **A base budget switched off per website** (`no-limit` inside a block)?
   *Recommendation: yes, it is how an API is kept out of the browsers' pace.*
4. **One file, or a directory** (`sites/*.rules`, one file per website,
   `site` taken from a first line)? *Recommendation: both — `include sites/*.rules`
   with a `site` block in each file is the same thing.*
5. **`default` without a block:** the base alone (today's behaviour), or refused?
   *Recommendation: the base alone; a `host` rule refuses unknown names, as
   today.*
