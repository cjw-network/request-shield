# ADR 0005 — Settings checked once and compiled for OPcache

- Status: accepted (2026-09-28)

## Context

Checking every setting costs about 10 µs per request — as much as all the
checks of a request together.

## Decision

`Settings::load()` checks the settings file once and writes the typed values
as a PHP file that OPcache serves; later requests import them unchecked. It is
rebuilt when the settings file's mtime or size changes.

## Consequences

About 8 µs per request, most of it one `stat()` (about 2.6 µs each on the test
machine), which is why no further file is stat'ed on this path. A wrong
setting is still an error at once, naming the key.
