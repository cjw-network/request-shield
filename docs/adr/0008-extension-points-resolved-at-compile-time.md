# ADR 0008 — Extension points are resolved at compile time

- Status: accepted (2026-10-03, with [0031](../proposals/0031-robust-core-plugins.md) phases B–D: `Extension`, `Rules\Vocabulary`, the five capabilities, `Cli\Command`)

## Context

The core has two read-only plugin hooks. A visual WAF backend, a statistics
backend, an HTTP-cache backend, an API and CMS adapters need more: own rules,
own rule-file words, own pages, own CLI commands, log sinks. Each must cost a
passing request nothing when it is not used (`AGENTS.md`).

## Decision

- Two interfaces: `Extension` (static, compile time: vocabulary, compile,
  plugins, routes, commands, check) and `Plugin` (per request, as today).
  Optional capabilities on `Plugin` (`RuleCounts`, `Sink`, `Pages`,
  `RuleProvider`, `Handler`; `ApiProvider` on `Extension` is planned, 0031
  G.0) are discovered by `instanceof` **when the settings are compiled** and
  written into the compiled settings: `$s->hooks` (hook ⇒ classes),
  `$s->routes`, `$s->ext` (extension id ⇒ checked values).
- The request path only tests `($s->hooks['x'] ?? []) !== []`. No
  `class_exists`, no reflection, no `stat()` per request.
- Every call into a plugin passes one guard that catches `\Throwable` and logs
  once a minute per plugin.
- A new capability is a new interface (MINOR); changing an existing one is MAJOR.

## Consequences

- "No plugin, no cost" is structural, not a promise; the bench proves it with
  features off and on.
- Plugin settings live in an untyped `ext` map, checked by the extension's
  `compile()` — still fail-fast at compile time.
