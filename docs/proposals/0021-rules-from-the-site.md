# 0021 — Rules from the site: the CMS tells the shield what a page needs

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-30 |
| Affects | rule files ([0003](0003-human-readable-rule-files.md)), the site asking for the check ([app challenges](../features/app-challenges.md), `requirePass()`), budgets counted by the site (`consume()`), the browser check in the form ([0010](0010-browser-check-in-the-form.md)), known parameters ([0009](0009-typed-query-parameters.md)), the rules pages, the Exponential adapter |

## Summary

The CMS knows things the rule file cannot: this page has a contact form, that
one is a checkout, this search is expensive, that address is a payment
provider's callback that must never meet a browser check, this page is
personal and must not be cached, these are the parameters a view takes. Today a
site owner has to write each of these into the rule file by hand, and keep it
up to date when an editor adds a form.

This proposal lets **the site say it itself**, in the language of the rule
files: a page answers with a header such as

```
Request-Shield-Rule: challenge **/contact max-age 10m
```

and the shield takes the header out (the browser never sees it), checks what
the rule may do, and **keeps it as a learned rule** for the following requests —
the next `POST /contact` meets the browser check. Rules from the site may always
**tighten**; they may **loosen** only where the rule file allows it; and some
things they can never touch.

It makes sense: the knowledge sits where it is maintained (an editor adds a form
— the form is protected), the rule file stays the one place that sets the
limits, and everything the site said is visible on the rules page like any
other rule.

## In one picture

![Request 1: the browser asks for the contact page, the shield lets it through, the CMS renders the form and adds the header "Request-Shield-Rule: challenge **/contact max-age 10m"; at the end the shield takes the header out, checks that it only tightens and keeps it for a day as a learned rule. Request 2: the form is sent; the shield checks it with the rule file and the learned rule — the browser check first, then the CMS. Below: always allowed — tighten; only where the rule file allows — loosen; never from the site — restricted areas, blocked addresses, the path check, sizes, trusted proxies, the mode.](0021-rules-from-the-site.svg)

## Why "the next request", not this one

The shield decides **before** the site runs; a response header exists only
**after** it. So what a page says can act in two places:

| When | What it can do | How today |
|---|---|---|
| **this response** | replace it with the check page, mark it "not for a cache", count an event against a budget | `X-Request-Shield-Challenge: required` (with `set app-challenge on`), `requirePass()`, `consume()` — already there |
| **the following requests** | a check for the form's `POST`, a limit for its address, the parameters a view takes, an exception for a callback | **new: learned rules** |

The first row exists; this proposal gives it one name and adds the second —
and, better still for the structure of a site, a CMS plugin that pushes the
rules before any page is shown (below).

## What the site can say

One header per rule, `Request-Shield-Rule`, in the rule-file language — the same
parser, the same checks, the same words on the rules page. From PHP in the same
process also without a header:

```php
Shield::active()?->learn('challenge **/contact max-age 10m', 'contact form');
```

**For this response** (a short word instead of a rule):

