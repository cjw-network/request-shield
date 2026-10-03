# Settings, checked once

## What it does

The configuration is one PHP array (`src/Config.php` has every key with its
default). It is **checked** into typed values — a wrong type is an error that
names the key — and, with `Shield::protectFile()` or `bootstrap.php`,
**compiled**: the checked values are written once as a PHP file (0600) that
OPcache serves from memory, and rebuilt when the settings file's mtime or size
changes.

## Use cases

- Every request on a busy site: settings cost about 8 µs instead of 10–11 µs,
  most of it one `stat()` of the settings file to notice changes.
- Deploying a wrong setting: the error names it at once
  (`request-shield: 'budgets.requests.limit' must be an integer`).

## Configuration

```php
\CjwNetwork\RequestShield\Shield::protectFile('/path/to/request-shield.php');   // compiled
\CjwNetwork\RequestShield\Shield::protect($configArray);                       // checked every time
```

The compiled file goes to `.request-shield/` next to the settings file unless
`protectFile()` is given another directory; the store's default is
`.request-shield/store` there too. Both belong outside the document root, as
the settings file does. The system's temp dir is not used: on a shared host
it is shared between customers, and the store holds the secret.

## What this installation can do: the tiers

PHP ≥ 8.0 is the only requirement ([ADR 0013](../adr/0013-hosting-tiers.md));
a writable directory and APCu are tiers the shield detects. `request-shield
check site.rules` and `version site.rules` print the tier and what is not
active in it:

| Tier | Environment | What runs |
|---|---|---|
| **S0** | no writable directory, no APCu | the stateless rules (hard rejects, blocked paths, access rules, host, forms from the website, parameters, attack patterns, the cacheable definition); the settings are compiled on every request; budgets, bans and the pass cookie's secret have nowhere to live, lists, feeds and statistics neither |
| **S1** | a writable `store-dir`, no APCu — the shared-hosting norm | everything, in files (~25 µs a request) |
| **S2** | APCu | counters in shared memory (~9 µs a request) |

A feature that lacks its tier switches off and `check` says so; nothing fails.

## When the rules cannot be compiled

A rule file with a mistake in it — a typo after a deploy, a file the server
cannot read — never takes the site down
([ADR 0007](../adr/0007-fail-safe-pass-through.md)):

- **After a good compile:** the last good compiled settings stay in force; the
  requests are decided as before the change. PHP's error log gets one line a
  minute naming the file, the line and the mistake; a marker next to the
  compiled settings keeps the servers from compiling again on every request
  until the file changes.
- **At the first install**, with nothing compiled yet: the shield runs
  switched off (every request passes, nothing is counted or logged) and the
  error log says so once a minute, with the mistake.
- **A compiled settings file cut short** (a full disk) is noticed, deleted
  and compiled anew on the next request.
- `request-shield check site.rules` is where a mistake is an error, with file
  and line — run it before a deploy; a passing `check` means nothing above
  will happen.

The fix is picked up as any change is: the next request compiles the file.

## Limits

A settings file changed twice within one second to the same size keeps the
first change until the next write; edit tools rarely manage that.
