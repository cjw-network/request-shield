# 0016 — The rule advisor: what to switch on, in plain words

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-30 |
| Affects | the counters of [0012](0012-dashboard.md), modes ([0004](0004-modes-monitor-and-strict.md)), the dashboard, rule files (a file the advisor writes) |

## Summary

The shield watches what reaches the site — in monitor mode or while it
enforces — and turns it into **suggestions a site owner can act on**:
*"37 requests looked for WordPress's login this week, and this is no
WordPress site: include @wordpress."* Each suggestion comes with the rule line,
what it would have changed ("would have refused 37 requests, none from a real
visitor"), and one click to **try it in monitor first**, then enforce it.
Deterministic rules of thumb, no AI needed. An **optional** language model
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

## Privacy

Suggestions and simulations work on counters and path shapes. Examples shown
on the dashboard come from the log as the log keeps them (masked addresses).
Nothing is sent anywhere unless a model is configured, and then only the
aggregated, anonymised patterns described above.

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
