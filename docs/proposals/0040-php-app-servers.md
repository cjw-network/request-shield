# 0040 — In a PHP application server: Qbix and Exponential Velocity

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-04 |
| Affects | how the shield is started (a server hook instead of `auto_prepend_file`), what it keeps between requests (the compiled settings, its static state), the cache plugin's end of a request, a test setup |
| Relates to | [RSF05-07 the single file](../features/RSF05-07-single-file.md) · [RSF04-03 the page cache](../features/RSF04-03-http-cache.md) · [0039 a cache that speaks the known dialects](0039-cache-compatible.md) · [0031 phase I, the adapters](0031-robust-core-plugins.md) |

## The idea

The shield is built for one PHP process per request (PHP-FPM, mod_php, the
built-in server): `auto_prepend_file` runs it, it reads its compiled
settings, decides, and ends with the request. A new kind of server runs
PHP differently: **Qbix**, a web server written in PHP without nginx or
PHP-FPM, and **Exponential Velocity**, a distribution of it for production
by the makers of Exponential. It loads the application once, then serves
requests from long-lived workers (copy-on-write forks) -- static files,
PHP, WebSockets and a dashboard from one process.

Can the shield run in it -- and better than in front of PHP-FPM?

## What the servers do (from their documentation, not yet tried)

- **Workers:** by default persistent -- a worker serves many requests in a
  row; between two, a snapshot made by reflection restores every static
  property (about 0.5 ms for a small application, 4-5 ms for a CMS), and
  the superglobals are set again. Optionally **one fork per request**, for
  code that does not bear persistent workers.
- **PHP runs unchanged:** 44 functions are replaced in the source as it is
  loaded (`header()`, `exit()`, `session_start()`, `ini_set()` …), so they
  reset per request.
- **Events:** `Q::event()` -- a hook where a request can be handled before
  the application.
- **Static files** are served by the same process, with their cache time.
- **APCu:** shared by the workers; Velocity has a reverse cache of its own
  in APCu.
- **Not documented:** whether `auto_prepend_file` is honoured.

## What it could bring

1. **Settings once, not per request.** Loaded with the application before
   the workers fork, the compiled settings are in every worker's memory:
   the ~10 µs a request spends today on setting up (the compiled file
   through OPcache, the checks of their age) fall to almost nothing. The
   decision itself stays a few microseconds.
2. **Before any application code:** a Qbix adapter hooks into the server's
   dispatch (`Q::event()`): a refused request never reaches the worker's
   application, not even its front controller.
3. **Static files too:** behind nginx the shield sees only what reaches
   PHP; here every request passes through PHP, so a scanner's
   `/.env` or `/wp-config.php.bak` is refused even where no PHP file is
   asked for -- and the blocked paths cost the same as before.
4. **One store for all workers:** APCu is shared: budgets, bans and the live
   view are the same for every worker, as with PHP-FPM and APCu.

## What must be checked first

- **How it starts:** the first line of the front controller works whatever
  the server does with `auto_prepend_file`; the adapter's hook is the goal.
- **State between requests:** the shield keeps static state
  (`Shield::active()`, the registry of extensions, per-process caches of
  the compiled settings and of the crawler lists). The server restores
  static properties after each request -- that must not throw away what
  should stay (the settings loaded before the fork) nor keep what must go
  (the active shield, the request's decision). The settings belong in the
  preloaded part, the rest per request.
- **The end of a request:** the page cache keeps an answer from an output
  buffer and a shutdown function (`register_shutdown_function`). In a
  persistent worker the request does not end the process: whether shutdown
  functions and the final flush run per request is open. Until it is known,
  the page cache is off there -- Velocity has a cache of its own anyway
  (as with a Varnish in front, [0039](0039-cache-compatible.md)).
- **`exit()` and headers:** the shield answers with `header()`,
  `http_response_code()` and `exit` -- replaced by the server, so they
  should work; a test proves it.
- **Who the visitor is:** `REMOTE_ADDR` and the headers as the server sets
  them; behind a proxy the shield's `trust` as everywhere.
- **The browser check, the pass and the widget:** they need nothing but
  answers and cookies -- to be tested end to end.

## Proposed

1. **A test setup** (`examples/qbix/`, not shipped): Qbix in **fork per
   request** first -- the closest to PHP-FPM --, the demo site behind it,
   the shield as the front controller's first line. The demo's end-to-end
   tests (`DemoTest`) against it: every row's status and `X-RS` as with the
   built-in server. Then the same in persistent workers, and what breaks.
2. **What persistent workers need**, as found by 1: settings loaded before
   the fork (`Shield::preload($ruleFile)`), the per-request state reset by
   the shield itself at the start of `protect()` -- so it does not depend
   on the server's snapshot --, the page cache refusing to start where the
   end of a request is not seen (`check` says so).
3. **A Qbix adapter** (`adapters/qbix`, 0031 phase I) once 1 and 2 hold: the
   hook in `Q::event()` before the application, static files included, a
   bench against PHP-FPM with the same rules.

Nothing of it changes a request under PHP-FPM: `preload()` is a function
nobody calls there, and the reset at the start of `protect()` is what a new
process gives anyway.

## Cost

- Under PHP-FPM: nothing.
- In Qbix: less than today per request (the settings are already loaded);
  a refused static file costs a decision instead of a file read.

## Open questions for the owner

1. **Which first:** Qbix itself, or Exponential Velocity (the same with a
   distribution around it)? Proposed: Velocity, as the one sites would run.
2. **When:** after 0039 (G.4-G.6), or before? Proposed: after -- it needs
   a working setup to test against, and the cache questions are answered
   by 0039 first.
3. **Static files through the shield:** wanted (every request decided), or
   left to the server (as behind nginx)? Proposed: through the shield --
   it is what the place in front of everything is for -- with the server's
   own cache time kept.
