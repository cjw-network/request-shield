# Rule files

## What it does

The settings written one rule per line, for people rather than PHP — readable
and editable without knowing PHP arrays, loadable from a main file plus any
number of further files (one per CMS extension, say), and compiled once into
the same OPcache-served PHP file the settings use. A rule file is an
alternative to the PHP settings array, not a second system: it is turned into
that array, and every value is checked the same way.

```text
# site.rules

host        www.example.org example.org
trust       10.0.0.0/8                       # the load balancer

block       /wp-admin/**  /xmlrpc.php        # this site is not WordPress
block regex ^/(phpmyadmin|adminer)

cache-query page
cache-path  /  /news/**  /page/*

limit       requests 600/min challenge-at 300
limit       misses   60/min  on-demand
challenge   /login  /admin/**                # always check the browser
restrict    /admin/**  to 192.0.2.0/24       # the office only
allow       POST  /contact  /edit/**         # a POST only where the forms are
exempt      192.0.2.50                       # monitoring

set         secret ${SHIELD_SECRET}
set         log /var/log/request-shield.log
include     rules.d/*.rules
```

```php
CjwNetwork\RequestShield\Shield::protectFile('/path/to/site.rules');
// or: REQUEST_SHIELD_CONFIG=/path/to/site.rules with bootstrap.php
```

The demo (`examples/demo/request-shield.rules`) is a complete example.

## The format

One rule per line: a keyword, then values separated by spaces. `#` starts a
comment at the start of a line or after a space; `\#` is a literal `#`.

