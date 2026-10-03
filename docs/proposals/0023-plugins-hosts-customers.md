# 0023 — Plugins, statistics per website, and a view for each customer

| | |
|---|---|
| Status | **Phases 1–5 implemented** (2026-10-01): the plugin interface, the statistics as its first plugin; statistics per website; groups and an overview of all websites; access per group; the demo's customer menu and the plugin guide; phase 6 (two packages) accepted, to come |
| Proposed | 2026-10-01 |
| Affects | the core (`Shield::protect()`, `Shield::record()`), the statistics (`Stats`, `Report\*`), the dashboard and its login ([0012](0012-dashboard.md)), the visitors page ([0022](0022-visitors-page.md)), rule files (`plugin`, `stats-hosts`, `stats-group`, `stats-access`), packaging |

## Summary

Three steps that build on each other:

1. **The core stays the mini WAF; everything else is a plugin.** The core
   checks, decides, answers and logs. After that it tells its plugins what
   happened — two hooks, *decided* and *ended*. The **statistics become the
   first plugin**: a package of its own that a site installs when it wants it.
   Anyone can write a plugin on the same two hooks.
2. **Statistics per website.** On a server where 50 sites share one PHP-FPM
   pool, one rule file and one store, every website gets its own numbers — but
   only websites the rules name, because the `Host` header comes from the
   client. Websites are **grouped by customer**: an overview of all of a
   customer's websites, and each one on its own.
3. **Each customer sees only their own.** Access per group with a token whose
   **hash** is in the rule file: an admin sees everything, a customer only their
   group. A customer gets in by logging in once, by a **signed link from their
   own menu** in the hosting panel or CMS, or by the JSON API.

## In one picture

