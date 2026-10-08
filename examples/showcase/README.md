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
- **An API** in a block of its own (`match /api/** { … }`, `api-path /api/**`):
  products as JSON or XML (`GET /api/v1/products?format=xml`), typed
  parameters, 30 calls a minute, and `challenge POST` for every write. A JSON
  form sends `fetch()` to `POST /api/v1/messages` and shows each step: 429
  with the task in `Request-Shield-Challenge`, solved by the shield's own
  solver (`/rs-check/widget.js`, `RS.solve`), sent again with
  `Request-Shield-Solution`, 201 and a pass; the next message goes straight
  through. *Send as a bot* shows what a script without a browser gets.
  "Why these rules?" explains each one -- and that `post-origin` does not
  apply to an API (programs send no Origin; CORS protects against other
  websites), and that the API checks its JSON itself.
- **WCAG & GDPR**: ALTCHA's own pages on accessibility
  (altcha.org/legal/compliance/wcag/) and the GDPR
  (altcha.org/legal/compliance/gdpr/), each beside what holds for the
  shield's check and where it differs -- the shield sets a pass cookie
  (strictly necessary, to be confirmed), has no external WCAG audit, and
  is not affiliated with ALTCHA.
- **Exponential**: the rules of `examples/exponential` (the CMS with
  siteaccesses, URL aliases and its admin), section by section: each
  section's rule lines (without their comments) and its examples, read as
  `request-shield test` reads them. *Check* decides one on the server
  (`/__exp`) with those rules -- the admin as `/admin`, switched on, a fresh
  store each time -- exactly as `request-shield test` does; *Check all*
  decides them in one request (one by one they would run into `SHOW-PACE`).
  A second website cannot be opened from this page, so nothing here is a
  real request to it.
- **Build rules** (`/learn`, its own page): a learning run (proposal 0016)
  for your browser -- *Start recording* sets the cookie `rs-learn`, the
  showcase you click through in a second tab is recorded by shape (paths,
  parameter and field types, what each page offers), the table shows it
  live, and *Check the rules* sends it through `showcase.rules` as
  `request-shield replay` does: ✓, ✕ for a click that would be refused, ? for
  what a page only offers. Starting a run works only from this machine
  (127.0.0.1), or with `REQUEST_SHIELD_SHOWCASE_LEARN=on`. Below: the
  commands for a real server and CI, and what `advise` will suggest.
- **The live log** is docked bottom right on every part of the page: the
  end of the showcase's own log (`set log`, `/__log`, addresses masked),
  each line as time, decision, status, rule and request, new ones lit up;
  click its bar to fold it away (it remembers that).
- **Install** shows the steps with real paths: the folder, `.htaccess`,
  `.user.ini`, `require` in `index.php` or `wp-config.php`, and a first rule
  file in `monitor` mode with `set log` -- without a log file, monitor mode
  has nowhere to say what it would have done.
- **The browser check** for real: *open the login* in a new tab. It forgets
  your pass first (`/__login`), so the ring comes every time; the rules make
  the check a little harder than a real site would (`difficulty-min 500000`),
  so you can watch it, and count this machine too (`exempt none` -- by
  default the shield leaves its own machine alone).

The page and its content were generated with the help of AI; the footer
says so in both languages.

Bootstrap 5.3.8 and Bootstrap Icons 1.13.1 are in `assets/vendor/` (MIT, their
licences beside them): the page loads nothing from another host. The store
goes to `/tmp/request-shield-showcase` (`REQUEST_SHIELD_SHOWCASE_VAR`).

**For your own machine only:** the rules trust `127.0.0.1` as a proxy so the
page can play visitors; on a public server anyone could then name their own
address. The technical demo with one example per feature is
[examples/demo](../demo/README.md).
