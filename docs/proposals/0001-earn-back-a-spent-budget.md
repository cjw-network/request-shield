# 0001 — Earn back a spent budget: a challenge instead of 429, for forms and APIs too

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-28 |
| Affects | budgets, the challenge, the responder |

## Summary

A client past a budget's `limit` gets `429 Too Many Requests` today. With this
proposal a budget can ask for a proof of work instead, and a solved challenge
resets the client's counter for that budget: the client "pays" for the next
`limit` requests with a little CPU. That works for GET pages (as the browser
check does now), for **form posts** (the challenge page re-submits the form)
and for **API calls** (a machine-readable challenge in a header).

## Motivation

- A real user who submits a form too often — a shop's checkout retried, a
  search refined twenty times — should not be locked out for the rest of the
  window. A bot should.
- An attacker rotating through addresses pays per address and per burst,
  instead of simply waiting for the window to pass.
- APIs cannot run a browser script, but can solve a proof of work; the ALTCHA
  format has client libraries for several languages.

## Design

### Budget setting

```php
'budgets' => [
    'posts' => ['limit' => 20, 'window' => 60, 'onExceeded' => 'challenge'],   // default: 'throttle'
],
```

- Past `limit` (and not before: `challengeAt` stays the separate, earlier
  browser check) the decision is `CHALLENGE` with the budget's name.
- A solution for that budget resets the client's counter for it
  (`Store::reset(key)`, one write) and is bound to client, budget and
  challenge, and single-use as today.
- **Escalation:** a second counter per client and budget counts solves per
  hour; the difficulty doubles with each (`maxnumber × 2^solves`, capped at a
  configured maximum). One legitimate user solves once or twice; sustained
  abuse becomes exponentially more expensive.

### Browsers: GET

As the browser check today: the page solves, sets the solution cookie and
reloads.

### Browsers: form posts

The challenge page carries the posted fields as hidden inputs and, once
solved, submits them again to the same URL with the solution cookie set:

- only `application/x-www-form-urlencoded` and `multipart/form-data` without
  files, up to a size limit (e.g. 64 KB); a post with files gets 429 with a
  message, since a browser cannot re-send a file it no longer has;
- every field is HTML-escaped; the target is always the request's own URL
  (no open redirect);
- CSRF tokens stay valid: same session, same form data.

### APIs

For a request that does not accept `text/html` (or matches a configured API
path):

```http
HTTP/1.1 429 Too Many Requests
Request-Shield-Challenge: eyJhbGdvcml0aG0iOiJTSEEtMjU2Ii...   (base64url of the ALTCHA JSON)
Content-Type: application/json

{"error":"rate_limited","challenge":{"algorithm":"SHA-256","challenge":"…","maxnumber":50000,"salt":"…","signature":"…"}}
```

The client solves it and repeats the request with
`Request-Shield-Solution: <base64url payload>`. Clients with an API token are
exempt as today and never see this.

### Optional: a visible CAPTCHA

A `ChallengeProvider` interface, with the proof of work as the default and
adapters for Cloudflare Turnstile, hCaptcha or Friendly Captcha later. These
send the visitor's data to a third party; the default stays self-hosted.

## Security

- Solutions bound to client bucket, budget and challenge; single-use; expire.
- Escalating difficulty per client and budget.
- Re-submitted forms: escaped fields, own URL only, size limit, no files.
- The API challenge reveals nothing but what the browser page reveals.

## Cost

Nothing on the passing path. A reset is one store write; a challenge as today
(~12 µs page, ~9 µs check).

## Open questions

1. Default `onExceeded`: stay `throttle` (safe) or `challenge`?
2. Reset the counter fully, or credit a fixed number of requests?
3. Escalation factor and cap.
4. Which content types count as "API" by default.
