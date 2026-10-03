# 0031 — Steps and progress

The working file for [proposal 0031](0031-robust-core-plugins.md). It is the
resume point: anyone — a person or an agent, on any machine — reads it and
continues with the first open step. **The commit that finishes a step also
ticks it here and updates "Status".** A started step is marked `[~] <branch>`
and pushed at once, so a crash loses nothing.

Legend: `[ ]` open · `[~] feature/<branch>` started · `[x] <short hash>` done.

## Resume prompt (paste into a fresh agent session)

```text
You are working on request-shield (repository root = current directory). Read AGENTS.md first,
then docs/proposals/0031-robust-core-plugins.md (the plan) and docs/proposals/0031-steps.md (the
steps). Check the state: git status, git log -5, php tests/run.php (must be green; if not, read
"Status" below first — it says whether a step is half done and on which branch).
Take the first step that is [ ] or [~]. Complete it fully by AGENTS.md: tests (new ones fail
without the change), bench before and after, docs and CHANGELOG in the same change. Mark it [x]
with the commit hash in 0031-steps.md, update "Status", commit everything together
("Added:/Fixed:/Updated: …"). Then the next step. If something must deviate from the plan, write
the deviation under "Status" and into the proposal before going on. Ask when an owner decision
(list in the proposal) is needed.

Working mode: Do the work yourself in this session. Spawn subagents only for independent,
read-heavy searches across many files — at most 2, model haiku (Explore agent). No forks, no
workflows. After finishing a step, run exactly one review with pr-review-toolkit:code-reviewer,
model sonnet, on the uncommitted changes only (git diff); report only high-confidence findings;
fix them; then commit. If pr-review-toolkit is not installed here, use the code-review skill
(sonnet) on the diff with the same rule and note it under "Status".
```

## Setup on a new machine

```text
git clone https://github.com/cjw-network/request-shield.git && cd request-shield
composer install            # dev tools only (phpstan, psalm); the library has no dependencies
php tests/run.php           # must be green; php -d apc.enable_cli=1 tests/run.php for the APCu legs
# The review plugin (optional; the fallback is the code-review skill):
#   /plugin                                  → tab "Installed"; or: claude plugin list
#   /plugin marketplace add anthropics/claude-code
#   /plugin install pr-review-toolkit@claude-code-plugins     (user scope is enough; .claude/ is gitignored)
#   /agents                                  → "pr-review-toolkit:code-reviewer" must appear
#   If "not found": /plugin → Discover, search "pr-review"; until then use the fallback.
```

Model choice is free; the guard rails (tests red without the change, bench,
PHPStan max, the sonnet review, this file) make a switch between models
possible at any step. For the security-critical refactorings in phases A–E use
the model you trust most there, not fast mode.

## Steps

### Phase 0 — Documents
- [x] **0.1** `docs/proposals/0031-robust-core-plugins.md` (the plan) + `0031-steps.md` (this file, resume prompt, status) — files linked in `docs/README.md`, the `AGENTS.md` note is in. `2e4f4fa`
- [x] **0.2** `docs/proposals/0002-single-file-build.md` (build, editions, bootstrap search order, signing) — status Draft, linked. *(hash: next commit)*
- [ ] **0.3** Renumber the local drafts: `0002-check-levels.md` → `0032-check-levels.md` (with a section "Relation to 0004 modes"), `0003-event-log.md` → `0033-event-log.md` (relation to the `Sink` hook and `set log`); `docs/README.md` updated, 0007 noted as free; stash `local README proposal links` dropped.
- [ ] **0.4** `llms.txt` (start here / understand / never), `docs/llm/prompts/install.md`, `docs/llm/prompts/review.md` (the two prompts verbatim).
- [ ] **0.5** `.gitattributes` comment corrected; `tests/DocsTest.php`: no duplicate proposal numbers, every `NNNN-*.md` linked from `docs/README.md`, every path in `llms.txt` exists — green, and red without 0.3.
- [ ] **0.6** ADR placeholders (status proposed): 0007 fail-safe pass-through, 0008 compile-time extension points, 0009 plugins tighten only, 0010 single file and editions, 0011 one source for reference, demos and tests (RSF), 0012 repository structure and signing, 0013 hosting tiers, 0014 wire bytes and the `rs` prefix.

### Phase A — Robustness
- [ ] **A.1** `Shield::VERSION` + `request-shield version` (version, build, PHP, store, tier, rule-set versions) — `tests/CliTest.php`.
- [ ] **A.2** Fail-safe wrapper: try/catch(\Throwable) around the body of `protect()`/`protectFile()`, `requirePass()`, `consume()`, `widget()`, the `ob_start` callback → `allowUncached('shield error')`, `Shield::failed()` throttled — `tests/RobustnessTest.php` cases 1, 7, 9 — red without the wrapper.
- [ ] **A.3** Compile fallback: catch in `Settings::load()/loadFor()` → last good compiled settings + `.failed` marker; none → `mode off` + log; `TypeError` in `import()` → `unlink` + rebuild — RobustnessTest cases 5, 6, 8, 10.
- [ ] **A.4** Bootstrap search order (`REQUEST_SHIELD_CONFIG` → `request-shield.rules` next to the file → `config/request-shield.rules` → `config/request-shield.php`) in `bootstrap.php`; README install section corrected; E2E test without `REQUEST_SHIELD_CONFIG`.
- [ ] **A.5** E2E tests set `REQUEST_SHIELD_CONFIG` themselves (PluginTest/AccessTest fail today with a local `config/request-shield.php`).

