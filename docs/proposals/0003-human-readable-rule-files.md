# 0003 — Human-readable rule files, loadable from several places

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-09-29 |
| Affects | settings loading, adapters |

## Summary

Rules written one per line in a small text format that people can read and
edit without knowing PHP, loaded from a main file plus any number of further
files — for example one per CMS extension — and compiled once into the same
OPcache-served PHP file the settings use today. The PHP array settings stay; a
rule file is an alternative source for the same settings.

## Motivation

- Site owners and administrators should be able to read and change the rules
  ("block these paths", "check the browser on the login page") without editing
  PHP arrays.
- A CMS extension or plugin knows which of its URLs are valid and which are
  sensitive; it should ship its own rules instead of asking every site to copy
  them.
- The same rules could later be exported for nginx, Apache or Varnish.

## The format

One rule per line: a keyword, then values separated by spaces. `#` starts a
comment. Simple patterns by default — `*` matches anything except `/`, `**`
anything — and `regex` for the rare case that needs one.

```text
# site.rules

host        www.example.org example.org
trust       10.0.0.0/8                       # the load balancer

block       /.env  /.git/**  *.sql  *.bak
block       /wp-admin/**  /xmlrpc.php        # this site is not WordPress
block regex ^/(phpmyadmin|adminer)

cache-query page
cache-path  /  /news/**  /page/*

limit       requests 600/min challenge-at 300
limit       misses   60/min  on-demand
challenge   /login  /admin/**                # always check the browser
exempt      192.0.2.50                       # monitoring

set         debug-header on
include     rules.d/*.rules
```

| Keyword | Setting it fills |
|---|---|
| `host` | `hosts` |
| `trust` | `trustedProxies` |
| `method` | `methods` |
| `block` / `unblock` | `blockedPaths` (add / remove) |
| `cache-path`, `cache-query` | `cacheable.paths`, `cacheable.query` |
| `limit <name> <n>/<sec\|min\|hour> [challenge-at <n>] [on-demand]` / `no-limit <name>` | `budgets` |
| `challenge`, `challenge-exempt` | `challenge.alwaysPaths`, `challenge.exemptPaths` |
| `exempt` | `exempt.ips` |
| `set <key> <value>` | any other setting (`store`, `debug-header`, `ipv6-prefix`, …) |
| `include <path or glob>` | further files, relative to the including file |

Every error names file and line (`site.rules:7: unknown rule "blok"`); every
pattern is compiled when the files are read, so a broken one is an error then,
never a silent miss on a live site.

## Several sources

- `include rules.d/*.rules` — files in alphabetical order (as Debian's
  `conf.d`), so `10-base.rules`, `50-extension.rules`, `90-site.rules` layer
  predictably.
- From code: `Shield::protectFile('site.rules', sources: [...])`; an adapter
  fills `sources` from its extensions — for Exponential,
  `extension/*/settings/request-shield.rules` of every active extension.
- **Merging:** lists are combined; single values follow "the later wins";
  `unblock`, `no-limit` and `challenge-exempt` let a later file take back what
  an earlier one (or a default) set. The site's own file comes last.

## Cost

Nothing per request beyond today: the files are parsed once and compiled into
the PHP file OPcache serves. What costs is noticing a change — one `stat()`
per source file, about 2.6 µs each on the test machine:

- with APCu: sources are checked at most every N seconds (`set recheck 10`),
  otherwise one APCu read;
- without APCu: only the main file is checked on each request; files from
  extensions are recompiled with `php bin/request-shield compile` (run on
  deploy or when an extension is installed).

## Security

- Rule files are read, never executed; there is no expression language.
- They must not be writable by the web server (the loader can warn when they
  are).
- `include` stays below the including file's directory unless an absolute
  path is given.

## Compatibility

The PHP array settings keep working unchanged; `protectFile()` accepts a
`.rules` file or a `.php` settings file. Defaults apply unless a rule file
takes them back.

## Open questions

1. Is `*`/`**` enough for patterns, or should `?` and `{a,b}` be supported?
2. Default recheck interval with APCu (10 s?).
3. Should the site's own file always be applied last, or where it includes others?
4. A command that prints the effective rules after merging (`request-shield show`) — useful for support.
