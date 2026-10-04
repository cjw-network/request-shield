# RSF05-06 Error pages

## What it does

![The built-in page: a card in the middle of the page, a ring with a quiet ":-(" (or the site's logo), the title "Not found", one sentence, a reference, and a link to the home page; light and dark. On the right, the site's own page: set error-page 404 errors/404.html, its placeholders filled in, read when the rules are compiled. Below, the statuses it covers: 400, 403, 404, 405, 414, 429 (with how long to wait), 431; for an API the same as JSON.](../proposals/0030-error-pages.svg)

When the shield refuses a request, it answers itself -- the site never runs.
That answer is a calm page in the look of the browser check: a ring with a
`:-(` (or the site's logo), a title and one sentence in the visitor's
language, how long to wait after too many requests, and the way home.

| Status | Title | Sentence |
|---|---|---|
| 400 | Bad request | The address could not be read. |
| 403 | No access | This address is not open to you. |
| 404 | Not found | This address does not exist here. |
| 405 | Not here | This kind of request is not taken at this address. |
| 414 | Address too long | The address is longer than this site takes. |
| 429 | Too many requests | Please wait N seconds, then try again. |
| 431 | Request too large | The request carries more than this site takes. |

- **It never says why:** no rule, no pattern, nothing about a list or a ban.
  A person needs to know what to do; an attacker should learn nothing.
- **It loads nothing:** inline CSS and SVG, no script, no font, no picture
  from elsewhere -- a visitor refused here is refused for the site's
  stylesheet too. A Content-Security-Policy keeps it so, and nobody can
  frame it.
- **A program gets JSON:** a request on an `api-path`, or one that asks for or
  sends JSON, gets `{"status": 404, "error": "not found"}` (429 with
  `retryAfter`). HEAD gets the headers only.
- **English and German** are built in; every title and sentence can be
  replaced, also for another language: `set text.de.not-found-text Diese Seite
  gibt es nicht.` The keys are `bad-request`, `no-access`, `not-found`,
  `not-allowed`, `too-long`, `too-many`, `too-large` and the same with `-text`.

The status code and the headers stay what they were: only the body changed.

## Use cases

- **A link from an old newsletter** to an address a rule refuses: the
  visitor sees a calm page and the way home, not a broken server.
- **A pause in a shared office:** past the pace, the page says how long to
  wait, in the visitor's language.
- **The site's own design:** a CMS's designed 404 page, saved as a file once,
  answers what the shield refuses.

## Configuration

```text
set error-page 404 errors/404.html            # one status
set error-page 4xx errors/refused.html        # every status the shield refuses with
set error-page 429 errors/pause.{lang}.html   # one per language: pause.de.html, pause.en.html

site shop.example {
  set error-page 404 shop/errors/404.html     # one website its own
}
```

- **A file next to the rule file** (relative to it, as `include`), HTML, at
  most 64 KB, UTF-8. It is **read when the rules are compiled** and kept in
  the compiled settings: nothing is read from disk when a request is refused.
  A changed file is noticed like a changed rule file.
- **Which page:** the status's own, else the `4xx` one -- each in the
  visitor's language, else the one for all languages; else the shield's own.
  A plugin that draws pages (the `Pages` capability) comes first.
- **Placeholders,** filled in and HTML-escaped: `{status}`, `{title}`,
  `{text}`, `{wait}` (seconds, for 429), `{home}`, `{lang}`, `{reference}`.
  Anything else in braces stays as it is, so CSS and scripts keep theirs.
- **The site's page is the site's:** the shield sends no CSP of its own for
  it. Inline its CSS, or load it from a path the web server serves itself --
  never through PHP, where a refused visitor is refused again.
- `request-shield check` reads the files (missing, too large, not UTF-8: a
  mistake naming the line); Rules & setup shows which statuses have a page of
  their own.
- **The logo:** `set challenge-logo` replaces the `:-(` on these pages and the
  ring's shield on the check page: one logo for all of the shield's pages.

## Cost

| | |
|---|---|
| a passing request | nothing |
| a refusal, the shield's own page | a few string replacements |
| a refusal, the site's page | the same: the page is in the compiled settings |
| compiling the rules | each `error-page` file read once |

## Limits

- **Not for the check page:** it needs its script and its form; it keeps its
  own frame and takes the logo and the texts.
- **Files only,** no address: a fetch when the rules are compiled could fail
  or hang. Save the CMS's page as a file once.
- **A refusal is never handed to the site to answer:** the point of refusing
  early is that the site does not run.

## Examples from the demo

What the demo's rules decide for this feature -- the same lines `request-shield test` checks and the demo's front page shows (`php -S 127.0.0.1:8080 examples/demo/router.php`).

<!-- examples: docs/tools/sync-examples.php from examples/demo/request-shield.rules -- do not edit; run the tool. -->
**RSF05-06 · Error pages**

What a refused visitor sees: a calm page in the own language, how long to wait after too many requests, the way home -- never why. A program gets JSON.

| Request | The rules decide | |
|---|---|---|
| `/.env` | "not found" (404) — the site never sees it · rule SCAN-HIDDEN — from another address (198.51.100.7) | The shield's own page: "Not found" |
| `/.env/` | "not found" (404) — the site never sees it · rule SCAN-HIDDEN — from another address (198.51.100.7) | the same for a folder |
| `/environment` | the site answers it — from another address (198.51.100.7) | an address that only looks alike |
| `/.git/config` | look at it | The page, in your browser's language |
<!-- /examples -->