### Phase A3 — Hosting tiers
- [ ] **A3.1** Cache path → `store-dir/cache/`; not writable → compile in memory (S0) instead of a fatal; `check` names the tier and what is inactive — `HostingTiersTest` S0.
- [ ] **A3.2** `Live` with a file-ring fallback (`store-dir/live`, `O_APPEND`, N entries); settings freshness, crawler DNS cache and `Stats` behind a capability check with a file fallback — `grep apcu_` only in `Store/` and one `Capability` class.
- [ ] **A3.3** `curl` fallback for feed/crawler updates, a clear message without network — unit test with `allow_url_fopen=0`.
- [ ] **A3.4** `HostingTiersTest` S1/S2 (same requests, same decisions) + CI leg "minimal hosting" in `tests.yml`; README numbers per tier.

### Phase A2 — Bytes
- [ ] **A2.1** Headers `X-Request-Shield*` → `X-RS*` (`X-RS`, `X-RS-Monitor`, `X-RS-Check`, `X-RS-Access`) in code, tests, demos, docs; internal headers removed before output (test).
- [ ] **A2.2** Cookie names `rsp/rss/rsd`; pass cookie v2 (`2.<expires base36>.<tag b64url 11>.<mac b64url 22>`), `v1` removed — `ChallengeTest`/`ChallengeJsTest` adjusted.
- [ ] **A2.3** `tests/WireBytesTest.php` (cookie ≤ 48 B, no `X-RS*` on a pass without debug, header-block limit, challenge page gzip limit); bytes in the bench; a bytes line in `browser-challenge.md`.

### Phase B — Decoupling
- [ ] **B.1** `Settings::$ext/$hooks/$routes` as the last constructor parameters, `FORMAT` bump, round-trip test.
- [ ] **B.2** `Extension` interface + `Rules\Vocabulary` (word/set/offer); `RuleFile` asks the registry before the "unknown" throw; test extension `rs-test` with `set fail-at <stage>`.
- [ ] **B.3** Stats vocabulary out of `RuleFile`/`Settings`; `StatsExtension` registers it; the plugin reads `ext.stats.*` — stats tests unchanged and green.
- [ ] **B.4** `Shield.php:506` removed; `StatsExtension::compile()` appends `StatsPlugin`; the `check` warning moves into `Extension::check()`.
- [ ] **B.5** Routes registry; `dashboardOnly()` without `Frame`; `Frame::links/isPage/pageFor/TABS` from `$s->routes`; stats declares its pages.
- [ ] **B.6** The shield serves routes under `dashboard-path` (`Handler`, behind `Access::gate()`, `no-store`, `noindex`, CSRF); the demo wiring goes; `check` warns on a route without `restrict`/`dashboard-access`.
- [ ] **B.7** `Access` generalised: `dashboard-access`/`dashboard-session`, cookie `rsd`, opaque principal; group mapping into the stats plugin; CLI `access-token`.
- [ ] **B.8** `RuleCounts` capability; `RulesPage`/`SetupPage` without `StatsReport`.
- [ ] **B.9** `Sink` hook in `Log::note()`; `Live` as the first sink.
- [ ] **B.10** `Pages` hook in `Responder::body()`/`Gate` (kinds `error|challenge|access-login`).

### Phase C — Rule chain
- [ ] **C.1** `Step` + `Shield::chain()`; `Inspector::trace()` iterates `chain()`.
- [ ] **C.2** `explain()` cases into the rule classes (`Step::explain`).
- [ ] **C.3** `SetupPage` from `chain()`; `RuleProvider` hook (stages, `stricter()` only, a throw → pass) — RobustnessTest case 1 through the public API.
- [ ] **C.4** `Handler` hook for passing requests (`protect()` after `settle()`), `Response` class, `Request::cacheKey()`.

