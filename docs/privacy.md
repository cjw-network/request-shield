# Privacy and the GDPR

What the shield processes about visitors, where it keeps it, for how long, on
which legal basis it most likely rests, and how to set it up privacy-friendly —
feature by feature, for what exists today and for the proposals.

> **Not legal advice.** This page describes what the software does and the
> readings of the GDPR and the German TDDDG (formerly TTDSG) that are common for
> such processing. Every legal statement is *to be confirmed by the site's data
> protection officer*; the site operator is the controller and decides.

## In short

- **The defaults are privacy-friendly:** statistics off, the log off (and when
  on, addresses masked), no third parties, no tracking cookie, no script from
  elsewhere, no external calls — except optional DNS lookups to verify crawlers
  and, run by an administrator, the update of the crawlers' address lists.
- **The shield keeps no profiles.** It counts per client address for a few
  seconds to minutes (the budgets), and counts aggregates (statistics). It never
  combines data into a picture of a person.
- **A visitor's "no" is honoured** (planned with the visitor statistics of
  [0022](proposals/0022-visitors-page.md)): a browser that sends Global Privacy
  Control or Do Not Track is never counted as a visitor and its address is not
  used for statistics at all — the automated objection of Art. 21(5) GDPR,
  always on.
- **Security is the purpose of almost everything it does** — keeping attacks,
  floods and scrapers away from the site. Network and information security is
  named as a legitimate interest in recital 49 GDPR; the likely basis is
  Art. 6(1)(f) GDPR.

## Feature by feature

### Budgets (counting requests per client)

- **Processed:** the client's address — IPv4 as it is, IPv6 as its /64 network
  (the "client bucket") — as the key of a counter.
- **Kept:** in the store (APCu memory, or files in `store-dir` named by a hash
  of the key), for the budget's window (a minute by default, at most the longest
  window configured). Expired entries are dropped by the store.
- **Basis:** security of the service (Art. 6(1)(f), recital 49): refusing floods
  needs counting per client. **Consent:** not needed — nothing is stored on the
  visitor's device.
- **Privacy-friendly:** keep windows short; `exempt` for your own monitoring.

### The browser check (proof of work)

- **Processed:** a task signed for the client bucket; the browser computes a
  hash and sends back a number. **Nothing is read from the device** — no
  properties, no fingerprint.
