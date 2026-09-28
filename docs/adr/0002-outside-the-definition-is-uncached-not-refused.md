# ADR 0002 — Outside the cacheable definition: answered, not refused

- Status: accepted (2026-09-28)

## Context

A definition of valid URLs (patterns, an adapter's URL index) can lag behind
the site: a page published a second ago is not in the index yet.

## Decision

Only clear cases are refused (hard rejects). A URL outside the definition is
answered as `allow-uncached`: rendered, never stored, and it can be counted
against a budget.

## Consequences

No false 404 for new content; cache pollution is still impossible. Floods of
unknown URLs are handled by budgets, not by the definition.
