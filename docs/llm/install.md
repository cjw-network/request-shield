# Install request-shield on a PHP site

For an AI agent (or a person) with shell access to the server. Follow the
steps in order and literally; each says what to run, what you should see, and
what to do when you see something else. Ask the owner where a step says so --
never guess. This page was followed in a container; the log is in
[install-dryrun.md](install-dryrun.md).

What you need: PHP 8.0 or newer on the command line and for the site, and
write access to a directory **beside** the document root. Nothing else: no
Composer, no extension, no database.

## 1. Find the places

Find out, and write down:

- **The document root** -- the directory the web server serves (`DocumentRoot`
  in Apache, `root` in nginx, the hoster's panel). Example: `/var/www/html`.
- **A directory beside it** for the shield, readable by the PHP user, *not*
  inside the document root. Example: `/var/www/request-shield`. Create it:
  `mkdir -p /var/www/request-shield`.
- **How PHP runs:** `php-fpm` or CGI (a `.user.ini` in the document root
  works), Apache's `mod_php` (`apache2ctl -M | grep php` lists `php_module`;
  `.htaccess` with `php_value` works when Apache allows it), or neither
  available (the first line of the front controller -- the `index.php` every
  request goes through).
- **The user PHP runs as for the site:** `www-data` on Debian and Ubuntu,
  `apache` or `nginx` on Red Hat, the pool's `user =` for php-fpm, often the
  account itself on shared hosting. The running processes show it:
  `ps -eo user=,comm= | grep -E 'php-fpm|apache2|httpd|nginx' | sort -u` --
  the user that is not `root` (root runs the parent process). With php-fpm
  it is the pool's user, not the web server's: `grep -h '^user' /etc/php*/*/fpm/pool.d/*.conf
  /etc/php-fpm.d/*.conf 2>/dev/null`.
- **The application:** `wp-config.php` = `wordpress`; a `composer.json` that
  requires `symfony/*` or `ibexa/*` = `symfony`; `config.php` plus `settings/`
  = `exponential`; anything else = `plain`.

## 2. Download and verify

```
cd /var/www/request-shield
wget https://github.com/cjw-network/request-shield/releases/latest/download/request-shield.php
wget https://github.com/cjw-network/request-shield/releases/latest/download/SHA256SUMS
wget https://github.com/cjw-network/request-shield/releases/latest/download/request-shield.php.minisig   # once the releases are signed
php request-shield.php verify request-shield.php
```

(`curl -fsSLO <address>` does the same as `wget <address>`.) Until the
releases are signed the `.minisig` download fails with 404: that is expected,
go on.

Expect `checksum: ok`, then either `signature: ok (request-shield vX.Y.Z
sha256:…)` or `signature: not checked -- this build has no release key yet`.
Both are fine for now; the second means only that the download is complete.
**`does NOT match`, or anything with exit code 1: stop, delete the files, and
tell the owner.** Then `php request-shield.php version`: the first line names
the version.

## 3. Start the rules

```
php request-shield.php init --app=<the application> --docroot=<the document root> --out=/var/www/request-shield/request-shield.rules
```

Name the file **`request-shield.rules`, beside `request-shield.php`**: the
shield finds it there without any setting. `init` refuses a file inside the
document root -- if it does, you chose the wrong directory in step 1.

The starter is in **monitor mode**: every rule is checked and logged, nobody
is refused. It already includes the scanner rules, the attack patterns, the
known marketing parameters and a request limit, and keeps the shield's own
pages (`/rs/…`) to the server itself. Leave `set mode monitor` as it is.

Then give the shield its own directory, writable by the PHP user from step 1
and by nobody else, and check as that user:

```
mkdir -m 700 /var/www/request-shield/.request-shield
chown <the PHP user>: /var/www/request-shield/.request-shield
su -s /bin/sh <the PHP user> -c 'php /var/www/request-shield/request-shield.php check /var/www/request-shield/request-shield.rules'
```

The `tier:` line must say `S1` or `S2` and "writable". **`S0` or "not
writable"** means the store, the compiled rules and the log have nowhere to
go: the shield still lets every request through, but slowly and without a
log -- fix the owner and mode of `.request-shield/`. Only that directory
belongs to the PHP user; `request-shield.php` and the rules stay read-only
for it. (Run as root, `check` says "writable" whatever PHP will find.)

