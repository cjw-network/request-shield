# 0006 — The site asks for the browser check

| | |
|---|---|
| Status | **Implemented** 2026-09-29 (see [the site asks for the check](../features/app-challenges.md)) |
| Proposed | 2026-09-29 |
| Affects | Shield, Gate, the check page, texts |

## Summary

The application can demand the browser check: `Shield::active()->requirePass()`
before it acts on sent content — without a pass the visitor gets the check,
and the form is sent again by itself afterwards — and the response header
`X-Request-Shield-Challenge: required` on a page such as a form.

## Motivation

The site knows moments the shield cannot see: content is being sent, a form
for an account is opened, a post looks like spam. Checking every POST by rule
(`challenge /comment`) is blunt; the site can do it where and when it matters,
and a visitor must not lose what they typed.

## Decisions (2026-09-29)

1. **The form is sent again automatically** after the check, with a *"Send
   again"* button without JavaScript; only once, only to the same address,
   only after a solved check; nothing kept on the server.
2. Files and forms over 256 KB are not carried; the visitor is asked to send
   again.
3. The texts are in the visitor's language (English and German built in).
4. The header variant needs `set app-challenge on` (output buffering);
   `requirePass()` works always and costs nothing until called.

## Cost

`requirePass()`: nothing until called; then one pass check (~5 µs), or the check
page (~12 µs). The header variant: output buffering of GET pages while
switched on.
