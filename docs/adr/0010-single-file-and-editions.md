# ADR 0010 — One signed file per edition; the mini edition has no backend

- Status: proposed (2026-10-03; accepted with [0002](../proposals/0002-single-file-build.md) / [0031](../proposals/0031-robust-core-plugins.md) phase E)

## Context

Shared hosting has no Composer and often no shell; an AI installing the shield
needs one stable, verifiable artifact. The owner wants a version that works
without any backend.

## Decision

- `build/single-file.php` builds **one PHP file per edition** from the source
  tree, deterministically: `request-shield.php` (**mini**: the core without
  `Report/*`, with the rule compiler, the CLI and the shipped rule sets
  embedded via `Rules\Shipped`), `request-shield-api.php`,
  `request-shield-waf.php` (API + pages), `request-shield-stats.php`. A `full`
  bundle only when asked for.
- The file is also the CLI; it finds `request-shield.rules` next to itself;
  the header comment is the install guide.
- Releases are signed (minisign, Ed25519) and attested; the public key is
  embedded in the file; `verify` and `self-update` are CLI-only.
- `rules/crawlers.php` is not embedded; the single file documents
  `protectFile()`.

## Consequences

- Three lines install it. The whole test suite runs against the built file in
  CI (`REQUEST_SHIELD_ENTRY`).
- Backend features are separate downloads, loaded with `plugin … from <file>`.
