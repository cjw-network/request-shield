# 0030 — Error pages: a quiet page of the shield's own, or the site's

| | |
|---|---|
| Status | **Accepted** 2026-10-02 (decisions below: the recommendations); to be built in the phases below |
| Proposed | 2026-10-02 |
| Affects | the shield's own answers (`Responder`: 400, 403, 404, 405, 414, 429, 431), the texts (`set text.…`), rule files (new setting `error-page`), the log (a reference), the demos |

## Summary

When the shield refuses a request, it answers itself: the site never runs.
Today that answer is a bare HTML page, a heading ("Not Found") and a link
home. It does its job for scanners, but a person who lands there (an old
link, a mistyped address, a pause after too many requests) sees something
that looks broken.

This proposal gives those answers a face:

1. **A built-in page** in the look of the check page: a ring in the middle with
   a quiet `:-(` (or the site's logo, `set challenge-logo`), one sentence in the
   visitor's language, how long to wait for a pause, a link home. Inline, no
   script, no outside resources, light and dark.
2. **The site's own page** per status: `set error-page 404 errors/404.html`, with
   placeholders the shield fills in. Per website (in `site` blocks) and per
   language.
3. **A reference** on the page and in the log line, so a person who writes
   "I got an error" can be found in the log, without the page telling an
   attacker which rule refused it.

The status code, the headers and the decision stay exactly as they are; only
the body changes.

## In one picture

![The built-in page: a card in the middle of the page, a ring with a quiet ":-(" (or the site's logo), the title "Not found", one sentence, a reference, and a link to the home page; light and dark. On the right, the site's own page: set error-page 404 errors/404.html, its placeholders filled in, read when the rules are compiled. Below, the statuses it covers: 400, 403, 404, 405, 414, 429 (with how long to wait), 431; for an API the same as JSON.](0030-error-pages.svg)

## Motivation

- **People see these pages too.** A link from an old newsletter to
  `/content/view/full/89` (refused by a rule like `EXP-SYSVIEW`), a visitor in a
  shared office past the pace (429), an editor whose form went to the wrong
  address (405). The page they get is the site's face in that moment.
- **It should not look like the site is down.** A bare "Not Found" in the
  browser's default font looks like a broken server. A calm page with the
  site's logo and a way back looks like a decision.
- **Sites want their own.** Most CMSs have a designed 404 page. The shield
  answers before the CMS runs (that is the point: no PHP framework, no
  database for a refused request), so it cannot use the CMS's page. It can use
  a static copy of it.

## Design

### The built-in page

```text
            ╭─────────╮
            │   :-(   │          (or the site's logo: set challenge-logo)
            ╰─────────╯
             Not found
   This address does not exist here.
        Reference 7KQ2-M4XD · 14:31
          To the home page
```

- **The same frame as the check page:** the card, the ring, the system font,
  light and dark (`prefers-color-scheme`). The ring holds a `:-(` drawn in SVG
  (not an emoji: it looks the same everywhere), or the logo from `set
  challenge-logo` when the site has one. One logo for all of the shield's
  pages.
- **Per status a title and one sentence**, in the visitor's language (the
  language rule of the check page: `set language`, else the browser's):

  | Status | Title (en) | Sentence (en) |
  |---|---|---|
  | 400 | Bad request | The address could not be read. |
  | 403 | No access | This address is not open to you. |
  | 404 | Not found | This address does not exist here. |
  | 405 | Not here | This kind of request is not taken at this address. |
  | 414 | Address too long | The address is longer than this site takes. |
  | 429 | Too many requests | Please wait %s seconds, then try again. |
  | 431 | Request too large | The request carries more than this site takes. |

  The sentences **never say why** (no rule, no pattern, nothing about bans):
  a person needs to know what to do, an attacker should learn nothing. Every
  one can be replaced: `set text.de.not-found.text Diese Seite gibt es hier
  nicht.`
- **Self-contained:** inline CSS, the SVG inline, no script, no fonts or
  images from elsewhere. That matters: a banned visitor's request for a
  stylesheet is refused too, so a page that loads anything from the site
  would show up broken exactly when it is needed.
- **Headers** as today, plus for the page: `Content-Security-Policy:
  default-src 'none'; style-src 'unsafe-inline'; img-src data:`,
  `X-Robots-Tag: noindex`, `Cache-Control: no-store`.
- **An API** (`api-path`, or a request that asks for JSON) gets JSON instead:
  `{"status": 404, "error": "not found", "reference": "7KQ2-M4XD"}` (429: with
  `retryAfter`). **HEAD**: the headers only.
- About 2 KB; built from a constant string with a few replacements.

### The site's own page: `error-page`

```text
set error-page 404 errors/404.html            # one status
set error-page 4xx errors/refused.html        # every status the shield refuses with
set error-page 429 errors/pause.{lang}.html   # per language: pause.de.html, pause.en.html, else the built-in

site shop.example {
  set error-page 404 shop/errors/404.html     # one website its own
}
```

- **A file next to the rule file** (relative to it, as `include`), HTML,
  at most 64 KB. **Read when the rules are compiled**, kept in the compiled
  settings: nothing is read from disk when a request is refused. A changed
  file is noticed like a changed rule file (`recheck`).
- **Placeholders**, filled in HTML-escaped: `{status}`, `{title}`, `{text}`,
  `{wait}` (seconds, 429), `{home}`, `{lang}`, `{reference}`. Anything else in
  braces stays as it is (CSS and scripts keep their braces).
- **The site's page is the site's:** the shield sends no CSP of its own for it
  (the page may load the site's stylesheet). The docs say plainly why the
  built-in page loads nothing, and what a custom page should do: inline its
  CSS, or load it from a path the web server serves itself (never through
  PHP, where a banned visitor is refused).
- `check` reads the files (missing, too large, not UTF-8: an error naming the
  line), and the rules page shows which statuses have a page of their own.
- **Not for the check page.** It needs its script and its form; it keeps its
  own frame and takes the logo and the texts (as today).

### The reference

- A short code per refused request (`7KQ2-M4XD`: 8 characters, random), on
  the page, in the JSON and **in the log line** (`ref=7KQ2-M4XD`), with the
  time. A support person searches the log for it and finds the rule, the
  address, the reason, without the visitor having to describe anything.
- Only for refusals the shield answers itself, generated only then: a passing
  request costs nothing.

## Cost

| | |
|---|---|
| a passing request | nothing |
| a refusal, built-in page | a few string replacements (~2–5 µs), one random reference |
| a refusal, the site's page | the same: the file is in the compiled settings |
| compiling the rules | each `error-page` file read once |

## Privacy and security

- The page never names the rule, the pattern, a list or a ban; the reference
  is random and means something only to whoever reads the log.
- The built-in page loads nothing and runs nothing; it cannot be framed
  (`frame-ancestors 'none'` as part of the CSP).
- A custom page is the site's content: the shield fills in only its
  placeholders, each escaped.

## Phases

1. The built-in page for every status the shield refuses with (the frame,
   the `:-(`, the logo, the texts en/de, light and dark), JSON for APIs, the
   headers. The demos show it.
2. `set error-page` (status, `4xx`, `{lang}`, in `site` blocks), the
   placeholders, `check` and the rules page.
3. The reference on the page, in the JSON and in the log.

## Decisions (2026-10-02)

1. The built-in page shows a `:-(` drawn in SVG; the site's logo replaces it.
2. A reference on the page, in the JSON and in the log (phase 3), random.
3. No automatic reload after a pause (429): the page says how long.
4. Placeholders `{status}`; only the listed names are replaced.
5. `error-page` takes files only, not addresses.
6. A refusal is never handed to the site to answer.

## Open questions (as proposed)

1. **The `:-(`**, or a neutral sign (an exclamation mark, a closed door)?
   *Recommendation: the `:-(`, drawn in SVG: friendly, and the same on every
   device; the site's logo replaces it.*
2. **A reference on the page?** *Recommendation: yes (phase 3), random, also in
   the log. It turns "I got an error" into one log line.*
3. **429: reload by itself after the wait** (a `<meta http-equiv="refresh">`)?
   *Recommendation: no. A pause should not turn into requests by itself; the
   page says how long, the visitor reloads.*
4. **Placeholders `{status}` or `{{status}}`?** *Recommendation: `{status}`;
   only the listed names are replaced, so CSS braces are safe.*
5. **`error-page` as an address** (the CMS's own 404, fetched when the rules are
   compiled)? *Recommendation: no, files only. A fetch at compile time can
   fail or hang; the site saves its page as a file once.*
6. **Hand a 404 to the site** (`error-page 404 site`: let the CMS answer it)?
   *Recommendation: no. The point of refusing early is that the site does not
   run; a site that wants its own design uses a file.*