### Phase D — CLI + namespace
- [ ] **D.1** `bin/request-shield` as a dispatch table + `Extension::commands()`; `stats` into the plugin.
- [ ] **D.2** `plugins/stats/src` → `CjwNetwork\RequestShield\Stats\`; `composer.json`/`bootstrap.php`/`phpstan.neon.dist` adjusted; `plugin … from <file>`.
- [ ] **D.3** `docs/features/plugins.md` rewritten around `Extension`; ADRs 0008/0009 accepted.

### Phase E — Single file
- [ ] **E.1** `Rules\Shipped` replaces every `__DIR__` read of `rules/` (6 places) — `grep "__DIR__ . '/.."` in `src/` empty.
- [ ] **E.2** `build/single-file.php` (deterministic, manifests mini/waf/stats/api, heredoc minify) + `tests/SingleFileTest.php`.
- [ ] **E.3** `REQUEST_SHIELD_ENTRY`/`rsEntry()` in the runner and the 9 E2E tests; `RuleFileTest:493` adjusted; CI leg `single`.
- [ ] **E.4** `tests.yml` with a `plan` job (PR: 3 legs + minimal hosting; main: 14; nightly: all).
- [ ] **E.5** `release.yml`: build×2 + cmp, suite against the file, `SHA256SUMS`, minisign + attestation, release assets; environment `release`, tag ruleset; public key in `SECURITY.md`/`Shipped::PUBKEY`.
- [ ] **E.6** `verify`, `self-update` (CLI only, signature mandatory where `sodium` exists), `init --app=…` + `rules/starter/`.
- [ ] **E.7** `docs/llm/install.md`, `write-rules.md`, `check.md`; the install prompt followed literally in a container (log in `docs/llm/install-dryrun.md`).

### Phase F — Feature contract
- [ ] **F.1** RSF ids in every `docs/features/*.md` H1 and the `docs/README.md` table; test names with ids (a pure renaming commit).
- [ ] **F.2** `tests/FeatureContractTest.php` (docs ↔ demo ↔ tests ↔ Vocabulary, `.demo-exempt`) — first with an exception list that F.6 empties.
- [ ] **F.3** Parser: `# demo:`/`# try:` markers, `ua "…"`, quoted headers; `Examples` returns status + headers.
- [ ] **F.4** `Report/DemoSite` + `ExamplesPage`, server-side `/__answer`; `examples/demo/index.php` → 3 lines + `pages.php`; `examples/exponential` on the same base; `DemoTest` iterates groups.
- [ ] **F.5** CLI `examples --markdown|--html|--coverage`, `vocabulary`; `docs/tools/sync-examples.php`, `gen-reference.php`, `docs/reference/*` generated; `--check` in CI.
- [ ] **F.6** Close the gaps: trust, post-origin, feeds (`feed … from <file>`), strict, sites, `@attacks` with `expect`, crawlers, lists/bans — one commit per feature; empty the exception list from F.2.
- [ ] **F.7** `docs/diagrams/*.dg` + `docs/tools/diagram.php`; Pages export of the recorded demo (`pages.yml`).
- [ ] **F.8** `docs/glossary.md`, `docs/for/{admins,hosters,editors,customers,developers}.md` (each a diagram + a scenario), `CONTRIBUTING.md` section "plain language", `tests/DocsStyleTest.php` (diagram required, glossary links) — first with an exception list that F.9 empties.
- [ ] **F.9** `Help` service (`Help::link(RSF, anchor)` from `Vocabulary`), `set docs-url`, help links + one-sentence explanations + explained empty states on every core and stats page, the challenge page and the widget; CLI errors end with a docs anchor; `tests/UiHelpTest.php` + `docs/tools/check-anchors.php`; screenshots for the docs from `examples --html`; `FeatureContractTest` extended by diagram + help link.

### Phase G — First consumers
- [ ] **G.0** `plugins/api`: `ApiProvider`/`Endpoint`/`ApiService`, the host (auth, envelope, problem details, cursor, ETag, rate limit, `api-write`, `api-origins`, audit via `Sink`), core endpoints `status rules trace test check reload live lists feeds crawlers log`, `openapi.json|yaml`, in-process facade `Api::call()`, `tests/ApiContractTest.php`, `docs/features/api.md` (RSF6.5) + demo group; `LivePage` polling and stats `?format=json` moved onto the API (old JSON paths removed).
- [ ] **G.1** 0030 error pages as a `Pages` consumer in the core.
- [ ] **G.2** HTTP-cache backend as a `Handler` plugin (`plugins/cache`), bench 0 µs without the plugin.
- [ ] **G.3** `plugins/waf` (Report + Access) as its own file `request-shield-waf.php`; the mini build without `Report/*`.

### Phase H — Split + v1.0
- [ ] **H.1** Own `composer.json`/LICENSE/README/CHANGELOG/SECURITY/.gitattributes per subpackage; root `require-dev` path repos; `testkit/`.
- [ ] **H.2** `split.yml`, mirror repositories, Packagist; branch cleanup, rulesets, PR template; org decision implemented.
- [ ] **H.3** v1.0.0: changelog, tag, release assets checked, the review prompt run in full and its result stored as `docs/reviews/v1.0.0.md`.

### Phase I — CMS adapters
- [ ] **I.1** `rules/app/{exponential,wordpress,symfony,ibexa,drupal}.rules` with `expect` + demo groups (`site wp.localhost {}` …); `@wordpress` → `@not-wordpress`.
- [ ] **I.2** `adapters/exponential` · **I.3** `adapters/wordpress` · **I.4** `adapters/symfony` (+ Ibexa) · **I.5** `adapters/drupal` — each: activation README, settings page, route export, cache kill switch, widget, dashboard menu, `Sink`, tests against a pseudo-CMS.

## Status

- **Last step done:** 0.2
- **Next step:** 0.3
- **Open owner questions:** see the proposal's last section.
- **Deviations from the plan:** none.
- **Review of this step:** `pr-review-toolkit` is not installed on the machine that wrote 0.1; the fallback (code-review skill, sonnet) was used.
