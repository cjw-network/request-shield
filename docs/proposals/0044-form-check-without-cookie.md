# 0044 — The check in the form without a cookie

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-08 |
| Affects | the check inside the form (`widget.js`, the endpoint, `requirePass()`), `Challenge\Gate` (which cookies it sets), the check page's resend, the settings (`pass-cookie`), `check` |
| Relates to | [RSF03-02 the browser check](../features/RSF03-02-browser-challenge.md) · [RSF03-03 the check inside the form](../features/RSF03-03-browser-check-in-the-form.md) · [ADR 0004 stateless, signed challenges](../adr/0004-stateless-signed-challenge-and-pass.md) · [privacy](../privacy.md#the-browser-check-proof-of-work) · [0035 JSON forms](0035-checked-json-forms.md) · [0042 a harder task for forms](0042-pow-v2-for-forms.md) |

## The question

ALTCHA sets no cookie at all
([its GDPR page](https://altcha.org/legal/compliance/gdpr/)). How does it
know a visitor passed? **It does not keep a pass.** Its widget solves a task
for one form, puts the answer (task, number, signature) into a hidden field,
and the answer goes to the server with the form, in the same request. The
server checks the signature and the expiry, and remembers that this answer
was used. The next form: a new task. Nothing has to stay in the browser.

The shield does the same inside the form today -- the answer travels in the
hidden field `rss` ([RSF03-03](../features/RSF03-03-browser-check-in-the-form.md)).
But when the answer is accepted, it **also sets the pass cookie** (an hour
by default), so the visitor's next pages and forms need no new task. That
cookie is what a site has to explain in its privacy notice
(*strictly necessary, § 25(2) no. 2 TDDDG -- to be confirmed*).

**The proposal:** a switch for sites that only protect forms and want no
cookie from the shield at all -- *the answer in the form field, and nothing
else*.

## Who wants this

- **A public body or an association** whose privacy notice says "this
  website sets no cookies". A contact form with a spam problem should not
  change that sentence.
- **A site with a strict cookie policy or a consent tool** that lists every
  cookie: the shield's pass would be one more line to justify, and some data
  protection officers do not accept "strictly necessary" without a debate.
- **A site that only needs forms protected**: no budgets that ask for the
  check on pages, no check page at the sign-in -- just the contact form, the
  comment box, the newsletter sign-up.

## Today and with the switch

```
Today (pass cookie)
  form page ──► box fetches a task ──► browser solves ──► answer in field rss
  send ──► shield: answer valid, first use ──► form goes through
                                           └─► Set-Cookie: pass (1 h)
  next form ──► box: "passed" at once (the cookie) ──► send ──► through

With  set pass-cookie off
  form page ──► box fetches a task ──► browser solves ──► answer in field rss
  send ──► shield: answer valid, first use ──► form goes through
                                           └─► no cookie
  next form ──► box fetches a NEW task ──► solves ──► send ──► through
```

For the visitor nothing changes: the box solves while they type, 0.1–0.5
seconds, a few seconds on an old phone. They solve once per form sent
instead of once an hour -- usually unnoticed, because it happens while they
type.

## What changes

1. **`set pass-cookie off`** (default `on`, today's behaviour). Off:
   - an accepted answer from the form field lets **this request** through and
     sets no pass cookie;
   - the endpoint never answers `{"passed": true}` (there is no pass to
     report): every form view gets a task;
   - `requirePass()` accepts only the answer in this request; `requirePass(300)`
     ("a pass from the last 5 minutes") means the answer's own age.
2. **Used once, as today.** An answer can be sent only once: the shield
   already remembers a used answer (a hash, for its few minutes of life) in
   the store. That needs a store (APCu or a writable directory, tiers S1/S2).
   Without one (S0) an answer could be sent again within its few minutes --
   `check` says so, as it does today for the pass's secret.
3. **Without JavaScript.** Today: the form is sent without an answer, the
   check page comes, solves, and sends the form again by itself (a form
   without files) -- carrying the answer in a short-lived **solution cookie**.
   With the switch off, the check page puts the answer **into the form it
   sends again** instead (as a hidden field). A form with files cannot be sent
   again by the check page today either; that stays a limit (see 0038 for a
   way without JavaScript).
4. **The check on pages needs a cookie** -- a budget's `challenge-at`,
   `challenge /login` for a page someone opens, the pace of page views. A GET
   request has no form field to carry an answer, and without a pass the
   visitor would be checked on every page. With `pass-cookie off`:
   - **proposed:** `check` refuses the combination with a clear message
     ("pass-cookie off: only the check in forms -- `challenge-at` in
     `limit requests` needs the pass cookie; use a pause instead
     (`limit … ` without `challenge-at`)");
   - `challenge POST <paths>` (forms and POST endpoints) still works: the
     answer comes in the form or, at an `api-path`, in the
     `Request-Shield-Solution` header -- also without a cookie.
5. **APIs.** At an `api-path` the client already sends its answer in a header
   (`Request-Shield-Solution`); today an accepted one sets the pass cookie too,
   which a program usually ignores. With the switch off: no cookie there either.

## Privacy

With `pass-cookie off` and the log off (the default), **the shield sets no
cookie and stores nothing about a visitor** beyond the budgets' counters
(seconds to minutes, per address) and the used answers' hashes (minutes). The
question of § 25 TDDDG does not arise for the shield. That is the same
position ALTCHA describes for itself. The privacy notice can say: *"To keep
automated spam out, your browser solves a small computing task when you send
a form. No cookie is set, nothing is read from your device."* -- *to be
confirmed by the data protection officer; not legal advice.*

## Cost

- **Nothing on the passing path** when the switch is on (the default) or when
  no form is checked: one setting more in the compiled settings.
- Off: no `Set-Cookie` header (a little less to send); every form view asks
  the endpoint for a task (~12 µs on the server, as today for a first visit);
  the browser solves once per form instead of once an hour.
- Bots: unchanged -- every form they send costs them a solve, as today; even
  a little more, because a pass can no longer be reused for an hour.

## Tests (when built)

- An accepted answer in the form sets no `Set-Cookie` with `pass-cookie off`;
  with it on (default) it does, as today.
- The same answer sent twice: the second is refused (store), with the switch
  off as on.
- The endpoint never answers `passed` with the switch off.
- The check page without JavaScript sends the form again with the answer as a
  field, no solution cookie.
- `check` refuses `pass-cookie off` with `challenge-at` and with
  `challenge <GET paths>`; accepts it with `challenge POST …`.
- End to end (`examples/demo`): the contact form sent twice, two tasks, no
  cookie in either answer.

## Open questions for the owner

1. **The name:** `set pass-cookie off`, or `set check forms-only`, or a word
   in `widget-path` (`set widget-path /rs-check cookieless`)?
2. **Pages that ask for the check** with the switch off: refuse in `check`
   (proposed), or turn a `challenge-at` into a pause (429) by itself -- which
   is quieter, but changes what a rule says?
3. **S0** (no store): allow the switch with a warning (an answer could be
   sent again for a few minutes), or refuse it?
4. **Forms over several steps** (a wizard): one task per step, or the first
   step's answer carried along by the site? Proposed: one per step -- it is
   solved while the visitor types.
5. **The showcase:** show both side by side ("with pass" / "without cookie"),
   so a site can see the difference?