| Rule | Setting | |
|---|---|---|
| `host <names>` | `hosts` | the site's hosts; others 404 |
| `trust <addresses or ranges>` | `trustedProxies` | who may send `X-Forwarded-*` |
| `method <METHODS>` | `methods` | methods allowed at all |
| `allow <METHODS> <paths>` | `methodPaths` | those methods only there, else 405 ([access rules](access-rules.md)) |
| `restrict <paths> to <addresses or ranges>` | `restricted` | only those addresses, else 403 ([access rules](access-rules.md)) |
| `block <paths>` / `unblock <paths>` | `blockedPaths` | 404 before the site sees it / take a block back |
| `block query|header <Name>|headers|anywhere <regex>` | `contentRules` | attack patterns in the query or the headers, 403 |
| `unblock [<what>] at <paths> [for <addresses>]` | `blockExceptions` | blocked paths let through at some paths only (an admin's file reader) ([access rules](access-rules.md#exceptions-an-admins-file-reader)) |
| `query <name> <type> … [at <paths>]` / `query strict` | `queryParams`, `queryStrict` | the query parameters the site takes and their types (`int`, `number`, `word`, `id`, `list`, `text`, `any`, `/regex/`); only `text` and the unknown ones go to the attack patterns; `strict`: anything else 404 ([known parameters](known-parameters.md)) |
| `cache-path <paths>` | `cacheable.paths` | what a cache may keep; `any`: every path (default) |
| `cache-query <names>` | `cacheable.query` | parameters a cached URL may have; `any` (default), `none` |
| `limit <name> <n>/<unit> [challenge-at <n>] [on-demand] [on-exceeded challenge]` | `budgets` | units `s`, `sec`, `min`, `hour`, `day`, also `20/10s`; `on-exceeded challenge`: past the limit the check that frees the counter instead of a pause ([budgets](budgets.md#past-the-limit-a-pause-or-earn-it-back)) |
| `api-path <paths>` | `challenge.apiPaths` | the site's API: a check there is JSON with a header, not a page |
| `no-limit <name>` | `budgets` | switch a budget off, the default one too |
| `challenge <paths> [max-age <duration>]` | `challenge.alwaysPaths`, `challenge.alwaysMaxAge` | always check the browser there; `max-age 5m`: a pass from the last five minutes there ([modes](modes.md)) |
| `monitor <rule>` | `monitorRules` | before `block`, `restrict`, `allow`, `limit`, `challenge`, `query strict`: logged as it would decide, not enforced ([modes](modes.md)) |
| `challenge-exempt <paths>` | `challenge.exemptPaths` | never challenge there (APIs, feeds) |
| `exempt <addresses or ranges>` | `exempt.ips` | never counted |
| `crawlers <kind> allow\|check\|block` / `crawler <ID> allow\|check\|block` | `crawlerPolicy` | what the site does with verified crawlers, by kind (`search`, `ai-search`, `ai-user`, `ai-training`) or one by one ([known crawlers](known-crawlers.md)) |
| `crawler <kind> ua /<pattern>/ [dns <suffixes>] [ranges <lists>]` | `crawlers` | a crawler of the site's own, verified by DNS or an address list (`ranges ./ours.json`) |
| `set <key> <value>` | any other setting | see below |
| `include <path or glob>` | — | further rule files, relative to this one |

`none` as the first value empties a list first — the defaults too
(`exempt none 192.0.2.1`, `block none`).

### Paths

Simple patterns by default, matched against the path (not the query):

| Pattern | Matches | Not |
|---|---|---|
| `/wp-admin/**` | `/wp-admin`, `/wp-admin/x/y.php` | `/wp-adminx` |
| `/page/*` | `/page/about` | `/page/a/b` (`*` stays in one segment) |
| `*.sql` | `/dump.sql`, `/a/b/dump.sql` (no leading `/`: anywhere) | `/dump.sqlx` |
| `**/admin/**` | `/admin`, `/shop/admin/x` (in any directory) | `/administrator` |
| `/file?.txt` | `/file1.txt` (`?` is one character) | `/file12.txt` |

`regex` switches the rest of the line to regular expressions, written without
delimiters: `block regex ^/(phpmyadmin|adminer)`. Every pattern is compiled
when the files are read — a broken one is an error then, never a silent miss.

`unblock <pattern>` takes back exactly that pattern from an earlier line or
file; `unblock [SCAN-BACKUP]` a rule by its ID.

### Attack patterns

`block` with a target instead of a path pattern looks inside the request —
the patterns are always regular expressions and case does not matter:

```text
block query \bunion\s+select\b          # the query string
block header User-Agent \b(sqlmap|nikto)\b   # one header
block headers \$\{jndi:                 # every header (not Cookie: name it)
block anywhere \$\{env:                 # path, query and every header
```

Matched after the value is normalised — decoded twice, lower case, SQL
comments and runs of white space as one space — so `%2527` or `UnIoN/**/SeLeCt`
do not get past; the answer is 403 and the log and trace name the rule. Form
contents (POST bodies) are not looked at. `unblock`, `unblock at` and `replace`
work for these rules exactly as for path blocks; `rules/attacks.rules`
(`include @attacks`) is a reviewed set, after the OWASP Core Rule Set's first
level.

## match blocks: the rules of an area in one place

```text
match /admin/** {
  [SITE-ADM]    restrict to 192.0.2.0/24          # the admin area: office only
  [SITE-ADM-F]  allow POST                        # forms only here
                challenge                         # always the browser check
                unblock [SCAN-HIDDEN@1] for 192.0.2.0/24   # the file manager opens .env
}

match /shop {
  match /checkout/** {
    [SHOP-PAY]  challenge                         # /shop/checkout/**
  }
}

match regex ^/api/ {
                challenge-exempt
}
```

- Inside a block, a rule that takes paths **leaves them out** — they are the
  block's: `restrict to …`, `allow <METHODS>`, `challenge`, `challenge-exempt`,
  `cache-path`, `block` (the whole area), `unblock [<ID>] [for <addresses>]`
  (an exception there), and `replace [ID] <one of these>`.
- A block is exactly the rules written out with the path — the same settings,
  the same cost per request; the rules page shows each rule's area.
- An inner block adds its path to the outer one; `**` only at the end of the
  innermost; a block by `regex` holds no blocks.
- Rules without paths (`host`, `trust`, `method`, `exempt`, `block query …`)
  and `set`, `include`, `ids`, `version` do not go inside; `limit` and
  `cache-query` per area are planned
  ([proposal 0008](../proposals/0008-match-blocks.md), second step).
- IDs go on the rules inside, not on the block. `}` stands on a line of its
  own; a block not closed by the end of its file is an error naming the line
  of its `match`.

## IDs, namespaces and descriptions

```text
ids SITE                                                  # this file's IDs start with SITE-

[SITE-10]    restrict /admin/** to 192.0.2.0/24           # the admin area: office only
[SITE-FILES] unblock [SCAN-HIDDEN] at /admin/files/** for 192.0.2.0/24   # the admin's file reader
             block /old-api/**                            # no ID: named site.rules:5
```

- **`[ID]` before a rule** gives it a name that stays when lines move:
  letters, digits, `-`, `_`, `.` — names or numbers (`[SITE-10]`,
  `[SHOP-CHECKOUT]`). Decisions, the log, the rules page and `trace` use it
  (`rule=SITE-10`); where it is written is shown next to it. A rule without an
  ID is named by file and line, as before.
- **`ids <NAMESPACE>`** at the top of a file: every ID in that file starts with
  `<NAMESPACE>-` — a number block per file, so the site (`SITE`), an extension
  (`SHOP`) and the built-ins (`SCAN`, `WP`) never collide. `ids SHOP required`:
  every rule in the file needs an ID (not `set` and `include`). A namespace
  binds only its own file.
- **An ID used twice** — in any file — is an error naming both places.
- **The comment after a rule is its description**: the rules page shows it
  instead of the pattern, for people who do not read patterns
  (the pattern stays underneath).

## Versions, revisions and replacing a rule

```text
ids SITE
version 2026-09-29.2                                            # this rule set's version

[SITE-DL]  unblock [SCAN-BACKUP@1] at /downloads/**             # downloads are archives
replace    [SCAN-TEST@1] block /phpinfo.php /info.php            # test.php is a real page here
```

- **`version <word>`**, one per file: shown by `check`, `show` and the rules
  page — which rule set is live on which server.
- **`[ID@n]`** before a rule is its revision; the built-in rules raise it when a
  rule changes what it matches ([`rules/CHANGELOG.md`](../../rules/CHANGELOG.md)).
- **`[ID@n]` as a reference** names the revision you reviewed. After a library
  update that changes the rule, `check` (exit 3) and the rules page warn:
  *"SITE-DL (site.rules:4) was written for SCAN-BACKUP revision 1; SCAN-BACKUP
  is now revision 2 … please check what changed"*. The changed rule applies at
  once; only your deviation needs a look.
- **`replace [ID@n] <rule>`** swaps a rule in one line: what the old rule set
  is taken back, the new one takes its ID — the log and the rules page go on
  counting it. For any rule: a built-in one, an extension's, your own.

### Changing a built-in rule — the ways

| You want | Write |
|---|---|
| one rule differently | `replace [SCAN-BACKUP@1] block *.sql *.bak` |
| one rule open at some paths only | `[SITE-DL] unblock [SCAN-BACKUP@1] at /downloads/**` (`for <addresses>`) |
| one rule gone | `unblock [SCAN-CGI@1]` |
| all of a file gone, your own instead | `unblock @scanners`, then `include rules.d/our-scanners.rules` |

Never edit `rules/*.rules` in the library's directory: an update overwrites it,
and the change is gone without a word. The ways above live in your own files
and survive updates — and with `@n` you learn when the rule underneath changed.

## The built-in rules

The blocks every site has are rule files shipped with the library, read
before the site's own rules — the same format, with IDs and descriptions:

| File | IDs | Use |
|---|---|---|
| `rules/scanners.rules` | `SCAN-HIDDEN`, `SCAN-BACKUP`, `SCAN-TEST`, `SCAN-DBTOOL`, `SCAN-CGI` | always read |
| `rules/wordpress.rules` | `WP-FOLDERS`, `WP-SCRIPTS` | `include @wordpress` (or `block @wordpress`), for sites that are not WordPress |
| `rules/crawlers.rules` | `CRAWL-GOOGLE`, `CRAWL-GPTBOT`, … (19) | always read: the [known crawlers](known-crawlers.md), with their address lists in `rules/crawlers/` |
| `rules/tracking.rules` | `TRACK-UTM`, `TRACK-GOOGLE`, `TRACK-MICROSOFT`, `TRACK-META`, `TRACK-SOCIAL`, `TRACK-MAIL`, `TRACK-OTHER` | `include @tracking`: the marketing tags as known parameters ([known parameters](known-parameters.md#the-marketing-tags-tracking)) |

`unblock @scanners` takes back all of a shipped file's blocks, `unblock
[SCAN-CGI]` one. PHP array settings get the same blocks from `Config`; a test
keeps both the same.

### `set`

| Key | Value |
|---|---|
| `secret` | at least 32 characters; better `${SHIELD_SECRET}` than in the file |
| `store` | `auto`, `apcu`, `file`, `memory` |
| `store-dir` | where file counters and a generated secret live |
| `pass-ttl`, `solution-ttl` | `3600`, `30m`, `1h`, `1d` |
| `difficulty-min`, `difficulty-max` | numbers |
| `cookie`, `solution-cookie` | cookie names |
| `bind-user-agent`, `search-engines`, `debug-header`, `strip-untrusted-forwarded` | `on` / `off` (`search-engines off`: no crawler is recognised) |
| `dns-lookups` | new DNS lookups a minute to verify search engines, for all requests together (default 30; `0`: none — a DMZ without DNS) |
| `app-challenge` | `on`: the site may ask for the check with the header `X-Request-Shield-Challenge: required` ([docs](app-challenges.md)) |
| `ipv6-prefix`, `max-uri`, `max-query-parameters`, `max-header-bytes` | numbers |
| `challenge-logo` | an SVG file (relative to the rule file) for the middle of the check page's ring, checked strictly ([how it looks](browser-challenge.md#how-it-looks)) |
| `widget-path`, `widget-difficulty` | the browser check inside a form: its endpoint (`/request-shield`; unset: off) and difficulty ([docs](browser-check-in-the-form.md)) |
| `home` | a path (`/`) or an address: the shield's own pages (404, a pause, the check page) link to it, "To the home page" |
| `language` | `auto` (default: the visitor's browser language among those there are texts for, else English) or a code: `de`, `en` |
| `text.<key>`, `text.<lang>.<key>` | what visitors read (the rest of the line): for every language, or for one — `set text.de.title Einen Moment, bitte`. Keys: `title`, `text`, `noscript`, `nocookies`, `failed`, `try-again` (`%s` = seconds), `bad-request`, `no-access`, `not-found`, `not-allowed`, `too-long`, `too-many`, `too-large`, `error`. English and German are built in; another language comes with its texts (`text.fr.title …`) |
| `mode` | `off`, `monitor`, `enforce` (default), `strict` ([modes](modes.md)) |
| `crawler-verify` | `both` (default), `ranges` (the published address lists only: no DNS, for a DMZ), `dns` ([known crawlers](known-crawlers.md)) |
| `log`, `log-level`, `log-ip`, `log-max-size` | [the log](log-and-rule-ids.md) |
| `stats` | `off` (default), `on`, or the parts: `requests`, `crawlers`, `not-found`, `bots` ([statistics](statistics.md)) |
| `dashboard-path` | where the statistics pages live: `/rs` (default) gives `/rs/dashboard`, `/rs/stats`, `/rs/shield`; something in front is fine (`/admin/rs`) ([statistics](statistics.md#the-statistics-page)) |
| `stats-hours`, `stats-days`, `stats-months`, `stats-flush` | days the hours are kept (7), days the day totals are kept (400, then summed into months), months kept (0: for good), seconds between writes to disk with APCu (60) |
| `crawler-log`, `crawler-log-kinds`, `crawler-log-days`, `crawler-log-query` | one log per known crawler and day: its directory, the kinds logged, days kept (30), whether the query is kept ([statistics](statistics.md#one-log-per-crawler-optional)) |
| `recheck` | how often the files are checked for changes, see below |

`${NAME}` is an environment variable, `${NAME:-default}` one that may be unset.
The values used are recorded: the compiled settings are rebuilt when one
changes.

## Several sources

- **`include rules.d/*.rules`** — the files in alphabetical order, as Debian's
  `conf.d`: `10-base.rules`, `50-shop.rules`, `90-local.rules` layer
  predictably. An include stays below the including file's directory (`..` is
  refused) unless its path is absolute; a file including itself is an error.
- **From code:** `Shield::protectFile('site.rules', sources: [...])` or
  `Settings::load($file, null, $sources)` — further rule files or globs read
  **before** the main file. An adapter fills them from its extensions, for
  Exponential `extension/*/settings/request-shield.rules`.
- **Merging:** lists are combined, single values follow "the later wins", and
  `unblock`, `no-limit`, `none` let a later file take back what an earlier one
  (or a default) set. The main file comes last, so the site has the last word.

Every rule remembers its ID and where it was written (`SITE-10` in
`site.rules:12`, `ext/shop/settings/request-shield.rules:2`, `SCAN-BACKUP` in
`built-in scanners.rules:11`), and every
decision names it ([rule IDs](log-and-rule-ids.md)).

## Cost and changes

Nothing per request beyond loading the compiled settings: the files are read
once and kept as a PHP file that OPcache serves. What costs is noticing a
change:

| | checked | cost (PHP 8.1) |
|---|---|---|
| with APCu | all files and include directories, at most every `recheck` seconds (default **10**) | ~5.5 µs setup, no `stat()` in between |
| without APCu | the main file on every request; the others when it changes | as a PHP settings file (one `stat()`, ~2.6 µs of it) |
| `set recheck 0` | every file on every request | one `stat()` each (~2.6 µs) |
| PHP settings file, for comparison | the file on every request | ~8 µs on a quiet machine |

So with APCu an edit takes up to `recheck` seconds to apply. Without APCu, a
changed or added extension file is picked up when the main file changes:
`php bin/request-shield reload site.rules` checks everything and marks it
changed (run it on deploy or after installing an extension).

A file added to an included directory is noticed through the directory's
mtime. mtime has whole seconds: a change of the **same size within the same
second** it was read is not seen (the same holds for PHP settings files).

A broken edit is an error on the next check — with file and line — not the
old settings quietly kept: `bin/request-shield check` first.

## The command line

```bash
php bin/request-shield check  site.rules [--source=<glob>]...   # errors with file:line; warns about world-writable files
php bin/request-shield show   site.rules [--source=<glob>]...   # the rules in effect, each with its origin
php bin/request-shield reload site.rules [--source=<glob>]...   # check, then mark changed for every server
```

`show` prints every rule as the regular expression it became, with the line it
comes from — the answer to "why was this request refused?". It never prints a
secret.

## Security

- Rule files are read, never executed; there is no expression language.
- They must not be writable by the web server's user or by anyone else on the
  machine; `check` warns about files anyone can change. Keep them outside the
  document root, like the PHP settings.
- `include` stays below the including file unless an absolute path is given.
- Access rules match the path as the application routes it (decoded, `//` and
  `/./` collapsed, any case), so `//admin` or `/%61dmin` do not slip past
  `restrict /admin/**` ([access rules](access-rules.md)).

## Compatibility

PHP array settings keep working unchanged; `protectFile()` takes a `.rules`
file or a `.php` settings file. Proposal:
[0003](../proposals/0003-human-readable-rule-files.md).
