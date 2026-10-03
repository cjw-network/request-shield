# ADR 0014 — Every transmitted byte counts: `rs` is the prefix

- Status: accepted (2026-10-03, with [0031](../proposals/0031-robust-core-plugins.md) phase A2, steps A2.1–A2.3; the build minification comes with E.2)

## Context

The pass cookie travels with every request of a checked visitor for hours
(`rs_pass=v1.<10 digits>.<16 hex>.<32 hex>`, ~71 bytes); header names like
`X-Request-Shield-Challenge` are long; nothing is live, so names are free to
change.

## Decision

- **`rs`** is the abbreviation of RequestShield everywhere: headers `X-RS`,
  `X-RS-Monitor`, `X-RS-Check`, `X-RS-Access`; cookies `rsp` (pass), `rss`
  (solution), `rsd` (dashboard); feature ids `RSF…`.
- The pass cookie becomes `rsp=2.<expires base36>.<tag base64url>.<mac base64url>`
  (~45 bytes with the name): the same 128-bit MAC, a denser encoding.
- Internal app→shield headers (`X-RS-Check`, `X-RS-Access`) never leave the
  server. No `X-RS*` header is sent on a passing request unless the debug
  header is on.
- The challenge page's and widget's inline CSS/JS are minified by the build.
- The limits are numbers in `tests/WireBytesTest.php`; the bench prints the
  bytes.

## Consequences

- ~26 bytes less on every request of a checked visitor; nothing to configure.
- Debug header and log keep the rule id, not the feature id: no extra byte.
