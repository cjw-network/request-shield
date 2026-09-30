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

### Proposals

| Proposal | Personal data | Notes |
|---|---|---|
| [0013](proposals/0013-ip-lists.md) deny list, automatic bans | addresses, each with a reason and an end date; bans end by themselves (at most `ban-max`) | security; purpose and duration explicit; review permanent entries |
| [0015](proposals/0015-page-statistics.md) page statistics | none: path, day, group, number | no cookie, no script |
| [0018](proposals/0018-audience-statistics.md) sources, devices | none stored: host of the referring site, device class, browser family — from headers the browser sends | the screen-width beacon reads from the device: **consent**, off by default |
| [0018](proposals/0018-audience-statistics.md), [0019](proposals/0019-seo-geo-dashboard.md) distinct visitors | a daily-salted hash of address + User-Agent, in memory for one day | opt-in only, contested; never a cookie of its own, never fingerprinting |
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
deny list and bans of 0013. For a request about those, search the files for the
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
> it does, it sets a technically necessary cookie ("rs_pass", valid for one
> hour) so you are not asked again. No data is passed to third parties. Legal
> basis: our legitimate interest in the security of our website, Art. 6(1)(f)
> GDPR. [If the log is on:] Refused requests are logged with a shortened IP
> address for up to [N] days.

Add a sentence for each further feature you switch on (statistics: "counted in
aggregate, without IP addresses or cookies"; `log-ip full`: the full address and
its retention).

## Configured privacy-friendly — a checklist

```text
set log-level stop           # only what was refused or checked
set log-ip masked            # the default
set pass-ttl 1h              # or shorter
set crawler-verify ranges    # no DNS lookups (or a local resolver)
set stats off                # the default; on: aggregates only
```