| Header | Does |
|---|---|
| `Request-Shield: challenge` | the check page instead of this page, unless the visitor has a pass (today's `X-Request-Shield-Challenge: required`, which keeps working) |
| `Request-Shield: no-cache` | the shield counts and logs it as "not for a cache"; with [0020](0020-cache-keys-without-tracking.md) a cache behind it skips it |
| `Request-Shield: count searches` | one against the budget "searches" (= `consume('searches')`) |

**For the following requests** (a rule, learned):

| The site says | Tightens or loosens | Example |
|---|---|---|
| `challenge <paths> [max-age …]` | tightens | a contact form, a checkout, a login |
| `limit <name> <n>/<time> at <paths>` | tightens (a new budget, or a lower one) | 3 comments a minute on a news page |
| `query <name> <type> … at <paths>` | **loosens** (with `query strict` it lets the parameter through; a typed value is not scanned by the attack patterns) | the parameters a view takes: `(offset) int`, `page int` |
| `no-limit <name> at <paths>` | **loosens** | a payment provider's callback |
| `challenge-exempt <paths>` | **loosens** | an address a machine calls, never a browser |
| `unblock <what> at <paths>` | **loosens** | `.well-known/acme-challenge` for a certificate |

## What the site may change — and what never

```
app-rules on                               # tighten: always (default: off)
app-rules relax at **/hooks/** **/.well-known/acme-challenge/**   # loosen: only here
app-rules ttl 1d                           # how long a learned rule lives without being said again
app-rules max 200                          # at most this many learned rules
```

- **Tighten — always** (when `app-rules on`): a check, a lower limit, stricter
  parameters, "not for a cache", counting an event.
- **Loosen — only at the paths `app-rules relax at` names.** Anywhere else a
  loosening rule is refused, logged (`app-rule refused: … not at a relax path`)
  and shown on the rules page as refused.
- **Never from the site:** `restrict` (who may reach an area), blocked addresses
  (except `unblock` at a relax path), the path check, sizes, website names,
  trusted proxies, the mode, the secret, the log. A site that is taken over must
  not be able to switch its own protection off.

**Who can send such a header?** Only the site's code sets response headers; a
visitor cannot. The shield also removes any `Request-Shield*` header a client
sends, and every `Request-Shield*` response header before the response leaves —
the browser never learns what the site told the shield. What stays is a header
injection bug in the site (a value from the visitor written into a header): the
rules above limit what such a bug could do to "more protection" everywhere
except the relax paths.

## Learned rules: where they live

- In the store directory (`learned.rules`, the same format as a rule file, with
  the page that said it and when), compiled like the rule files and checked for
  changes the same way (with APCu every 10 s) — **a normal request pays
  nothing extra**: the learned rules are part of the compiled settings.
- Written only when something changes: a page that says the same rule again
  refreshes its time at most once an hour (no write per request).
- IDs `APP-<n>`, stable per rule text; the log and the statistics name them like
  any rule (`rule=APP-3`).
- Past `ttl` without being said again, or past `max` (oldest first), a rule is
  dropped — a page that no longer has the form stops protecting it a day later.
- The rules pages show them as a file of its own ("learned from the site", with
  the page and the time), the rule tester applies them, and
  `bin/request-shield learned [list|forget <ID>|clear]` manages them.

## A CMS plugin that pushes the rules

Better than waiting for pages to speak: a **plugin in the CMS pushes the rules**
when something changes. It knows the whole site — every form, every address,
every view and its parameters, every siteaccess — and it can act the moment an
editor publishes, not when the first visitor comes.

![A CMS plugin pushes rules: an editor publishes a form, moves a page, adds a siteaccess or changes a view; the plugin builds the rules from what the CMS knows, checks them with the shield's parser and the limits for rules from the site, and writes cms.rules at once; every server reads it within 10 seconds or at once with reload. In the rule file the site owner decides what the plugin may do: include-app cms.rules (tighten always), app-rules relax at … (loosen only there). Several servers: a shared rule directory, or a signed push to each server.](0021-cms-plugin-push.svg)

**How:**

- The plugin listens to the CMS's events (publish, move, delete, a form
  added, a siteaccess changed) and builds the rule set from its data: a check
  and a limit for each form's address, the parameters each view takes, the
  areas only editors reach, the callbacks of its payment or newsletter
  extensions (at the relax paths).
- It writes it with the shield's own code — `RuleSet::write('cms', $lines,
  $storeDir)` — which parses every line with the rule-file parser, applies the
  limits for rules from the site, and only then puts the file in place (a new
  file, then renamed: a server never reads half a file). A line with a mistake
  stops the push and keeps the old file; the plugin shows the error in the
  CMS's admin interface.
- IDs come from the CMS's objects — `CMS-FORM-12`, `CMS-VIEW-3` — so the log
  and the statistics name the form, and the rules page links back to it.
- The rule file decides what the plugin may do:

  ```
  include-app cms.rules           # rules from the CMS: tighten always
  app-rules relax at **/hooks/**  # loosen only here
  ```

  `include-app` reads the file like `include`, but with the limits of rules from
  the site (tighten always, loosen only at `relax` paths, never `restrict`,
  proxies, the mode …). A site owner who trusts the plugin fully writes a plain
  `include` instead.
- Every server reads the new file within `recheck` (10 s with APCu) or at once
  after `reload` (the plugin can call it).
- **Several servers:** a shared rule directory (one file for all), or the plugin
  pushes to each server's endpoint (`POST /.request-shield/rules`, only from
  the CMS's addresses, signed with the shared secret and a timestamp, so a
  push cannot be forged or replayed).

The Exponential adapter (phase 3) is the first such plugin: it needs the URL
index anyway, and the HTTP cache's purge listener already tells it when content
changes.

| | Plugin pushes | Page headers / `learn()` | Written by hand |
|---|---|---|---|
| A new form protected | **when it is published** | from its second visitor | when someone edits the file |
| Pages served by a cache | **covered** | not refreshed | covered |
| "This page, now" (a check, count this) | no | **yes** | no |
| Reviewable, in one file | **yes** (`cms.rules`) | on the rules page | yes |
| A header injection bug can add rules | no | limited to tightening | no |
| Needs CMS code | a plugin | a header in a template | nothing |
| Cost per request | none (compiled) | none (compiled) | none |

**Recommendation:** the plugin for the structure of the site (forms,
addresses, views, areas, callbacks); a page header only for what a page knows
when it renders (a check now, count this search); learned rules from headers as
the fallback for a CMS without a plugin.

## Advantages and disadvantages

**For:**

- Protection follows the content: an editor adds a form, the form gets a check
  and a limit — without anyone touching the rule file.
- Exceptions where the site knows them (a payment callback, a certificate
  challenge), still bounded by the rule file.
- One language: the site writes rule lines; the parser, the checks, the rules
  page, the tester and the log are the same.
- `query strict` becomes practical for a CMS: it declares the parameters its
  views take, instead of a site owner guessing them — a loosening rule, so only
  where the rule file allows it (`app-rules relax at /**` for a site that
  trusts its CMS with that), or better through the written rule file.

**Against:**

- **Two authors of the protection.** The rule file stays the authority, but
  what is active is no longer only in it. Mitigation: the rules page shows every
  learned rule with its page and time, and `app-rules off` (the default) keeps
  today's behaviour.
- **First request not covered:** a form is protected from the second request
  on (after its page was rendered once). For a form that matters from the first
  second, the rule belongs in the (written) rule file.
- **Pages from a cache don't speak.** A page served by a cache in front of PHP
  never reaches the site, so it cannot refresh its rule; the `ttl` must be longer
  than the cache keeps pages (default: a day against minutes).
- **A header injection bug** in the site can add rules. Limited to tightening
  outside the relax paths; worst case some visitors see a check or a limit.
- A little more to learn for integrators: a header and a PHP call.

## Cost

A page without the header: none (the shield already looks at `headers_list()`
at the end of a page for the statistics). A page with it: parse and check the
line (a few microseconds; the result is cached by its text), and a write only
when the learned set changes.

## Open questions

1. **Header or PHP call first?** *Recommendation: both, the header as the
   general way (any template can set it), `learn()` for code.*
2. **One header with a rule line, or small words** (`Request-Shield: form=/contact`)?
   *Recommendation: rule lines — one language, nothing new to document.*
3. **Default `ttl`:** a day, or a week? *Recommendation: a day, refreshed when
   the page is shown.*
4. **Which loosening rules at all?** *Recommendation: `no-limit`,
   `challenge-exempt`, `unblock`, `query` — never `restrict` or anything about
   proxies. `allow POST` and `cache-path` stay out: whether they widen or narrow
   depends on what the rule file already says.*
5. **The plugin first, or the headers first?** *Recommendation: the plugin —
   `RuleSet::write()` and `include-app` first, with the Exponential adapter as the
   first user (its URL index is most of it); headers and learned rules after.*
6. **The push endpoint for several servers** in the first version, or a shared
   directory only? *Recommendation: a shared directory first; the signed
   endpoint when a site needs it.*
