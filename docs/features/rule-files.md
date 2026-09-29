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
| `cache-path <paths>` | `cacheable.paths` | what a cache may keep; `any`: every path (default) |
| `cache-query <names>` | `cacheable.query` | parameters a cached URL may have; `any` (default), `none` |
| `limit <name> <n>/<unit> [challenge-at <n>] [on-demand]` | `budgets` | units `s`, `sec`, `min`, `hour`, `day`, also `20/10s` |
| `no-limit <name>` | `budgets` | switch a budget off, the default one too |
| `challenge <paths>` | `challenge.alwaysPaths` | always check the browser there |
| `challenge-exempt <paths>` | `challenge.exemptPaths` | never challenge there (APIs, feeds) |
| `exempt <addresses or ranges>` | `exempt.ips` | never counted |
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

`block @scanners` and `block @wordpress` add the built-in sets (the scanner
set is on by default); `unblock @scanners` takes it back, `unblock <pattern>`
takes back exactly that pattern from an earlier line or file.

### `set`

| Key | Value |
|---|---|
| `secret` | at least 32 characters; better `${SHIELD_SECRET}` than in the file |
| `store` | `auto`, `apcu`, `file`, `memory` |
| `store-dir` | where file counters and a generated secret live |
| `pass-ttl`, `solution-ttl` | `3600`, `30m`, `1h`, `1d` |
| `difficulty-min`, `difficulty-max` | numbers |
| `cookie`, `solution-cookie` | cookie names |
| `bind-user-agent`, `search-engines`, `debug-header`, `strip-untrusted-forwarded` | `on` / `off` |
| `ipv6-prefix`, `max-uri`, `max-query-parameters`, `max-header-bytes` | numbers |
| `text.title`, `text.text`, `text.noscript`, `text.nocookies`, `text.failed`, `text.lang` | the challenge page's texts (the rest of the line) |
| `log`, `log-level`, `log-ip`, `log-max-size` | [the log](log-and-rule-ids.md) |
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

Every rule remembers where it was written (`site.rules:12`,
`ext/shop/settings/request-shield.rules:2`, `default @scanners`), and every
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
