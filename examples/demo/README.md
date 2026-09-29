# Demo site

A mini site protected by request-shield: how it is included, and what each
part does. From the repository's root:

```bash
php -S 127.0.0.1:8080 examples/demo/router.php
```

Open http://127.0.0.1:8080/ — the page shows the shield's decision for the
request (and so does the `X-Request-Shield` header in the browser's network
tab), and links to one example per feature:

| Link | What happens |
|---|---|
| `/`, `/page/about` | passes; a cache may keep the page |
| `/?utm_source=newsletter`, `/random/…` | passes, marked uncacheable |
| `/challenge` | **the invisible browser check**, every time until you hold a pass cookie |
| `/.env` | 404 — a scanner's request never reaches the page |
| `/files/%2e%2e/secret` | 400 — path traversal |
| `/reset` | forgets your pass cookie, so you can see the check again |
| reload any page 20 times | the check appears (budget: 20 requests a minute), past 60 a pause (429) |
| the form | a POST passes, never cached |

### In a subdirectory of a web server

The demo also runs where it lies, e.g. with the repository under Apache's or
nginx's document root: open `…/examples/demo/`. It works out its own address
from `SCRIPT_NAME`, so every link and the form stay inside it:

- **with rewrite rules** (the `.htaccess` here, for Apache and LiteSpeed; for
  nginx `try_files $uri /…/examples/demo/index.php$is_args$args;`):
  `…/examples/demo/challenge`
- **without them:** `…/examples/demo/index.php/challenge`. The web server then
  answers paths such as `/.env` itself; the shield sees them only as
  `index.php/.env`.

The patterns in `request-shield.php` match the end of the path for this reason;
a real site anchors them at its own base.

The limits are low on purpose (`request-shield.php`); a real site uses the
defaults or more. The router sends every path to `index.php`, as a web
server's rewrite rules would — PHP's built-in server would otherwise answer
`/.env` itself.

Counters and the generated secret go to `request-shield-demo/` in the system's
temporary directory — never into the demo's directory, which a web server
might hand out (set `REQUEST_SHIELD_DEMO_VAR` to put them elsewhere).
