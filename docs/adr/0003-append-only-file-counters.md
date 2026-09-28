# ADR 0003 — File counters: one appended byte per request

- Status: accepted (2026-09-28)

## Context

Hosting without APCu or Redis still needs counters shared by concurrent PHP
processes, cheap and without lost updates.

## Decision

A request appends one byte to the file of its key and window (`O_APPEND`); the
file's size is the count. One-byte appends are atomic: no lock, no
read-modify-write. A sweep on a small fraction of requests removes past
windows.

## Consequences

Exact under concurrency (tested: 8 processes × 250 hits = 2,000), about 30 µs
per request (disk), files in a directory the shield owns.
