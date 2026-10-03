# ADR 0009 — Plugins can tighten a decision, never loosen it

- Status: proposed (2026-10-03; accepted with [0031](../proposals/0031-robust-core-plugins.md) phase C)

## Context

With own rules from plugins (`RuleProvider`) the question is whether a plugin
may also override or veto what the core decided — for example let a request
through that a rule refused.

## Decision

- A plugin rule returns `null` or a `Decision`; the core folds it in with
  `Decision::stricter()`. A plugin can refuse, slow down or challenge what the
  core would let through; it cannot let through what the core refused.
- No hook replaces a decision. The one "loosening" input, the application's
  `$known` callback for the cacheable definition, stays an adapter callback
  about caching, not security.
- Plugin steps cannot be placed before the list rules (deny, feeds, bans).
- A throwing plugin rule counts as "no opinion" (pass) and is logged.

## Consequences

- The fail-safe reasoning ([ADR 0007](0007-fail-safe-pass-through.md)) holds:
  a broken plugin can only make the shield more permissive by *not* acting,
  never by acting wrongly.
- Monitor mode's "what would have happened", bans and the log keep their
  single source of truth: the core's chain.
