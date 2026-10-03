# 0027 — Protected areas: passwords, one-time codes, from the application

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-01 |
| Affects | rule files (new rule `protect`, `restrict … or`), the store (codes), the shield's own pages (a small form), the command line (`password`, `code`), the dashboard's lists page ([0026](0026-live-view-and-lists.md)), the log and the live view |

## Summary

Parts of a website that should not be public, but have no login of their
own: a preview of the new design, files for the press, a staging area, the
download of an order. Today a site protects them with `.htaccess` and Basic
Auth, or with `restrict` to the office's addresses.

This proposal lets the shield protect an area **with a password or a code**,
before the application runs:

- **Passwords** in the rule file, as hashes only.
- **Codes** that the application, the command line or the dashboard issues.
  Each is valid **for a time** and/or **a number of uses** (one-time codes),
  and has a note.
- **Addresses or a password:** the office gets in without one, everyone
  else with one.
- A small form of the shield's own instead of the browser's Basic Auth
  dialog. A right password or code sets a **signed cookie** for the area.
- **Brute force** is counted, paused, checked and, with bans, banned. Every
  attempt shows up in the live view.

Protecting **parts of a website by address range** exists already
(`restrict <paths> to <ranges>`). It gains `or a password` and, optionally,
times of day.

## In one picture

![A request for a protected path: an address the rule lets in goes through; a valid area cookie goes through; anything else gets the shield's own small form, which takes a password (a hash in the rule file) or a code (issued by the application, the command line or the dashboard, valid for a time and a number of uses, its hash kept in the store). Right: the code's use counted, a cookie for the area, on to the page. Wrong: counted, a pause and the browser check, with bans a ban.](0027-protected-areas.svg)

## Motivation

- `.htaccess` Basic Auth: a password file on the server (often in the web
  root), the browser's dialog, no expiry, no one-time use, no protection
  against guessing, nothing the application can hand out. And on Plesk with
  nginx in front it does not cover static files.
- "Send the customer a link that works for 24 hours, once" is common: press
  kits, invoices, a preview for one client. The application knows who should
  get in; the shield can keep the door.
- `restrict` refuses everybody outside the office. Often "the office without
  a password, everyone else with one" is what is wanted.

## Design

### The rules

```text
[A-INTERN]  protect /intern/** with password sha256:9f2c… or 192.0.2.0/24    # the office without, the agency with
[A-PREVIEW] protect /preview/** with password $2y$12$… for 8h                # one password, a cookie for 8 hours
[A-FILES]   protect /download/** with code for 1h                            # codes issued by the application
[A-STAGE]   protect /stage/** with password … or code                         # either
[A-ADMIN]   restrict /admin/** to 192.0.2.0/24 or password $2y$12$…           # restrict, with a way in from elsewhere
[A-OFFICE]  restrict /intern/** to 192.0.2.0/24 at mon-fri 07:00-19:00        # times (optional, phase 3)
```

- `protect <paths> with <ways> [for <time>]`. The ways are `password <hash>`
  (several allowed), `code`, and addresses or ranges, joined with `or`.
  `for` sets how long the area's cookie lasts (default 8 hours, at most 30 days).
- **Passwords** are hashed: `$2y$…` / `$argon2id$…` (`password_hash()`) for
  passwords people choose, `sha256:…` for long random ones (a token, not a
  word). `bin/request-shield password` asks for one and prints the line to
  paste. **The rule file never holds a password.**
- In `match` and `site` blocks like any rule (per area, per website).
- `monitor protect …` logs who would have been asked, and lets them through
  (try it first, as with the other rules).

### The way in

- **The form:** the shield answers a protected path with a small page of its
  own (like the check page, in the visitor's language, the site's logo, `home`):
  "This area is protected", a field, a button. Nothing of the site's page is
  sent. POST only from the same site (`Origin`), only over HTTPS (refused over
  plain HTTP unless `set protect-http on` for a test machine).
- **A link with a code:** `https://example.org/download/report.pdf?rs-code=K7…`.
  The code is checked, **taken out of the address** with a redirect, and
  masked in the log and the live view (`rs-code=…`). So it does not end up in
  logs, the browser history or `Referer`.
- **Right:** a cookie `rs_area_<id>`: HMAC with the shield's secret over the
  area, its end, and a **generation** (changed with the area's passwords:
  changing a password ends every cookie of that area). `HttpOnly`,
  `SameSite=Lax`, `Secure`, path the area's. Then a redirect to the page asked
  for.
- **Wrong:** "not right". Each attempt counts against a budget of its own
  (`access`, 10 a minute per address): past it, a pause and the browser check.
  The bans of [0013](0013-ip-lists.md) can take it as a signal
  (`ban after 20 access in 10m for 1h`). Logged with the area and the
  address, **never the password or the code**.
- **APIs:** `Authorization: Bearer <code>` on an area marked `api-path` gets
  401 with JSON instead of the form.

### Codes: issued by the application

```php
use CjwNetwork\RequestShield\Access;

$code = Access::issue($settings, 'A-FILES', for: 86400, uses: 1, note: 'order 4711');
$url  = 'https://example.org/download/4711.pdf?rs-code=' . $code;   // in the mail to the customer
Access::revoke($settings, $code);                                   // when the order is cancelled
```

```text
request-shield code site.rules A-FILES --for=1d --uses=5 --note="press kit"
request-shield codes site.rules            # the active ones: area, note, uses left, until
request-shield code site.rules --revoke=K7…
```

- **A code is 128 random bits** (Base32, 26 characters; optionally a short
  form for typing, `XXXX-XXXX-XXXX`, with fewer bits and a stricter budget).
