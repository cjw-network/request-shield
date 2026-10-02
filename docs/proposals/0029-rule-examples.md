# 0029 — Examples next to the rules: `expect`, a test command, a generated overview

| | |
|---|---|
| Status | **Accepted** 2026-10-02 (decisions below: the recommendations); **phase 1 implemented** 2026-10-02 ([examples](../features/rule-examples.md)): `expect`, `request-shield test`, the built-in and Exponential examples, the instruction for language models |
| Proposed | 2026-10-02 |
| Affects | rule files (new line `expect`), the command line (`request-shield test`, later `request-shield crawl`), the rules page (`<dashboard-path>/waf/rules`), the demos (their test tables), the built-in rule files, a short instruction for language models (`docs/llm/`) |

## Summary

A rule says what it refuses. Whether it does that, and only that, is today
shown by hand: a trace on the command line, a test file in PHP, a table of
links in a demo page. All of these are written apart from the rule and
drift away from it.

This proposal puts **examples next to each rule**, in the rule file itself:

```text
[EXP-SYSVIEW] block regex (?i)/content/view(/|$)          # pages by node number
expect GET /content/view/full/2        404                # what it refuses
expect GET /ger/content/view/full/89   404
expect GET /content/view/sitemap/2     passes             # and what it must not
```

From these lines:

1. **`request-shield test site.rules`** decides every example and says which
   ones do not come out as written. It runs before a deploy and in CI.
2. **The rules page and the demos** show each rule with its examples: the
   address, what should happen, what happens now, and a link to try it. The
   overview is generated from the rules and cannot drift away from them.
3. **A language model can write them** with a short instruction. It writes
   proposals, and a person reads them. After that they are fixed,
   reviewable tests like any other.

## In one picture

