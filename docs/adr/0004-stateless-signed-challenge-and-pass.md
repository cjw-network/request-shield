# ADR 0004 — Stateless, signed challenges and pass cookies (ALTCHA-compatible)

- Status: accepted (2026-09-28)

## Context

A browser check must work across every PHP process and server of a site,
without a database, and not depend on a third party.

## Decision

The ALTCHA proof-of-work format (salt with expiry and client tag, challenge,
HMAC signature); a pass cookie `v1.<expires>.<tag>.<mac>` bound to the client
bucket and User-Agent. Only the single use of a solution is stored (in the
counter store, for its lifetime).

## Consequences

Any server with the secret can check both; the official ALTCHA widget and its
client libraries could be used instead of the built-in script. The secret must
be shared by all servers of a site.
