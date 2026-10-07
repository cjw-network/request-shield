# 0043 — The shield's decision in the web server's access log

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-07 |
| Affects | the response headers (a header meant for the server's log, not for the visitor), the settings (`set server-log`), the docs (Apache, nginx, LiteSpeed snippets), `check` |
| Relates to | [RSF05-05 the log and rule IDs](../features/RSF05-05-log-and-rule-ids.md) · [RSF05-03 modes](../features/RSF05-03-modes.md) · [0030 error pages](0030-error-pages.md) (the reference) · [0033 event log](0033-event-log.md) |

## The idea

The shield writes its own log (`set log`), by default only what it stopped,
checked or slowed down. The web server writes an access log anyway -- every
request, with status, size, time and User-Agent, rotated by the system, read
by the tools a hoster already has (GoAccess, AWStats, fail2ban, a SIEM).

If the access log also carried **what the shield decided and by which rule**,
one line would tell the whole story of a request:

```text
203.0.113.50 - - [07/Oct/2026:23:09:33 +0200] "GET /?q=1%27%20UNION… HTTP/1.1" 403 1874 "-" "python-requests/2.32" rs="reject attack ATK-SQL-UNION SKVD-YB3R"
198.51.100.7 - - [07/Oct/2026:23:09:34 +0200] "GET /news HTTP/1.1" 200 18233 "-" "Mozilla/5.0 …" rs="-"
```

## Is it sensible?

**Yes -- as an option, with the header kept from the visitor.**

What it brings:

- **One log for everything:** the shield's decision next to what the web
  server knows and the shield does not -- bytes sent, time taken, the
  application's own status (a 500 after the shield let a request through).
- **Every request at no extra cost:** the shield's own log at `log-level
  all` is a write per request; the access log is written anyway. The shield
  only adds a header.
- **The tools that are already there:** fail2ban can ban at the firewall from
  `rs="reject …"` lines; GoAccess and AWStats can count by the field; a
  hoster's log shipping takes it along without a new file to collect.
- **The reference** (`ref=`, the code on the refusal page, [0030](0030-error-pages.md))
  joins a visitor's complaint to the access log line, not only to the
  shield's own.

What speaks against it, and how it is met:

- **The visitor must not see it.** A header that says "refused by
  ATK-SQL-UNION" tells an attacker which rule to work around. Today's
  `X-RS` (`set debug-header on`) is for testing for exactly that reason. So
  the new header is a different one, and every snippet below **takes it out
  of the answer after the server noted it**. The shield cannot see the
  server's configuration; `check` says so when the setting is on, and the
  docs' snippets always come in pairs (note, then unset).
- **Static files** never reach PHP: their lines say `rs="-"`, as a request
  the shield let through without a header. That is honest -- the shield did
  not see them.
- **The passing path:** a `header()` call is about a microsecond. With the
  default level (`stop`: only what was stopped, checked or slowed down) a
  request that passes pays one comparison and no header -- nothing, as
  AGENTS.md asks of a feature that is not in use.
- **Several layers:** with a Varnish or CDN in front, the header is noted by
  the server that runs PHP; the outer layer never sees it if it is unset
  there.

## How

### In the shield

```text
set server-log header            # off (default) | header | note
set server-log-level stop        # stop (default) | flag | all -- as log-level
```

- **`header`:** the answer carries `X-RS-Log: <action> <reason> <rule> <ref>`,
  each part a word (`-` when there is none), at most 120 bytes:
  `reject attack ATK-SQL-UNION SKVD-YB3R`, `challenge requests SHOW-PACE -`,
  `monitor-reject blocked-path SCAN-HIDDEN -`. Short, and each part one word:
  a reason or a budget name with spaces (`blocked path`, `method not allowed
  here`) gets `-` for them, and `"`, control characters and anything outside
  visible ASCII are left out -- so a log parser splits the value at spaces
  and it cannot break the quoted field it stands in.
- **`note`:** with Apache's mod_php the shield calls `apache_note('rs', …)`
  instead -- no header at all, nothing to unset. `check` says when `note` is
  set but the server is not mod_php (the value then goes nowhere).
- Not the existing `X-RS`: that stays the test header, sent to the visitor on
  purpose.

### In the web server

**Apache with PHP-FPM, or mod_php** -- note the header, then take it out:

```apache
# mod_headers: copy the shield's header into a note, then remove it from the answer
Header always note X-RS-Log rs          # "always": a 200 and a refusal alike
Header unset X-RS-Log
Header always unset X-RS-Log
LogFormat "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\" rs=\"%{rs}n\"" combined_rs
CustomLog ${APACHE_LOG_DIR}/access.log combined_rs
```

`Header note` exists for exactly this (mod_headers: *"useful if a header
sent by a CGI or proxied resource is configured to be unset but should also
be logged"*). **Tried** on Apache 2.4.41 with a CGI that sends the header
with a 200 and with a 403:

| Configuration | The visitor sees `X-RS-Log` | The access log |
|---|---|---|
| no `Header` lines | yes -- the leak | `rs="-"` |
| `Header note` + both unsets | no | `rs="-"` (nothing noted) |
| `Header note` **and** `Header always note` + both unsets | no | `rs="-"` -- the second overwrites the first |
| **`Header always note` + both unsets** | **no** | **`rs="challenge requests SHOW-PACE -"`, `rs="reject attack ATK-SQL-UNION SKVD-YB3R"`** |

So: `always note`, once. PHP-FPM behind `proxy_fcgi` is to be tried the same
way in the end-to-end test before the docs promise it. With mod_php and `set
server-log note`, the `Header` lines are not needed: `%{rs}n` reads the note
the shield set.

**nginx** -- it logs the header from PHP-FPM and does not pass it on:

```nginx
log_format rs '$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent '
              '"$http_referer" "$http_user_agent" rs="$upstream_http_x_rs_log"';
access_log /var/log/nginx/access.log rs;
fastcgi_hide_header X-RS-Log;      # in the location that passes to PHP-FPM
```

**LiteSpeed** reads the Apache configuration for `.htaccess`, but its access
log format is set in the server's admin console (`%{X-RS-Log}o` and a header
rule to remove it); to be tried before it is documented.

**fail2ban**, as one use of the field:

```ini
# filter.d/request-shield.conf
[Definition]
failregex = ^<HOST> .* rs="(reject|throttle) \S+ \S+ \S+"$
```

A refusal at the firewall is cheaper than a refusal in PHP -- for the
addresses that keep coming back. (The shield's own bans stay: they work on
shared hosting without a firewall.)

## Cost

| | |
|---|---|
| off (default) | nothing |
| `header`, level `stop` | a request that passes: one comparison; a stopped one: one `header()` (~1 µs) |
| `header`, level `all` | one `header()` per request |
| `note` | one `apache_note()` instead of the header |
| the web server | one field more in a line it writes anyway |

## Plan

1. `set server-log`, `set server-log-level`; the header (or the note) where
   the decision is made (`Shield::protect()`, `Responder`), its value one
   function (`Log::serverValue()`: each part one word, spaces as `-`, no `"`
   or control characters), tested; a cached answer carries none
   (the cache plugin drops it like `X-RS`).
2. `check`: a warning when it is on (the header must be unset by the
   server; `note` without mod_php).
3. Docs: RSF05-05 gets "In the web server's log" with the snippets; an
   end-to-end test with PHP-FPM (the header leaves PHP) and, where it runs
   in CI, nginx with `fastcgi_hide_header` (it is logged, not sent).
4. Benchmark before and after (off, `stop`, `all`).

## Open questions for the owner

1. **Worth it at all,** or is the shield's own log enough? *Recommendation:
   yes, as an option -- hosters live in their access logs, and fail2ban on
   the firewall is a real gain for addresses that never stop.*
2. **The header's name:** `X-RS-Log` (the `rs` prefix, [ADR 0014](../adr/0014-wire-bytes-and-the-rs-prefix.md))?
3. **Default level `stop`,** or `all` so every line has a value?
   *Recommendation: `stop` -- nothing on the passing path; `rs="-"` already
   means "let through".*
4. **Should the shield's own log then be off by default** where the server
   log is used? *Recommendation: no -- the shield's log has the full URL as
   the visitor wrote it and the masked address; the access log has neither
   rule IDs for passes nor the shield's monitor lines unless the level says so.*