![The rule file holds each rule with its expect lines. The test command decides each example without counting anything and reports pass or fail, for CI and before a deploy. The rules page and the demo pages show the examples with the live result and a link to try them. A language model can propose expect lines from the rule file and the site's real addresses, which a person reviews before they are kept. Later, a crawl of the site checks that none of its own links is refused.](0029-rule-examples.svg)

## Motivation

- **Rules change, tests stay behind.** The Exponential rules
  ([use case](../use-cases/exponential.md)) have about 60 cases in three
  places: `ExponentialRulesTest.php`, the demo's table in `index.php`, and the
  explanation. Each change to a rule means three edits, and nothing notices
  when one is forgotten.
- **A site's own rules have no tests at all.** The library tests its built-in
  rules; a site that adds `block /old-api/**` or an `allow POST` list has
  only `check` (the syntax is right) and `trace` (one address at a time, by
  hand). The question "does my change still let the contact form through?"
  has no command.
- **The near misses are the valuable examples.** `/shop/package` must pass
  although `package` is an admin module; `/administration-guide` must pass
  although it starts with `/admin`. These are known to the people who know
  the site, not to the pattern.

### Why not generate the tests from the patterns?

The idea is close: the rule says what it matches, so make an example from it.
It does not work well enough:

- A glob (`/content/view/**`) gives one example easily. A regular expression
  (`EXP-MODULES`, the attack patterns) can only be turned into addresses in
  part, and the result is an address nobody would send.
- A test made from the rule only proves that the rule matches itself. A
  wrong rule produces a wrong test that passes.
- The examples that matter, the near misses and the site's real addresses,
  are not in the pattern at all.

So the examples are written. They can be written by a person or proposed by a
language model, and they are kept next to the rule.

## Design

### The line

```text
expect <METHOD> <address> <outcome> [by <ID>] [from <address>] [with pass] [times <n>]
```

| Part | Meaning |
|---|---|
| `<METHOD>` | `GET`, `POST`, `HEAD` … |
| `<address>` | a path with its query (`/content/search?SearchText=yoga`), or a full URL when the host matters (`https://admin.example.org/…`, for `host` rules and `site` blocks). Written as a visitor's browser would send it; `%20` and the like as usual |
| `<outcome>` | `passes` (answered, a cache may keep it), `uncached` (answered, not kept), `answered` (either: for near misses, whatever the site's cache rules; added when built), `check` (the browser check), or a status refused with: `403`, `404`, `405`, `429` |
| `by <ID>` | the rule that must decide. **Without it, the rule the line follows** (the last rule with an ID before it in the same file); for `passes`, nothing is checked |
| `from <address>` | the visitor's address (default `198.51.100.7`, a documentation range): for `restrict`, `exempt`, `deny`, the lists |
| `with pass` | the visitor holds a valid pass (the check solved before) |
| `times <n>` | the request sent `n` times in a row: budgets, bans. The outcome is that of the last one |

- After the address, the outcome and the options may come in any order
  (`expect GET /login with pass answered`; settled when built).
- An `expect` line belongs to the file it is written in, may stand inside
  `match` and `site` blocks (it then gets the block's website for its host
  when no host is written), and has no ID of its own.
- **A comment after it is its description**, shown on the rules page and in
  the test's output (`# a near miss: the alias names an admin module`).
- Examples for rules of other files (the built-in ones, an extension's) name
  them: `expect GET /backup.zip 404 by SCAN-BACKUP`.

### What they cost

Nothing per request. `expect` lines are read by the rule file reader and
kept apart: they never enter the compiled settings a request loads. Only
`test`, `check` and the rules page read them.

### `request-shield test`

```bash
php bin/request-shield test site.rules [--source=<glob>]... [--only=<ID>] [--as-written] [--junit=<file>]
```

```text
EXP-SYSVIEW   ✓ GET /content/view/full/2              404 by EXP-SYSVIEW
              ✓ GET /content/view/sitemap/2           passes
EXP-MODULES   ✕ GET /shop/package                     expected passes, got 404 by EXP-MODULES
                  a near miss: the alias names an admin module
EXP-SEARCHES  ✓ 11 × GET /content/search?SearchText=a check by EXP-SEARCHES

41 examples: 40 pass, 1 fails. 3 rules without an example: EXP-INI, EXP-API, EXP-PACE
```

- **Each example on a fresh store**, so examples never count against each other.
  A budget is tested with `times`. Nothing is written to the site's store or
  log; the same engine as `trace`.
- **Exit code 0** when every example passes, **1** when one fails, **2** for a
  mistake in the files (as `check`). `--junit` writes a report for CI
  systems.
- **Rules in `monitor`:** tested as they would decide when switched on, so a
  site can write the examples before it enforces a rule. `--as-written` tests
  them as they are (they then pass everything).
- **Rules without an example** are listed at the end, not counted as a
  failure.

### The rules page and the demos

- **The rules page** (`<dashboard-path>/waf/rules`) shows each rule's examples
  under it: the address (a link, for `GET` on the site's own host), what should
  happen, what happens now (✓/✕), and the description. A ✕ there is the same
  warning as in `test`, on a live site.
- **The demos** generate their tables of numbered tests from the `expect` lines
  of their rules, grouped by the rules' sections, instead of a list written in
  PHP. `DemoTest` and `ExponentialDemoTest` fetch every row as they do now, over
  HTTP, so the page, the rules and the tests are one source.

### Examples shipped with the library

The built-in rule files (`scanners`, `wordpress`, `crawlers`, `tracking`) get
`expect` lines, with near misses, wherever an example is harmless to write
(`/.env` 404, `/environment` passes). A site that includes them can run
`test` and sees them pass, and an update that changes a built-in rule is
tested against the same examples.

The Exponential rules (`examples/exponential/`) move their cases from
`ExponentialRulesTest.php` into `expect` lines; the test then only runs
`request-shield test` on both main files.

### An instruction for language models

`docs/llm/write-rule-examples.md`, a page to give a language model together
with a rule file:

- For each rule with an ID: at least one address it refuses or checks, and
  **at least one near miss** it must let through. Real addresses of the site
  where they are known (its sitemap, a crawl, the access log), otherwise
  typical ones for the CMS.
- Only `expect` lines, each with a comment saying why. Nothing else in the
  file is changed.
- **No personal data:** no real visitors' addresses (documentation ranges
  only: `192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`, `2001:db8::/32`),
  no names, no session cookies, no tokens or secrets.
- Afterwards: `request-shield test`. A failing example is either a wrong
  example or a wrong rule, and a person decides which.

The language model writes the examples once; they are then plain lines a
person has read. Nothing in the shield asks a model at run time.

### Later: the site's own links must pass (`request-shield crawl`)

```bash
php bin/request-shield crawl site.rules https://www.example.org/ [--max=500] [--rate=5/s]
```

It reads the site's pages (same host only, politely: at most `--rate`
requests a second, `--max` pages), collects every link and every form's
target, and decides each with the rules without counting. **Any of the site's
own addresses that the rules refuse is reported**, with the page that links
to it. This is how the Exponential rules were checked against a real
installation (163 links, all passing); as a command, every site can run it
after changing its rules. The decisions are made locally: the crawl only
reads pages, and the shield on the site counts the crawl like any visitor
(run it from an address the rules let in, or with `exempt`).

## Compatibility

A file with `expect` lines cannot be read by older versions of the library:
`check` there names the line. Rule files are rarely shared with older
installations; a site that must can keep its examples in a file of their own
(`include site.examples` on the new version only).

## Phases

1. `expect` lines in rule files; `request-shield test` (with `--only`,
   `--as-written`, `--junit`); examples in the built-in rule files and the
   Exponential rules; `docs/llm/write-rule-examples.md`.
2. The rules page shows the examples with their live results; the demos
   generate their test tables from them.
3. `request-shield crawl`.

## Decisions (2026-10-02)

1. `expect` is a line of its own, checked by the reader like any rule.
2. Rules in `monitor` are tested as they would decide when switched on;
   `--as-written` tests them as they are.
3. Without `by`, the rule the line follows must decide.
4. Rules without an example are listed by `test` only, not by `check`.
5. `crawl` goes into the library's command line: read-only, polite by default.
6. The demos' tables, including each group's explanation, are generated from
   the rule file.

## Open questions (as proposed)

1. **A line of its own (`expect …`) or a comment (`# expect …`)?**
   *Recommendation: a line of its own. The reader checks it like any rule (a
   typo in the method or the outcome is an error naming the line), and the
   rules page can trust it. A comment would be read by older versions without
   complaint, but also by everything else without checking.*
2. **Rules in `monitor`: tested as they would decide when switched on?**
   *Recommendation: yes by default (the examples are written before a rule is
   switched on), `--as-written` for the other way.*
3. **`by` left out: the rule the line follows?** *Recommendation: yes. That
   is what "next to the rule" means, and it keeps the lines short.*
4. **Rules without an example: a warning in `check` too?** *Recommendation:
   only in `test`. `check` stays about mistakes; a missing example is not one.*
5. **`crawl` in the library, or a tool of its own?** *Recommendation: in the
   library's command line, read-only, polite by default; it uses the same
   engine as `trace` and `test`.*
6. **The demo tables generated from `expect` (phase 2): also the
   explanation per group?** *Recommendation: yes, from the comments above a
   group of rules in the file (the section's text). The rule file is then the
   demo's only source.*
