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

## Extensions: words and settings of their own

A plugin runs per request and reads. An **extension** speaks at compile time:
it adds words and `set` keys to the rule file, checks them when the rules are
compiled, and what it checked lands in the compiled settings, in a slot of
its own (`$settings->ext[<id>]`); the core never reads that slot. A request
pays nothing for an extension (ADR 0008). The statistics are the first
shipped extension (`plugins/stats/src/StatsExtension.php`: `set stats …`,
`stats-hosts`, `stats-skip`, the `crawler-log` keys, compiled into
`ext.stats`); the bootstrap names it, so no `plugin` line is needed. A class
or a directory may be both: the statistics are one extension
(`StatsExtension`) and one plugin (`StatsPlugin`, which
`StatsExtension::plugins()` adds to the settings' plugins when something is
counted or logged).

```php
use CjwNetwork\RequestShield\{Extension, Settings};
use CjwNetwork\RequestShield\Rules\{RuleFileException, Vocabulary};

final class AlertsExtension implements Extension
{
    public static function id(): string { return 'alerts'; }          // ext.alerts

    public static function vocabulary(Vocabulary $v): void
    {
        // set alerts-after 50: typed like the core's keys (bool, int, seconds, string, words, path);
        // lands in ext.alerts.after (the extension's own prefix is dropped, the rest camelCased).
        $v->set('alerts-after', 'int', 'refusals per minute before a message');
        // alert-to ops@example.org: a word of its own; the parser hands it the line's
        // words, the extension's values so far, the line's place and the rule's id.
        $v->word('alert-to', static function (array $args, array $values, string $at, string $rid): array {
            if (count($args) !== 1 || strpos($args[0], '@') === false) {
                throw new RuleFileException("$at: alert-to <address>");
            }
            $values['to'][] = $args[0];
            return $values;
        }, 'alert-to <address>: who hears about it');
    }

    /** Checks the slot with the whole base settings in hand; returns what the plugin reads. */
    public static function compile(array $raw, Settings $base): array
    {
        if (($raw['to'] ?? []) === []) {
            throw Settings::wrong('ext.alerts.to', 'at least one alert-to address');
        }
        return ['after' => $raw['after'] ?? 50, 'to' => $raw['to']];
    }

    public static function plugins(array $compiled): array { return []; }   // Plugin classes to run per request, from the compiled slot (0031 B.4)
    public static function routes(array $compiled): array { return []; }   // its pages: path => key, tab, role, order (0031 B.5)
    public static function commands(): array { return []; }     // command-line commands (0031 D.1)
    public static function check(Settings $s): array { return []; }   // warnings for `check` (0031 B.4)
}
```

```text
plugin Acme\Shield\AlertsExtension      # from here on its words are known
set alerts-after 20
alert-to ops@example.org
```

