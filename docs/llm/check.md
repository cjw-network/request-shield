# Check what you wrote

For an AI agent after writing or changing rules ([write-rules.md](write-rules.md)).
Three commands, all on the command line, none of them touches a visitor:

```
php request-shield.php check request-shield.rules     # does it compile; what is suspicious
php request-shield.php test  request-shield.rules     # does every expect line hold
php request-shield.php trace request-shield.rules "GET https://www.example.org/.env" [--ip=198.51.100.7] [--ua="…"]
```

(`bin/request-shield` instead of `request-shield.php` in a Composer or source
install.)

## check

| Exit | Means | Do |
|---|---|---|
| 0 | it compiles, nothing to warn about | go on |
| 3 | it compiles, with warnings on stderr (`warning: …`) | read each one; fix it, or tell the owner why it stays |
| 1 | an error: `<file>:<line>: <what>` | open that line; the message says what was expected |

The last lines say what the rules come to (`ok: … blocked patterns, …
budget(s)`), the hosting tier (`tier: S1 …` -- without APCu every counter is
a file: fine for a small site) and notes. Warnings you will meet:

- **"the dashboard's pages are open to everyone"** -- keep the starter's
  `restrict **/rs/** to 127.0.0.1 ::1`, or ask the owner for the admin's
  ranges.
- **"… can be changed by anyone on this machine"** -- `chmod o-w` on that
  file.
- **"… was written for SCAN-BACKUP revision 1; SCAN-BACKUP is now revision
  2"** -- a shipped rule changed in an update: read its new text in `show`,
  then pin the new revision (`[SCAN-BACKUP@2]`).
- **"the feed … is not fetched yet"** -- `request-shield feeds <file> update`
  (and a cron line for it).

## test

Decides every `expect` line, each on a fresh store, with the rules switched
on as `enforce` would have them (monitor mode included). Exit 0: all pass;
1: one fails; 2: a mistake in the files. A failing line:

```
SITE-FORMS   ✕ POST /contact   expected answered, got check by SITE-ORIGIN
                 (request-shield.rules:33)
```

reads: the example below `SITE-FORMS` expected the form to be answered, but
`SITE-ORIGIN` (another rule) sends a form without `Origin` to the browser
check first. Decide which is right: here the example lacks what a browser
sends -- `expect POST /contact answered header Origin:https://www.example.org`.
Change a rule only when the example shows what the site really needs. The
summary names rules without an example: give each one.

## trace

What one request meets, check by check, in words, with the rule that
decides; nothing is counted. Exit 0: it passes (or the mode lets it pass);
4: it would be refused. Use it for a page the owner says is blocked, with
`--ip` and `--ua` as the visitor had them.

## The log

With `set log` (the starter has it) every decision that stopped a request,
or would have in monitor mode, is a line in `.request-shield/shield.log`: the
time, the address masked, the outcome (`reject`, `throttle`, `challenge`, in
monitor mode `monitor-reject`, `monitor-throttle`, `monitor-challenge`), the
status, the reason, the rule's ID, the request, the User-Agent. Only
requests that reach PHP are there (see [install.md](install.md), step 7). Read it with the owner after a few days:

```
grep -c monitor- .request-shield/shield.log                                              # how many would have been stopped
grep monitor- .request-shield/shield.log | grep -o 'rule=[^ ]*' | sort | uniq -c | sort -rn   # by rule
```

A line looks like this (the address masked to its network):

```
2026-10-03T22:25:46+00:00 127.0.0.0/24 monitor-reject 404 "unknown parameter" rule=SITE-STRICT "GET http://localhost/news.php?page=two" "curl/7.74.0"
```

A rule that would have stopped real visitors (a page of the site, a form,
the editors' area) is wrong: fix it, add an example, run `test`. Attackers'
paths (`/.env`, `/wp-login.php` on a site without WordPress) are right.

## When to switch to enforce

Only the owner decides, after seeing: `check` and `test` exit 0; a few days
of log in which every `monitor-…` line is an attacker or a bot; no complaint
from the site's users. Then `set mode enforce` -- the next request uses it.
If something goes wrong: `set mode monitor` again, at once.

## The site shows an error

The shield never stops the site for its own sake: a rule file that does not
compile keeps the last good rules (or none), and the error is in PHP's error
log once a minute. A white page or a PHP error right after step 6 of
[install.md](install.md) means the activation line points to a wrong path, or
PHP is older than 8.0: remove the line, check the path with `ls -l`, check
`php -v` **for the web server** (not only the command line), then try again.
