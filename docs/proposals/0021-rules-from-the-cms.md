# 0021 — Rules from the CMS: a plugin pushes what the site needs

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-30 |
| Affects | rule files ([0003](0003-human-readable-rule-files.md): `include`, `recheck`, `reload`), budgets, the browser check ([app challenges](../features/RSF03-04-app-challenges.md), [0010](0010-browser-check-in-the-form.md)), known parameters ([0009](0009-typed-query-parameters.md)), the rules pages, the Exponential adapter |

## Summary

The CMS knows things the rule file cannot: this page has a contact form, that
one is a checkout, this area is only for editors, that address is a payment
provider's callback that must never meet a browser check, these are the
parameters a view takes. Today a site owner writes each of these into the rule
file by hand — and has to keep it up to date whenever an editor adds a form or
moves a page.

This proposal lets **a plugin in the CMS push the rules** when something
changes: it builds a rule file from what the CMS knows, the shield checks every
line with its own parser and within limits the site owner sets, and every
server applies it within seconds. The rules from the CMS may always
**tighten**; they may **loosen** only where the rule file allows it; and some
things they can never touch.

It makes sense: the knowledge sits where it is maintained (an editor publishes
a form — the form is protected, from its first visitor on), the rule file stays
the one place that sets the limits, and what the CMS pushed is one file,
visible on the rules page like any other.

## In one picture

![A CMS plugin pushes rules: an editor publishes a form, moves a page, adds a siteaccess or changes a view; the plugin builds the rules from what the CMS knows, checks them with the shield's parser and the limits for rules from the site, and writes cms.rules at once; every server reads it within 10 seconds or at once with reload. In the rule file the site owner decides what the plugin may do: include-app cms.rules (tighten always), app-rules relax at … (loosen only there). Several servers: a shared rule directory, or a signed push to each server.](0021-cms-plugin-push.svg)

## How it works

- **The plugin listens to the CMS's events** — publish, move, delete, a form
  added, a siteaccess or a view changed — and builds the rule set from its
  data, for example:

  ```
  [CMS-FORM-12]   challenge **/contact max-age 10m          # contact form (object 12)
  [CMS-FORM-12L]  limit contact 3/10m at **/contact          # the same form: 3 messages in 10 minutes
  [CMS-AREA-5]    challenge **/members/**                    # members' area
  [CMS-VIEW-3]    query offset int   page int at **/news/**  # the news view's parameters
  [CMS-HOOK-1]    no-limit requests at **/hooks/payment      # the payment provider's callback
  ```

- **It writes it with the shield's own code**: `RuleSet::write('cms', $lines,
  $dir)` parses every line with the rule-file parser, applies the limits below,
  and only then puts the file in place (a new file, then renamed — a server
  never reads half a file). A line with a mistake, or one the limits refuse,
  stops the push and keeps the old file; the plugin shows the error in the
  CMS's admin interface.
- **IDs come from the CMS's objects** (`CMS-FORM-12`), so the log, the
  statistics and the rule tester name the form, and the rules page can link
  back to it in the CMS.
- **Every server reads the new file** within `recheck` (10 s with APCu), or at
  once after `reload` — the plugin calls it after a push. A normal request pays
  nothing extra: the file is compiled with the others.
- **Several servers:** a shared rule directory (one file for all), or the
  plugin pushes to each server (`POST /.request-shield/rules`, only from the
  CMS's addresses, signed with the shared secret and a timestamp, so a push can
  be neither forged nor replayed).

The **Exponential adapter** (phase 3) is the first such plugin: it needs the URL
index anyway, and the HTTP cache's purge listener already tells it when content
changes.

## What the CMS may change — and what never

The rule file decides:

```
include-app cms.rules                                             # rules from the CMS, within the limits
app-rules relax at **/hooks/** **/.well-known/acme-challenge/**    # where they may loosen
```

- **Tighten — always:** a check (`challenge`), a new or lower limit (`limit`),
  counting only the site does (`limit … on-demand`).
- **Loosen — only at the paths `app-rules relax at` names:** `no-limit`,
  `challenge-exempt`, `unblock`, and `query` (a declared parameter is let
  through by `query strict`, and a typed value is not scanned by the attack
  patterns). Anywhere else such a line stops the push with the reason.
- **Never from the CMS:** `restrict` (who may reach an area), blocked
  addresses outside the relax paths, the path check, sizes, website names,
  trusted proxies, `set …` (the mode, the store, the secret, the log). A site
  that is taken over must not be able to switch its own protection off.
- `allow POST` and `cache-path` stay out: whether they widen or narrow depends
  on what the rule file already says.
- A site owner who trusts the plugin fully writes a plain `include cms.rules`
  instead — then it is a rule file like any other.

## Advantages and disadvantages

**For:**

- Protection follows the content: an editor publishes a form, the form gets a
  check and a limit — from its first visitor on, without anyone touching the
  rule file.
- Pages served from a cache are covered: the rules do not depend on a page
  being rendered.
- Exceptions where the CMS knows them (a payment callback, a certificate
  challenge), still bounded by the rule file.
- One language and one file: the parser, the checks, the rules page, the tester
  and the log are the same as for hand-written rules; `cms.rules` can be
  reviewed and kept in version control.
- `query strict` becomes practical for a CMS: it declares the parameters its
  views take, instead of a site owner guessing them (at the relax paths, or
  with a plain `include`).

**Against:**

- **Two authors of the protection.** The rule file stays the authority, but
  what is active is no longer only in it. Mitigation: the rules page shows
  `cms.rules` as a file of its own, with the time of the last push; without
  `include-app` nothing changes.
- **A plugin per CMS.** Exponential first; WordPress and Ibexa later (phase 4).
  A site without a plugin keeps writing its rules by hand.
- **A push can fail** (a mistake in a generated line): the old file stays, the
  plugin must show it — protection is then as old as the last good push.

## Rejected: rules from response headers

A page could say what it needs in a response header
(`Request-Shield-Rule: challenge **/contact`), and the shield would keep it as a
learned rule for the following requests. **Rejected**, because:

- the shield decides *before* the site runs, so a header only helps from the
  **second** visitor of a page on — the plugin protects the first;
- pages served from a cache never reach the site, so they could not keep their
  rules alive;
- a **header injection bug** in the site (a visitor's value written into a
  header) could add rules;
- learned rules are state that lives outside any file: harder to review, to
  reproduce and to explain.

What a page needs *for itself, now* stays as it is: `requirePass()`, the check
in the form, `consume()` for an event, and — with `set app-challenge on` — the
`X-RS-Check: 1` header.

## Cost

None per request (the pushed file is compiled with the others). A push: parse
and check the lines (milliseconds for hundreds of rules), one write, one
rename, and a `reload`.

## Open questions

1. **`include-app` as its own word, or `include … as app`?** *Recommendation:
   `include-app` — it stands out in the rule file.*
2. **Which loosening rules at all?** *Recommendation: `no-limit`,
   `challenge-exempt`, `unblock`, `query` — never `restrict` or anything `set`.*
3. **The push endpoint for several servers** in the first version, or a shared
   directory only? *Recommendation: a shared directory first; the signed
   endpoint when a site needs it.*
4. **The first plugin:** the Exponential adapter, writing on publish from its URL
   index and the forms of its content classes? *Recommendation: yes.*
