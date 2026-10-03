# 0005 — Versioned rule sets, reviewed revisions, replacing a rule

| | |
|---|---|
| Status | **Implemented** 2026-09-29 (see [rule files](../features/RSF05-01-rule-files.md#versions-revisions-and-replacing-a-rule)) |
| Proposed | 2026-09-29 |
| Affects | rule files, `bin/request-shield`, the active rules page |

## Summary

Every rule file can carry a `version`, every rule a revision (`[SCAN-BACKUP@3]`).
A site that takes back, replaces or opens a rule names the revision it reviewed;
when a library update changes that rule, `check` and the rules page say so. And
`replace [ID] <rule>` swaps a rule in one line, keeping its ID.

## Motivation

- A site changes built-in rules for good reasons (downloads are `.zip` files,
  an admin's file reader opens `.env`). When the library later fixes or extends
  such a rule, nobody would notice that the site's deviation deserves a look.
- Several servers, several deploys: which rule set is live where?
- Replacing a rule took two lines and gave it a new ID, so its history in the
  log started from zero.

## Design

```text
# rules/scanners.rules (shipped)
ids SCAN required
version 2026.09.1
[SCAN-BACKUP@1]  block regex \.(bak|old|…)$     # backups, dumps and archives

# site.rules
ids SITE
version 2026-09-29.2
[SITE-DL]  unblock [SCAN-BACKUP@1] at /downloads/**            # downloads are archives
replace    [SCAN-TEST@1] block /phpinfo.php /info.php           # test.php is a real page here
```

- **`version <word>`**, one per file, named by the file's namespace (`SITE`,
  `SCAN`) or, without one, by the file. Shown by `check`, `show` and the rules
  page ("rule sets SCAN 2026.09.1, SITE 2026-09-29.2").
- **`[ID@n]` before a rule** gives its revision; the shipped rules have one and
  raise it when a rule changes what it matches (`rules/CHANGELOG.md`). A rule
  without one is revision 1.
- **`[ID@n]` as a reference** (in `unblock`, `unblock … at`, `replace`) pins the
  revision reviewed. When the rule's revision differs, a warning names both:
  *"SITE-DL (site.rules:4) was written for SCAN-BACKUP revision 1;
  SCAN-BACKUP is now revision 2 (built-in scanners.rules:12) — please check
  what changed"*. `check` exits with 3, the rules page shows it at the top.
  **The changed rule applies at once**; only the deviation needs a look. A
  reference without `@n` is never warned about.
- **`replace [ID@n] <rule>`** takes back everything the rule with that ID set
  (blocks, cache paths, challenge paths, budgets, restrictions, exceptions,
  method paths, hosts) and reads the new rule under the same ID; the location
  reads `site.rules:5 (replaces built-in scanners.rules:13)`, the comment is
  the new description. Any rule can be replaced, not only built-in ones — an
  extension's too.

## Cost

None per request: versions, revisions and warnings are worked out when the
rule files are read and compiled.

## Decisions taken when implementing

1. Warnings, not errors: an update must never stop a site from starting, and
   the protection it brings applies at once.
2. A pin to a revision higher than the rule's own is warned about too (a typo,
   or rules rolled back).
3. The replaced rule keeps the revision of the rule it replaces, so the pin
   goes on being checked against the library's.
