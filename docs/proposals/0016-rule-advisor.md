# 0016 — The rule advisor: what to switch on, in plain words

| | |
|---|---|
| Status | **Draft** -- the replay is implemented (`request-shield replay`, [examples next to the rules](../features/RSF05-04-rule-examples.md#the-replay-your-own-clicks-as-a-test)), and the recording (`request-shield learn … start|stop|status`, [recording a learning run](../features/RSF05-04-rule-examples.md#recording-a-learning-run-learn): a cookie set with a bookmark or a header, `--for`, `--from`; one JSON line per request in `<store-dir>/learned.jsonl`); the suggestions are not |
| Proposed | 2026-09-30 |
| Affects | the counters of [0012](0012-dashboard.md), modes ([0004](0004-modes-monitor-and-strict.md)), the dashboard, rule files (a file the advisor writes), the command line (`learn`) |

## Summary

The shield watches what reaches the site — in monitor mode or while it
enforces — and turns it into **suggestions a site owner can act on**:
*"37 requests looked for WordPress's login this week, and this is no
WordPress site: include @wordpress."* Each suggestion comes with the rule line,
what it would have changed ("would have refused 37 requests, none from a real
visitor"), and one click to **try it in monitor first**, then enforce it.
Deterministic rules of thumb, no AI needed. A **learning run** — someone (or
the site's end-to-end tests) clicking through the application once — gives the
advisor a picture of good traffic to build the strict rules from, and the same
run **replayed** tests every change of the rules before it is enforced. An
**optional** language model
can explain findings and propose rules for new attack patterns — as ideas,
checked by the rule parser and tried in monitor like every other suggestion.

## In one picture

![Counters and the log feed the advisor; it writes suggestions in plain words, each with its rule line and what it would have changed; try in monitor, then enforce; an optional language model sees only aggregated patterns, never addresses, and its ideas go the same way](0016-rule-advisor.svg)

## Motivation

- Most sites will never write a rule. They need someone to say *what* to
  switch on — based on their own traffic, not a generic checklist.
- Monitor mode ([0004](0004-modes-monitor-and-strict.md)) already records what
  a rule *would* do; nobody reads a log for a week. The advisor reads it.
- Tuning a limit by guess is how sites end up checking real visitors (too low)
  or protecting nothing (too high). The data to set it right is there.

## Design

### Where it looks

The counters of 0012 (per rule, action, crawler, country), a few new ones kept
only while the advisor is on — **per-client request rate histogram** (how many
clients reached 10, 20, 50, 100, 200 … requests a minute), **unknown query
parameter names** (names only, counted), **404s answered by the site** per path
shape (the end-of-request hook of [0015](0015-page-statistics.md)) — and the
`monitor-…` lines of the log.

### What it suggests (first set)

| Finding | Suggestion |
|---|---|
| Requests for `/wp-login.php`, `/wp-admin/**`, `xmlrpc.php` on a site that is not WordPress | `include @wordpress` |
| Unknown parameters are only marketing tags | `include @tracking`, then `query strict` in monitor |
| A watched rule (`monitor block …`) fired only on scanners for 14 days | remove the word `monitor` |
| A watched rule fired on requests that look like real visitors | keep watching, or remove it — with examples |
| No real visitor went above 45 requests a minute; the limit is 600 | `limit requests 120/min challenge-at 60` — with how many clients each value would have met |
| Many 404s for `.php` paths that do not exist | `block regex \.php$` except the site's front controllers — only if no 200 answer had that shape |
| Requests claiming to be Googlebot from other addresses | nothing to do (they are treated as visitors) — said, so nobody worries |
| One address refused 5,000 times | `deny <address> until …` ([0013](0013-ip-lists.md)) |
| Monitor mode for 7 days, all would-be refusals were scanners | `set mode enforce` |

Every suggestion: a sentence, the numbers behind it, the rule line, and its
**simulated effect** — how many past requests it would have refused, checked or
let through, split into people / crawlers / bots. A suggestion that would have
refused anything a real visitor sent is marked, never pre-selected.

### Adopting a suggestion

- **Dashboard:** "try in monitor" writes the rule with `monitor` in front into
  `<store-dir>/advice.rules` (read after the site's rules, like the lists of
  0013); "enforce" removes the word; "undo" removes the line. Only lines built
  from the advisor's own templates are written there — never free text.
- **Command line:** `bin/request-shield advise site.rules` prints the
  suggestions with their rule lines, for people who keep rules in version
  control and add them by hand.

### A learning run: good traffic, on purpose

Waiting a week in monitor mode shows what the site gets; it takes a week, and
real traffic is mixed. A learning run shows what the site **needs** — in an
hour, and only from someone the site trusts:

![A learning run marks the requests of one person or of the site's end-to-end tests with a token; the shield records their shape -- paths, methods, parameter names and types, never values; the advisor suggests rules from it; the recorded run is replayed against every change of the rules: none of your clicks refused, before enforce](0016-learning.svg)

```text
$ php bin/request-shield learn start site.rules --for 2h
learning until 12:40 -- send this with every request: Request-Shield-Learn: 6f3c…  (or: --from 192.0.2.10)
$ php bin/request-shield learn stop site.rules
recorded 1,284 requests, 214 paths in 37 patterns, 9 forms, 12 parameters
```

1. **Mark the run:** `learn start` prints a token for a header or cookie (for
   the site's end-to-end tests: Playwright, Cypress), or ties the run to one
   address. The token **only marks** requests — it never lets one past a check
   (a leaked token would otherwise be a key), it expires, and only one run is
   active at a time.
2. **Go through the application:** by hand, or better automatically — the
   site's end-to-end tests, a walk through the sitemap, or the CMS's own URL
   index (the Exponential adapter knows every page). Signed in too, for the
   admin area.
3. **What is recorded is the shape, not the content:** paths, grouped into
   patterns (`/news/*` rather than 500 addresses); which methods where (a POST
   only to `/contact` and `/edit/**`); parameter names and the **type** of
   their values (`page` a number, `q` text, `sort` a word — never the values);
   form fields and sizes; hosts, content types. Kept in
   `<store-dir>/learned.json`, nothing else.
4. **What the advisor makes of it** — suggestions like the others, with their
   rule lines: `cache-path` for the patterns, `allow POST …` for the forms,
   `query page int  sort word` and `query strict` ([0009](0009-typed-query-parameters.md)),
   `challenge` for the login; *"12 paths were never used — block them?"*; and
   the other way round, *"your rules would refuse 3 of your own clicks"*.

A learning run is best for the rules that describe **what is allowed**
(`query strict`, `allow`, `restrict`): a clean run is the best source for them.
It cannot teach limits (one person is no measure of a crowd) — those keep
coming from monitor mode or the defaults.

### The replay: your own clicks as a test

The recorded run is a set of requests known to be good. `bin/request-shield
learn replay site.rules` sends them through the rules — simulated, as the rules
page's check does, nothing counted — and reports *"none of your 1,284 clicks
would be refused"*, or exactly which would be, and by which rule. Before
switching a rule from `monitor` to enforced, before `set mode enforce` or
`strict`, after an update of the library: a test that takes seconds, also in a
site's CI. The dashboard offers it next to every suggestion ("try it on your
learning run").

**Limits, and what to do about them.** No run clicks everything: rare admin
functions, error pages, deep paging, every search term, links from newsletters
with their own parameters. So the advisor generalises (`/news/2026/10/…` →
`/news/**`), includes `@tracking` by default, shows uncertain spots as
questions — and a learning run is followed by some days of monitor mode with
real traffic, which catches the gaps. Content changes (new pages) are why
patterns, and for a CMS its URL index, beat fixed paths.

### Optional: a language model

Off unless configured (`set advisor-llm <endpoint>` — a local model such as one
run with Ollama, or an API the site chooses). Two uses:

1. **Explaining** a finding in the site owner's language.
2. **Ideas for new attack patterns:** unusual requests the deterministic rules
   do not know — clustered first without a model (rare characters, long or
   encoded values, new parameter names, sudden new path shapes) — are
   described to the model, which may propose a rule.

Safeguards, not optional:

- **What leaves the server is aggregated and anonymised:** path shapes
  (`/wp-content/plugins/*/readme.txt`), parameter names, value *types and
  lengths*, counts, User-Agent families — **never addresses, never raw query
  values, never cookies or headers with personal data**.
- **Logged URLs are attacker-controlled text:** they are passed as quoted data
  with a fixed instruction, and anything the model returns is only ever a
  *proposal* — never executed, never written without a person.
- **A proposed rule must parse** (the rule-file parser), must not match any of
  the site's recent passing requests (the simulation above), and goes in as
  `monitor` first — like every other suggestion.

## Cost

| | Per request |
|---|---|
| advisor off (default) | nothing |
| advisor on | the counters of 0012, plus one histogram counter per client and minute and, for unknown parameters, one counter per name (~0.5–1 µs with APCu) |
| making suggestions | on demand (dashboard, `advise`), from the counters; never on a request |
| a language model | only on demand, from the dashboard; nothing per request |
| a learning run | only for the marked requests: one header or address compared per request while a run is active, and the shape of a marked request recorded (~5–10 µs); nothing when no run is active |
| a replay | on demand, outside any request (the rules page's simulation) |

## Privacy

Suggestions and simulations work on counters and path shapes. Examples shown
on the dashboard come from the log as the log keeps them (masked addresses).
Nothing is sent anywhere unless a model is configured, and then only the
aggregated, anonymised patterns described above. A learning run records only
the site's own run (its operator or its tests), and only shapes — no values, no
addresses of visitors.

## Open questions

1. Adopted suggestions in `advice.rules` (proposed) or printed only, for the
   site to add by hand? *Recommendation: both — the file for sites without
   version-controlled rules, `advise` for those with.*
2. How long to watch before suggesting `set mode enforce`? *Recommendation:
   7 days with no would-be refusal of a real visitor.*
3. A model at all in this library, or only a documented export
   (`advise --json`) that any tool, human or model, can read? *Recommendation:
   start with the export; add the built-in call only if sites ask for it.*
4. Should suggestions be sent (e-mail, the CMS's notifications)? *Recommendation:
   later; the JSON API of 0012 lets a CMS do it.*
5. The learning run: marked by a token (header or cookie) or by one address?
   *Recommendation: both; a token for end-to-end tests, an address for a person
   clicking by hand.*
6. What first: the replay, or the whole learning run? *Recommendation: the
   replay — it is small (the rules page can already simulate a request without
   counting it) and useful on its own, with a list of URLs as its input; the
   recording and the suggestions after it.*
