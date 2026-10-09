# 0047 — The admin pages: an area per plugin, and a login by default

| | |
|---|---|
| Status | **Draft** -- the cache's page built (a tab today, `/rs/cache`; the area when part 1 is built) |
| Proposed | 2026-10-09 |
| Affects | `Routes` (a group per route), `Frame::tabs()` (two rows), `Dashboard::serve()` and `Access::gate()` (a login always asked), the extensions' `routes()`, `Cli` (`check`, `init`, `access-token`), new core pages *Start* and *System* |
| Relates to | [RSF06-01 rules page](../features/RSF06-01-active-rules-page.md) · [RSF06-02 live and lists](../features/RSF06-02-live-and-lists.md) · [RSF06-03 statistics: who sees what](../features/RSF06-03-statistics.md#who-sees-what-tokens-a-login-signed-links) · [RSF06-04 plugins](../features/RSF06-04-plugins.md) · [RSF06-05 API](../features/RSF06-05-api.md) · [0012 dashboard](0012-dashboard.md) · [0023 plugins, hosts, customers](0023-plugins-hosts-customers.md) · [0031 robust core, plugins](0031-robust-core-plugins.md) · [0045 own files out of reach](0045-own-files-out-of-reach.md) |

## The question

*"Würde es nicht Sinn machen, das Menü im Admin-Dashboard nach Plugins
einzuteilen -- Startseite, WAF, Stats … mit jeweiligen Unterpunkten?"* and
*"Das Admin-Backend sollte standardmäßig mit Passwort geschützt sein."*
(owner, 2026-10-09)

Two parts, one proposal: they meet on the same pages (the *Start* page, the
*System* area with sign-out and sessions) and in the same code
(`Dashboard::serve()`).

## Part 1 — An area per plugin

### Today

Every page with a tab sits in **one flat row**, ordered by the routes'
`order` (`Routes::tabs()`):

```
All websites | Dashboard* | Visitors & pages | Protection | Rules & setup | Live | Lists
└──────────────────── stats ──────────────────────────┘ └─────────── waf ───────────┘
```

(* the statistics' overview, `/rs/stats/overview`, for the admin only)

- Nobody sees which page belongs to which plugin; "Dashboard" is the
  statistics' overview, not a start page of the whole.
- What belongs together lies apart: the statistics' *Protection* and the
  WAF's *Rules / Live / Lists*.
- The cache and the API have no page at all; the rule advisor (0016:
  `learn`, `advise`, `replay`) and `check` only a command line.
- Every new plugin makes the row longer.

The routes already carry their plugin (`ext` in `Routes::entry()`), so two
levels need almost no new data.

### Proposed

```
┌─────────────────────────────────────────────────────────────────────┐
│ Start   Protection (WAF)   Statistics   Cache   API   System        │  ← row 1: areas
├─────────────────────────────────────────────────────────────────────┤
│ Live · Blocks & lists · Rules & setup · Learning                    │  ← row 2: the area's pages
└─────────────────────────────────────────────────────────────────────┘
```

| Area | From | Pages |
|---|---|---|
| **Start** | core | a card per plugin that is on (its numbers, its state, its warnings); the hosting tier (S0/S1/S2), the version, `check`'s findings |
| **Protection** | waf | Live · Blocks & lists · Rules & setup · Learning (0016: a recording, `advise`, `replay`) |
| **Statistics** | stats | All websites · Overview · Visitors & pages · What the protection did · Response times (0046) |
| **Cache** | cache | Hit rate · Roles · Purge (address, tag) · Memory (0031 G.4 part 3: APCu, the disk's cap) |
| **API** | api | Tokens · Endpoints (OpenAPI) · Writes on record |
| **System** | core | Settings & tier · Files and modes (0045, `file-mode`) · Version & update · Sign-in: sessions, sign out everywhere (part 2) · Help |

The rules:

- **An area shows only when its plugin is loaded and has a page the reader may
  open.** The mini edition shows *Start* and *System*; a customer (a reader of
  0023) typically sees only *Statistics* -- with one area, row 1 is left out.
- **An area with one page gets no second row.**
- **The core owns *Start* and *System*.** A plugin may give *Start* a card
  through an optional capability (a method such as `startCard()`); without
  one, its card is a link to its area.
- **Addresses stay** (`/rs/stats/visitors`, `/rs/waf/live`): only the frame
  changes, no link outside breaks.

### What changes in the code

- A route entry gets an optional `group` (a string); by default its
  extension's id, `core` without one. `Routes::compile()` checks it as the
  other fields.
- An extension names its area: a label `[English, German]` and an order
  (the areas' order; the pages' `order` stays the order inside an area).
- `Routes::tabs()` returns the tabs per area; `Frame::tabs()` draws one or two
  rows, the current area and page marked; on a narrow screen row 1 scrolls
  sideways, row 2 wraps.
- New: the *Start* page and the *System* page (core routes below
  `dashboard-path`, `/rs/` and `/rs/system`).

Only the dashboard's pages draw a frame: **nothing on the passing request's
path changes** (the routes are compiled already, the group is one more field).

## Part 2 — A login by default

### Today

| Set up | What happens |
|---|---|
| no `restrict`, no `dashboard-access` | **403**, the page says what is missing (fail-safe, but nobody gets in) |
| only `restrict /rs/** to <address>` | **the full admin without signing in** -- the address alone |
| `dashboard-access` (tokens) | the login form, a signed cookie `rsd` for `dashboard-session` (8 h), 10 wrong tries a minute per address, then 429 |

The second line is the gap: whoever shares the allowed address -- an office
network, a shared proxy, another customer on the same server -- is the admin.

### Proposed

```
request for /rs/…
      │
  a restrict rule over it, and the address outside? ── yes ──▶ 403 (the address layer)
      │ no (the address inside, or no restrict at all)
  signed in (rsd cookie, Bearer token, signed link)? ── yes ──▶ the page
      │ no
  a login set up? ── no ──▶ "set up": the CLI line, or the setup code (below)
      │ yes
  the login form (429 after 10 wrong tries a minute)
```

1. **A login is always asked.** `restrict` becomes an *additional* layer (the
   address *and* a login), no longer a way around it. Without a login only by
   saying so: `set dashboard-login off`, accepted only with a `restrict` rule
   over `dashboard-path`; `check` warns while it is off.
2. **The first admin without a door for strangers** -- never "who comes first
   becomes the admin":
   - **CLI:** `request-shield init` (and `access-token --admin`) makes the
     admin's access, prints it **once** and keeps only its hash, as the
     tokens today.
   - **A host without a shell:** the first visit to an unset dashboard writes
     a one-time setup code into the store (mode `file-mode`, `0600`; outside
     the document root, 0045). The page asks for it; only who can read the
     server's files (FTP, SSH, the host's file manager) has it. With it, the
     admin sets a password. The code is deleted after use and ends after an
     hour; a new one is written only when the old one is gone.
3. **A password for people, tokens for programs.** Today one signs in with a
   32-byte token -- a very strong password, but not one a person keeps in mind.
   A password the person chooses is added:
   - `dashboard-password * <hash>` in the rule file, made by
     `request-shield password` (it asks twice, never on the command line);
     `password_hash()` (Argon2id where PHP has it, bcrypt otherwise),
     `password_verify()`, `password_needs_rehash()` on sign-in;
   - at least 12 characters; checked only at sign-in -- the slow hash costs
     nothing on any other request;
   - wrong tries count per address (as today) **and per account** (a budget
     of its own, so a botnet spread over many addresses is slowed too); the
     account's budget only **slows** the form (a growing delay, ending on its
     own after minutes) and never locks the admin out: a valid `rsd` session,
     a token and a signed link still pass, and a right password from an
     address without wrong tries is checked after the delay;
   - tokens stay for the API and scripts, signed links for a customer's panel
     (0023).
4. **The session** stays the signed `rsd` cookie: HttpOnly, Secure on HTTPS,
   `SameSite=Lax` (a signed link arrives from another site, so not Strict;
   the form's POST is checked by `Origin` already). New: the cookie carries a
   generation number kept in the store; *System → Sign in → Sign out
   everywhere* raises it, so every running session ends, not only the
   browser's own.
5. **The site's own administrator** (`Access::gate(…, ['admin' => true])`,
   the site's own login) stays: a site that has a login of its own needs no
   second one.

### What it costs

- Only the dashboard's pages: the gate runs only when a route matched.
- The setup code: one file, written only while the dashboard has no login and
  someone opens it -- never on a passing request.
- The password hash: one `password_verify()` per sign-in (tens of
  milliseconds by design), none afterwards.

## What stays as it is

- The pages' addresses, the API's paths and their roles (admin / reader).
- Tokens (`dashboard-access`, `until`, several per group), signed links,
  `Authorization: Bearer`, the 429 budget per address.
- The page without any guard set up still answers 403 -- only its text now
  names the two ways to set one up.
- No backward compatibility is owed (0031): nothing is live.

## Tests (when built)

- Part 1: a registry with stats only → one area, no row 1; stats + waf → two
  areas, the current one marked; a reader sees only areas with a reader page;
  a route with `group` lands in that area; a wrong `group` (not a string) is
  refused at compile time; the mini edition shows Start and System.
- Part 1: every area's page links to a heading (`check-anchors`); a `?` on
  every page (F.9's contract).
- Part 2: `restrict` alone → the login form (fails today: the page opens);
  `dashboard-login off` with `restrict` → the page; without `restrict` →
  refused at compile time; `check` warns.
- Part 2: the setup code -- written once, `0600`, used once, gone after an
  hour; a wrong code counts against the budget; two parallel first visits
  write one code.
- Part 2: a password -- right, wrong, rehash on a weaker stored hash; the
  account's budget after N wrong tries from N addresses slows the form, ends
  on its own, and never stops a valid session, token or signed link; *sign
  out everywhere* ends a second browser's session.
- End to end under PHP-FPM: an unset dashboard → setup code → password →
  signed in → sign out everywhere.
- Bench: the passing request unchanged; a dashboard page within noise.

## Open questions for the owner

1. **The statistics' *What the protection did*** (a page of the stats plugin):
   stay under *Statistics*, or move to *Protection* by its `group`?
   *Proposed: stay, with a link from Protection's Live page.*
2. **The first page after signing in:** *Start* for the admin? A reader goes
   to its statistics as today. *Proposed: yes.*
3. **Cache and API:** pages of their own now, or first only a card on *Start*?
   *Proposed: the card first; the cache's page with G.4 part 3.*
4. **The menu:** two rows at the top, or a sidebar on the left?
   *Proposed: two rows -- close to today, simple on a phone.*
5. **A password as well as tokens**, or the token only, called a password?
   *Proposed: both -- the password for people, tokens for programs.*
6. **`restrict` without a login** (`dashboard-login off`): allowed at all, for
   a developer's machine? *Proposed: yes, with `check`'s warning.*
7. **The setup code as a file** for hosts without a shell, or the CLI only?
   *Proposed: both.*
8. **A second factor (TOTP)** now, or a draft of its own later?
   *Proposed: later; the session's generation number makes room for it.*
