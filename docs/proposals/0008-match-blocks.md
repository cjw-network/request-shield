# 0008 — `match` blocks: the rules of an area in one place

| | |
|---|---|
| Status | **Implemented** 2026-09-29, first step (see [rule files](../features/rule-files.md#match-blocks-the-rules-of-an-area-in-one-place)); `limit` and `cache-query` per area: open |
| Proposed | 2026-09-29 |
| Affects | rule files (syntax only, and two optional new abilities), the active rules page |

## Summary

A `match <path> { … }` block collects the rules of one area — the admin, the
shop, the API — instead of repeating the path on every line. The idea comes
from Firebase Security Rules; unlike them, a block holds the same plain rules as
today, **no conditions and no expression language**, and compiles to the same
settings. Existing rule files stay valid.

## Motivation

A site's rules are usually thought of by area: "the admin only from the office,
forms only there, always check the browser". Today that is three lines with the
same path, and the connection between them is in the reader's head:

```text
[SITE-ADM]   restrict  /admin/** to 192.0.2.0/24
[SITE-ADM-F] allow     POST /admin/**
[SITE-ADM-C] challenge /admin/**
```

The active rules page could also show a site by area — easier for people who do
not read patterns.

## Why not the Firebase format itself

Firebase Security Rules (`service …{ match /users/{userId} { allow write: if
request.auth.uid == userId; } }`) decide on **data** for a **signed-in user**,
with a small programming language for conditions and "deny by default". For
request-shield that does not fit:

- The shield runs before any sign-in and knows neither the user nor the data —
  most of what the conditions look at (`request.auth`, `resource.data`) would
  have nothing to refer to.
- Conditions need a parser and an evaluator: much code, a new attack surface,
  and time on every request. Rule files are read, never executed
  ([0003](0003-human-readable-rule-files.md)), and compiled to expressions that
  cost microseconds.
- "Deny by default" does not suit a public website; the shield turns away what
  is suspicious and lets the rest through.
- For people who are not programmers, `restrict /admin/** to …  # office only`
  reads better than `allow write: if …`.

What is worth taking over is the structure: **nested `match` blocks.**

## The syntax

```text
ids SITE

match /admin/** {
  [SITE-ADM]    restrict to 192.0.2.0/24          # the admin area: office only
  [SITE-ADM-F]  allow POST                        # forms only here
                challenge                         # always check the browser
                unblock [SCAN-HIDDEN@1] for 192.0.2.0/24   # the file manager opens .env
}

match /shop/** {
                cache-query page sort             # in the shop, these parameters may be cached
  [SHOP-PACE]   limit requests 120/min challenge-at 60     # counted in the shop only
}

match /api/** {
                challenge-exempt                  # programs, never the browser check
                allow POST PUT DELETE
}
```

- `match <path pattern> {` opens a block, `}` on a line of its own closes it.
  The path pattern is written as everywhere (`*`, `**`, `?`, or `regex …`).
- Inside, a rule that takes paths **leaves them out**: they are the block's.
  `restrict to …`, `allow POST`, `challenge`, `challenge-exempt`, `block`
  (the whole area), `unblock [ID] for …` (an exception here), `cache-path`
  (the area may be cached).
- Rules without paths — `host`, `trust`, `method`, `exempt`, `set`, `include`,
  `ids`, `version` — are not allowed inside a block: an error names the line.
- IDs, revisions, descriptions and `replace` work as today.
- Errors: a block that is not closed names the line of its `match`; a `}`
  without a block names its own line.

It compiles to exactly the settings the three lines above give today: the same
cost per request, and the same rule IDs in decisions and the log.

## Two new abilities (optional, to decide)

Inside a block, two rules could mean something that today cannot be said at
all:

1. **`limit` counted per area:** `limit requests 120/min` inside `match
   /shop/**` counts only the requests to the shop. Today every budget counts
   every request (or what the application counts itself). Cost: one pattern
   match per request for each such budget.
2. **`cache-query` per area:** the parameters a cached address may have, per
   area (`page sort` in the shop, `page` elsewhere). Today there is one list for
   the whole site. Cost: one pattern match per request while any is set.

Both could come later; the block syntax is useful without them.

## Nesting

```text
match /shop {
  match /checkout/** {
    challenge                                     # /shop/checkout/**
  }
}
```

An inner path is added to the outer one; `**` only at the end of the innermost.
Whether nesting is worth it — or one level is enough — is an open question.

## The active rules page

A view "by area": one card per `match` block with its rules in plain words
("The admin area: only the office · forms only here · always the browser
check"), in addition to today's view by kind of rule.

## Cost

The block syntax: none — it is resolved when the files are read. The two new
abilities: see above, only while used.

## Compatibility

Rule files without blocks are unchanged. A file with blocks cannot be read by
older versions of the library (an error names the `match` line).

## Decisions (2026-09-29)

1. Nesting: yes — an inner path is added to the outer one; `**` only at the
   end of the innermost; a block by regex holds no blocks.
2. `limit` and `cache-query` per area: a second step; inside a block today
   they are an error that says so.
3. IDs per line only, not for blocks.
4. Braces, `}` on a line of its own.
5. Also: the rules page shows each rule's area; `replace` works inside a block.

## Open questions (as proposed)

1. Nesting, or one level only?
2. The two new abilities (`limit` and `cache-query` per area): with the block
   syntax, later, or not at all?
3. An ID for a whole block (`[SITE-ADM] match /admin/** {`), its rules then
   `SITE-ADM.1`, `SITE-ADM.2` …, or IDs only per line as today?
4. `{ … }`, or blocks by indentation (like YAML) — braces are easier to parse
   and to get right in a text editor without help.
