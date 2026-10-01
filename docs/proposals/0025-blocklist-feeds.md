# 0025 — Public blocklists: other people's experience, used with care

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-01 |
| Affects | rule files (new rule `feed`), the command line (`feeds update`, `feeds export`), the IP lists ([0013](0013-ip-lists.md)) |

## Summary

There are public lists of addresses that attack, send spam or run botnets.
Some are kept carefully enough to refuse every request from them. Most are
not: they list addresses shared by many people, or addresses that were bad
last week. This proposal lets a site **subscribe to such lists ("feeds")**.
A cron job fetches them, and each one gets **its own action**: refused,
checked, or only counted towards a ban. The same lists can also be **exported
for the server's firewall**, so the worst addresses never reach PHP at all.

Nothing is looked up per request and nothing about the site's visitors is
sent anywhere. The lists are fetched (pull only), compiled like the deny list
and searched in about a microsecond.

## Motivation

- A site sees an attacker only after the attack has started. Other sites saw
  it earlier.
- Some networks are hijacked or run by criminals as a whole (Spamhaus DROP).
  Nobody legitimate is behind them, so refusing them costs nothing.
- Botnet and scanner addresses change daily. A ban ([0013](0013-ip-lists.md))
  needs some signals first; a feed can make a known scanner meet the browser
  check on its first request.
- A firewall (nftables/ipset, fail2ban) is cheaper than any PHP. It just needs
  the lists in its format.

## Which lists exist, and what each is good for

The list below is from memory, as of 2026. **Every list's terms and format must
be checked again before it goes into the shipped catalog**, and listed there
with its terms.

| Feed | What it lists | Size (about) | Wrong hits | Fits as |
|---|---|---|---|---|
| **Spamhaus DROP** (v4 + v6, JSON; EDROP is merged into it) | hijacked netblocks and networks run by criminals | ~1,000–1,500 ranges | practically none: made for "drop all traffic" | **`deny`** |
| **FireHOL level1** | an aggregate: DROP, DShield, Feodo, fullbogons | a few thousand ranges | few, **but it includes the private/bogon ranges** (10/8, 192.168/16 …): behind a proxy, or in an intranet, that refuses everyone | `deny`, only with the private ranges taken out (see below) |
| **DShield top 20** (SANS ISC `block.txt`) | the /24 networks scanning most in the last days | 20 ranges | few | `deny` or `check` |
| **abuse.ch Feodo Tracker** | botnet command servers | small | none (they are servers) | `deny` (it matters more for outgoing traffic) |
| **Proofpoint Emerging Threats** `compromised-ips.txt` | compromised hosts seen attacking | a few hundred to thousands | some: compromised home machines get cleaned up, addresses get reassigned | `check` |
| **blocklist.de** (`all.txt`) | addresses fail2ban users reported in the last 48 h | 20,000–40,000 | **some**: shared addresses, carrier NAT | `check`, or `ban-signal` |
| **Stop Forum Spam** (toxic lists, or by frequency) | addresses posting form spam | large | some | `check` **at forms only** |
| **Tor exit nodes** (torproject.org bulk list) | the Tor network's exits | ~1,000–2,000 | **not bad as such**: journalists, people protecting their privacy | `check` at forms at most, never `deny` by default |
| **Cloud and hosting ranges** (AWS, Google Cloud, Azure … publish theirs) | data centres, where many bots run and few people browse | large | some (VPNs, company proxies) | `check` |
| AbuseIPDB, CrowdSec, Project Honey Pot | community reports, scored | large | depends on the threshold | need an account or API key, and have their own terms: an **adapter** later, not a built-in feed |

**Never for a website:** Spamhaus **PBL/ZEN** and other mail DNSBLs. The PBL
lists *dynamic consumer addresses* (people at home), so on a website it would
refuse most normal visitors. DNSBL lookups per request also cost a DNS
round-trip each.

## Design

### The rules

```text
[FEED-DROP]  feed spamhaus-drop      deny                    # hijacked networks: refused, 403
[FEED-SCAN]  feed dshield-top20      check                   # the browser check on the first request
[FEED-F2B]   feed blocklist-de       ban-signal 3            # counts as 3 signals towards a ban (0013)
[FEED-SPAM]  feed stopforumspam      check at /contact/** /comment/**
[FEED-TOR]   feed tor-exits          check at /login/**
[FEED-OWN]   feed https://intranet.example/bad.txt deny format plain  # a list of one's own
set feeds-max-age 3d       # a list older than this is no longer used, and `check` warns
```

- **Actions:** `deny` (403 like the deny list, checked in the same first
  step), `check` (the browser check, then passed like any visitor), `ban-signal
  <n>` (counts towards the bans of [0013](0013-ip-lists.md): a scanner from the
  list is banned after its first refusal instead of its twentieth), and `count`
  (statistics only: "how many of our requests come from listed addresses?",
  the safe way to try a list).
- `at <paths>`: the action only there (forms, sign-in).
- `monitor feed …` ([0004](0004-modes-monitor-and-strict.md)) logs whom it
  would have hit. The recommended first step for every list.
- **Never affected:** addresses let in (`exempt`), trusted proxies, verified
  crawlers, and the **private and special ranges** (10/8, 172.16/12,
  192.168/16, 127/8, 100.64/10, fc00::/7, fe80::/10 …), which are taken out of
  every feed when it is compiled, because they are the site's own network.
  Entries wider than /16 (IPv4) or /32 (IPv6) are dropped unless the feed is
  marked `wide ok` (DROP is: whole hijacked networks are its point).

### Fetching: `feeds update`

