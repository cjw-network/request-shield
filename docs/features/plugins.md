# Plugins

The core of request-shield is a mini web application firewall: it checks every
request, decides, answers what it refuses itself, and logs. Everything beyond
that — the statistics, metrics, alerts, exports — is a **plugin**: told what the
core decided, and how a request ended. The statistics are the first one, and
come with the shield (`plugins/stats/`). Proposal
[0023](../proposals/0023-plugins-hosts-customers.md), decision
[ADR 0006](../adr/0006-core-and-plugins.md).

## What a plugin gets

```php
interface Plugin
{
    // After the decision, before the answer: every request the shield saw.
    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void;

    // When a request that went on to the site has ended ($continues was true).
    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void;
}
```

- **`$decision`** — `allow`, `allow-uncached`, `challenge`, `throttle` or
  `reject`, with its status and reason; **`$rule`** the rule that decided
  (`SCAN-HIDDEN`, `site.rules:12`), null for a request let through.
- **`$continues`** — true: the site answers it, `ended()` follows with the
  site's status and headers. False: the shield answered itself (or code that
  calls `record()` wants it handled now).
- **`$would`** — in monitor mode, what would have been decided.
- **`Seen`** — what the core knows beyond the request, worked out on first use
  and kept, so a plugin that does not ask pays nothing:
  `host()` (the website: lower case, no port), `crawler()` (the known crawler
  the User-Agent names), `verified()` (its address proves it), `crawlerKind()`,
  `botFamily()` (curl, python, headless …), `who()` (people, crawlers, bots).

## Naming it

In a rule file:

```
[SITE-ALERTS] plugin Acme\Shield\RefusalAlert     # a message when refusals pile up
```

or in PHP settings: `'plugins' => [Acme\Shield\RefusalAlert::class]`. The class
must be loadable (Composer, or your own autoloader before the shield runs). The
statistics need no line: `set stats on` (or `set crawler-log …`) brings them.

`bin/request-shield check` warns about a plugin it cannot find, or that is no
`Plugin`.

## Rules for plugins

- **Read only.** A plugin cannot change a decision or the answer.
- **Made once per request**, as `new Acme\Shield\RefusalAlert($settings)`, and
  only when a plugin is named: without plugins the core does nothing more.
- **Quick.** `decided()` runs before the visitor gets the answer, `ended()` at
  the end of the request (PHP's shutdown) — the visitor has the page by then
  only if the site called `fastcgi_finish_request()`. Keep both to counters and
  appends; anything slow (a network call) belongs in a cron job that reads what
  the plugin wrote. The example below is tested with the shield's own tests.
- **Failing is safe.** Every call is wrapped: an exception is noted in PHP's
  error log (once a minute per plugin) and the request goes on as if the plugin
  were not there. A class that is missing or no `Plugin` is left out the same way.

## An example: a message when refusals pile up

```php
<?php
namespace Acme\Shield;

use CjwNetwork\RequestShield\{Decision, Plugin, Request, Seen, Settings};

/** Writes one line to PHP's error log when more than 200 requests a minute are refused. */
final class RefusalAlert implements Plugin
{
    private const LIMIT = 200;

    public function __construct(private Settings $settings)
    {
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
        if ($decision->action !== Decision::REJECT || !function_exists('apcu_inc')) {
            return;
        }
        $minute = intdiv((int) $now, 60);
        $count = apcu_inc("acme:refused:$minute", 1, $ok, 120);
        if ($count === self::LIMIT + 1) {               // once, when the line is crossed
            error_log(sprintf('acme: more than %d refusals in the minute %s on %s (last rule: %s)',
                self::LIMIT, date('H:i', (int) $now), $seen->host(), $rule ?? '-'));
        }
    }

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
    }
}
```

## Cost

None without plugins. With plugins, one call to each per request; the
statistics measured as before the split (decide plus statistics, APCu: about
39 µs per request against 15 µs without — the same as when they were part of
the core).

## The statistics plugin

`plugins/stats/` — `StatsPlugin` (counting, the crawler logs), `Stats` (the
counters), `Report\StatsReport`, `Report\StatsPage`, `Report\VisitorsPage`. For
now in this repository and loaded by the same autoloader; a package of its own
(`cjw-network/request-shield-stats`) when the interface has settled. Its
settings are the `stats` words of the rule file ([statistics](statistics.md)).
