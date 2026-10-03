# 0033 — Event log: see what was blocked and counted, and why

| | |
|---|---|
| Status | **Draft** — partly built, see *Relation to the log and the `Sink` hook* |
| Proposed | 2026-09-28 (as a local draft numbered 0003; renumbered 2026-10-03, 0003 is the rule files) |
| Affects | the shield, the responder, the budgets; a new `bin/shield-report` |
| Relates to | [0032 check levels](0032-check-levels.md) (the `monitor` event) · [log and rule IDs](../features/log-and-rule-ids.md) · [0016 rule advisor](0016-rule-advisor.md) · [0031](0031-robust-core-plugins.md) (the `Sink` hook) |

## Relation to the log and the `Sink` hook (2026-10-03)

Since this was drafted the core has a log of its own
([log and rule IDs](../features/log-and-rule-ids.md)): `set log <file>`,
`log-level`, one line per decision with the rule id, masked addresses,
rotation, and the live view reading it. [0031](0031-robust-core-plugins.md)
adds a **`Sink`** hook, so a JSON-lines event log as described here becomes a
small plugin instead of a core feature; the "from logs to rules" report is
[0016 the rule advisor](0016-rule-advisor.md). What remains specific to this
draft: the event *kinds* (`miss`, `uncacheable`, `app404`), the hard caps per
client and per day, and the IP `truncate|hash` choice — worth carrying into
the sink plugin and 0016. The sketch below predates rule files and speaks of
PHP-array keys.

## Summary

An optional, off-by-default log of what the shield did — rejects, throttles,
challenges, counted misses, and (under `monitor`, proposal 0032) what it *would*
have done. One JSON line per event, appended to a file outside the document
root. It is the raw material for making new rules and for loosening rules that
are too tight. A small CLI, `bin/shield-report`, reads the lines and prints
ready-to-paste config **suggestions** — it never applies anything.

**Nothing changes without the new key.** With `log.enabled => false` (the
default) the request path pays for one compiled boolean and nothing else.

## Motivation

- Today the shield is silent: a `429` or a `404` leaves no trace to learn from.
  Which scanner paths actually arrive? Are the budgets realistic, or shutting
  out real users? Which URLs cause the expensive cache misses?
- A rule should be provable from real traffic before it is made real, and
  reviewable afterwards. That needs a record of events, not of every request.

## Design

### Setting

```php
'log' => [
    'enabled' => true,
    'file'    => __DIR__ . '/../var/log/shield-%Y-%m-%d.jsonl',  // outside the document root
    'events'  => ['reject', 'limit', 'challenge', 'miss', 'monitor'],
    // costlier, only for deriving rules:
    // 'events' => [..., 'uncacheable', 'app404'],
    'sample'  => 1.0,           // 0.1 = one event in ten (under heavy traffic)
    'perClientPerMinute' => 20, // hard cap per client, so a flood cannot fill the disk
    'maxBytesPerDay'     => 50_000_000,
    'ip' => 'truncate',         // full | truncate (IPv4 /24, IPv6 /48) | hash
],
```

### Events

| Event | When | Use for rules |
|---|---|---|
| `reject` | a hard reject (405/400/404/414/431) | which scanner paths arrive; are limits too tight? |
| `limit` | a budget exceeded → 429 | are budgets realistic, and who hits them? |
| `challenge` | a challenge served / solved | the bot share; where to set the threshold |
| `miss` | `Shield::consume('misses', …)` from the app | which URLs cost expensive misses → candidates for `cacheable` or `blockedPaths` |
| `monitor` | under the `monitor` level: what *would* have been blocked | proving a rule safe before it is made real |
| `uncacheable` | answered outside the cacheable definition | which query params / paths are missing from `cacheable` |
| `app404` | the **application** answered 404 (via `register_shutdown_function` + `http_response_code()`) | the best source of new `blockedPaths` |

One line per event, JSON Lines:

```json
{"t":"2026-09-28T10:12:03Z","ev":"reject","why":"blockedPath","st":404,"m":"GET","h":"example.org","p":"/.env","q":[],"ip":"203.0.113.0/24","ua":"curl/8.4"}
```

`q` is a list of parameter **names**, never values (see Security).

### Where the writes happen

- `reject`, `limit`, `challenge`: in the responder / budget rule, on the path
  that already ends the request — the one place that knows the decision and
  reason. This adds nothing to a passing request.
- `miss`: in `Shield::consume()`, only for the budget the application counts.
- `monitor`: where a would-be reject is recorded (proposal 0032).
- `uncacheable`: on the passing path, and so **only** when explicitly listed —
  it is the one event that touches an otherwise-untouched request.
- `app404`: a shutdown handler registered **only** when the event is enabled,
  because it costs on every request whether or not the app 404s.

## Speed