## 4. Fit the rules to the site

Follow [write-rules.md](write-rules.md): it says what to look at in the
application and which line each finding becomes. Uncomment the `host` line
with the site's real names. Write an `expect` line for each rule you add
([write-rule-examples.md](write-rule-examples.md)).

## 5. Check

```
su -s /bin/sh <the PHP user> -c 'php /var/www/request-shield/request-shield.php check /var/www/request-shield/request-shield.rules'
php request-shield.php test /var/www/request-shield/request-shield.rules
```

`check` as the PHP user, as in step 3: run as root it cannot see what PHP
will find. Both must exit 0 (`check` exits 3 when it only warns -- read each warning and
fix it, or tell the owner why it stays). [check.md](check.md) explains the
messages and what to do with each.

## 6. Switch it on

Show the owner the exact line and where it goes **before** writing it.
Choose by how PHP runs (step 1); use absolute paths.

- **php-fpm or CGI:** in the document root, the file `.user.ini`:
  `auto_prepend_file=/var/www/request-shield/request-shield.php`. PHP reads
  `.user.ini` again after `user_ini.cache_ttl` (5 minutes by default).
- **Apache with mod_php:** in the document root's `.htaccess`:
  `php_value auto_prepend_file /var/www/request-shield/request-shield.php`.
  Apache reads it only where its configuration has `AllowOverride Options`
  (or `All`) for the document root; Debian's default for `/var/www/` is
  `None`, and then the line does nothing at all. Changing that is the
  owner's or the hoster's decision: ask, or use the front controller line.
- **A front controller only:** as the very first line after `<?php` in
  `index.php`: `require '/var/www/request-shield/request-shield.php';`.

If the site already has an `auto_prepend_file`, do not replace it: use the
front controller line instead, or ask the owner.

See that it is on, with a temporary page in the document root:

```
echo '<?php echo ini_get("auto_prepend_file"), " | ", $_SERVER["REQUEST_SHIELD"] ?? "(not run)", PHP_EOL;' > <the document root>/rs-on.php
curl -s https://<the site>/rs-on.php
rm <the document root>/rs-on.php
```

Expect the path of `request-shield.php` and the shield's decision (`allow`).
An empty path or `(not run)`: the line from above is not read -- see its
note, or use another way.

## 7. See it work

```
curl -s -o /dev/null -w '%{http_code}\n' 'https://<the site>/'
curl -s -o /dev/null -w '%{http_code}\n' 'https://<the site>/index.php?q=%3Cscript%3Ealert(1)%3C/script%3E'
php request-shield.php trace /var/www/request-shield/request-shield.rules 'GET https://<the site>/index.php?q=%3Cscript%3Ealert(1)%3C/script%3E'
tail -n 5 /var/www/request-shield/.request-shield/shield.log
```

Expect both answered as before (200) -- monitor mode refuses nobody -- and
in the log a line for the second with `monitor-reject` and the rule that
caught it (an `ATK-…` attack pattern, or one of the site's own). `trace`
says in words what each check decided. Use a page that **is** PHP (the
front page, `index.php`): the shield runs where PHP runs. A path that is no
PHP file -- `/.env` on a site without a front controller -- is answered by
the web server alone, which is why the scanner rules act on sites that send
every request to one `index.php` (WordPress and most CMS do). **No log line,
or a PHP error on the page:** remove the line from step 6 at once, then read
[check.md](check.md), "The site shows an error".

## 8. Report, and what comes next

Tell the owner: the files you created or changed, each rule you added with
one sentence why, the output of `check` and `test`, and the next steps --
read the log for a few days (`grep monitor- .request-shield/shield.log`),
fix what a real visitor would have hit, then change `set mode monitor` to
`set mode enforce`. **Do not switch to enforce yourself.**

## Never

- Never put the shield, its rules, `.request-shield/` or the log inside the
  document root.
- Never guess address ranges for `restrict`; ask the owner.
- Never write a real visitor's address, a name, a token or a secret into a
  rule file or an example (documentation ranges only: 192.0.2.0/24,
  198.51.100.0/24, 203.0.113.0/24).
- Never install anything else.