![Requests for many websites reach the request-shield core, the mini WAF, which checks, decides and answers, then tells its plugins: decided, ended. The statistics plugin counts per website, only websites it knows; a made-up host gets no statistics of its own. Websites grouped by customer: Customer A has a.de, www.a.de, b.de; Customer B has c.de. Who sees what: the admin token every website and every view; Customer A's token only its group, never Customer B, never the server's rules and settings. How a customer gets in: a token login once, a signed link from the customer's own menu valid for 10 minutes, or JSON with a bearer token. A customer's menu: the hosting panel makes the signed link on the server; it opens Customer A's statistics.](0023-plugins-hosts-customers.svg)

## 1. The core and its plugins

**What stays in the core:** the checks and rules, the budgets and the store,
the browser check, verifying known crawlers (that is security), the log, and
later the lists and bans of 0013. Nothing in the core needs a plugin.

**The hooks:**

```php
interface Plugin
{
    /** After the decision, before the answer: read only, cannot change it. */
    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now): void;

    /** When the request has ended: the status and headers the site (or the shield) sent. */
    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void;

    /** Its own pages under the dashboard path (/rs/…), or null: not mine. */
    public function page(Request $request, string $path): ?string;
}
```

`Seen` is what the core worked out anyway and a plugin should not redo: the
website (normalised host), the known crawler and whether its address verified
it, the bot family, whether the site's answer was a page (HTML).

- **Registered in the rule file:** `plugin CjwNetwork\RequestShieldStats\Statistics`
  (a class the autoloader finds). A plugin reads its own settings
  (`set stats …` stays as it is — the plugin owns those words).
- **Cost when no plugin is registered:** nothing — the core checks one empty
  list. With plugins: one call each per request (the statistics: as today, a
  few microseconds with APCu). `ended` is only registered as a shutdown function
  when a plugin wants it.
- **A broken plugin never breaks the site:** every call is wrapped; an error is
  logged once a minute and the request goes on.
- **Read only:** a plugin cannot change a decision. (A hook that may *add*
  a refusal — a plugin with its own rules — is a later question, see the end.)
- **Packaging:** `cjw-network/request-shield` (the core) and
  `cjw-network/request-shield-stats` (the statistics; its own version, its own
  changelog). At first both in this repository (`plugins/stats/`), split into two
  packages when the interface has settled.
- **Your own plugins** — for example metrics for a monitoring system, a message
  to a chat channel when refusals pile up, an export of the bans for a firewall:
  a guide (`docs/features/RSF06-04-plugins.md`) with a 40-line example.
- **Compatibility:** `set stats on` without the plugin installed: `check` says
  which package to install, the site keeps running without statistics.

## 2. Statistics per website

```
stats-hosts a.de www.a.de b.de c.de shop.c.de      # or: every name the "host" rule allows
```

- **The website** is the `Host` the request names, normalised: lower case,
  without the port and a trailing dot (`WWW.A.DE:443` → `www.a.de`). `www.a.de`
  and `a.de` are two websites — a group puts them together.
- **Only known websites get statistics of their own;** any other name counts as
  **"other hosts"**. The `Host` header comes from the client: otherwise anyone
  could create unlimited statistics with made-up names.
- **Kept per website** in its own directory (`stats/a.de/`, `stats/b.de/`), the
  same files as today; the APCu names carry the directory already, so websites
  never count into each other. One rule file, one store — for all 50.
- **Cost:** one lookup of the host in a list (~0.1 µs).
- **The server's overview** (admin): all websites summed.

## 3. Groups

```
stats-group "Customer A"  a.de www.a.de b.de
stats-group "Customer B"  c.de shop.c.de
```

- A group's page: the visitors page (0022) **summed over its websites**, with a
  switch at the top: *all websites of Customer A* · a.de · www.a.de · b.de.
- Summing: the counters add up exactly. The lists (pages, sources …) are merged
  and cut again at their limit — exact at the top, approximate at the tail.
  Unique visitors (0022's option) merge exactly: the daily sketches of several
  websites merge like those of several days.
- A website may be in several groups (a reseller's group and the customer's).

## 4. Who sees what

```
stats-access *            sha256:3b4c…   # the admin: everything
stats-access "Customer A" sha256:9f2c…   until 2027-12-31   # the agency
stats-access "Customer A" sha256:71aa…                      # the customer's own
```

- **A token** is 32 random bytes; `bin/request-shield token "Customer A"`
  prints it once and the line to paste. The rule file holds only its
  **SHA-256 hash** — someone who reads the rules cannot get in. (A slow
  password hash is for passwords people choose; a random token of this length
  does not need one.) Compared with `hash_equals()`.
- **Several tokens per group** — to hand one to the agency and one to the
  customer, to rotate them; each may end (`until`) and is withdrawn by deleting
  its line.
- **What a customer's token opens:** only its group — the overview and each of
  its websites, the visitors page, and the protection numbers of its websites.
  **Never** another group, never "Rules & setup" (server paths, every rule, the
  settings), never the server's overview.
- **The admin token** opens everything, with a group switch.

**Three ways in:**

1. **Log in once:** a form (`<dashboard-path>/login`) takes the token by POST and
   sets a **signed session cookie** — HMAC with the shield's secret over the
   group and the expiry, `HttpOnly`, `SameSite=Strict`, `Secure` on HTTPS,
   valid 8 hours (`stats-session 8h`). The token is never in an address, so it
   does not end up in logs, browser histories or `Referer` headers.
2. **A signed link from the customer's own menu:** the hosting panel or the
   customer's CMS makes the link **on its server**, with the shared secret:

   ```php
   $url = Access::link($settings, 'Customer A', 600);   // /rs/stats?g=customer-a&exp=1727800000&sig=…
   ```

   It carries no token, opens only that group, and **expires** (here: 10
   minutes; a copied link stops working). Opened, it sets the session cookie
   and redirects to the address without the signature.
3. **JSON for a customer's own widgets:** `Authorization: Bearer <token>` on
   `<dashboard-path>/api/…` returns only that group's numbers.

**Protection of the door itself:**

- Wrong tokens and signatures count against a budget of their own (10 a minute
  per address, then 429 and the browser check); each is logged — **without**
  the token.
- The login form needs a same-origin `POST` (`Origin` checked); every page is
  read only, so a forged click can change nothing.
- `X-Robots-Tag: noindex`, `Cache-Control: private, no-store`, `Referrer-Policy:
  no-referrer` on every dashboard page; `frame-ancestors` only for addresses the
  rules name (`stats-frame-from https://panel.example.net`), for a panel that
  shows the page in a frame.
- Address rules (`restrict /rs/** to …`) still work and come first: a site can
  allow the customer pages from everywhere and the admin views only from the
  office.

This is the dashboard login of [0012](0012-dashboard.md) (there: one password
for the panel), extended to groups.

## 5. The demo

*Built (phase 5): `/customer-menu` in the demo, `stats-access` with two public
demo tokens, the gate's `admin` option (the demo's own machine is the
administrator, `?rs-login=1` shows the form); the plugin guide in
[plugins](../features/RSF06-04-plugins.md#the-statistics-plugin-a-plugin-with-pages-of-its-own).
The rule tester's "other hosts" example is left to the rules page.*

Once 2–4 exist, the demo shows them with two websites it answers to anyway
(`localhost84` and `127.0.0.1`):

- `stats-group "Customer A" localhost84` and `stats-group "Customer B" 127.0.0.1`;
- a page **"a customer's menu"** — a pretend hosting panel with a menu item
  "Statistics" whose link is made with `Access::link()`: it opens Customer A's
  statistics, and only those;
- the login form with a demo token printed on the demo page (a demo only);
- the rule tester shows a request with a made-up host counting as "other hosts".

## Cost

- No plugin registered: nothing. Statistics as a plugin: as today (a few µs a
  request with APCu), plus ~0.1 µs for the website's name.
- A customer's page: like the visitors page (4–6 ms) times the websites of the
  group for the reading, plus one HMAC for the cookie (~1 µs).

## Phases

1. **The plugin interface** in the core, the statistics moved behind it (same
   repository, `plugins/stats/`), every test as before.
2. **Statistics per website** (`stats-hosts`, "other hosts", a directory each).
3. **Groups** (`stats-group`, the summed page with its website switch).
4. **Access** (`stats-access`, tokens and their hash, the login, signed links,
   the bearer API, the protection of the door).
5. **The demo's customer menu**; the plugin guide.
6. **Two packages** (`request-shield` and `request-shield-stats`).

## Decisions (2026-10-01)

1. **One repository** with `plugins/stats/`, until the interface has settled.
2. **Plugins are read only** in the first version.
3. **A customer sees** the numbers of its websites and the pages stopped; which
   rules decided stays with the admin.
4. **Sessions** last 8 hours, configurable.
5. **Signed links** last 10 minutes by default, at most an hour.
6. **Groups for the protection settings** (a customer's own rules) later — 0021
   per host first.

Phase 1 as built: `CjwNetwork\RequestShield\Plugin` with `decided()` and
`ended()`; `decided()` also gets `$continues` (true: the site answers, `ended()`
follows) — the page hook of the sketch above comes with phase 4, when the
plugin's own pages need it. `Seen` as described, lazily. `plugin <class>` and
`'plugins' => […]`; the statistics registered by `set stats on`. Details:
[plugins](../features/RSF06-04-plugins.md), [ADR 0006](../adr/0006-core-and-plugins.md).

## Open questions (answered above)

1. **One repository with `plugins/stats/`, or two from the start?**
   *Recommendation: one, until the interface has settled.*
2. **May a plugin add a refusal** (its own rules), or are plugins read only?
   *Recommendation: read only in the first version.*
3. **What does a customer see of the protection** — the numbers of its websites
   (stopped, not found), or also which rules decided? *Recommendation: the
   numbers and the pages stopped; the rules stay with the admin.*
4. **Session length:** 8 hours? *Recommendation: 8 hours, configurable.*
5. **Signed links:** 10 minutes by default? *Recommendation: yes, at most an
   hour.*
6. **Groups also for the protection settings** (a customer's own rules for its
   websites)? *Recommendation: later — 0021 (rules from the CMS) per host first.*

Phase 2 as built: `set stats-hosts <names>` (also `host`: the host rule's
names, `sites`: the site blocks'; `*.domain` one label deep, as site blocks),
above the site blocks. `Stats::siteOf()` (a `WeakMap` per settings: 0.15–0.35
µs with 51 names), `Stats::of($settings, $site)` (`stats/hosts/<name>/`,
`stats/hosts/(other)/`), `Stats::all()`/`readAll()` (all websites added up, with
what was counted before in `stats/`), `StatsReport::read()` and the option
`site`; the pages' website switch, kept in every link and the JSON;
`bin/request-shield stats --site=`. A quiet website's hour is rolled up by the
first request of each hour in each process (`Stats::tend()`). The demo counts
`localhost84` and `127.0.0.1` apart.

Phase 3 as built: `stats-group "<name>" <websites>` (quotes for a name with
spaces; an ID made from it, `customer-a`; a website in several groups; its
websites counted apart without naming them in `stats-hosts`; above the site
blocks). `Stats::all($s, 'group:<id>')`, `Stats::known()`; the website switch
with a section per group; `bin/request-shield stats --group=`. **Added on
request:** an overview of all websites (`/rs/sites`, the first tab with
`stats-hosts`): every group with its websites below it, the rest, the other
hosts -- page views with a bar, the change against the period before, people,
crawlers, bots, stopped, not found, a small curve; sorted by page views; a
click opens one (`StatsReport::sites()`).

Phase 4 as built: `stats-access "<group>"|* sha256:<hash> [until <day>]`,
`set stats-session` (8 hours), `bin/request-shield token`. `Access::gate()`
(the form by POST with `Origin` checked, a signed session cookie with a
generation: a token added or removed ends the group's logins; signed links
`Access::link()`, 10 minutes, at most an hour; `Authorization: Bearer`;
10 wrong tries a minute per address, then 429; logged without the token;
`?rs-logout=1`), `Access::site()`, `Access::links()`; `StatsPage` takes `who`
and enforces it itself: a customer's views, tabs, website switch and overview
show its group only, the protection without its rules, never Rules & setup or
the server's overview. **One change from the sketch:** the cookie is
`SameSite=Lax`, not `Strict`: with `Strict`, the cookie set after a signed link
from another site (the panel) would not be sent on the redirect. The pages
are read-only, so `Lax` gives nothing away.

