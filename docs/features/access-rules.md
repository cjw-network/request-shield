# Access rules: paths by address, methods by path

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

## Details

- **The client address** is the one a [trusted proxy](trusted-proxies.md)
  vouches for (`X-Forwarded-For` from `trust`ed addresses only), otherwise the
  peer — never a header anyone could send.
- **The path as the application routes it** (`Request::matchPath()`):
  percent-decoded, `//` and `/./` collapsed; the patterns a rule file writes
  ignore case. So `//admin/`, `/%61dmin/`, `/ADMIN/` and `/./admin/` are all
  `/admin/`. (`/../` never gets this far: it is a [hard reject](hard-rejects.md).)
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
