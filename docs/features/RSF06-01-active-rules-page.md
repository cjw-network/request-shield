# RSF06-01 The active rules page

## What it does

A page that shows how the site is protected — for site owners, not only for
technicians — and lets anyone with access try an address against the rules:

- **At a glance:** how many kinds of address are refused, restricted areas,
  limits per visitor, and — from the [log](RSF05-05-log-and-rule-ids.md) — how many
  requests were refused and checked in the last 24 hours.
- **Try an address:** kind of request, address and the visitor's IP; the page
  shows every check as a step — ✓ fine, ! answered with a remark (not cached,
  browser check), ✕ refused here, – not checked any more — and the result in
  one sentence: *"This visitor gets "not found" (404) — the site never sees
  it. Decided by: SCAN-BACKUP"*. **Nothing is counted**: the
  check only reads the visitor's counters, and shows them ("4 of 60 per
  minute, browser check from 20").
- **The rules in plain words**, grouped the way a site owner thinks about them
  (addresses only attackers ask for, areas for certain visitors, where forms
  may be sent, website names and sizes, what a cache may keep, pace per
  visitor, browser check, proxies and the log). Each shows what was written
  (`/wp-admin/**`, not the regular expression; the built-in ones in words:
  "backups, dumps and archives: .bak, .old, .sql, .zip …" — the comment after a
  rule in its file is its description), its ID and where it is
  written (`SITE-10`, `site.rules:12`), and how often it decided in the last 24 hours,
  and when last.
- **Lately:** the latest refused or checked requests, in words.
- The summary, the counts and the latest activity **refresh every 10
  seconds** (only while the page is visible).

The same check on the command line, for support:

```text
$ php bin/request-shield trace site.rules "POST https://www.example.org/page/about"
  ✓ Kind of request                   POST is accepted (GET, HEAD, POST, OPTIONS)
  …
  ✕ Where forms may be sent           a POST is only accepted at: /contact  [site.rules:22]
  – Areas for certain visitors        not checked: already refused above
This visitor gets "not allowed here" (405) …. Decided by site.rules:22.
```

## Rules & setup in the dashboard

The dashboard serves the same as its page **Rules & setup**
(`/rs/waf/rules`), in four parts. Each part's heading has a `?` that opens
its section here.

### The rule tester

An address (a full URL or a path), the kind of request, the visitor's IP and,
if wanted, a User-Agent. Every step says what it makes of it, a diagram shows
where the request ends, and the rule that decides links to its row. Nothing
is counted: the pace shows the visitor's real counters.

### The way of a request

The checks in the order the shield runs them, each coloured when it is on
and grey when it is off, each with what it answers and how it is switched.
After them: what the log and the statistics write, and when.

### The rules

Every rule as the rule files hold it, one part per file: its ID, what it does
(the comment after it), how it is written, its line, and how often it
decided in the period shown. `/rs/waf/rules#rule-SITE-ADMIN` opens a rule's
row.

### The technical settings

Everything the shield runs with, as compiled from the rule files: mode,
proxies, limits, store, browser check (the secret only as "set" or "not
set"), log, lists, feeds.

### Help on every page

Every page of the dashboard says in one sentence what it shows, and every
section has a `?` that opens its place in these docs, with the feature's id
in its title. A part with nothing to show yet says how it gets something.
The links go where `set docs-url` says:

```text
set docs-url https://docs.example.org/request-shield   # a copy of your own
set docs-url off                                       # no links; the sentences stay
```

The default is the repository's docs. The check page and the box inside a
form link to [the browser check, in plain words](../explained/browser-check.md)
for visitors. Error messages of the command line end with the address of
their section.

## Use

```php
use CjwNetwork\RequestShield\Report\RulesPage;
use CjwNetwork\RequestShield\Shield;

// in the site's admin area, after protect()/protectFile():
echo RulesPage::render(Shield::active()->settings, [
    'check' => $_GET,                   // the form's method, url, ip
    'action' => '/admin/request-shield',// where the form goes
    'ip' => $_SERVER['REMOTE_ADDR'],    // the address the check starts with
]);
```

Or with any settings: `RulesPage::render(Settings::load('site.rules'))`. The
page is complete HTML without external resources, in light and dark. The demo
has it at `/rules` (`examples/demo`).

The inspector walks `Shield::chain()` -- the one list of what the shield
checks, in its order (0031 C.1) -- so a step added to the chain appears in the
trace by itself. The parts are usable on their own: `Report\Inspector` (the step-by-step
check), `Report\LogStats` (counts per rule from the log), `Report\Describe`
(settings and decisions in words).

## Security

The page shows how the site is protected — which addresses are refused,
which areas are restricted, the limits. **It belongs behind the site's admin
login, or a `restrict` rule** (the demo: `restrict **/rules to 127.0.0.1 ::1`).
It never shows the secret. Everything from requests and the log is escaped.

## Cost

None on a normal request: the classes are loaded only when the page (or
`trace`) runs. The page reads at most the last megabyte of the log (and of the
rotated file), a few milliseconds.

## Examples from the demo

What the demo's rules decide for this feature -- the same lines `request-shield test` checks and the demo's front page shows (`php -S 127.0.0.1:8080 examples/demo/router.php`).

<!-- examples: docs/tools/sync-examples.php from examples/demo/request-shield.rules -- do not edit; run the tool. -->
**RSF06-01 · The active rules page**

Every rule in plain words, with a check for any address: this machine only.

| Request | The rules decide | |
|---|---|---|
| `/rules` | no access (403) · rule DEMO-RULES — from another address (198.51.100.7) | from anywhere else |
| `/rules` | the site answers it — from 127.0.0.1 | The active rules, from this machine |
| `/rs/waf/rules` | look at it | Rules & setup in the dashboard: the way of a request, every rule |
<!-- /examples -->