- **The store keeps** the SHA-256 of the code, the area, until when, uses left,
  the note — **never the code itself**. It ends by itself. Codes have to
  survive a restart, so they are kept in files in store-dir (as `ban-keep file`),
  and APCu only caches.
- **Uses:** counted when the code is taken (not per request: the cookie
  carries the visitor afterwards). `uses: 1` makes a one-time code.
- **The dashboard** (0026's lists page) gets a tab **Codes**: issue one (area,
  for how long, how many uses, the note), the active ones, revoke. The live view
  shows "a code for A-FILES taken (order 4711)".

### The application asks: a header (or one line of PHP)

The rules name the areas; **which page belongs to one, the application can
decide**. An editor ticks "protected" on a page or a section in the CMS, and
the template says so, without touching a rule file. This is the way
[0006](0006-the-site-asks-for-the-check.md) works for the browser check
(`X-RS-Check: 1`), now for a password or a code:

```text
[A-PREVIEW] protect area with password $2y$12$… or code for 8h     # an area without paths: only when the application asks
```

```php
// In the template of a page marked "protected" (needs set app-challenge on):
header('X-RS-Access: A-PREVIEW');

// Or before rendering, cheaper (the page is not rendered twice; a POST is sent again after the login):
Shield::active()?->requireAccess('A-PREVIEW');
```

- **The header names an area of the rule file, nothing else.** A password,
  a hash or a code never travel in it. The shield removes it before the answer
  leaves.
- **A valid cookie for the area:** the page goes through as rendered.
- **None:** the rendered page is thrown away (it was held back, as for the
  check header), and the shield's form answers instead. Right → the cookie,
  and a redirect to the same address: the application renders the page again,
  says so again, and this time it goes through.
- **An area the rules do not know:** 403, logged. The door fails closed, so a
  typo in a template never opens anything.
- **Codes** the application issues for that area (`Access::issue($settings,
  'A-PREVIEW', …)`) are taken on the same form or by link (`?rs-code=…`).
- **The cost:** a page that asks is held back until it is finished (only with
  `set app-challenge on`); without a cookie it is rendered twice. That is why
  `requireAccess()` is the better way where the application knows early.
- **Limits:** the page must not be sent early (no `flush()` before the end);
  files the web server serves itself never reach PHP; a cache in front of the
  site must not keep such a page (the shield marks the form `no-store`, and a
  protected page should send `Cache-Control: private`).

### Parts of a website by address (exists, extended)

- `restrict <paths> to <ranges>` exists: everyone else gets 403.
- **New:** `… or password <hash>` / `… or code`. Instead of 403, the form,
  for everyone outside the ranges.
- **New, phase 3:** `at <days> <hh:mm>-<hh:mm>` in the server's time zone (the
  office only during office hours; outside, nobody, or the password).

## Security

- Hashes only in the rule file; codes only as hashes in the store; nothing of
  either in the log, the statistics or the live view.
- Constant-time comparisons (`hash_equals()`, `password_verify()`).
- Guessing: the `access` budget per address (and per area), the browser
  check, bans. A short code gets a stricter budget.
- The cookie is no session: it says "this browser may enter area X until T".
  Logging out is deleting it (a link on the form page). A stolen cookie works
  until it ends, as Basic Auth credentials would. Hence `for` is short by
  default, and changing the password ends them all.
- **Not a replacement for the application's users and roles.** For areas
  that have no login of their own. The application keeps deciding who may do
  what inside.
- Static files: protected only where PHP sees the request (front controller,
  `auto_prepend_file`). Files the web server serves directly need the web
  server's own rule, or a route through PHP. The docs say so plainly.

## Cost

| | |
|---|---|
| a request outside protected paths | one path match, as for `restrict` (~0.1 µs per area) |
| inside, with a cookie | one HMAC (~1–2 µs) |
| the form, a password | `password_verify()` (bcrypt cost 12: ~250 ms, only for an attempt, counted) |
| a code | one SHA-256 and one store lookup |

## Privacy

- A code's note is written by people: "why, not who" (the order, not the
  customer's name).
- The log keeps attempts with the address as it keeps every refusal (masked
  by default). Codes and passwords are never logged.

## Phases

1. `protect … with password` (the form, the cookie, the budget, the log, the
   live view); `bin/request-shield password`; `restrict … or password`; areas
   without paths that the application asks for (`X-RS-Access`,
   `requireAccess()`).
2. Codes: `Access::issue()/revoke()`, `request-shield code/codes`, the store
   (files), `?rs-code=` taken out of the address; the dashboard's Codes tab.
3. Times of day for `restrict`/`protect`; `Authorization: Bearer` for APIs.

## Open questions

1. **The form or Basic Auth?** *Recommendation: the form (it can say what is
   going on, works with codes and links, logs out); Basic Auth as an option
   (`with basic …`) for tools that only know that.*
2. **Where codes are kept:** files in store-dir (survive restarts) or APCu
   only? *Recommendation: files, APCu as a cache.*
3. **How long a cookie lasts by default:** *Recommendation: 8 hours, at most
   30 days, per area with `for`.*
4. **Short codes for typing** (`XXXX-XXXX-XXXX`, ~60 bits): allowed?
   *Recommendation: yes, with a stricter budget (3 wrong a minute per
   address and area), long ones for links.*
5. **The header names an area only** (proposed), or may it carry a
   password hash of the application's own? *Recommendation: the area only:
   anything that could be a credential stays out of headers, and the rule
   file stays the one place the passwords' hashes are.*
6. **Per user?** A code with a user ID the application can read back
   (`Shield::active()->area()['note']`). *Recommendation: the note only, read
   back by the application if it wants; no user accounts in the shield.*
