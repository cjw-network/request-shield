# Reference: the command line

<!-- Written by docs/tools/gen-reference.php from src/Rules/Reference.php -- do not edit; run the tool. -->

`bin/request-shield` in the repository and a Composer install, `php request-shield.php` for the single file. What it prints without a command:

```text
usage: request-shield check|show|reload <main.rules> [--source=<glob>]...
       request-shield trace <main.rules> "GET https://www.example.org/path" [--ip=<address>] [--ua=<User-Agent>] [--source=<glob>]...
       request-shield test <main.rules> [--source=<glob>]... [--only=<ID>] [--as-written] [--junit=<file>]
       request-shield replay <main.rules> <session.har|access.log|urls.txt> [--ip=<address>|log] [--all] [--as-written] [--junit=<file>]
       request-shield crawlers <main.rules> [update] [--force]
       request-shield access-token <main.rules> "<principal>"|'*'
       request-shield feeds <main.rules> [list|update|export] [--force] [--format=plain|nginx|nftables|ipset] [--write=<file>]
       request-shield stats <main.rules> [--days=7 | --from=YYYY-MM-DD --to=YYYY-MM-DD] [--by=day|week|month|year] [--crawler=<ID>] [--path=/news/] [--sort=views|blocked|refused|checked|throttled] [--site=<name>|--group=<name>] [--json]
       request-shield api <main.rules> "GET|POST </path>" [--<param>=<value>]... | --openapi[=json|yaml]
       request-shield deny|allow <main.rules> <address|range> [--for=7d | --until=2026-10-07[T15:30]] [--reason="…"] [--force]
       request-shield unlist <main.rules> <address|range>
       request-shield lists <main.rules>
       request-shield version [<main.rules>]
       request-shield init --app=exponential|plain|symfony|wordpress [--docroot=<dir>] [--out=<file>] [--force]
       request-shield verify <request-shield.php> [--sums=<SHA256SUMS>] [--sig=<file.minisig>] [--key=<public key>]
       request-shield self-update [--check] [--to=vX.Y.Z] [--major]
       request-shield examples <main.rules> --markdown [--feature=RSF02-06] | --html [--out=<file>] | --coverage
       request-shield vocabulary [--json]
```

## The commands

- **`version`** the library's version and build, PHP, whether APCu is there and the shipped rule sets' versions; with a rule file also the store in use, the mode and the versions of the site's own rule files.
- **`check`** reads every file, reports the first error with file and line, and warns about rule files anyone else could change.
- **`show`** the rules in effect after merging, each with the line it comes from.
- **`reload`** check, then mark the main file changed (touch), so every server reads the rules again on its next check -- for files added or changed where servers without APCu do not look by themselves (extensions).
- **`trace`** what happens to a request, check by check, in plain words (nothing is counted); the visitor's address with --ip (default 198.51.100.7).
- **`test`** decides every example next to the rules (expect lines), each on a fresh store, with the rules switched on (monitor as enforce; as they are with --as-written); lists the site's rules without an example. Exit 0: all pass, 1: one fails, 2: a mistake in the files.
- **`replay`** sends a recording of requests known to be good through the rules -- a session clicked through in a browser or by end-to-end tests (a HAR file), a web server's access log (its 2xx and 3xx), or a list of addresses -- each once, on a fresh store, nothing counted, the rules switched on as for test; says which the rules would refuse or check, and by which rule. Static files and other websites' addresses are left out (--all keeps the static files); every request comes from --ip (log: each line's own address). Exit 0: none refused, 1: one is, 2: a mistake in the files.
- **`crawlers`** the known crawlers, what the site does with each, and how old their address lists are. "update" fetches the operators' current lists into store-dir (cron, a deploy -- or where there is internet, copying store-dir/crawlers/ into a DMZ); the servers read them on their next check. A list that shrank to less than half is kept unless --force.
- **`access-token`** a new token for the dashboard (proposal 0023): printed once, with the dashboard-access line to paste -- only its hash goes into the rule file. "*" for everything, a group's name for its websites only.
- **`feeds`** the public blocklists the rules name (feed …): how many entries, how old. "update" fetches those that are due (cron, e.g. hourly; each at most as often as its terms allow) into store-dir/feeds; a list that shrank to less than half is kept unless --force. "export" writes the addresses kept out (the deny list and the feeds named deny) for the level below PHP: a firewall (nftables, ipset), nginx, a plain list. Not .htaccess: Apache reads it on every request -- measured far too slow for lists (see docs/proposals/0025-blocklist-feeds.md).
- **`stats`** what the counters (set stats on) say about the last days: requests let through, checked, refused, the rules behind them, the answers' status codes, pages not found and who links to them, what each known crawler did, other bots -- in words, or as JSON for a CMS.
- **`examples`** the "# demo:" groups of a rule file (one per feature): as Markdown tables for the docs (--markdown, --feature=RSF02-06 for one), as a page that needs no server with what test decided for each row (--html, --out=&lt;file&gt;), or how much is covered (--coverage).
- **`vocabulary`** every rule and set key, how it is written and the feature it belongs to (--json: with what each does) -- docs/reference's source.
- **`init`** a commented starter rule file for an application, in monitor mode; never inside --docroot, never over a file without --force.
- **`verify`** is a downloaded file the released one: its checksum from SHA256SUMS and, with the release key and sodium, its minisign signature.
- **`self-update`** the single file replaces itself with a signed release, every file checked before any is replaced (--check: exit 10 when a newer one exists); the command line only, never by itself.
