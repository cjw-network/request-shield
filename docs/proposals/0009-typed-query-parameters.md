# 0009 — Typed query parameters: the cheapest check first

| | |
|---|---|
| Status | **Implemented** 2026-09-30 (see [known parameters](../features/RSF02-05-known-parameters.md)) |
| Proposed | 2026-09-29 |
| Affects | rule files, the order of the checks, the attack rules (0007), the Exponential adapter |

## Summary

A site says which query parameters it knows, and of which type — `Offset int`,
`SearchText text`, `utm_* any`. The shield reads the parameter names anyway
(for the cache check); with the list it can:

- refuse an address with an **unknown parameter** at once (`query strict`,
  optional) — before a single attack pattern runs;
- skip the attack patterns for values that **cannot hold an attack** because of
  their type (digits, a word) — only free text is scanned;
- keep what it does today where nothing is said: unknown parameters are
  answered, but not cached.

## In one picture

![Each parameter is looked up in the site's list: a number or a word passes unscanned, free text is scanned for attacks, an unknown one or one of the wrong type is refused with query strict -- without strict scanned and not cached](0009-typed-query-parameters.svg)

## Motivation

- Many sites have few parameters. Exponential's frontend keeps its view
  parameters in the path (`/news/(offset)/12`); only the search and forms use
  a query string. Every other `?…` is either a marketing tag or an attack
  appended to an arbitrary address (`/article?id=1' …`).
- The attack rules ([0007](../features/RSF05-01-rule-files.md)) are the most expensive
  check: about 2 µs for a query with parameters. A name list and a type are
  far cheaper and answer most of these requests before them.
- A list of known parameters is also documentation: what does this site take?

## The syntax

```text
# everywhere: marketing and tracking tags, taken, never cached
query  utm_* any   fbclid any   gclid any   mc_cid any

# the search page
match /content/search {                               # (with 0008; without: "at /content/search")
  query  SearchText text   SubTreeArray int   Offset int   SearchPageLimit int
}

# a list view with paging and sorting
match /news/** {
  query  page int   sort word
}

query  strict                                        # every other parameter, every other value: 404
```

Without `match` blocks (0008): `query SearchText text at /content/search`.

**Types** (the value, after decoding):

| Type | Allows | Attack patterns |
|---|---|---|
| `int` | digits, an optional minus | skipped |
| `number` | `int` and a decimal point | skipped |
| `word` | letters, digits, `-`, `_`, `.` — up to 64 | skipped |
| `id` | letters, digits, `-`, `_` — a slug or a key | skipped |
| `list` | `word`s separated by `,` | skipped |
| `text` | anything (up to `limits` as today) | **scanned** |
| `any` | anything, not even scanned | skipped (for tags such as `utm_*`) |
| `/regex/` | what the expression allows | skipped |

`name[]` and `name[key]` count as `name` (as PHP reads them). A name may use
`*` (`utm_*`).

## What happens

1. **No query string:** nothing changes — the typical Exponential page costs
   nothing here, as today.
2. **Every parameter known and of its type:** the answer may be cached as the
   cache rules say; the attack patterns run only on the `text` values (and on
   the headers, as before).
3. **An unknown parameter, or a value not of its type:**
   - with `query strict`: **404** (reason `query parameter`, rule ID of the
     `strict` line) — no attack pattern runs;
   - without: as today — answered, not cached, the whole query scanned by the
     attack rules.

## The order of the checks

Cheapest first, as now, with the parameters before the attack patterns:

method → sizes → path check → host → blocked paths → forms → restricted areas →
**known parameters (new)** → **attack patterns** → cache → budgets.

The cache check today uses the parameter names too; both would share one pass
over the query string.

## View parameters (Exponential)

The same types for Exponential's view parameters in the path —
`/(offset)/12`, `/(year)/2026` — are a matter for the Exponential adapter: it
knows the format and would add a check like

```text
view-param  offset int   year int   month int   tag word
view-param  strict
```

Today view parameters are not looked at by the attack rules at all (they are
path, and only `anywhere` rules see the path).

## Cost

- **Parsing the query:** already done for the cache check (`Request::queryNames()`);
  the values in the same pass.
- **Per known parameter:** one name lookup (a hash for plain names, a pattern
  for names with `*`) and one type check — a `ctype_digit()` or one short
  expression, well under a microsecond for a handful of parameters.
- **Attack patterns:** run on the `text` values only, or skipped entirely; a
  request refused by `strict` runs none.
- **No query string:** nothing.

## Caution

`query strict` refuses links other tools create: newsletters and ads append
`utm_*`, `fbclid`, `gclid`, `mc_cid`, `_ga`, `msclkid`, and some CMS plugins
their own. A built-in list of such tags (`@tracking`, shipped like
`@wordpress`) would help; `strict` should be tried with `monitor` first
([0004](0004-modes-monitor-and-strict.md)): the log shows what would be refused.

## Decisions (2026-09-30) — and why

| Question | Decision | Why |
|---|---|---|
| `query strict`: 404 or 400? | **404** | Says nothing about a filter at work, like the blocked paths; the log names the rule for whoever debugs. |
| A tracking list? | **Shipped**: `include @tracking` (`utm_*`, `fbclid`, `gclid`, `msclkid`, …), versioned like the other built-in rules | With `strict`, marketing links keep working; maintained in one place instead of forgotten by each site. |
| A value not of its type, without `strict`? | **Scanned as `text`** by the attack rules | A wrong type is often the attack itself; the cost is for those values only. |
| `cache-query` and `query` as one? | **No, two rules** | A known parameter need not be cacheable (a search term); the docs show them side by side. |

## As built — where it differs

- **An empty value is of every type** (`?page=`): forms send their empty
  fields, and in strict mode each would have been a 404.
- **Which declaration counts:** a path's own before those for every path, an
  exact name before one with `*` (the more specific wins), the first line for
  the same name. The rules are merged into one lookup with the settings.
- **The attack patterns get the pairs, not only the values** — an unknown
  parameter's name is scanned too (`?<attack>=1`).
- **Cost, measured:** next to the attack rules about even for typed queries
  (the lookup costs what leaving the values out of the scan saves); a refusal
  under `strict` 6.7 instead of 17.7 µs. Details in the feature doc.

## Open questions (as proposed)

1. `query strict` answering 404, or 400 (a malformed request)? 404 says less.
2. A shipped list of tracking tags (`include @tracking`), or each site its own?
3. Values not of their type without `strict`: only "not cached" (as proposed),
   or scanned by the attack rules as `text` (safer, a bit dearer)?
4. Should `cache-query` (the cache's parameter list) and `query` become one
   rule — "known parameters may be cached" — or stay two?
