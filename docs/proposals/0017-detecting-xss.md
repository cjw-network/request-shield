# 0017 — Detecting cross-site scripting: a small detector of our own, behind a cheap filter

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-30 |
| Affects | the attack rules (`rules/attacks.rules`, `ATK-XSS-…`), `Rule\ContentRule`, the content hints, the rules page |

## Summary

Today the shield looks for cross-site scripting (XSS) with three regular
expressions (`ATK-XSS-TAG`, `ATK-XSS-EVENT`, `ATK-XSS-URL`) over the decoded
query. They catch the plain cases, and miss many of the techniques collected in
the classic XSS cheat sheet ([HTML Purifier's smoke test](http://htmlpurifier.org/live/smoketests/xssAttacks.php)
lists them). This proposal compares four ways to do better and recommends
**a small XSS detector of our own** — a tokenizer that reads a value the way a
browser would, in the few places a value can land in a page — **run only when
a cheap filter finds the characters an attack needs**, and only on values that
can hold one (typed parameters, 0009, are skipped). No dependency, a few
microseconds, and only for the rare suspicious value.

## In one picture

![A value from the query or a header: without the characters an attack needs it passes at once; a typed parameter passes; otherwise a small detector reads it as a browser would and flags scripts, event handlers and script addresses -- refused, or only logged in monitor mode. The real defence stays in the application: output encoding and a content security policy](0017-detecting-xss.svg)

## What a request filter can do — and what it cannot

XSS is a flaw in how the **application writes a page**: a value reaches the
page without being encoded for where it lands. The real defence is there:
encoding every value for its context (the template engine), a Content Security
Policy that forbids inline scripts, cookies marked `HttpOnly`. A request
filter is a **second line**:

| | A request filter helps | It cannot |
|---|---|---|
| Reflected XSS (a link with the attack in its query) | yes — the attack is in the request | — |
| Stored XSS (an attack saved in a comment, shown later) | when it arrives, if bodies are scanned | once it is stored, or when it came another way (an import) |
| DOM XSS (the page's own script reads the address) | partly: only what reaches the server — the fragment after `#` never does | the rest |
| Scanners probing for XSS | yes, and their noise stops early | — |

The big risk is the other direction: **false positives**. A CMS's editors post
HTML on purpose (rich-text fields, templates), a forum allows some markup, a
search for "`<b>`" is legitimate. Hence: query and headers by default, form
bodies only where a site switches it on, exceptions per path (`unblock
[ATK-XSS…] at /admin/**`), and every new check tried in `monitor` first
([0004](0004-modes-monitor-and-strict.md)).

## Motivation

- The regular expressions are a list of known shapes. XSS techniques vary the
  shape endlessly: other elements and attributes that run script, entity and
  percent encodings (mixed, nested, without the closing semicolon), broken or
  unusual markup a browser still repairs, control characters and white space
  in odd places, script addresses hidden behind encodings, styles that load
  code in old browsers. Each variant needs another expression, and each
  expression is another chance for a false alarm.
- A browser does not match patterns; it **tokenizes**. A detector that
  tokenizes the value the same way sees through most variations at once:
  whatever the disguise, the result is "an element that runs script" or "an
  attribute that is an event handler" or "an address with a script scheme".
- The shield's budget: a passing request costs about 7–20 µs today. Any XSS
  check must stay far below that for the ordinary request — which has no `<`,
  no quote and no `javascript` in it at all.

## The options

| | (a) HTML Purifier | (b) Own detector (tokenizer) | (c) More regular expressions | (d) Filter + (b) — recommended |
|---|---|---|---|---|
| What it is | a sanitiser: rewrites HTML to a safe subset; "detecting" = purify the value, compare with the original | reads the value as a browser would, in its possible contexts (text, attribute, address, script), flags what would run | the OWASP Core Rule Set's way: more expressions per technique | characters an attack needs? then (b); typed parameters skipped |
| Tags and elements | very good | good | fair (a list) | good |
| Event handlers | very good | good (any `on…` attribute in a tag, however written) | fair | good |
| Script addresses (`javascript:` and relatives) | very good | good, after decoding as a browser does | fair — encodings multiply the patterns | good |
| Encodings, obfuscation | very good (it parses) | good (the same decoding the browser does) | poor — each encoding is another pattern | good |
| Broken, unusual markup | very good | good — the tokenizer repairs like a browser | poor | good |
| Style-based vectors (old browsers) | good | fair (a short list of dangerous style constructs) | fair | fair |
| False positives | **high as a detector**: any markup at all changes when purified — `<b>` in a search is "an attack" | low: plain markup passes, only what runs is flagged | medium, grows with each pattern | low |
| Cost per suspicious value | high: builds a DOM-like model, loads its configuration and definitions — hundreds of microseconds to milliseconds | a few µs (one pass over a short value) | 1–5 µs, growing with the list | a few µs, only for suspicious values |
| Cost per ordinary request | the same, unless filtered first | a few µs, unless filtered | 1–3 µs (it runs on every value with a hint) | ~0.2 µs (one `strpbrk()`/`stripos()` pass, as the content hints do today) |
| Dependencies | a Composer package, about 400 files | none | none | none |
| Single file (0002) | no — too large, and its licence (below) | yes: one class, some hundred lines | yes | yes |
| Licence | LGPL 2.1+: may be used by MIT code, but bundling it into one MIT file makes that file a combined work with LGPL parts — its terms (replaceable library, notice, source) would travel with every copy | ours (MIT) | ours | ours |
| Maintenance | upstream; its purpose is output, not detection | ours: the tokenizer follows the HTML standard, which changes slowly | ours, and the list only grows | ours |
| PHP 8.0 | yes | yes | yes | yes |

**Why HTML Purifier is the wrong tool here:** it is excellent at what it is
for — making HTML *output* safe, for a site that accepts user HTML (it belongs
in the application's comment or editor code). As a request detector it would
flag every harmless markup, and costs far too much to run before every page.

**Why only more expressions is not enough:** it is the cheapest to start, and
the shield already has the engine (0007, content rules). But it fights the
encodings one by one, and the list becomes both slower and noisier. Expressions
stay useful for the unambiguous cases (a closing script element) and for
site-specific patterns.

## Recommendation

**(d): the cheap filter, then a small detector of our own**, kept as a content
check like the others (IDs, `unblock`, `monitor`, rules page, trace):

1. **Filter** (every value that can hold an attack, as today): does it contain
   `<`, or a quote together with `=`, or a script scheme name, or an encoded
   form of these (`%`, `&#`, `\`)? If not — the ordinary request — nothing else
   runs. Typed parameters (0009) are skipped before that; the attack patterns
   already receive only text, unknown and wrongly typed values.
2. **Detector**, for the rest: decode as a browser does (percent decoding
   twice, HTML entities with and without the semicolon, the characters a
   browser drops or treats as white space), then tokenize — in three contexts,
   since the shield does not know where the application puts the value:
   as HTML text (elements that run script or load content, any event-handler
   attribute, attributes whose value is an address with a script scheme), as
   an attribute value (breaking out of the quote, then the same), and as an
   address (a script or data scheme at its start).
3. **Decision**: a finding is `reject 403 "attack"` with its own ID
   (`ATK-XSS@2`, replacing the three expressions, which stay for one release as
   `monitor` rules to compare), logged with what was found in plain words
   ("an event-handler attribute", "a script address") — never the value itself
   beyond the log's usual URL.

Staged:

| Stage | What | Risk |
|---|---|---|
| 1 | The detector as a library class with unit tests built from **technique categories** (one generated case per category and encoding, not a copied corpus); measured | none: not used yet |
| 2 | `rules/attacks.rules` gains `block query xss` (and `header <Name> xss`), shipped as `monitor` next to the old expressions | none: only logged; the log shows the difference |
| 3 | After a release in monitor: enforced, the three expressions retired | false positives, seen in stage 2 |
| 4 | Form bodies, opt-in per path (`block body xss at /comment`) — the stored-XSS case | high for rich-text fields: never by default |

## Design sketch

```text
block query xss                 # the detector on the query's text values
block header Referer xss        # and on a header
monitor block body xss at /comment /contact     # bodies: only where switched on, watched first
unblock [ATK-XSS] at /admin/**  # the editors post HTML there
```

- `Rule\XssDetector` (no state, one static method: value → finding or null),
  used by `ContentRule` for targets written with `xss` instead of a regex.
- Contexts and lists are data in the class: the elements and attributes that
  run script or load content, the schemes, the dangerous style constructs —
  reviewed against the HTML standard, versioned with the rule set (0005).
- The content hints (the prefilter) get the detector's trigger characters, so
  a value without them costs what it costs today.
- The Inspector says what was found and in which context; `trace` too.

## Cost

- Ordinary request (no trigger character in any scanned value): about
  **0.2 µs** — one byte scan per value, as today's hints.
- A value with trigger characters but harmless (a search for `a < b`): the
  detector, **a few µs** for a short value; values are bounded by the size
  limits the shield already enforces.
- Nothing is loaded unless the rule is used; no dependency, one class in the
  single file.

To be measured in stage 1, with the same bench as the attack rules.

## Limits

- A second line: output encoding and a Content Security Policy in the
  application remain the real defence; the proposal says so on the rules page.
- The fragment after `#` never reaches the server (DOM XSS).
- Values the shield never sees: cached pages served by a CDN, JSON bodies (not
  scanned unless switched on), imports.
- Where a site accepts HTML on purpose, the detector must be taken back there
  — or the site relies on sanitising the output (that is where HTML Purifier
  belongs).
- No detector catches everything; the aim is the known techniques by category,
  with few false alarms.

## Open questions

1. **The approach:** own detector behind the filter (recommended), or first
   only more expressions? Recommendation: the detector — the expressions do
   not scale against encodings, and the detector costs nothing on the ordinary
   request.
2. **Bodies:** scan form bodies at all? Recommendation: only opt-in per path,
   and always `monitor` first; never JSON bodies by default.
3. **One context or three?** Recommendation: three (text, attribute, address)
   — the shield does not know where the application writes a value; each
   context adds little cost, and plain markup still passes.
4. **Old style-based vectors** (only old browsers ran them): include a short
   list, or leave them out to keep false alarms low? Recommendation: a short
   list of the constructs that load code, in `monitor` only for one release.
5. **HTML Purifier as an optional adapter** for sites that already have it?
   Recommendation: no — it belongs in the application's output, not in the
   request path; the docs point there.
