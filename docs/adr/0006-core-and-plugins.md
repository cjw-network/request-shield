# ADR 0006 — The core is the firewall; everything else is a plugin

- Status: accepted (2026-10-01)

## Context

The shield grew statistics, crawler logs and their pages inside its core:
`Shield::record()` counted for the dashboard, and the core loaded the counters'
classes. A site that only wants the firewall carried them along; a site that
wants something else (metrics, alerts, exports) had no place to hang it.
Proposal [0023](../proposals/0023-plugins-hosts-customers.md).

## Decision

- The core checks, decides, answers and logs. After the decision it tells its
  **plugins** — classes implementing `CjwNetwork\RequestShield\Plugin` — what
  happened (`decided()`), and for a request the site answers, how it ended
  (`ended()`, with the site's status and headers). `Seen` carries what the core
  worked out (website, crawler, bot family), lazily.
- Plugins are **read only** (they cannot change a decision) and **cannot break
  a request** (errors are caught and logged once a minute per plugin).
- They are named in a rule file (`plugin <class>`) or the PHP settings
  (`'plugins' => […]`); the **statistics** are the first plugin
  (`plugins/stats/`, `StatsPlugin`) and come with `set stats on` on their own.
- For now in this repository, loaded by the same autoloader, the classes keeping
  their names; a package of their own once the interface has settled.

## Consequences

- No plugin, no cost: the core checks one empty list. With the statistics: as
  before the split (measured: decide plus counting with APCu ~39 µs, ~15 µs
  without statistics, before and after).
- `Shield::record()` stays public, for code that runs `decide()` and `settle()`
  itself: it now tells the plugins.
- The statistics' helpers moved with them: `StatsPlugin::folders()`, `isHtml()`,
  `isSitemap()`, `statusKeys()`; a bot's family is the core's (`Seen::family()`,
  `Stats::botFamily()` asks it).
- The statistics' settings (`set stats …`) are still read by the core's
  `Settings` — moving them to the plugin is part of the package split.
