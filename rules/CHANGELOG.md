# Changes to the built-in rules

The rule files in this directory are read by every site (`scanners.rules`) or
on request (`wordpress.rules`: `include @wordpress`; `attacks.rules`:
`include @attacks`). Each file has a
`version`; each rule a revision (`[SCAN-BACKUP@1]`), raised when the rule
changes what it matches. A site that takes back, replaces or opens a rule and
names the revision it reviewed (`unblock [SCAN-BACKUP@1] at /downloads/**`) is
warned by `bin/request-shield check` and the active rules page when the
revision here is newer — the rule itself applies at once.

## 2026.10.2

`attacks.rules` (`include @attacks`):

| Rule | Revision | Matches |
|---|---|---|
| `ATK-SQL-FUNC` | 1 (new) | SQL injection through the database's own functions: `extractvalue(`, `updatexml(`, `load_file(`, `into outfile '…'` / `into dumpfile '…'`, `@@version` and other server variables (also `@@global.…`, `@@session.…`), `xp_cmdshell`. The words alone ("load file into outfile tutorial") pass. |
| `ATK-JNDI` | 2 | also a lookup inside a lookup -- `${${lower:j}ndi:…}`, `${${upper:j}${upper:n}di:…}` -- and the lookups `lower:`, `upper:`, `ctx:`, `main:`, `spring:`; revision 1 let the nested ones through. Two placeholders side by side (`${amount} of ${count}`) still pass. |

The attack rules also see the body of MySQL's versioned comments
(`/*!50000UNION*/`): the value they are matched against changed, no rule did.

## 2026.10.1

No rule changed what it matches (no revision raised). `scanners.rules` and
`wordpress.rules` carry examples next to their rules (`expect` lines,
[proposal 0029](../docs/proposals/0029-rule-examples.md)): what each rule
refuses, and near misses it must let through. `bin/request-shield test`
decides them, together with a site's own examples.

## 2026.09.1

First version as rule files, with the patterns the library had since 0.1.0.

| Rule | Revision | Matches |
|---|---|---|
| `SCAN-HIDDEN` | 1 | hidden files and folders: `.git`, `.svn`, `.hg`, `.bzr`, `.env`, `.htpasswd`, `.DS_Store`, `.idea`, `.vscode` |
| `SCAN-BACKUP` | 1 | `.bak`, `.old`, `.orig`, `.save`, `.swp`, `.sql`, `.sql.gz`, `.tar`, `.tar.gz`, `.tgz`, `.zip`, `.7z`, `.rar`, `.log` |
| `SCAN-TEST` | 1 | `phpinfo.php`, `php_info.php`, `info.php`, `test.php` |
| `SCAN-DBTOOL` | 1 | `vendor/phpunit`, `phpmyadmin`, `pma`, `adminer` |
| `SCAN-CGI` | 1 | `cgi-bin`, `.well-known/` except `acme-challenge`, `security.txt`, `change-password` |
| `WP-FOLDERS` | 1 | `/wp-admin`, `/wp-includes`, `/wp-content` |
| `WP-SCRIPTS` | 1 | `/wp-login.php`, `/xmlrpc.php`, `/wp-config.php` |
| `ATK-SQL-UNION`, `ATK-SQL-TIME`, `ATK-SQL-TAUT`, `ATK-SQL-SCHEMA`, `ATK-SQL-STACK` | 1 | SQL injection in the query: `UNION SELECT`, `sleep()`, tautologies, `information_schema`, `; DROP TABLE` |
| `ATK-XSS-TAG`, `ATK-XSS-EVENT`, `ATK-XSS-URL` | 1 | cross-site scripting in the query: `<script>` and its siblings, `on*=` handlers, `javascript:` and `data:text/html` addresses |
| `ATK-PHP`, `ATK-SHELL` | 1 | code and shell injection in the query: `<?php`, `eval()`, `shell_exec()`, `; cat /`, `$(id)`, `/bin/sh` |
| `ATK-LFI`, `ATK-WRAPPER` | 1 | file inclusion in the query: `../../`, `/etc/passwd`, `php://` and the other wrappers |
| `ATK-JNDI` | 1 | Log4Shell and template injection (`${jndi:…}`, `${env:…}`), anywhere in the request |
| `ATK-UA-TOOLS` | 1 | attack tools by their User-Agent: sqlmap, nikto, nmap, wpscan, nuclei … |
| `ATK-EXPLOIT` | 1 | paths of well-known exploits: `eval-stdin.php`, `/hnap1`, `/boaform`, `/.aws`, `/.ssh` |
