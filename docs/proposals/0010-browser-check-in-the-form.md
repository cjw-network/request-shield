# 0010 — The browser check inside the form

| | |
|---|---|
| Status | **Implemented** 2026-09-29 (see [the browser check inside the form](../features/browser-check-in-the-form.md)) |
| Proposed | 2026-09-29 |
| Affects | the browser check, `requirePass()` ([0006](0006-the-site-asks-for-the-check.md)), a new small endpoint, texts |

## Summary

Optionally, the browser check runs **inside the form** instead of on a page of
its own: a placeholder in the form becomes a small box — *"Checking your
browser … ✓ Checked"* — that solves the task while the visitor is still
typing, and puts the answer into the form. When the form is sent,
`requirePass()` finds it there and lets the form through: no check page, no
sending again. This is how ALTCHA's own widget and Cloudflare Turnstile work;
here it runs on the site's own server.

## Motivation

Today ([0006](0006-the-site-asks-for-the-check.md)) a form sent without a pass
gets the check page, and the form is sent again by itself afterwards. That
works, but:

- the visitor waits **after** clicking, and sees a page they did not expect;
- **files cannot be sent again** — upload forms have to ask for the check on
  the form's page beforehand;
- nothing shows that the site protects the form.

With the check in the form, the work is done while typing, files are no
problem, and the visitor sees what happens.

## How it looks

```html
<form method="post" action="/comment">
  <textarea name="comment"></textarea>
  <div data-request-shield></div>        <!-- the box appears here -->
  <button>Send</button>
</form>
<script src="/.request-shield/widget.js" defer></script>
```

or from PHP: `echo Shield::active()?->widget();` (the placeholder and the script
tag). The box:

- *"Checking your browser …"* with a small progress bar, then *"✓ Browser
  checked"* — in the visitor's language (German and English built in, the
  site's own texts per language, as for the check page);
- announced to screen readers (`aria-live="polite"`);
- styled by the site if it wants (plain classes, no external CSS).

## How it works

1. **The page carries no task.** The script asks for one when it is needed:
   `GET /.request-shield/challenge` — an address the shield answers itself,
   before the application runs. The form page itself stays **cacheable**
   (Exponential's HTTP cache, Varnish): a task written into the page would be a
   different one for every visitor.
2. **When to start** (per placeholder): on the first input into the form
   (default — no visitor who only reads spends any computing time), on
   loading, or when the button is pressed.
3. The browser solves the task (the same solver as the check page) and puts the
   answer into a hidden field (`rss`) of the form.
4. **On sending**, `requirePass()` also looks at that field: a valid, unused
   answer for this visitor → the pass cookie is set, the form goes through —
   as if the visitor had held a pass already. Every answer counts once.
5. **Without JavaScript**, or when the task could not be fetched: the field
   stays empty, and everything happens as today (the check page, the form sent
   again).
6. A visitor who already holds a pass: the box says *"✓ Checked"* at once,
   without a task.

## The endpoint

`/.request-shield/challenge` (the path configurable, `set widget-path …`):

- `GET` → a task as JSON, in ALTCHA's format (`algorithm`, `challenge`,
  `maxnumber`, `salt`, `signature`); with a valid pass: `{"passed": true}`.
- `/.request-shield/widget.js` → the script, as a file (for sites whose
  Content-Security-Policy forbids inline scripts), cacheable for a long time.
- Counted against the visitor's budgets like any request; answered in the
  shield, never by the application.
- Because the format is ALTCHA's, a site may use ALTCHA's own widget against
  the same address instead of ours.

## Security

- The task is signed and bound to the visitor's address group, as today; an
  answer is valid for a few minutes and **once**.
- The answer in a form field is worth no more than the answer in the cookie
  today: the same checks.
- The endpoint gives a task to anyone who asks — as the check page does today;
  it costs the shield ~12 µs, the asker the computing time.
- Difficulty: the widget's own setting (`set widget-difficulty …`), lower than
  the check page's by default — the visitor is still typing.

## Cost

- Pages without the placeholder: nothing.
- Pages with it: one small request to the endpoint per form view (~12 µs on the
  server), the script once per browser (cached), and a fraction of a second in
  the browser while the visitor types.

## Compatibility

`requirePass()` works unchanged; the widget only makes it find an answer in the
form. Sites without the widget see no difference.

## Decisions taken when implementing (2026-09-29)

1. It starts on the first input into the form (`widget('load')` or
   `data-start` for the others).
2. The box is always there, small; ✓ once done.
3. The endpoint has no leading dot and is off unless set:
   `set widget-path /request-shield`.
4. The script is served by the shield as a file (`widget.js`, cached a day,
   ETag); `Challenge\Widget::script()` gives it to sites that serve it
   themselves.
5. Also: a page every visitor is checked on (`challenge /login`) takes the
   answer from the form, and the answer is taken out of `$_POST`.

## Open questions (as proposed)

1. Start by default on the first input, or on loading the page?
2. Should the box be visible at all times, or only while checking and once
   done (the "✓" small and unobtrusive)?
3. The endpoint's default path: `/.request-shield/…`, or something that looks
   less like a hidden file (some hosters refuse paths starting with a dot)?
4. Should the script be shipped as a file in the library as well, for sites
   that want to serve it themselves (a CDN, their own bundle)?