```text
php bin/request-shield feeds site.rules update      # cron, e.g. hourly; each feed at most as often as its terms allow
```

- The same mechanism as the crawler lists ([0011](0011-known-crawlers.md)):
  fetched over HTTPS into `<store-dir>/feeds/`, written whole, and the main
  rule file touched so every server reads them within its recheck.
- **A list that shrank to less than half is kept** (a broken download, not a
  change; `--force` takes it). A list that cannot be parsed is kept as it was.
- Conditional requests (`If-Modified-Since`/ETag), and **no more often than the
  feed's minimum interval** from the catalog (DROP: once an hour at most;
  Spamhaus asks for that, and blocks those who fetch more often).
- A list older than `feeds-max-age` is no longer used, and `check` and the
  setup view say so. Stale threat data does more harm than none: the
  addresses have moved on to someone else.
- **The catalog** (`rules/feeds.json`, shipped): name, URL, format (plain,
  CIDR, JSON lines, DShield's tab format), minimum interval, terms (a link and
  a one-line summary: "free for any use", "non-commercial", "attribution"),
  and whether wide entries are fine. A site can name a URL of its own instead.
- **No list is shipped in the package.** Only the catalog. The lists are
  fetched by each site under the list's own terms, and the repository never
  redistributes them.

### Many addresses: a packed table

blocklist.de has tens of thousands of addresses. As a PHP array in the
compiled settings that is several MB per worker's view of OPcache. So big
feeds are compiled into **one sorted, packed binary string per family** (4 + 4
bytes per IPv4 range, 16 + 16 per IPv6). It is an interned string in OPcache,
shared by all workers, and searched by binary search with `substr()`:

| Feed size | Compiled | Lookup (expected) |
|---|---|---|
| 1,500 ranges (DROP) | ~12 KB | < 1 µs |
| 40,000 addresses (blocklist.de) | ~320 KB | ~1–2 µs (16 steps) |

All feeds of one action are merged into one table, so a request does **one**
search per action, however many lists there are. This also suits the deny
list of [0013](0013-ip-lists.md) when it grows big.

### For the firewall: `feeds export`

```text
php bin/request-shield feeds site.rules export --format=nftables > /etc/nftables.d/request-shield.nft
php bin/request-shield feeds site.rules export --format=ipset    # ipset restore
php bin/request-shield feeds site.rules export --format=plain    # one range per line (fail2ban, a cloud firewall's API)
```

- Exports only the feeds marked `deny`, plus the deny list's entries, with
  the same exclusions (private ranges, trusted proxies, exempt).
- Optional, as the site wants. A firewall answers before the web server even
  sees the connection, which is the cheapest place for DROP. The shield's own
  check stays as the second line (shared hosting, where nobody may touch the
  firewall).
- The shield never changes the firewall itself. Loading the file is a
  privileged step that the admin's cron or config management does.

## Cost

| | Per request |
|---|---|
| no feeds | nothing |
| feeds, any number | one binary search per action used (`deny`, `check`, `ban-signal`): ~1–2 µs |
| `count` | the same search, plus a counter only on a hit |
| fetching | none: a cron job, outside requests |

## Privacy

- **Pull only:** the shield fetches lists and **sends nothing about the site's
  visitors**. Reporting addresses back (AbuseIPDB, CrowdSec) would share
  personal data with a third party. If it ever comes, it is a separate,
  opt-in adapter with its own section in [privacy.md](../privacy.md).
- The lists hold third parties' addresses, processed for security
  (Art. 6(1)(f)); a site's notice can name the lists it uses.
- A refusal because of a feed is logged with the feed's rule ID
  (`rule=FEED-DROP`), so a visitor who was hit by mistake can be found and
  let in (`request-shield allow …`).

## Risks

- **Wrong hits:** shared addresses (carrier NAT, offices, VPNs, Tor) are on
  many lists. Hence: actions other than `deny` for anything but DROP-like
  lists, `count` and `monitor` first, `at <paths>`, and a statistics view of
  the hits per feed.
- **Stale lists:** `feeds-max-age`, a warning in `check`.
- **A list that breaks or is taken over:** the shrink guard, the format check,
  HTTPS only, the private ranges and too-wide entries dropped, trusted proxies
  never affected. A feed can refuse at most what its action allows.
- **Terms change:** the catalog names the terms; a site that uses a list
  agrees to them, not the library.

## Phases

1. `feed … count|deny|check` with the catalog's safest lists (Spamhaus DROP,
   DShield top 20, Feodo), `feeds update`, the packed table, `monitor feed`,
   the exclusions, `check` warnings.
2. `ban-signal`, `at <paths>`, the larger lists (blocklist.de, Stop Forum Spam,
   Tor exits, cloud ranges), hits per feed in the statistics.
3. `feeds export` for nftables/ipset/plain.
4. Adapters for scored services with an API key (AbuseIPDB, CrowdSec), each with
   its own terms, opt-in.

## Open questions

1. Ship a recommended set switched on by the setup example (DROP as `deny`,
   DShield as `check`), or every feed off until named? *Recommendation: off
   until named; the example rule file shows DROP + DShield commented out, with
   `monitor` in front.*
2. `ban-signal` as a weight (3 signals) or "banned on the first refusal"?
   *Recommendation: a weight. It keeps the ban rules as they are.*
3. The packed table also for the deny list of 0013 above a few thousand
   entries? *Recommendation: yes, the same code.*
4. `feeds export`: also write the deny list's entries and running bans (for
   fail2ban-like use)? *Recommendation: the deny list yes; bans no, they are
   short and per server.*