- **Cookies:** a solution cookie (minutes, once) and a pass cookie (an hour by
  default, `pass-ttl`), signed, holding an expiry time and a tag derived from the
  client bucket and — with `bind-user-agent on`, the default — the browser
  string. The server stores nothing but the fact that a solution was used (a hash
  of the task, for the solution's lifetime).
- **Basis and consent:** the cookies serve the security of the service the
  visitor asked for; the common argument is that they are *strictly necessary*
  (§ 25(2) no. 2 TDDDG) and need no consent — *to be confirmed*. They must
  never be used for anything else (see [0019](proposals/0019-seo-geo-dashboard.md#the-shields-own-pass-cookie)).
- **Privacy-friendly:** a short `pass-ttl`; `bind-user-agent off` stores less but
  lets a pass travel between browsers.
- The check inside the form and pages that ask for the check use the same
  cookies.

### Trusted proxies (X-Forwarded-For)

- **Processed:** the client address a trusted proxy reports, used instead of
  the proxy's own. Nothing is stored for it; untrusted forwarded headers are
  removed (`strip-untrusted-forwarded`).

### The log

- **Processed:** for requests that were refused or checked (`log-level stop`,
  the default level; `flag` also uncached ones, `all` every request): time, the
  client address **masked** to its network (`198.51.100.0/24`,
  `2001:db8:1::/48`) by default, decision, rule, the full URL, the User-Agent.
  URLs can contain personal data (a search term, an e-mail address in a link).
- **Kept:** in `set log <file>`, one rotation at `log-max-size`; keep it short
  (logrotate).
- **Basis:** security (Art. 6(1)(f)). **`log-ip full`** (for a ban list such as
  fail2ban) keeps full addresses: personal data with a clear purpose — keep
  those logs short and say so in the privacy notice.
- **Privacy-friendly:** leave it off unless needed; `log-level stop`; masked
  addresses.

### Known crawlers (search engines and AI crawlers)

- **Processed:** for a request that names a crawler: its address compared with
  the operator's published list (on the server), or a **DNS lookup** of the
  address (reverse and forward) — a lookup sends the address to the configured
  resolver. Results are remembered for a day (APCu, or a file in `store-dir`
  named by a hash of crawler and address).
- **Privacy-friendly:** `set crawler-verify ranges` (no DNS at all), or a local
  resolver; `set dns-lookups 0`. The lists are fetched only by an administrator
  (`crawlers update`, `bin/update-crawler-lists`), never while a request runs.

### IP lists and automatic bans ([docs](features/RSF01-02-ip-lists.md))

- **What:** the list files (`allow.rules`, `deny.rules` in `lists-dir`) hold
  addresses or ranges, each with its reason, who added it and when, and an end
  if one was given. A ban keeps the client's address (IPv6: its /64) in the
  store with its end time; the counters behind it expire with their windows,
  the record of earlier bans after a day.
- **Basis:** security (Art. 6(1)(f)): defence against an attack, a stated
  purpose. **Retention:** an entry's end date; a ban at most `ban-max` (a day
  by default). Give deny entries an end (`--for=7d`) and review those without
  one (`request-shield lists`).
- **Where:** outside the document root by default (`<store-dir>/lists`),
  files 0640 in a 0750 directory; bans in APCu (gone with a restart) or as
  small files in store-dir.
- **Never banned:** addresses let in, trusted proxies, verified crawlers.
- **`set ban-keep file`:** a banned address is also kept in a file in
  store-dir until its ban ends (at most `ban-max`), so the ban survives a
  restart.

### Public blocklists (feeds, [docs](features/RSF01-03-blocklist-feeds.md))

- **Pull only:** the lists are fetched; nothing about the site's visitors is
  sent anywhere.
- **What:** third parties' addresses from public lists, kept in
  `<store-dir>/feeds` (0640) until the next fetch, used at most
  `feeds-max-age` (3 days) after it.
- **Basis:** security (Art. 6(1)(f)); a privacy notice can name the lists
  used. A refusal names the list's rule in the log, so a visitor hit by
  mistake can be let in.

### The live view (`set live on`, [docs](features/RSF06-02-live-and-lists.md))

- **What:** the last requests the shield stopped or checked (at most 2,000),
  each with the **full address**, the request, the user agent and the rule.
- **Where and how long:** with APCu in memory, gone with a restart; with the
  file store in `<store-dir>/live.log` (outside the document root, readable by
  the web server's user only, rotated past 500 KB so at most about two files
  of 2,000 lines exist). Each entry is shown for `live-keep` (an hour by
  default, at most a day); with `set store memory` nothing is kept.
- **Basis:** security (Art. 6(1)(f)): seeing an attack and keeping exactly its
  address out. Requests that pass are not kept. Without `set live on` the live
  view shows the log's lines, masked as the log keeps them.
- **Who sees it:** whoever the site lets open the dashboard (an address rule,
  its login).

### Statistics (being built: proposals [0012](proposals/0012-dashboard.md), [0014](proposals/0014-crawler-statistics.md))

- **Processed:** counters per hour — decisions, rules, status codes, known
  crawlers' visits and pages, bot families by User-Agent. **No visitors'
  addresses.** For verified crawlers the last visit's address is kept: an
  operator's server, not a person's. For pages not found, the referring page of
  the site itself, or — for other sites — **only their host**.
- **Kept:** hours 7 days, days 400 days, months for good (configurable).
- **Basis:** legitimate interest; aggregates without identifiers. **Consent:**
  not needed in many readings — nothing is read from or stored on the device.
- **Per-crawler logs** (`set crawler-log`): verified crawlers with their full
  address (operators' servers); requests that only claim a crawler's name are
  masked like the log; `crawler-log-days`, `crawler-log-query off`.

**Forms** (part `forms`): per form address and hour, how many were sent,
saved, gave an error or were stopped; where from as the path of the
website's own page, or only the host of another website. Never a field, a
value or a file name; nothing per visitor.

### Unique visitors and visits (planned: proposal [0022](proposals/0022-visitors-page.md), `set stats visitors on`)

- **Processed:** for each page view by a person, a hash (HMAC-SHA256) of the
  IP address and the browser string, keyed with a salt that is made at midnight,
  lives only in memory and is replaced the next day. The address itself is
  **never stored**, neither is the hash beyond the visit.
- **Kept:** per day a HyperLogLog sketch (4 KB of registers — no list of
  visitors, no hash in it can be read back); for a visit an entry with the hash,
  its start, its last page view and its count of pages, **deleted 30 minutes
  after the last page view**; aggregate counters (visits, bounces, duration,
  entry pages) like the other statistics.
- **Not done:** no cookie, no script, nothing read from the device, no linking
  across days (the salt is gone), no profile, nothing sent anywhere.
- **Objection — automatic, always honoured:** a browser sending `Sec-GPC: 1`
  (Global Privacy Control) or `DNT: 1` is never counted as a visitor: no hash,
  no visit, no country, no network area — only an anonymous page view. This is
  the objection "by automated means using technical specifications" of
  Art. 21(5) GDPR; there is no switch to turn it off. The page shows how many
  page views came with the signal.
- **Basis:** legitimate interest in measuring the site's reach, Art. 6(1)(f)
  GDPR, with immediate anonymisation; to be named in the privacy notice (text
  below). **Consent** under TDDDG §25: not needed in the common reading —
  nothing is stored on or read from the device; the EDPB's guidelines 2/2023 read
  Art. 5(3) ePrivacy more broadly (contested).
- **Default:** off. Switch it on knowingly, with the notice.
- **Compared with log statistics (AWStats):**

  | | AWStats (from log files) | `set stats visitors on` |
  |---|---|---|
  | IP address | in the log in full as long as it is kept; hosts in the monthly data files | never stored; hashed in memory, salt replaced daily |
  | Linking over time | possible while logs or data files exist | within one day only |
  | Unique visitors | distinct addresses per month | address + browser per day; a sketch, no list |
  | A visitor's "no" (GPC, DNT) | not honoured — the usual log format does not record it | always honoured: not counted, the address not used |
  | Consent (common practice) | none | none |

  So if a site runs log statistics today without consent, this option processes
  less and keeps nothing that points to a person.

**Countries** (`set stats geoip`, planned in 0022): the country is looked up at
the page view and only the country is counted; the address is not stored. The
lookup is cached per /24 (IPv6 /48) network for a day, in memory.

**Network areas** (`network …`, `stats-networks`, planned in 0022): page views
(and visitors) per area of an intranet — named ranges, or private addresses by
their first two or three octets, never finer than /24, small areas folded into
"other areas". Public addresses only by a range the site names. **In a company
intranet:** employee data — in Germany the works council's co-determination
(§87(1) no. 6 BetrVG) applies as soon as the numbers *could* be used to monitor
behaviour or performance; off until configured.

### Proposals

| Proposal | Personal data | Notes |
|---|---|---|
| [0015](proposals/0015-page-statistics.md) page statistics | none: path, day, group, number | no cookie, no script |
| [0018](proposals/0018-audience-statistics.md) sources, devices | none stored: host of the referring site, device class, browser family — from headers the browser sends | the screen-width beacon reads from the device: **consent**, off by default |
| [0022](proposals/0022-visitors-page.md) unique visitors and visits (also asked in [0018](proposals/0018-audience-statistics.md), [0019](proposals/0019-seo-geo-dashboard.md)) | a daily-salted hash of address + User-Agent, in memory; a visit's entry for 30 minutes | off by default; no consent in the common reading, a line in the privacy notice — see above |
| [0022](proposals/0022-visitors-page.md) countries, network areas | the country; the area of an intranet (named, or by two or three octets of private addresses) | countries: a GeoIP file; areas: off until configured, never finer than /24, works council |
| [0016](proposals/0016-rule-advisor.md) rule advisor, optional language model | only aggregated shapes and counts leave the server — never addresses, raw values, cookies | off unless configured |

## What the shield does not do

- No profiles, no tracking across sites, no fingerprinting.
- No third parties: nothing from the shield loads from elsewhere, nothing is
  sent elsewhere (except the optional DNS lookups above).
- No use of security data for other purposes — the pass cookie and the budget
  counters are never statistics.

## Data subject rights

The shield keeps no profile to hand out or correct: budget counters live for
seconds to minutes, statistics are aggregates without identifiers. What can hold
an address for longer: the log (masked by default; full with `log-ip full`),
per-crawler logs of requests that only claim a crawler's name (masked), and the
deny list and bans ([IP lists](features/RSF01-02-ip-lists.md)). For a request about those, search the files for the
address or its network — and keep their retention short, so there is little to
find.

## What to put in your privacy notice

*An example to adapt — not legal advice; your data protection officer decides
the wording and the legal basis.*

> **Protection against attacks.** To protect this website against attacks,
> overload and automated misuse, a security component runs before every page.
> It processes your IP address (for IPv6: your network prefix) to count requests
> per client for a short time (usually one minute) and, when unusually many
> requests arrive, may ask your browser to solve a small computational task. If
> it does, it sets a technically necessary cookie ("rsp", valid for one
> hour) so you are not asked again. No data is passed to third parties. Legal
> basis: our legitimate interest in the security of our website, Art. 6(1)(f)
> GDPR. [If the log is on:] Refused requests are logged with a shortened IP
> address for up to [N] days.

Add a sentence for each further feature you switch on (statistics: "counted in
aggregate, without IP addresses or cookies"; `log-ip full`: the full address and
its retention).

**With `set stats visitors on`** (planned, 0022) — in English:

> **Reach measurement without cookies.** To know how many people use our
> website and which pages they read, we count visits in aggregate. For this, the
> IP address and the browser identification your browser sends with every request
> are combined into a pseudonym (a keyed hash) that changes every day; the IP
> address itself is not stored, and the pseudonym is deleted 30 minutes after
> your last page view. Only aggregate numbers remain (for example "350 visitors
> on 1 October"); no cookie is set, nothing is read from your device, nothing is
> passed to third parties, and visits on different days cannot be linked. Legal
> basis: our legitimate interest in measuring the reach of our website,
> Art. 6(1)(f) GDPR. You can object at any time: if your browser sends the
> "Global Privacy Control" or "Do Not Track" signal, you are not counted as a
> visitor. [If countries are on:] From the IP address we determine the country
> only; it is not stored.

— in German:

> **Reichweitenmessung ohne Cookies.** Um zu wissen, wie viele Menschen unsere
> Website nutzen und welche Seiten sie lesen, zählen wir Besuche in
> zusammengefasster Form. Dazu werden die IP-Adresse und die Browserkennung, die
> Ihr Browser bei jedem Aufruf mitsendet, zu einem täglich wechselnden Pseudonym
> (einem mit einem geheimen Schlüssel gebildeten Hashwert) verrechnet; die IP-Adresse selbst wird nicht
> gespeichert, das Pseudonym wird 30 Minuten nach Ihrem letzten Seitenaufruf
> gelöscht. Übrig bleiben nur zusammengefasste Zahlen (zum Beispiel „350 Besucher
> am 1. Oktober“); es wird kein Cookie gesetzt, nichts von Ihrem Gerät gelesen,
> nichts an Dritte weitergegeben, und Besuche an verschiedenen Tagen lassen sich
> nicht verknüpfen. Rechtsgrundlage: unser berechtigtes Interesse an der Messung
> der Reichweite unserer Website, Art. 6 Abs. 1 lit. f DSGVO. Sie können jederzeit
> widersprechen: Sendet Ihr Browser das Signal „Global Privacy Control“ oder „Do
> Not Track“, werden Sie nicht als Besucher gezählt. [Wenn Länder aktiv sind:]
> Aus der IP-Adresse wird nur das Land ermittelt; es wird nicht gespeichert.

## Configured privacy-friendly — a checklist

```text
set log-level stop           # only what was refused or checked
set log-ip masked            # the default
set pass-ttl 1h              # or shorter
set crawler-verify ranges    # no DNS lookups (or a local resolver)
set stats off                # the default; on: aggregates only
set stats visitors off       # the default (planned, 0022); on: a daily hash in memory, a line in the notice
```
