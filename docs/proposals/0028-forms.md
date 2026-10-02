# 0028 — Forms: counted, and sent only from the website itself

| | |
|---|---|
| Status | **Phase 1 implemented** 2026-10-02 ([forms from the website](../features/forms-from-the-website.md)): `post-origin`; phase 3 (`limit` inside `match` blocks) came with [0008](0008-match-blocks.md) step 2; phase 2 (the form counters, `backend`) to come. Decisions below: the recommendations |
| Proposed | 2026-10-01 |
| Affects | the statistics plugin (a card "Forms"), rule files (new rules `post-origin`, `backend`; `limit` inside `match` blocks, [0008](0008-match-blocks.md) step 2), the live view, the rules page |

## Summary

Forms are where a website takes something in, and where most attacks and
spam arrive. The shield already protects them in parts: the pace per visitor,
`allow POST <paths>` (forms only where they belong), the browser check inside
the form ([0010](0010-browser-check-in-the-form.md)). This proposal adds two
things:

1. **Forms counted:** per form, how often it was sent, **from which page of
   the website**, and how it ended (saved, an error, stopped by the shield, and
   why). The backend, where editors save all day, **counted apart**.
2. **Forms sent only from the website itself:** `post-origin same` refuses a
   form that another website sends in the visitor's name (cross-site request
   forgery). It checks the browser's `Origin` header, else the `Referer`.

Together: forms protected end to end (where from, the pace, the check in the
form) and counted, with one rule file and no service.

## In one picture

