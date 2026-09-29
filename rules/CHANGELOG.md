# Changes to the built-in rules

The rule files in this directory are read by every site (`scanners.rules`) or
on request (`wordpress.rules`: `include @wordpress`). Each file has a
`version`; each rule a revision (`[SCAN-BACKUP@1]`), raised when the rule
changes what it matches. A site that takes back, replaces or opens a rule and
names the revision it reviewed (`unblock [SCAN-BACKUP@1] at /downloads/**`) is
warned by `bin/request-shield check` and the active rules page when the
revision here is newer — the rule itself applies at once.

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
