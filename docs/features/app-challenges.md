# The site asks for the browser check

## What it does

The shield decides by itself when to check a browser (too many requests, a
page that is always checked). With this, **the site decides too** — the CMS,
a plugin, a template — at the moment it knows best: a comment is sent, a
profile form is opened, a post looks like spam.

Two ways, for two moments:

| When | How | Without a pass |
|---|---|---|
| **Content is sent** (POST) — before the site saves anything | `Shield::active()?->requirePass();` | the check page; afterwards **the form is sent again by itself**, nothing typed is lost |
| **A page is shown** (a form, GET) | the response header `X-Request-Shield-Challenge: required` | the check page instead of the page; afterwards the page |

With a pass — the normal case after the first check — both simply go on.

```php
use CjwNetwork\RequestShield\Shield;

// When a comment is sent, before it is saved:
Shield::active()?->requirePass();

// Only when it looks suspicious -- the site's own judgement:
if ($comment->linkCount() > 2 || $user->isNew()) {
    Shield::active()?->requirePass();
}

// A sensitive action: a check from the last five minutes.
Shield::active()?->requirePass(300);
```

```php
// On a form's page (any template or plugin that can send a header):
header('X-Request-Shield-Challenge: required');           // or: required; fresh=300
```

The header variant needs `set app-challenge on` (`'appChallenge' => true`):
the page is kept back until it is finished, so it can be replaced by the check
page — output buffering, which costs a little on every GET page, so it is off
unless asked for. The header never reaches the browser. Without the setting
the header does nothing.

`requirePass()` needs no setting; it costs nothing until it is called.

## Sending the form again

Without a pass, `requirePass()` answers with the check page, and that page
**carries the form's fields** as a hidden form. Once the browser has solved
the check, the script sends the form again — to the same address, the same
way, with the solution; the shield checks it, hands out the pass, and
`requirePass()` returns: the site saves the comment as if nothing had
happened. The visitor sees a moment of *"Your browser is being checked; then
what you entered is sent."* (in their language).

- **Only once, only to the same address**, only after a solved check; a
  solution counts once.
- **The site's own protection stays**: the form token (CSRF) comes back
  unchanged and is checked by the site as always.
- **Without JavaScript:** a *"Send again"* button.
- **Nothing is kept on the server**: the fields travel only in the page, from
  the visitor's browser back to it.
- **Not carried:** files (browsers do not allow it) and forms larger than
  256 KB. The visitor is asked to go back and send the form again — with a
  pass by then. For upload forms, ask for the check on the form's page (the
  header) instead.
- Passwords in a form come back in the page (the same site, HTTPS). For a
  login, check the page before: `challenge /login` or the header.

## Details

- `requirePass()` returns at once when this request already passed the check
  (a pass, or a solution the shield just took).
- `fresh` (seconds) asks for a pass issued in that time; an older one gets the
  check again — for a password change, a payment.
- Exempt paths (`challenge-exempt`) do not count when the site asks: it asked.
  Verified search engines pass, as everywhere.
- Decisions and the log name the rule `application` and the reason `app`
  ("the site asked for the browser check").
- Other requests (JSON APIs, PUT) get 429 for now; a challenge in headers for
  APIs is planned ([proposal 0001](../proposals/0001-earn-back-a-spent-budget.md)).

## Adapters

- **Exponential:** in a content/edit or collected-information handler before
  storing; the header from a template (`{set-block}` with a header operator) or
  a module view.
- **WordPress:** `preprocess_comment` → `requirePass()`; `send_headers` on the
  registration page → the header.
- **Symfony / Ibexa:** a kernel request listener for the routes, or in the
  controller before the form is handled.

Proposal: [0006](../proposals/0006-the-site-asks-for-the-check.md).