![A form sent passes: where it comes from (Origin, else Referer: one of the website's own names; payment callbacks, single sign-on and webhooks are exceptions; neither header: the browser check by default), the pace (the backend with a budget of its own), the browser check inside the form, the site. Counted per form: sent, from which page, how it ended; the backend apart. Nothing of what was typed is kept.](0028-forms.svg)

## 1. Forms counted

What a site owner wants to know, and today only finds in the web server's log:

- which forms are used at all, and from which pages (the contact form from the
  contact page, or from the product pages too);
- which ones break: sent, then answered with an error (a 4xx or 5xx);
- spam waves: many submissions in a short time, often **without a page
  before** (no Referer, or a foreign one);
- what the shield stopped where: another website, the pace, the check.

**What is counted** (the statistics plugin, `set stats on`):

| Counter | Meaning |
|---|---|
| `f:<path>` | a form sent: POST (also PUT, PATCH, DELETE) outside the APIs (`api-path`) |
| `fo:<path>\|saved` / `error` | how the site answered: 2xx/3xx saved, 4xx/5xx an error |
| `fo:<path>\|refused` / `checked` / `throttled` / `cross-site` | stopped by the shield, and how |
| `ff:<path>\|<page>` | where from: the path of the page on the website (the Referer's), `(another site) <host>`, or `(none)` |

- Kept per hour like the pages, with the same limits (the most sent kept, the
  rest "(other)"). With `stats-hosts` per website, with the website in front
  when several are read together.
- **Never** what was typed: no field, no value, no file name. A foreign page
  only by its host.
- **The backend apart:** `backend /admin/**` (or the CMS's admin paths) marks
  the editors' area. Its submissions are counted as one entry per area
  ("/admin/**: 1,240 saves, 4 errors"), not per address. Saving, autosave and
  the editor's AJAX calls do not drown the visitors' forms.
- **The card "Forms"** on the visitors page (and in the protection view): each
  form with sent, from where, saved / errors / stopped; the backend below;
  a click on "stopped" opens the live view filtered to that form.

## 2. Forms only from the website itself: `post-origin`

```text
[F-ORIGIN]   post-origin same                                   # forms only from this website's own pages
[F-ORIGIN]   post-origin same missing check except /pay/notify /sso/acs   # the usual setup
```

- **What "same" means:** the host of the `Origin` header is one of the
  website's own names: every name of the `host` rule, or of the site block
  (`site shop.a.de a.de { … }`), `*.domain` included. Without a `host` rule,
  the name the request was sent to. So a backend on `admin.example.org` saving
  to `www.example.org` is "same" when both are named.
- **Origin first, Referer as the fallback.** Modern browsers send `Origin` with
  every POST; many visitors suppress the Referer. `Origin: null` (sandboxed
  frames, privacy redirects) counts as missing.
- **Neither header** (`missing`): `check` (the default: the browser check, then
  through: never locks an editor out), `allow`, or `refuse`.
- **Another website:** 403, reason "a form sent from another website", the
  rule's ID in the log and the live view. `monitor post-origin …` first, to see
  what it would refuse.
- **Never for:** `except <paths>` (a payment provider's callback, single sign-on
  answers posted from the identity provider (SAML, OAuth `form_post`),
  webhooks), the APIs (`api-path`, they authenticate otherwise), addresses let
  in (`exempt`).
- **What it is for, honestly:** it stops **a foreign page sending a form in
  the visitor's name** (cross-site request forgery). This is what OWASP
  recommends as a defence ("verifying origin with standard headers"), and some
  frameworks check it. It is **not** a bot defence: a script sets `Origin` and
  `Referer` to anything it likes. Against bots, the browser check inside the
  form does the work. Most valuable for CMSs and plugins without form tokens of
  their own.

## 3. The backend: editors save all day

- `backend <paths>` (also inside a `site` block): the editors' area.
  - Counted apart (above).
  - **Its own pace:** `match /admin/** { limit edits 600/min }`, a budget for
    the area only. This is [0008](0008-match-blocks.md) step 2 (`limit` inside
    `match` blocks), built with this proposal. The visitors' pace stays strict,
    and many editors behind one office address with autosave every few seconds
    do not run into it. (Today: `restrict /admin/** to <office>` together with
    `exempt <office>` never counts them at all.)
  - `post-origin` applies there too: the editors' browser sends the website's
    own `Origin`.
- The shield never looks at who is signed in: no session, no cookie of the
  CMS. The area is a path, as for every other rule.

## Cost

| | |
|---|---|
| a GET | nothing new |
| a POST, `post-origin` | the Origin's host compared with the website's names (one lookup) |
| a POST, counted | two or three counters (APCu: ~1 µs) |
| the backend | one path match (as `restrict`) |

## Privacy

- No field contents, ever. The page a form was sent from: a path of the
  website itself, or only the host of a foreign one.
- The counts are aggregates per hour, like the pages; nothing is kept per
  visitor.

## Phases

1. `post-origin same` (with `missing`, `except`, `monitor`), the reason
   "cross-site" in the log, the live view, the rules page, the statistics'
   "stopped".
2. The form counters and the card "Forms"; `backend <paths>`, counted apart.
3. `limit` inside `match` blocks (0008 step 2): the backend's own pace.

## Decisions (2026-10-02)

1. `missing check` is the default: a browser solves the check once, a script
   without the headers has to every time.
2. POST, PUT, PATCH and DELETE outside `api-path` count as forms.
3. The backend is counted per area, not per address (phase 2).
4. `post-origin` is never on by default; it is named in the rule file, and the
   docs show it with `monitor` first.
5. "A wave of POSTs without a page before" as a ban signal: later, once the
   statistics show how often it happens.

Built in phase 1 beyond the text: `post-origin except <paths>` on a line of its
own adds exceptions (and switches nothing on); `expect … header <Name>:<value>`
for examples that need `Origin` or `Referer` ([0029](0029-rule-examples.md)).

## Open questions (as proposed)

1. **`missing`:** `check` by default (proposed), or `allow`? *Recommendation:
   `check`: a browser solves it once; a script without the headers has to.*
2. **Which methods count as a form:** POST only, or also PUT, PATCH,
   DELETE? *Recommendation: all four outside `api-path`. Inside it, the APIs
   are counted as now.*
3. **The backend per address or per area?** *Recommendation: per area
   (`/admin/**`): one line, not hundreds of edit URLs with IDs in them.*
4. **`post-origin` on by default?** *Recommendation: no, named in the rule file;
   the setup example shows it with `monitor` first.*
5. **"A wave of POSTs without a page before" as a ban signal** ([0013](0013-ip-lists.md))?
   *Recommendation: later. First see in the statistics how often it happens on
   real sites.*
