# request-shield for Exponential

A proposal for an Exponential site, ready to adapt:

- `exponential.rules`: the frontend (system URLs such as `/content/view/full/2`,
  internal files, forms, the search with its time filter, pace and bans);
- `exponential-admin-uri.rules`: **the main file when the admin is the
  siteaccess `/admin`**;
- `exponential-admin-host.rules`: **the main file when the admin has a host of
  its own** (`admin.example.org`);
- `config.php`: the integration (one `Shield::protectFile()`).

## The click demo

A pretend Exponential site behind these rules (`index.php`, `demo.rules`: the
`/admin` variant, switched on), with the addresses an Exponential site gets as
numbered tests to click: pages by alias, system URLs, admin modules, internal
files, downloads, view parameters, the search with its time filter, forms, the
admin. **Show the answer** fetches each in the background and shows the
status and the header `X-RS` (decision and rule). From the
repository's root:

```bash
php -S 127.0.0.1:8095 examples/exponential/router.php
```

Open http://127.0.0.1:8095/. Under a web server: `…/examples/exponential/`
with rewrite rules that send every path to its `index.php` (nginx: the
location block in [the other demo's README](../demo/README.md#in-a-subdirectory-of-a-web-server),
with this directory), or `…/examples/exponential/index.php/` without them.
Every link is relative to the demo's own address, so it works wherever it lies. Counters and the log go to
`/tmp/request-shield-exponential-demo/` (`EXP_DEMO_VAR` puts them elsewhere).
`tests/ExponentialDemoTest.php` fetches every row and checks it answers what it says.

The files ship in monitor mode: everything is logged as it would be decided,
nobody is refused. The reason for every rule, how to switch it on, what it
costs and what it does not do: [docs/use-cases/exponential.md](../../docs/use-cases/exponential.md).
`tests/ExponentialRulesTest.php` keeps every decision described there.
