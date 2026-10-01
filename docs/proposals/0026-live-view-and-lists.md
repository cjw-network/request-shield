# 0026 — The live view, and keeping the lists in the dashboard

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-01 |
| Affects | the dashboard ([0012](0012-dashboard.md)), the IP lists ([0013](0013-ip-lists.md)), the log, the feeds ([0025](0025-blocklist-feeds.md)) |

## Summary

Two tabs for the dashboard, for a server with one website or fifty:

- **Live:** what the shield is stopping right now, one row per request, newest
  at the top. Each row shows **the website**, the address, the request, what
  happened, **why in plain words**, and **where the decision came from**: a list,
  a feed, a ban, the site's own rule, a built-in rule, or the pace. One click
  keeps an address out or lets it in.
- **Lists:** both lists with a search. A form adds an entry (address or range,
  kept out or let in, for how long, **a comment of one's own**). Entries can
  be extended or removed, and the active bans are listed beside them.

Fast and plain, as the security panels in hosting control panels are: rows
that update every few seconds and say the reason right away.

## In one picture

```text
 Live   Lists   Overview   Rules                         ⏸ pause   [all websites ▾] [all ▾] [all sources ▾]
 ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
 10:42:17  shop.example.de   203.0.113.0/24   GET /.env               refused 404   only attackers ask for this       built-in rule  SCAN-12     [keep out]
 10:42:15  www.example.org   198.51.100.7     GET /                   banned 429    20 refusals in 5 minutes          ban            SITE-SCAN   [lift]
 10:42:11  blog.example.net  192.0.2.0/24     POST /wp-login.php      refused 403   on the deny list: "scraper"       list           LIST-D3     [unlist]
 10:42:09  shop.example.de   2001:db8::/64    GET /search?q=<script>  refused 403   an attack pattern in the query    built-in rule  ATTACK-31
 10:42:04  www.example.org   203.0.113.9      GET /login              checked       the browser check at sign-in      own rule       SITE-LOGIN
 10:42:01  shop.example.de   198.18.0.0/24    GET /                   refused 403   on Spamhaus DROP                  feed           FEED-DROP
```

## Live

### Where the rows come from

**From the log, not from a new write per request.** The log (`set log`)
already has one line per decision that was not a plain pass, with the time, the
address (masked as `log-ip` says), the action and status, the reason, the rule
ID, the method and **the full URL with the host**:

```text
2026-10-01T10:19:11+02:00 203.0.113.0/24 throttle 429 "banned" rule=SITE-SCAN "GET http://example.org/.env" "curl"
```

- The live tab asks `<panel-path>/api/live?after=<offset>` every 3 seconds
  (no WebSocket, plain `fetch()`); the server reads the log **from that byte
  offset** to its end, at most 64 KB, and returns only the new rows as JSON.
  Opening the tab reads the last 64 KB. So a busy site costs one `fseek` +
  one short read per open tab every few seconds, nothing per request.
- A rotated log (a new file under the same name) is noticed by its inode; the
  tab then starts from the new file's beginning.
- `log-level stop` (the default) logs refusals and bans; `flag` adds checks
  and the uncached, `all` everything that was not a plain pass. The tab says
  which level is set and what is therefore missing.
- **Several servers** behind a load balancer each have their own log: the tab
  shows the server's own log, or (later) a shared log directory with one file
  per server, merged by time.

### What each row says

| Column | From |
|---|---|
| time | the log line |
| **website** | the host of the logged URL; with site blocks ([0024](0024-rules-per-website.md)) also the site block's name |
| address | as logged (masked by default); with `log-ip full` the full one, and the country later ([0012](0012-dashboard.md#countries)) |
| request | method, path and query, shortened, the full one on hover |
| what happened | refused / banned / paused / checked / not cached, with the status, coloured as on the rules page |
| **why** | the reason in words, from the rule's own description where it has one ("only attackers ask for this", the comment of a list entry: "on the deny list: scraper") (`Describe`, as on the rules page and in `trace`) |
| **source** | from the rule ID and where the rule comes from: **list** (`LIST-…`, a deny entry of a list file), **feed** (`FEED-…`, 0025), **ban** (a `ban` rule), **own rule** (the site's rule files), **built-in** (`rules/…`), **pace** (a budget), **crawler policy** (a known crawler refused or checked) |
| rule ID | links to the rule on the rules page |
| action | keep out / let in for a while / unlist / lift a ban, each through the same code as the command line (below) |

### Filters, kept in the address of the page

Website (the hosts seen, and the site blocks), what happened, source, an
address or range, a text search in the request. **Pause** stops the updates
(for reading); the newest rows wait above, counted. All in plain JavaScript,
~3 KB, no library.

## Lists

- Both lists in one table: kept out / let in, address or range, **comment**,
  who added it and when, until when (or "for good", marked for review), a
  search. "Active bans" below: address, rule, until, [lift].
- **Add:** address or range; kept out or let in; for 1 hour / 1 day / 7 days /
  30 days / until a date / for good (deny only); **the comment**, required for
  "for good". Written by `Rules\Lists::add()` as the command line does, the
  note "`<comment> · dashboard <user> <time>`", the main rule file touched so
  every server reads it within its recheck.
- **Extend, change the comment, remove:** the same file, written whole.
- **Guards, as on the command line, and one more:** never a trusted proxy;
  ranges wider than /16 (IPv4) or /32 (IPv6) only after a second confirmation;
  **never the address the dashboard is opened from** (it would lock out the
  person clicking).
- **From the live tab:** "keep out" on a row opens the form with the address
  (or its /24 or /64), the rule's reason as the suggested comment, and 7 days.
- **A ban that keeps returning** (third ban in a week) is marked in the bans
  list with "keep out for good?" (the open question of 0013, answered there).

## Security

- Writing needs the dashboard's login (0012: address rule **and** password, or
  the CMS's login when embedded), a **POST with a CSRF token**, and the lists
  directory writable by the web server. Everything else stays read-only: the
  dashboard can write list lines and nothing else, because the list files hold
  nothing else ([0013](0013-ip-lists.md)).
- The live tab returns JSON only to a logged-in session, never cached, never
  indexed.
- The comment is stored as one line without `#`, `[` or `]`, and shown
  escaped.

## Customers ([0023](0023-plugins-hosts-customers.md))

A customer's view (a token per customer group) shows **only its own
websites** in the live tab, filtered on the server, not in the browser. The
lists are server-wide: a customer may not keep an address out of the other
customers' sites. So customers either get no list tab, or later **lists per
website** (`lists/<site>/deny.rules`, read only into that site's settings).
That is an open question below.

## Cost

| | |
|---|---|
| per request | nothing new: the log line exists already (when `set log` is on) |
| an open live tab | every 3 s one read of the new log bytes (at most 64 KB) and their parsing: well under a millisecond for a few dozen lines |
| writing a list entry | the list file written whole, then one rebuild of the settings ([big lists](../features/ip-lists.md#big-lists): 2 s for 200,000 entries, others keep the last settings meanwhile) |

## Privacy

The live tab shows what the log holds and nothing more: masked addresses by
default. A comment on a list entry is written by people and can name a
person. The form says so ("say why, not who"), and entries for good are
listed for review.

## Phases

1. Live tab from the log: rows, website, reason, source, filters, pause, the
   JSON endpoint with the byte offset.
2. Lists tab: table, search, add with a comment, extend, remove, active bans,
   lift a ban (file store: at once; APCu: through the web server's own
   request, which can reach its APCu).
3. Actions from live rows, "keep out for good?" for returning bans.
4. Customer views: live filtered to their websites; lists per website if
   question 2 says so.

## Open questions

1. Live from the log (proposed) or from its own ring buffer in APCu (works
   without `set log`, costs one write per refused request)? *Recommendation:
   the log; a site that wants the live tab switches the log on.*
2. Lists per website for customers (`lists/<site>/`)? *Recommendation: yes, in
   phase 4; the server's lists stay with the server's admin.*
3. Is the comment required for every entry, or only for "for good"?
   *Recommendation: required for "for good", suggested from the rule's
   reason otherwise.*
4. Show the full address in the live tab when the log keeps it masked (from a
   separate short buffer)? *Recommendation: no; what is not logged is not
   shown. The masked /24 can still be kept out as a range.*
