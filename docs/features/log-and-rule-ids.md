# The log and rule IDs

## Rule IDs: which rule decided

Every decision that stopped or flagged a request names the rule behind it —
where it was written:

| ID | Meaning |
|---|---|
| `site.rules:12` | line 12 of a rule file (relative to the main file's directory) |
| `ext/shop/settings/request-shield.rules:2` | an extension's rule file |
| `default @scanners.backups` | a built-in pattern (`@scanners.hidden-files`, `.backups`, `.test-scripts`, `.db-tools`, `.cgi`; `@wordpress.folders`, `.scripts`) |
| `built-in` | a check every site has: sizes, path encoding, traversal; a POST is never cached |
| `blockedPaths[3]`, `budgets.requests` | PHP array settings: the setting and index |

It appears in

- `X-Request-Shield: reject blocked path; rule=site.rules:5` (with
  `set debug-header on`),
- `$_SERVER['REQUEST_SHIELD_RULE']` and `Shield::currentRule()`, for the
  application,
- the log.

`Shield::explain($decision, $request)` finds it by running the matching again —
**only** for a request that was stopped or flagged, so a passing request costs
nothing extra. `bin/request-shield show` lists every rule with its origin.

## The log

```text
set log        /var/log/request-shield/shield.log
set log-level  stop          # stop (default) | flag | all | off
set log-ip     masked        # masked (default) | full
set log-max-size 10M         # then one rotation to shield.log.1
```

One line per request: time, the client address (anonymised by default),
decision, status, reason, rule, the request with its **full URL**, the
User-Agent:

```text
2026-09-29T08:41:03+02:00 198.51.100.0/24 reject 404 "blocked path" rule=default @scanners.hidden-files "GET https://www.example.org/.env" "Mozilla/5.0 ..."
2026-09-29T08:41:07+02:00 198.51.100.0/24 challenge 429 "requests" rule=site.rules:13 "GET https://www.example.org/news?page=4711" "python-requests/2.32"
2026-09-29T08:41:09+02:00 2001:db8:1::/48 reject 403 "restricted" rule=site.rules:25 "GET https://www.example.org//admin/" "curl/8.5"
```

The URL is the one the visitor used: scheme and host as a [trusted
proxy](trusted-proxies.md) reports them, the path and query as sent (not
decoded — `//admin/` and `%61dmin` stay visible).

| Level | Written |
|---|---|
| `stop` | rejected, throttled, challenged — what visitors noticed |
| `flag` | also `allow-uncached` (unknown URLs and parameters, POSTs) and solved challenges |
| `all` | every request — for a short look only: a write per request |
| `off` | nothing |

Budgets counted by the application (`Shield::active()->consume('misses')`)
are logged the same way when they refuse.

- **Addresses are anonymised** by default to their network, and written as
  one (`198.51.100.0/24`, `2001:db8:1::/48`), so nobody mistakes an entry for
  a single client: enough to see a pattern, not a person. `set log-ip full`
  when the log feeds a ban list (fail2ban) — then it holds personal data;
  keep it short and say so in the privacy notice. (The URL can hold personal
  data too — a search term, an e-mail address in a link.)
- **Nothing forged:** request line and User-Agent are shortened, non-printable
  characters become `?`, quotes `'` — a request cannot write a line of its own.
- One line per `write()` with `O_APPEND`: lines of parallel requests do not
  mix. The file is created 0640, its directory 0750.
- **Rotation:** past `log-max-size` (default 10 MB) the file is renamed to
  `.1` (one generation); for more, logrotate with `copytruncate` or `create`.

## Cost

A passing request writes nothing and looks nothing up (unless the level is
`all`). A logged one costs the rule lookup (a few pattern matches), one
`filesize()` and one append — a few microseconds, only for requests the shield
stopped or flagged.
