# 0045 — The shield's own files out of a browser's reach

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-08 |
| Affects | every place the shield creates a directory (store, secret, compiled settings, log, lists, feeds, statistics, the HTTP cache), `Tier` / `check`, the compile step of the settings, `init` |
| Relates to | [RSF05-01 rule files](../features/RSF05-01-rule-files.md) (`store-dir`, `lists-dir`, `http-cache-dir`, `log`) · [RSF05-02 settings and tiers](../features/RSF05-02-settings.md) · [privacy](../privacy.md) · [0016 rule advisor](0016-rule-advisor.md) (its recordings would live in `store-dir`) |

## The question

*"Rules could protect the shield's own data -- then it could lie in the
document root too?"* (owner, 2026-10-08)

Only in part. The shield runs **before PHP code** (`auto_prepend_file`, or
the first line of `index.php`). A file the web server finds on disk -- the
log, the rules, the generated secret, a recording of
[0016](0016-rule-advisor.md) -- is a **static file**: Apache or nginx sends it
without starting PHP, so the shield never sees the request and no rule can
stop it.

```
GET /.request-shield/store/secret
        │
   web server: the file exists → send it        ← the shield never runs here
        │
   (only a file that does NOT exist → index.php → shield → rules)
```

A CMS's usual setup passes only **missing** files to `index.php` (Apache:
`RewriteCond %{REQUEST_FILENAME} !-f`; nginx: `try_files $uri … /index.php`) --
the typical case; a server that sends every request to PHP is rare. So the only protection that holds on
every server is the one the docs already give: **the shield's files outside
the document root**. `request-shield init` refuses a rule file inside it
(`--docroot`). Nothing checks it later, and nothing protects a directory that
ends up there anyway (a setting changed by hand, a host that knows no other
place, `store-dir` pointing into `htdocs`). By default everything lies next
to the main rule file (`.request-shield/` and `.request-shield/store`), so it
lands in the document root only when the rule file does; without a rule file
the store defaults to the system's temp dir. The compiled settings
(`settings-*.php`) are PHP: a direct request would even run them.

**The proposal:** two safety nets for that case -- not a reason to put the
files there.

## 1. A note the shield finds itself

When the settings are compiled (the first request after a change -- not on
every request), PHP knows the document root (`$_SERVER['DOCUMENT_ROOT']`).
The shield compares it, as text, with each of its directories and files:
`store-dir`, the compiled settings' directory, `log`, `lists-dir`,
`http-cache-dir`, `crawler-log`, the rule files themselves.

- One inside: one line in PHP's error log, once per compile (in S0, with no
  writable directory, the settings are compiled on every request: there the
  line is written at most once a minute, as `Failure::note()` does) --
  *"request-shield: store-dir /var/www/html/.request-shield/store is inside
  the document root /var/www/html -- a browser could read the secret, the
  lists and the log; move it beside it (set store-dir …)"*.
- `request-shield check` says the same, given the root:
  `check site.rules --docroot /var/www/html` (the CLI has no document root of
  its own; `init` already takes `--docroot`). The tier notice (S0/S1/S2) gets
  the line too.
- Cost: none on the passing path in S1/S2 (compiled settings); in S0 a few
  string comparisons per request, next to a compile that costs far more. A
  string comparison per directory; no `realpath()` (a stat) -- the paths are compared as written,
  with `..` and `//` folded.

## 2. A deny file where the shield creates a directory

Wherever the shield creates a directory of its own (there are about a dozen
`mkdir()` calls: the store, the secret, the compiled settings, the log's
directory, lists, feeds, crawler lists, statistics, the HTTP cache), it also
writes, **once, only into a directory it just created**. Not into the file
store's shard directories (`FileStore` creates them on the request path): the
file in the store's root covers them, and the passing path gets no write.

- `.htaccess` with
  ```apache
  # written by request-shield: nothing here is for a browser
  Require all denied
  ```
  Apache 2.4 only (2.2 knew `Deny from all`). Apache reads it only for
  requests **into that directory** -- not on
  every request of the site, so the cost the shield avoids elsewhere
  ("Apache reads .htaccess on every request") does not arise here.
- nothing for nginx: nginx reads no such file. `check` prints the block to
  add instead:
  ```nginx
  location ^~ /.request-shield/ { return 404; }   # or: deny all;  (a 403)
  ```
  (`return` runs before `deny` would: together, the answer is the 404.) The
  path fits the default place; a `store-dir` elsewhere in the document root
  needs its own line -- `check` prints them all.

It is a second net, never the first: it works only where Apache allows
`.htaccess` (`AllowOverride AuthConfig` or `All`) and the `authz` module is
loaded. A directory the site created itself is left alone (the shield writes
no file into a directory it did not make).

One helper for all `mkdir()` calls (`Dir::make($dir, $mode)`) keeps this in
one place, and in the tests.

## What stays as it is

- The docs keep saying: **outside the document root**. That is the only
  protection that does not depend on the web server's configuration.
- `init --docroot` keeps refusing a rule file inside it.
- No rule in the shield pretends to protect its own files: it cannot.

## Tests (when built)

- A store directory under a document root: the compile writes the line to the
  error log once; a second request (compiled settings) writes nothing; in S0
  at most one line a minute.
- `check --docroot` names each directory inside it and exits with the warning
  code; outside: no line.
- A directory the shield creates gets `.htaccess`; a directory that already
  existed gets nothing; an existing `.htaccess` is never overwritten.
- End to end with Apache if available (`skip()` otherwise): a file in a
  created directory under the document root answers 403.
- Bench: the passing request unchanged in S1/S2 (the check runs only at
  compile time); no write on the request path (shard directories get no file).

## Open questions for the owner

1. **Write `.htaccess` by default**, or only with `set protect-dirs on`? It is
   a file the site did not ask for -- but only in directories the shield made.
   *Proposed: by default.*
2. **A directory inside the document root: warn, or refuse to use it**
   (fail safe would mean: still protect the site, but not write the log or
   the secret there)? *Proposed: warn; refusing would switch off budgets and
   the check on hosts that have no other place.*
3. **The rule files themselves** inside the document root (a `.rules` file is
   plain text): warn the same way? *Proposed: yes.*
4. **nginx:** only the printed block, or also a ready file to `include`?