- **Offered, then known.** `plugin <class>` offers an extension for the rest
  of the reading; the shipped ones are only named (the constant
  `REQUEST_SHIELD_EXTENSIONS`: `bootstrap.php` defines it right after the
  autoloader; with Composer, `plugins/stats/shipped.php` does, loaded through
  the package's autoload `files`) and the registry
  loads and offers them on its first lookup, when the rules are compiled --
  a class that is not there is skipped, and a passing request, which never
  consults the registry, loads none of it (ADR 0008). So `set stats on`
  needs no `plugin` line. An extension's word before its `plugin` line is an
  unknown rule.
- **Its own slot only.** Words and keys write into `ext.<id>`; a word or key
  the core has cannot be taken (the registry refuses it); two extensions
  cannot share an id, a word or a key. A key's `name` says where below the
  slot it lands (`set('stats-flush', 'seconds', …, name: 'flush')`; dotted
  for a nested place, `crawlerLog.dir`); `many: true` lets one key write
  several values (`set stats on|off|<parts>` writes `enabled` and `parts`).
- **What runs per request:** `plugins()` names the `Plugin` classes, given the
  compiled slot; they are appended to the settings' plugins when the rules
  are compiled, so the `Shield` itself knows no plugin by name. The
  statistics do this with `set stats on` (or a `crawler-log` directory).
- **Above the site blocks:** `set(…, serverWide: true)` marks a key the
  parser refuses inside a site block, with the core's message for its own
  server-wide keys (`stats-hosts`).
- **A word with paths:** `word(…, paths: true)` makes the parser compile the
  line's paths to patterns before the word sees them, and inside a `match`
  block the word takes no paths and gets the block's (`stats-skip`); a word
  without it keeps "does not go inside a match block".
- **Wrong at the line, or wrong at compile.** A bad value for a key is a
  `RuleFileException` naming the line (the key's type, or the extension's own
  check); a bad combination is an `InvalidArgumentException` from `compile()`
  naming the setting (`ext.alerts.to`) -- and on the request path the last
  good compiled settings stay in force, as for every mistake.
- **In a site block** an extension's `set` and words are the website's: its
  slot is compiled for that website, with the base's values and its own.
- The smallest extension is the tests': `tests/support/RsTestExtension.php`
  (`set fail-at <stage>`, `rs-test-mark`), used to make the shield fail on
  purpose where a test wants it.

## Capabilities: what a plugin can do for the pages

A plugin may implement more than `Plugin`. Each extra interface is a
**capability**: the compiler records which plugin class has which one into
the compiled settings (`$settings->hooks`, hook name => classes, by
`instanceof` when the rules are compiled), so a request -- or a page -- asks
one array and never a plugin. A new capability is a new interface (MINOR); a
plugin without it costs nothing more. The first (0031 B.8):

| Capability | What it answers | Who asks |
|---|---|---|
| `RuleCounts` | `ruleCounts($days, $now)`: rule id => how often it decided; `crawlerCounts($days, $now)`: what each known crawler did | the rules and setup page (`Report\Counts`), when it is drawn -- never a request |
| `Sink` | `note($request, $decision, $rule, $now, $monitor)`: what the log hears -- every request the shield did something about (at log-level all, every one) | `Log::note()`, where the log is written -- never a passing request. The live view is the first sink; a CMS logger or a Monolog handler are others. A sink masks addresses as the log does (`Log::mask()`) |

The statistics plugin has `RuleCounts` (`set stats on`): the rules page shows
"decided n times" from its counters. A plugin that throws in a capability is
left out -- the page is drawn without its numbers, the record goes to the
other sinks -- and PHP's error log hears it once a minute. The tests'
`tests/support/CountingPlugin.php` and `SinkPlugin.php` are the smallest.

## Cost

None without plugins. With plugins, one call to each per request; the
statistics measured as before the split (decide plus statistics, APCu: about
39 µs per request against 15 µs without — the same as when they were part of
the core).

## The statistics plugin: a plugin with pages of its own

`plugins/stats/` — `StatsPlugin` (counting, the crawler logs), `Stats` (the
counters), `Report\StatsReport`, `Report\StatsPage`, `Report\VisitorsPage`. For
now in this repository and loaded by the same autoloader; a package of its own
(`cjw-network/request-shield-stats`) when the interface has settled.

It is the model for a plugin that does more than count. What it owns, and how
it fits next to the core:

| | The statistics plugin | The core |
|---|---|---|
| **Its words** in the rule file | `set stats …`, `stats-hosts`, `stats-group` (the group a `dashboard-access` principal maps to), `stats-skip`, `set stats-path` ([statistics](statistics.md)); the login itself is the core's (`dashboard-access`, `set dashboard-session`) | everything else |
| **Its address** | its own setting `ext.stats.path` (`set stats-path`, default `<dashboard-path>/stats`); `StatsExtension::routes()` declares `/sites`, `/overview`, `/visitors`, `/protection` below it into `$s->routes` | `Routes::core()`: `<dashboard-path>/waf/`: `live`, `lists`, `rules` |
| **Its pages** | `StatsPage::viewFor($settings, $path)` says which page a path is (null: not one of its own, from `$s->routes`), `StatsPage::render()` draws it, `StatsPage::links()` the tabs | `Frame::pageFor()`, `Frame::links()` (both from `$s->routes`) |
| **Who may read them** | `Access::gate()`: the admin everything, a customer its group (`who`) | the site's own rules (`restrict <dashboard-path>/** to …`) |
| **Its data** | files per website and hour under `store-dir/stats/`, APCu as a buffer | the store (counters, bans) and the log |

What a plugin with pages should do the same way:

- **One address of its own, configurable.** A site with an `/rs/` of its own,
  or two dashboards on one host, moves it with one line; the tabs and links
  follow (`links()` builds them from the setting, never from a fixed path).
- **Declare its pages.** `Extension::routes(array $compiled)` returns them
  (full path => key, tab labels, role `admin|reader`, order) and they are
  compiled into `$s->routes` next to the core's; `Frame`, the tabs and
  `Shield::dashboardOnly()` derive from that table -- a request to one of
  them is not a visitor, and the pace leaves the admin's page views alone
  when a `restrict` rule lets the address in. The shield serving them comes
  with 0031 step B.6; until then the site routes them.
- **Say who asked.** A page that shows other people's data takes the `who`
  the gate returned and filters by it itself (`StatsPage` refuses another
  group's website whatever address is asked for). The links a customer gets
  come through `Access::links($settings, $who, …)`: no tab it may not open
  (the routes whose role is `reader`).
- **The shield serves the pages** (0031 B.6). A route's `page` names a class
  implementing `RoutePage`: `serve(Settings, Request, $route, $ctx)` returns a
  `Response` (status, header lines, body). `Dashboard::serve()` answers a
  request for a route before the application -- behind `Access::gate()` and
  the route's role (`admin` pages refuse a customer with 403), every answer
  `no-store` and `noindex`, a POST change with the page's token (CSRF). A
  route nobody guards (no `restrict` rule covers it, no login) answers 403 and
  `check` warns. The site wires nothing; the [demo](../../examples/demo/index.php)
  wires nothing either. In a build without the pages a route answers 404.

In the demo, `/customer-menu` is a pretend hosting panel: its "Statistics"
item is a signed link (`Access::link()`) that opens Customer A's statistics,
and only those. This machine itself is the administrator (the `admin` option
of `Access::gate()`); `?rs-login=1` shows the form all the same.
