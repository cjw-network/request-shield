# Showcase

One page that shows what request-shield does, in plain words, in German and
English — and lets you try it. From the repository's root:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8090 examples/showcase/router.php
```

(Several workers, so the page's own requests -- the stream, a bot -- do not
wait for each other; `php -S` alone answers one request at a time.)

Open http://127.0.0.1:8090/ (`?lang=de` or `?lang=en`). Four pages, one menu:

| Page | What it holds |
|---|---|
| `/` | what the shield is: the live stream, the promises, **a check instead of a puzzle, a pause instead of a ban** (the browser check drawn and to open; the growing pause drawn as a staircase, with the sign-in to try), what it does, the rules, a taste of three requests to send, WCAG & GDPR, a teaser for Exponential, install |
| `/try` | every card to try, by group -- the burst, the search and sign-in that count for themselves, the API with its JSON form |
| `/exponential` | the Exponential example: its rules section by section, every example checked on the server |
| `/learn` | building rules: a learning run, live |
| `/cache` | the HTTP cache at work: a slow magazine, hits and misses timed, members, publishing, campaign links |

The parts below describe them; a card behaves the same wherever it stands.

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
- **Cache** (`/cache`, its own page) -- the actions and the log right below
  the picture, scrolling with the page (*Dock at the bottom* fixes them
  there, remembered in the browser); the log about six lines high, newest at
  the bottom, drawn bigger or smaller at its grip or with the arrow keys;
  *Play it by itself* tells the whole story article after article --
  publish, a visitor's miss and hit, a campaign link, members, an editor, a
  bot's scan, a search crawler, publishing again -- until stopped, at
  reading pace, with a countdown naming the next step. A "?" next to *Try
  it yourself* unfolds what can be tried there. The picture has a switch,
  *Plain* (default) or *Technical* (browser, request-shield, HTTP cache, PHP
  application; each request's method, address, status, `X-RS-Cache` and
  time). First a picture for people who never saw a cache: four stations (visitor, doorkeeper, shelf, newsroom) and a dot
  that travels them for every click -- green from the shelf, orange when the
  newsroom puts the page together and it is shelved, red when the doorkeeper refuses -- and
  the shelf, one slot per page and role (visitors, members, editors; as this
  page saw it). *A bot scans the front page* sends what scanners try
  (made-up parameters and articles, `/.env`, an SQL injection): refused, or
  answered and never shelved; *A search crawler reads* fetches the front
  page and every article anonymously, as a visitor, and fills the visitors'
  shelf. The picture names who sent a request (a face each for the
  visitor, member A and B, the editor, the bot 🤖 and the crawler 🔎) and
  shows its result above the station that decided it. Opened under a host
  name not in `http-cache-hosts`, the page says the cache is off there and
  how to start it (`REQUEST_SHIELD_SHOWCASE_HOST`). Then: the shield's HTTP cache
  ([RSF04-03](../../docs/features/RSF04-03-http-cache.md)) in front of
  `/magazin/…`, a small CMS that takes half a second a page. Each button is a
  real request, timed in the browser, with the cache's answer (`X-RS-Cache`):
  the second load a hit; *as member A / B* a cookie `rs-demo-member` for the
  one request (`http-cache-session-cookie`; the page calls `cacheContext()`)
  -- B gets A's members' page after B's first click; *as an editor* a role of
  its own (`editor-…`), whose page members never get; *with a campaign link*
  the same hit (`cache-ignore @tracking`, proposal 0048); *Publish article*
  calls `Shield::active()?->purge()`, *Empty the cache* `purge(['*'])`. The
  showcase's own pages are never kept (`http-cache-ttl 0`: only a page with a
  max-age of its own is). `set stats requests pages times` puts the times by
  hit and miss into the statistics (`/rs/stats`, this machine only). The cache
  keeps what visitors send as host: `127.0.0.1:8090` and `localhost:8090`, or
  `REQUEST_SHIELD_SHOWCASE_HOST=www.example.org` on a demo server. On a
  public demo: `cache-query lang page` keeps made-up parameters out of the
  cache (they are answered from the kept page, `hit-only`, and never take the
  magazine's half second); the two buttons have a budget of 20 a minute per
  visitor; and run it on a server with several workers (PHP-FPM, or
  `PHP_CLI_SERVER_WORKERS=4`) -- a miss holds one for half a second.
- **Build rules** (`/learn`, its own page): a learning run (proposal 0016)
  for your browser -- *Start recording* sets the cookie `rs-learn`, the
  showcase you click through in a second tab is recorded by shape (paths,
  parameter and field types, what each page offers), the table shows it
  live, and *Check the rules* sends it through `showcase.rules` as
  `request-shield replay` does: ✓, ✕ for a click that would be refused, ? for
  what a page only offers. Starting a run, and seeing what it recorded, works
  only from this machine (127.0.0.1, as the shield sees the visitor -- behind
  a proxy it trusts, the forwarded address). `REQUEST_SHIELD_SHOWCASE_LEARN=on`
  opens it to every visitor: for a private demo only, never on a public
  server (anyone could start, stop and read the one run). Below: the
  commands for a real server and CI, and what `advise` will suggest.
- **The live log** is docked bottom right on every part of the page: the
  end of the showcase's own log (`set log`, `/__log`, addresses masked),
  each line as time, decision, status, rule and request, new ones lit up;
  click its bar to fold it away (it remembers that). Above the lines,
  *banned right now*: the bans the store holds (APCu or files -- with
  `ban-keep file` they outlast a restart; the same list as the dashboard's),
  each with its masked address, the seconds left, the rule, and what kind
  of visitor it looks like -- a browser, a crawler, a script: a guess from
  the User-Agent of its ban's line in the log. The tab *all bans* lists
  them too, and beside them the addresses kept out by hand (`deny`, from
  the rule file or the list the command line and the dashboard write),
  for good or until when. The demo never shows an address whole, only its
  network (/24, /48), whatever `log-ip` says.
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
goes to `var/` next to `showcase.rules` (`REQUEST_SHIELD_SHOWCASE_VAR`).

**On a public host, shared hosting with open_basedir too:** `php
build/showcase.php --out=showcase-site --host=showcase.example.org
--admin=203.0.113.7` makes one directory that runs on its own -- the page,
the library and the Exponential example in `lib/`, `var/` for what the
shield keeps; nothing outside it is read or written.

- **The host:** Apache that reads `.htaccess` (AllowOverride) with
  mod_rewrite, a (sub)domain whose document root is the directory (not a
  folder below a site), and PHP allowed to write in it (`var/` and the
  compiled settings in `.request-shield/`). `.htaccess` sends every address
  to `index.php`; `lib/`, `var/` and `.request-shield/` are never served.
  **After the upload, check** that `https://showcase.example.org/var/secret`
  is not served (403, or the showcase's "not here") -- a server that skips
  `.htaccess` (nginx, AllowOverride None) would hand out the secret.
- **`--host`** is the address visitors use: the HTTP cache keeps pages only
  for it.
- **`--admin`** names who may see what the showcase keeps for "this
  machine" -- the shield's own pages (`/rs/**`), the log (`/__log`, it holds
  the addresses other visitors asked for) and the learning run; without it,
  nobody. The copy trusts no proxy: on a public host "this machine" may be
  the hoster's proxy in front of every visitor. Behind such a proxy that
  hides the visitors' addresses, every visitor counts as one: the budgets
  of the tries are then shared.

**For your own machine only** (the repository's copy, not a built one):
the rules trust `127.0.0.1` as a proxy so the
page can play visitors; on a public server anyone could then name their own
address. The technical demo with one example per feature is
[examples/demo](../demo/README.md).
