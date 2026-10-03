# ADR 0012 — One repository with split mirrors; releases signed from one workflow

- Status: proposed (2026-10-03; accepted with [0031](../proposals/0031-robust-core-plugins.md) phase H)

## Context

The core, the plugins (api, waf, stats, cache) and the CMS adapters must be
installable with Composer on their own, released at their own pace, and still
developed, tested and documented in one place. The plugin API is not stable yet.

## Decision

- **A monorepo.** The core stays the root package (`cjw-network/request-shield`);
  `plugins/<name>/` and `adapters/<name>/` carry their own `composer.json`,
  `LICENSE`, `README`, `CHANGELOG`, `SECURITY.md`; the root requires them as
  path repositories in `require-dev`.
- **Read-only split mirrors** (`split.yml`, `git subtree split`) publish each
  subdirectory as `github.com/<org>/request-shield-<name>`, registered on
  Packagist. Tags: `vX.Y.Z` for the core, `<name>/vX.Y.Z` for a subpackage.
- **One release workflow** on a `v*` tag: reproducible build, the suite against
  the built file, `SHA256SUMS`, a minisign signature and a build attestation,
  the assets on the GitHub release under stable names. Signing secrets live in a
  protected environment; only maintainers create release tags.
- SemVer applies from v1.0.0; nothing before it is compatible with anything.

## Consequences

- One PR carries a cross-cutting change with its tests, bench numbers and docs.
- The vendor/org name is a separate owner decision, cheapest before the first
  Packagist publish; the PHP namespace stays `CjwNetwork\RequestShield`.