- A passing, normal request is **never** logged unless `uncacheable`/`app404`
  are enabled; with `log.enabled => false` there is no cost beyond one compiled
  boolean.
- One `file_put_contents(…, FILE_APPEND)` per event, line < 4 KB — atomic under
  `O_APPEND`, no lock, no read-modify-write, the same principle as the file
  counters (ADR 0003).
- The file name's `%Y-%m-%d` is resolved once per request from a timestamp the
  shield already has, not per event.
- **Definition of done:** a reject with logging on ≤ **+5 µs**; a passing
  request with logging on but without `app404`/`uncacheable` ≤ **+0.5 µs**.
  Numbers in the pull request.

## Security

- **No log injection.** Every value is written through
  `json_encode(…, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES)`; the
  path and user-agent are cut to 256 characters. Psalm's taint analysis must see
  the resulting line as clean.
- **No secrets in the log.** Parameter **names** only, never values (tokens,
  session ids, e-mails would land there otherwise). No cookies, no `Authorization`
  header, no body.
- **No log flooding.** `perClientPerMinute` and `maxBytesPerDay` cap hard; on
  reaching a cap a single `{"ev":"log_suppressed"}` line is written and nothing
  more that window.
- **Privacy (GDPR).** IP defaults to `truncate` (IPv4 /24, IPv6 /48); `hash`
  uses HMAC with the shield's existing secret and a salt that rotates daily, so
  a stored value cannot be reversed and does not correlate across days. The
  proposal recommends a retention window (e.g. 14 days, then delete).
- **Location.** The log directory is `0700`, files `0600`, and the settings
  check **refuses** a `file` path inside `DOCUMENT_ROOT`, so a log can never be
  served.
- **A write must never affect the request.** A full disk or a missing
  permission is swallowed; at most one `error_log()` per minute reports it. The
  request is answered exactly as it would be with logging off.
- **The response stays generic.** Logging changes nothing a client can see; no
  rule name is ever revealed to a scanner.

## `bin/shield-report`: from logs to rules

A small CLI (plain PHP, no dependency) that reads the JSONL files and prints
config **suggestions** as ready-to-paste snippets — never applied automatically.

```
php bin/shield-report var/log/ --days=7 --min=50
```

```
Top paths with app404 (candidates for blockedPaths):
  1 842x  /wp-login.php   -> not a WordPress site? add Config::wordpressPaths()
    611x  /old-shop/…     -> '#^/old-shop/#'
Budgets:
  requests: 3 clients over the limit, 0.01% of all clients -> the limit fits
Uncacheable query parameters (most frequent):
  utm_source 12 004x, fbclid 3 210x -> add to cacheable.query, or ignore on purpose
monitor: 214 requests would have been blocked by '#^/internal-api/#', 0 of them from known users
```

- A suggestion appears only above a minimum frequency (`--min`, default 50), and
  always with an example line.
- `--format=php` prints a snippet that pastes straight into the config.
- Adoption stays a human review (the checklist in the deployment plan, §2).

## The recommended flow for a new rule

1. Write the rule; set the affected path to `monitor` via `levels` (proposal 0032).
2. Let it run 24–48 h; read `bin/shield-report`: does the rule hit only what it
   should?
3. Remove the path's `monitor` level (back to `standard`/`strict`) — the rule is
   now real.
4. Reduce `log.events` to `reject`, `limit` for steady state.

## Definition of done

- Unit tests: each event shape; sampling; `perClientPerMinute` and
  `maxBytesPerDay` caps with the `log_suppressed` line; a non-writable directory
  swallowed without touching the response; the `DOCUMENT_ROOT` path refused;
  parameter names but no values; IP `truncate`/`hash`.
- An end-to-end test over the built-in server: a reject writes exactly one line
  with the right fields; a passing request writes none (without `uncacheable`).
- A log-injection test (control characters, invalid UTF-8, a `\n` in the path)
  produces one valid JSON line; Psalm taint clean.
- `bin/shield-report` tested against sample logs, including `--min` and
  `--format=php`.
- `bench/overhead.php` within the bounds under Speed; numbers in the PR.
- Docs in the same change: `docs/features/logging.md`, the `log` keys added
  (commented) to `config/request-shield.dist.php`, `CHANGELOG.md` (*Unreleased*).

## Open questions

1. `app404` needs a shutdown handler on every request when enabled. Is that
   acceptable, or should it be a separate, clearly-labelled opt-in key
   (`log.appStatus`) so no one turns it on without seeing the cost?
2. Daily-rotating salt for `hash`: store it in `storeDir`, or derive it from the
   secret and the date so nothing new is persisted?
3. Should `sample` apply to `reject` too, or only to the high-volume events, so
   a rare reject is never sampled away?
4. One file with `%Y-%m-%d`, or a fixed file the operator rotates with logrotate?
