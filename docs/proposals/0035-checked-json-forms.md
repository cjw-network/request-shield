# 0035 — The check for forms that send JSON

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-04 |
| Affects | `widget.js` (a promised function), the tests of the challenge's script, the docs |
| Relates to | [the check inside the form](../features/RSF03-03-browser-check-in-the-form.md) · [the site asks for the check](../features/RSF03-04-app-challenges.md) · [0030 error pages](0030-error-pages.md) · [0034 budgets for everyone together](0034-shared-budgets.md) · [use case: rate limits on the forms](../use-cases/form-rate-limits.md) |

## The problem

More and more forms are built in the browser by a script and sent with
`fetch` as JSON. The check inside the form does not reach them: it puts its
answer into a hidden field of a `<form>`, and such a script sends its own
JSON body. Checking the page that shows the form works, but checks every
reader and every crawler, not only those who send.

## What works today

The server side is there. A JSON request on a `challenge` path without a
pass gets `429` with the task as JSON and in `Request-Shield-Challenge`; the
same request again with `Request-Shield-Solution` is let through and sets the
pass cookie. `widget.js` carries the solver as `RS.solve` and `RS.payload`.
Ten lines in the form's script use them; the use case has them, tried
against a real server. But `RS` is an inner detail of `widget.js`: a release
may change it.

## The idea

1. **A promised function in `widget.js`:**

   ```js
   const response = await RS.fetch(url, init);   // fetch(), and a task from the shield solved on the way
   ```

   It sends; on `429` with a task it solves it, sends once more with the
   answer, and returns that response. The task has the check page's
   difficulty today; open whether a JSON request gets the widget's lower one.
   Without a task it returns the first response as it is. Documented,
   versioned with the shield, tested in Node like the check page's script
   (`tests/ChallengeJsTest.php`).
2. **Before sending, if wanted:** `await RS.pass({ maxAge: 3600 })` asks the
   widget endpoint first and solves there, so the form is sent once. The
   endpoint answers today `{"passed": true, "until": …}`; it would add
   `"issued"`, so the script knows whether the pass is fresh enough.
3. **The refusals in JSON** for a request that sends JSON
   ([0030](0030-error-pages.md)), so the form's script can say "please wait
   N minutes" instead of its general error.

Rules stay as they are:

```text
set        widget-path /request-shield
[JOB-SEND] challenge /forms/submit max-age 60m
[FORM-LIMIT] limit forms 5/hour at /forms/submit
```

## Cost

Nothing on the server: no new step, no new setting. In the browser one
function more in `widget.js` (well under 1 KB). A sender without a fresh pass
sends twice, or asks the endpoint once (2).

## Open questions for the owner

- Should the first try with a task count against a form's budget? Today it
  does: with `limit forms 5/hour` a sender without a pass has three sends.
- `RS.fetch` only, or `RS.pass` too?
- A name less likely to clash than `RS` on a site's pages, such as
  `RequestShield`, with `RS` kept for the shield's own pages?
