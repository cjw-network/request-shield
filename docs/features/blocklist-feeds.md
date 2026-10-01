# Public blocklists (feeds)

From [proposal 0025](../proposals/0025-blocklist-feeds.md).

## What it does

Public lists of addresses that attack, send spam or run botnets, used with
care. A cron job fetches the lists a rule file names, and each list gets **its
own action**:

| Action | What a request from the list gets |
|---|---|
| `deny` | 403, right after the deny list (the reason `feed`, the log names the rule) |
| `check` | the browser check, as on an always-checked page; a pass lets the visitor through |
| `ban-signal <n>` | every ban signal counts n times: a scanner from the list is banned after its first refusal instead of its twentieth ([bans](ip-lists.md#bans)) |
| `count` | nothing, but logged as what it would have done (`monitor-reject 403 "feed"`) and shown in the live view: **the safe way to try a list** |

`at <paths>` narrows an action to some paths (`check at /login/** /contact`).

Nothing is looked up per request and nothing about the site's visitors is sent
anywhere: the lists are fetched (pull only), compiled with the rules into the
same sorted table as the deny list, and searched in 2–3 µs.

**Never affected:** addresses let in (`exempt`), trusted proxies, and crawlers
that proved who they are ([known crawlers](known-crawlers.md)). **Taken out of
every list when it is fetched:** the site's own network (10/8, 172.16/12,
192.168/16, 127/8, 100.64/10, 169.254/16, 0/8, 224/3, fc00::/7, fe80::/10, ff00::/8,
::1, ::ffff:0:0/96), and ranges wider than /16 (IPv4) or /32 (IPv6), unless the
list is meant to hold whole networks (`wide-ok`, set for DROP, FireHOL, the
clouds).

## The catalog (`rules/feeds.json`)

| Name | What | Good for | Terms |
|---|---|---|---|
| `spamhaus-drop` | hijacked networks and networks run by criminals (IPv4 + IPv6) | `deny` | [Spamhaus DROP terms](https://www.spamhaus.org/drop/terms/) |
| `dshield-top20` | the 20 /24 networks scanning most | `deny` or `check` | CC BY-NC-SA 2.5: **non-commercial** |
| `feodo` | botnet command servers (abuse.ch) | `deny` | [abuse.ch](https://feodotracker.abuse.ch/blocklist/) |
| `firehol-level1` | an aggregate (DROP, DShield, Feodo …) | `deny` | those of its sources |
| `et-compromised` | hosts seen attacking (Proofpoint ET) | `check` | [ET](https://rules.emergingthreats.net/) |
| `blocklist-de` | fail2ban reports of the last 48 h (~25,000) | `check` or `ban-signal` | [blocklist.de](https://www.blocklist.de/en/index.html) |
| `stopforumspam` | ranges posting form spam | `check at` the forms | [Stop Forum Spam](https://www.stopforumspam.com/legal) |
| `tor-exits` | Tor exit nodes | `check at` sign-in or forms; **never `deny` by default** | the Tor Project |
| `aws`, `google-cloud` | cloud address ranges | `check` | published by the operators |

`bin/request-shield feeds site.rules` without feeds in the rules prints the
catalog. **Read a list's terms before using it.** The site fetching the list is
the one that has to follow them.

**Never for a website:** Spamhaus PBL/ZEN and other mail blocklists. The PBL
lists ordinary home connections.

## Configuration

```text
# Try a list first: counted and logged only.
[FEED-DROP]  feed spamhaus-drop count
# Then:
[FEED-DROP]  feed spamhaus-drop deny
[FEED-SCAN]  feed dshield-top20 check
[FEED-F2B]   feed blocklist-de ban-signal 3
[FEED-SPAM]  feed stopforumspam check at /contact /comment/**
[FEED-TOR]   feed tor-exits check at /login/**
[FEED-OWN]   feed our-list https://intranet.example/bad.txt deny format plain   # a list of one's own: https only
set feeds-max-age 3d          # a fetched list older than this is no longer used (default 3 days)
```

- `feed <name> [<https-url>] deny|check|count|ban-signal <n> [at <paths>] [format <format>] [wide-ok]`.
- Formats: `plain` (the first word of each line; `#` and `;` start a comment),
  `dshield` (start, end, prefix length), `jsonl:<field>` (one JSON object per
  line), `json:<field>[,<field>]` (those fields anywhere in one JSON document).
- `monitor feed …` works like `count`.
- **About the server:** feeds belong above the site blocks (`at` narrows
  them). The lists live in `<store-dir>/feeds`.

### Fetching: cron

```text
# every hour; each list at most as often as its catalog entry says (1 h, the clouds 1 d)
0 * * * *  php /path/to/vendor/cjw-network/request-shield/bin/request-shield feeds /path/to/site.rules update
```

- Over HTTPS, with the validators of the last fetch (`If-None-Match`,
  `If-Modified-Since`: a 304 costs nothing).
- **A list that shrank to less than half, or came back empty, is kept** (a
  broken download, not a change; `--force` takes it). A list that cannot be
  fetched is kept as it was. The exit code is 1 then, so cron mails it.
- Written whole (a temporary file, then renamed), 0640. The main rule file is
  touched when a list changed, so every server reads it within its recheck.
- A list older than `feeds-max-age` is not used: the settings are built again
  the moment it grows too old, and `check` warns. Stale threat data does more
  harm than none, because the addresses have moved on to someone else.
- `feeds site.rules` (or `list`): each list, its action, its entries, when it
  was fetched, its terms.

### Below PHP: `feeds export`

```text
php bin/request-shield feeds site.rules export --format=nftables --write=/etc/nftables.d/request-shield.nft
php bin/request-shield feeds site.rules export --format=ipset            # ipset restore < …
php bin/request-shield feeds site.rules export --format=nginx --write=/etc/nginx/request-shield-deny.conf
php bin/request-shield feeds site.rules export --format=plain
```

- The deny list and the feeds named `deny`, merged into the fewest CIDR blocks
  (Spamhaus DROP: ~1,700). Not `check`, `count` or `ban-signal` lists, not
  lists with `at <paths>` (a firewall knows no paths), not running bans.
- A block that touches a trusted proxy or an address let in is left out, and
  `export` says so.
- The shield never changes the firewall itself. Loading the file (`nft -f`,
  `ipset restore`, an nginx reload) is a privileged step for the admin's cron
  or configuration management.

### Not `.htaccess`

Writing the lists into `.htaccess` for shared hosting was proposed and
**measured before it was built**. Apache reads `.htaccess` on every request,
static files included. Measured on Apache 2.4 (event MPM), a static file,
`ab -k -c 8`, three rounds:

| `Require not ip` ranges in `.htaccess` | requests/s |
|---|---|
| 0 | 23,092–27,587 |
| 500 | 6,325–8,271 (3–4× slower) |
| 2,000 | 2,892–3,098 (8× slower) |
| 10,000 | 861–871 (28× slower) |
| 50,000 | 268–279 (90× slower) |

Even Spamhaus DROP alone would make every image and stylesheet about 8× slower,
while the shield finds an address in 2–3 µs at any list size. So there is no
`.htaccess` export. On shared hosting the shield in PHP is the place for these
lists; on a server you control, the firewall export.

## Why the lists are not in the repository

- **Terms:** several lists do not allow passing them on, or only under their
  own conditions (DShield: non-commercial). A public MIT package that shipped
  them would hand them on under other terms.
- **Freshness:** they change by the hour. A copy in a release would be stale
  within days, and a stale blocklist refuses people who got an address
  someone else used to have.

So the catalog holds the addresses and the terms, and every site fetches the
lists itself.

## How it shows

- The [live view](live-and-lists.md): source **feed**, "on the public list
  Spamhaus DROP", the rule ID.
- The rules page: a group "Public blocklists" with each list, its action, its
  entries, when it was fetched, its terms. The setup view: a step "Public
  blocklists" right after the deny list. `trace` names the list.
- `check` warns about a list that was never fetched or is too old.

## Cost

| | |
|---|---|
| no feeds | nothing |
| feeds named `deny` and `check` (measured with Spamhaus DROP and blocklist.de, ~27,000 entries) | 3–4 µs per request in all (loading, one search per action) |
| a list with `count` | what any watched rule costs (the watched rules' settings, ~7 µs), plus its search |
| building the settings with ~40,000 entries | ~0.3 s, once after a fetch; the other requests keep the last settings meanwhile |
| fetching (cron) | all ten catalog lists in ~2.4 s; a 304 costs almost nothing |

## Privacy

- **Pull only:** nothing about the site's visitors leaves the server.
- The lists hold third parties' addresses, processed for security
  (Art. 6(1)(f)). A site's privacy notice can name the lists it uses.
- A refusal because of a feed is logged with the list's rule ID. A visitor hit by
  mistake can be found and let in (`request-shield allow …`).

## Limits

- Wrong hits: shared addresses (carrier NAT, offices, VPNs, Tor) are on many
  lists. Use `deny` only for DROP-like lists, `count` first, `at <paths>`, and
  watch the live view.
- Lists that need an account or API key (AbuseIPDB, CrowdSec) are not in the
  catalog: adapters later (proposal phase 4), opt-in.
