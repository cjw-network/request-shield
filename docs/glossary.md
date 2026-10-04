# Glossary

The words these docs use, in plain language. Each page for people links a word
here the first time it uses it. A word in *italics* has its own entry.

### Address

*Also:* IP address, range

The number a visitor's computer uses on the internet, such as `198.51.100.7`.
A range is a block of addresses written with a slash: `192.0.2.0/24` means
the 256 addresses from `192.0.2.0` to `192.0.2.255`. An office or a hoster
usually has a range.

### APCu

A memory inside PHP that the shield keeps its counters in. With it, a
*request* costs the shield about 12 µs; without it, the counters go to files
and a request costs about 42 µs. Neither is noticeable to a visitor.

### Ban

*Also:* banned

An *address* kept out for a while, either by hand (`request-shield deny`) or
by itself, after it kept knocking at closed doors. A ban ends on its own; each
new ban of the same address lasts longer.

### Blocklist

*Also:* feed, feeds

A public list of addresses known for attacks, such as Spamhaus DROP. The
shield fetches it on a schedule and keeps those addresses out, or only checks
them, as the *rule file* says.

### Bot

*Also:* bots

A program that asks for pages without a person in front of it: a script, a
scraper, a tool such as `curl`. A *crawler* is a bot that says who it is.

### Browser check

*Also:* challenge, check page

A small task that a real browser solves by itself in a moment, without
anything to click. A program that only pretends to be a browser fails it.
Afterwards the visitor holds a *pass cookie*. See [the browser check, in plain
words](explained/browser-check.md).

### Budget

*Also:* budgets, pace, limit

How many *requests* one visitor may make in a time, such as 600 a minute.
Above part of it the visitor gets the *browser check*; above all of it,
"please wait" (*status code* 429).

### Cache

*Also:* page cache, cacheable

A store of finished pages, so that the site does not build the same page
twice. The shield keeps made-up addresses out of it: they are answered, but
not kept.

### Crawler

*Also:* crawlers, AI crawler, search engine

A *bot* that reads whole websites and says who it is: Googlebot for search,
GPTBot for AI training. The shield recognises the real ones by their
*addresses* and lets them in, checks them or refuses them, as the *rule file*
says.

### Dashboard

*Also:* rules page, live view

The shield's own pages in the browser, at `/rs/` by default: the *statistics*,
the active rules, the live view of what is stopped right now, and the lists.
Guard them: a rule that lets only the office in, or a *token*.
`request-shield check` warns while nothing guards them.

### Enforce

The normal *mode*: the rules act as written. Compare *monitor* and *strict*.

### Extension

*Also:* extensions

A part that adds words of its own to the *rule file*, such as the
*statistics*' `set stats on`. Every extension is also a *plugin*, or brings
one.

### False positive

*Also:* false hit, false positives

A real visitor refused by mistake. The *log* names the rule that did it; that
rule can be opened for one path or one *address range*.

### Front controller

The one PHP file that every page of a site starts in, such as `index.php`.
The shield runs before it.

### Log

A file with one line per refused or checked *request*: when, which *address*,
which path and which rule. Off until the *rule file* names the file.

### Microsecond

*Also:* µs, ms, millisecond

A millionth of a second (µs). A millisecond (ms) is a thousand of them. The
shield decides a passing request in about 12 µs with *APCu*, 42 µs without. A
site builds a page in 100 to 200 ms, thousands of times as long.

### Mode

*Also:* modes

How hard the shield acts, for the whole site: off, *monitor*, *enforce* or
*strict*.

### Monitor

*Also:* monitor mode

The *mode* for trying rules: everything is checked and written to the *log*,
nobody is refused. Single rules can be watched the same way.

### Pass cookie

A note in the visitor's browser, a cookie, that says the *browser check* was
solved. While it lasts, about an hour, the visitor is not checked again.

### Plugin

*Also:* plugins

A piece of code that hears what the shield decided, for every *request*: the
*statistics* are one. A plugin may refuse more than the rules do, never less.

### Proxy

*Also:* trusted proxy, load balancer, reverse proxy, CDN

A server in front of the website that passes requests on, and with them the
visitor's real *address*. The shield believes that address only from a proxy
the *rule file* names.

### Query parameter

*Also:* query parameters, parameter

The part of an address after the `?`, such as `?page=2`. The shield can allow
only the parameters a site uses, each of its type.

### Request

*Also:* requests

One question a browser or a program asks a website: one page, one picture,
one form sent. A page in a browser is usually dozens of requests.

### Rule

*Also:* rules

One line in the *rule file* that says what the shield does, such as `restrict
/admin/** to 192.0.2.0/24`.

### Rule example

*Also:* rule examples, expect

A line next to a rule that says what should happen to one *request*, such as
"`/.env` is refused". `request-shield test` checks every example, so a rule
change that breaks one is seen before it goes live.

### Rule file

*Also:* rule files

A plain text file with the shield's *rules*, one per line, usually
`request-shield.rules` next to the shield. See [rule files](features/RSF05-01-rule-files.md).

### Rule ID

*Also:* rule IDs

A rule's name in square brackets, such as `[SITE-ADMIN]`. The *log*, the
*statistics* and every refusal name it, so the rule behind a decision is
always found.

### Scanner

*Also:* scanners

A *bot* that tries thousands of known weak spots on every site it finds:
password files, backups, old admin tools. Most requests the shield refuses
come from scanners.

### Site block

*Also:* site blocks

A part of the *rule file* with rules for one website only, when one shield
protects several websites.

### Statistics

The shield's counts per hour: visitors, *crawlers*, *bots*, pages, what was
refused. They are a *plugin*, switched on with `set stats on`, and shown on
the *dashboard*.

### Status code

*Also:* status codes, 403, 404, 429

The number an answer begins with. 200: here is the page. 403: no access.
404: not found. 429: too many requests, please wait.

### Store directory

*Also:* store-dir

The folder where the shield keeps its counters, lists and *statistics*. It
must be outside the website's public folder.

### Strict

*Also:* strict mode

The *mode* for a site under attack: the *browser check* comes much earlier,
and a *pass cookie* lasts at most 15 minutes. Search engines stay welcome.

### Tier

*Also:* tiers

What a hosting lets the shield do: with *APCu* (fastest), with files, or with
neither. `request-shield check` names the tier.

### Token

*Also:* tokens, access token

A long secret word that opens the *dashboard*, for the admin or for one
customer's websites. The *rule file* holds only a fingerprint of it, so
reading the rules does not let anyone in.

### WAF

*Also:* web application firewall, firewall

A web application firewall: a gatekeeper in front of a website that refuses
attacks before the site runs. request-shield is a small one, written in PHP.
