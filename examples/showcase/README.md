# Showcase

One page that shows what request-shield does, in plain words, in German and
English — and lets you try it. From the repository's root:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8090 examples/showcase/router.php
```

(Several workers, so the page's own requests -- the stream, a bot -- do not
wait for each other; `php -S` alone answers one request at a time.)

Open http://127.0.0.1:8090/ (`?lang=de` or `?lang=en`).

- **The rules** are [`showcase.rules`](showcase.rules): about fifteen lines with
  values a real website uses — 60 pages a minute pass silently, then the
  invisible browser check, past 120 a pause; parameters with types; forms only
  to the contact page and only from the site's own pages; the admin area from
  the office network; the login checked first. The page shows the file, each
  rule beside what it means.
- **Every try is an `expect` line** right below its rule. `php
  bin/request-shield test examples/showcase/showcase.rules` decides them all,
  and `tests/ShowcaseTest.php` sends each one to the running page: the page
  cannot promise what the rules do not do.
- **Every try is a real request** to this server, decided by the real shield;
  the page reads the status and the `X-RS` header (`set debug-header on`). Each
  card is a visitor of its own (`X-Forwarded-For` from this machine, which the
  rules trust), so a try never locks you out. One card is decided on the
  server instead (`/__try`): a form sent from another website — no browser
  lets a page forge `Origin`.
- **The burst** sends 125 page views from one made-up visitor: 60 through, 60
  with the browser check, 5 paused.
- **Pages that count for themselves:** the search (`/search`, 5 a minute) and
  the sign-in (`/account/login`, 3 wrong passwords in 15 minutes; the right
  one is `sesam`) call `Shield::active()?->consume(…, answer: true)`; past
  their limit the shield answers instead, and a ban that doubles each time
  (`ban-growth 2`: 5 s, 10 s, 20 s …) keeps the visitor out before the page
  runs. *Bot mode* tries once a second for a minute and draws which tries
  reached the page. These budgets cannot be decided by `request-shield test`
  (the page counts them); `tests/ShowcaseTest.php` sends them for real.
- **Install** shows the steps with real paths: the folder, `.htaccess`,
  `.user.ini`, `require` in `index.php` or `wp-config.php`, and a first rule
  file in `monitor` mode with `set log` -- without a log file, monitor mode
  has nowhere to say what it would have done.
- **The browser check** for real: *open the login* in a new tab. It forgets
  your pass first (`/__login`), so the ring comes every time; the rules make
  the check a little harder than a real site would (`difficulty-min 500000`),
  so you can watch it, and count this machine too (`exempt none` -- by
  default the shield leaves its own machine alone).

Bootstrap 5.3.8 and Bootstrap Icons 1.13.1 are in `assets/vendor/` (MIT, their
licences beside them): the page loads nothing from another host. The store
goes to `/tmp/request-shield-showcase` (`REQUEST_SHIELD_SHOWCASE_VAR`).

**For your own machine only:** the rules trust `127.0.0.1` as a proxy so the
page can play visitors; on a public server anyone could then name their own
address. The technical demo with one example per feature is
[examples/demo](../demo/README.md).
