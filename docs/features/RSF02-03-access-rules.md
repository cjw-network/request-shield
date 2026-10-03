# RSF02-03 Access rules: paths by address, methods by path

## What it does

Two rules that close doors before the application opens them:

- **`restrict <paths> to <addresses or ranges>`** — an admin area or an
  internal API only for some addresses; everyone else gets **403**.
- **`allow <METHODS> <paths>`** — a method only where the site expects it: a
  POST only where the forms are, a PUT or DELETE only on the API; anywhere else
  **405**. Bots that post to every URL they find are turned away before the
  application parses a single form field.

```text
restrict    /admin/**  /setup/**  to 192.0.2.0/24 2001:db8:1::/48
restrict    /api/internal/**      to 10.0.0.0/8
allow       POST  /contact  /user/login  /edit/**
allow       PUT DELETE  /api/**
```

As PHP settings:

```php
'restricted' => [['paths' => ['#^/admin(?:/.*)?$#i'], 'ips' => ['192.0.2.0/24']]],
'methodPaths' => ['POST' => ['#^/contact$#i', '#^/edit(?:/.*)?$#i']],
```

## Exceptions: an admin's file reader

A file manager in the admin area has to open what the shield refuses
everywhere else — `.env`, `.git/config`, `backup.sql`. `unblock … at` lifts
the blocks at some paths only, and `for` only for some addresses:

```text
# nothing blocked in the file reader, only from the office
unblock at /admin/files/** for 192.0.2.0/24 2001:db8:1::/48

# finer: only hidden files and backups, only there
unblock [SCAN-HIDDEN] [SCAN-BACKUP] at /admin/files/** for 192.0.2.0/24
```

As PHP settings: `'blockExceptions' => [['paths' => ['#^/admin/files(?:/.*)?$#i'],
'patterns' => null, 'ips' => ['192.0.2.0/24']]]` (`patterns`: entries of
`blockedPaths`, `null` for all).

- **Never lifted:** the path check (`/../`, `%2e%2e`, disguised paths) — a file
  reader is exactly where `../../config.php` is tried — nor `restrict`, the
  budgets or the browser check.
- **Without `for` the exception is for everyone.** `bin/request-shield check`
  warns about it, and the [rules page](RSF06-01-active-rules-page.md) marks it; use it
  only where the application itself admits nobody but admins.
- Matched on the path as the application routes it, like the other access
  rules; the exceptions are looked at only once a block matched, so a normal
  request pays nothing.
- The step-by-step check shows it: *"would be refused (hidden files …), but
  open here for 192.0.2.5 — SITE-FILES"*.

## Details

- **The client address** is the one a [trusted proxy](RSF01-01-trusted-proxies.md)
  vouches for (`X-Forwarded-For` from `trust`ed addresses only), otherwise the
  peer — never a header anyone could send.
- **The path as the application routes it** (`Request::matchPath()`):
  percent-decoded, `//` and `/./` collapsed; the patterns a rule file writes
  ignore case. So `//admin/`, `/%61dmin/`, `/ADMIN/` and `/./admin/` are all
  `/admin/`. (`/../` never gets this far: it is a [hard reject](RSF02-01-hard-rejects.md).)
- `allow` also adds the methods to `method` (allowed at all); methods without
  an `allow` line are not restricted by path.
- Several `restrict` lines are independent; the first whose paths match
  decides. A request from an allowed address continues through the other
  checks (budgets, challenge) as usual.
- The checks run only when configured: a site without them pays nothing.

## Limits

- The paths are the URL's paths. An application reachable under several
  spellings (`/index.php/admin` as well as `/admin`) needs both, or a pattern
  like `**/admin/**`.
- 403 says that something is there. Where that matters, a `block` (404) for
  everyone plus access through a VPN or the web server's own rules is the
  stronger choice.
- Address rules are only as good as the address: behind a proxy that is not
  listed in `trust`, every client has the proxy's address.

## Cost

A regular expression per configured pattern, only for requests whose method
has an `allow` list or while `restrict` rules exist — well under a
microsecond for a handful of patterns.
