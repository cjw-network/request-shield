# 0013 — IP lists: let an address in, keep one out

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-30 |
| Affects | rule files (a new `deny` rule, list files), the dashboard ([0012](0012-dashboard.md)), the command line |

## Summary

Two lists a site owner keeps without editing rule files: **addresses let in**
(never counted, never checked — the office, the monitoring) and **addresses
kept out** (403, before anything else — a scraper, an attacker). Kept from the
dashboard or the command line, each entry with a reason and, if wanted, an
end date. Stored as small **list files** the shield reads like its other
rules: compiled once, checked in the same pass as today, so a passing request
pays nothing for them.

## In one picture

![The dashboard or the command line writes list files in store-dir; they hold only list lines; compiled with the rules; a request from a kept-out address gets 403 first, one from a let-in address is never counted or checked](0013-ip-lists.svg)

## Motivation

- An attacker's address, noticed in the log or on the dashboard, should be
  shut out in seconds — by someone who has never opened a rule file.
- The office or a monitoring service should never meet the browser check.
- "For a week" is common: a temporary block that lifts itself.
- What exists: `exempt <addresses>` (never counted) and `restrict <paths> to
  <addresses>` (areas for some). What is missing: refusing an address site-wide,
  and keeping either list without editing files by hand.

## Design

### The rules

```text
exempt  192.0.2.50                 # exists: never counted (budgets, so never checked by them)
deny    203.0.113.7  198.51.100.0/24  until 2026-10-07    # new: 403 before everything else
```

- `deny <addresses or ranges> [until <date>]`: refused with 403 (reason
  `denied`), checked **first** — before the method, before the path. The
  cheapest refusal there is, and a denied client costs the site nothing.
- The allow list uses `exempt`, which exists; entries from the list are also
  exempt from always-checked paths (`challenge`) — a let-in address is *never*
  checked. It is **not** exempt from blocked paths or attack patterns: a
  compromised office machine asking for `/.env` is still refused.

### The list files

```text
set lists-dir ${REQUEST_SHIELD_LISTS:-<store-dir>/lists}
```

- Two files there, `allow.rules` and `deny.rules`, written **only** by the
  dashboard and the command line, atomically (a temporary file, then
  `rename()`), one entry per line with its reason, who added it and when, as the
  rule's description:

  ```text
  [LIST-D17] deny 203.0.113.7 until 2026-10-07   # scraper, 900 requests/min · dashboard 2026-09-30
  ```
- Read after the site's rule files, like an include; recompiled when they
  change (the settings already notice changed files).
- **Only list lines are accepted there** — `deny` and `exempt` with addresses,
  nothing else. A list file that holds anything else is an error. So the
  dashboard, which needs a writable directory, can never be used to write
  rules: the rule files themselves stay read-only for the web server.
- Expired entries are left out when compiling; the compiled settings remember
  the next end date and are rebuilt once it has passed (one integer compared
  per request).

### Many addresses

Up to a few dozen entries: checked like `exempt` today (a loop over parsed
ranges). Above that the compiler builds a sorted range table (IPv4 and IPv6
apart) and the check is a binary search — about 1 µs for 10,000 entries — so a
list fed by a fail2ban-style script stays fast.

### Keeping them

- **Dashboard:** a tab with both lists, a search, "add" (address or range,
  reason, for 1 hour / 1 day / 1 week / always) and "remove". From the log and
  the country view: "keep this address out" next to an entry.
- **Command line:** `bin/request-shield deny 203.0.113.7 --for 7d --reason
  "scraper"`, `allow …`, `unlist …`, `lists` — for scripts, including a
  fail2ban action.
- **Guards:** the dashboard refuses to deny the address it is opened from, the
  addresses in `trust`, and ranges wider than /16 (IPv4) or /32 (IPv6) unless
  confirmed.

## Cost

| | Per request |
|---|---|
| no list entries | nothing |
| a few dozen entries | ~0.1 µs per entry (as `exempt` today) |
| thousands of entries | a binary search, ~1 µs |
| expiry | one integer comparison |
| a denied client | refused before any other check: less than any other refusal |

## Privacy

The lists hold addresses — personal data under the GDPR when they belong to
people. The reason and the end date make the purpose and the retention
explicit; entries without an end date are listed on the dashboard as
"permanent" so they are reviewed. The list files stay outside the document
root (in store-dir by default) and are created 0640.

## Open questions

1. `deny` checked first (before even the method), or after the path checks?
   *Recommendation: first — the cheapest place, and a denied address has no
   business anywhere.*
2. The allow list: `exempt` plus never checked (proposed), or also past
   blocked paths and attack patterns? *Recommendation: never past those.*
3. Answer to a denied client: 403 (proposed), or drop the connection
   (not possible from PHP), or 404? *Recommendation: 403 with the usual short
   page; the reason stays out of it.*
4. Should repeated refusals put an address on the deny list by themselves
   (an automatic ban)? *Recommendation: not in this step; the rule advisor
   ([0016](0016-rule-advisor.md)) can suggest it, a person decides.*
