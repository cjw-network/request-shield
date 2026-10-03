# ADR 0013 — PHP is the only requirement; a writable directory and APCu are tiers

- Status: proposed (2026-10-03; accepted with [0031](../proposals/0031-robust-core-plugins.md) phase A3)

## Context

The shield must run fast with APCu and still work on shared hosting without it,
possibly without a writable `/tmp`, with `open_basedir`, `disable_functions`
and `allow_url_fopen=0`.

## Decision

- **Requirement:** PHP ≥ 8.0 and the extensions PHP always has. Nothing else.
- **Tiers** the shield detects and `check`/`version` name: **S0** stateless (no
  writable directory, no APCu: stateless rules only, settings compiled in
  memory, budgets/bans/challenge off and said so), **S1** file (one writable
  `store-dir`: everything, the live view as a file ring), **S2** APCu (counters
  in shared memory, freshness without `stat()`).
- The compiled-settings cache lives in `store-dir/cache/`, not
  `sys_get_temp_dir()`. Every direct `apcu_*` use sits behind a capability
  check with a file fallback or a clean switch-off. Network updates fall back
  to `curl` and otherwise explain themselves.
- No feature may fail without its tier; it switches off and `check` lists it.
  Every feature doc states the tier it needs.

## Consequences

- `tests/HostingTiersTest.php` runs the same requests under S0/S1/S2 with
  identical decisions; a CI leg "minimal hosting" runs on every PR.
- Costs are stated per tier (~42 µs S1, ~12 µs S2 today).
