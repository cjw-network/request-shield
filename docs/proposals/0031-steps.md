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
read-heavy searches across many files — at most 2, model haiku (Explore agent). No forks, no workflows
(the owner allowed small workflows for B.3–B.5 only). After finishing a step, run exactly one review with pr-review-toolkit:code-reviewer,
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
- [x] **0.2** `docs/proposals/0002-single-file-build.md` (build, editions, bootstrap search order, signing) — status Draft, linked. `bd1c529`
- [x] **0.3** Renumber the local drafts: `0002-check-levels.md` → `0032-check-levels.md` (with a section "Relation to 0004 modes"), `0003-event-log.md` → `0033-event-log.md` (relation to the `Sink` hook and `set log`); `docs/README.md` updated, 0007 noted as free; stash `local README proposal links` dropped. `910af9a`
- [x] **0.4** `llms.txt` (start here / understand / never), `docs/llm/prompts/install.md`, `docs/llm/prompts/review.md` (the two prompts verbatim). `a76231d`
- [x] **0.5** `.gitattributes` comment corrected; `tests/DocsTest.php`: no duplicate proposal numbers, every `NNNN-*.md` linked from `docs/README.md`, every path in `llms.txt` exists — green, and red without 0.3. `1f042ba`
- [x] **0.6** ADR placeholders (status proposed): 0007 fail-safe pass-through, 0008 compile-time extension points, 0009 plugins tighten only, 0010 single file and editions, 0011 one source for reference, demos and tests (RSF), 0012 repository structure and signing, 0013 hosting tiers, 0014 wire bytes and the `rs` prefix. `a321324` (the steps-file line itself lands with A.1)

### Phase A — Robustness
- [x] **A.1** `Shield::VERSION` + `request-shield version` (version, build, PHP, store, rule-set versions; the tier comes with A3.1) — `tests/CliTest.php`. `a73a8e0`
- [x] **A.2** Fail-safe wrapper: try/catch(\Throwable) around the body of `protect()`/`protectFile()`, `requirePass()`, `consume()`, `widget()`, the `ob_start` callback → `allowUncached('shield error')`, `Shield::failed()` throttled — `tests/RobustnessTest.php` cases 1, 7, 9 — red without the wrapper. `da2aae9`
- [x] **A.3** Compile fallback: catch in `Settings::load()/loadFor()` → last good compiled settings + `.failed` marker; none → `mode off` + log; `TypeError` in `import()` → `unlink` + rebuild — RobustnessTest cases 5, 6, 8, 10. Fail safe only on the request path (`loadFor()`, `load(…, failSafe: true)`); `load()` for tools keeps throwing. `7e5ce2d`
- [x] **A.4** Bootstrap search order (`REQUEST_SHIELD_CONFIG` → `request-shield.rules` next to the file → `config/request-shield.rules` → `config/request-shield.php`) in `bootstrap.php`; README install section corrected; E2E test without `REQUEST_SHIELD_CONFIG` (`tests/BootstrapTest.php`, on a copy of the library). `1191200`
- [x] **A.5** E2E tests set `REQUEST_SHIELD_CONFIG` themselves (PluginTest/AccessTest fail today with a local `config/request-shield.php`). `0094dff`

### Phase A3 — Hosting tiers
- [x] **A3.1** Cache path → `.request-shield/` **next to the settings file** (not `store-dir/cache`: the store dir is only known after compiling), the store's default → `.request-shield/store` there (never the shared temp dir — the secret lives in the store); not writable → compiled on every request (S0), no fatal; `Tier::of()`, `check` and `version` name the tier and what is inactive — `HostingTiersTest` S0 + S1 + defaults. `aa778b4`
- [x] **A3.2** `Live` with a file fallback (`store-dir/live.log`, the log's line format, `O_APPEND`, rotated past 500 KB, read by `LogTail`); every APCu check through `Capability::apcu()`. Settings freshness, the crawler DNS cache and `Stats` already had file fallbacks (stat per request, `store-dir/se/`, hour files) — kept, now behind the one check. `75cfcf7`
- [x] **A3.3** `Http::get()` (file_get_contents, else curl) for feed/crawler updates; `Http::offline()` tells the CLI what to do where neither can — `tests/HttpTest.php` runs PHP with `allow_url_fopen=0` and with curl disabled against a server of its own. `4cab57d`
- [x] **A3.4** `HostingTiersTest` S2 and "same requests, same decisions at S0/S1/S2"; CI leg "minimal hosting" (`TESTS_HOSTING=minimal` → `serverPhp()` restricts every server a test starts); README cost table names the tiers; ADR 0013 accepted. `1d892d2`

### Phase A2 — Bytes
- [x] **A2.1** Headers `X-Request-Shield*` → `X-RS*` (`X-RS`, `X-RS-Monitor`, `X-RS-Check`, `X-RS-Access`) in code, tests, demos, docs; internal headers removed before output (test). `f7873a2`
- [x] **A2.2** Cookie names `rsp/rss/rsd`; pass cookie v2 (`2.<expires base36>.<tag b64url 11>.<mac b64url 22>`), `v1` removed — `ChallengeTest`/`ChallengeJsTest` adjusted. `30a6734`
- [x] **A2.3** `tests/WireBytesTest.php` (cookie ≤ 48 B, no `X-RS*` on a pass without debug, header-block limit, challenge page gzip limit); bytes in the bench; a bytes line in `browser-challenge.md`. `3ea9173`

### Phase B — Decoupling
- [x] **B.1** `Settings::$ext/$hooks/$routes` as the last constructor parameters, `FORMAT` bump, round-trip test. `9c7bc27`
- [x] **B.2** `Extension` interface + `Rules\Vocabulary` (word/set/offer); `RuleFile` asks the registry before the "unknown" throw; test extension `rs-test` with `set fail-at <stage>`. `1c5c4a6`
- [x] **B.3** Stats vocabulary out of `RuleFile`/`Settings`; `StatsExtension` registers it; the plugin reads `ext.stats.*` — stats tests unchanged and green. `6d8ffaf`
- [x] **B.4** `Shield.php:506` removed; `StatsExtension::compile()` appends `StatsPlugin`; the `check` warning moves into `Extension::check()`. `6d8ffaf`
- [x] **B.5** Routes registry; `dashboardOnly()` without `Frame`; `Frame::links/isPage/pageFor/TABS` from `$s->routes`; stats declares its pages. `f244b04`
- [x] **B.6** The shield serves routes under `dashboard-path` (`Handler`, behind `Access::gate()`, `no-store`, `noindex`, CSRF); the demo wiring goes; `check` warns on a route without `restrict`/`dashboard-access`. `1e57764`
- [x] **B.7** `Access` generalised: `dashboard-access`/`dashboard-session`, cookie `rsd`, opaque principal; group mapping into the stats plugin; CLI `access-token`. `fb447fa`
- [x] **B.8** `RuleCounts` capability; `RulesPage`/`SetupPage` without `StatsReport`. `e5b63a9`
- [x] **B.9** `Sink` hook in `Log::note()`; `Live` as the first sink. `8b7825d`
- [x] **B.10** `Pages` hook in `Responder::body()`/`Gate` (kinds `error|challenge|access-login`). `b637558`

### Phase C — Rule chain
- [x] **C.1** `Step` + `Shield::chain()`; `Inspector::trace()` iterates `chain()`. `ef5af20`
- [x] **C.2** `explain()` cases into the rule classes (`Step::explain`). `6a0f9f8`
- [x] **C.3** `SetupPage` from `chain()`; `RuleProvider` hook (stages, `stricter()` only, a throw → pass) — RobustnessTest case 1 through the public API. `(this commit; hash follows)`
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

- **Last step done:** C.3
- **Next step:** C.4
- **Open owner questions:** see the proposal's last section.
- **Deviations from the plan:** none open. (B.3 kept `stats-group`, `stats-access`, `stats-session`, `stats-path` in the core for a while; B.5 moved `stats-path`, B.7 the rest -- as the proposal's B.3 row says.)
- **Finding from C.3:** provided steps are inserted in `Shield::provided()` only when `hooks['ruleProvider']` is set (a shield without one never derives the chain early); the chain is then the source of the hot path's rule list. `Rule\Guarded` wraps a provided rule (a throw in `check()` or `explain()` says nothing, `Shield::failed()` hears it). The RobustnessTest case for a throwing provided rule goes through `Shield::protect()`: the request passes cacheable -- a plugin's broken rule is not a shield error. `SetupPage`'s way comes from `chain()` now and gained the post-origin step (StatsTest counted 16, now 17). Checks ran one after another (memory pressure on this machine). Reviewed with the code-review skill, no workflow.
- **Finding from C.2:** `explain()` is on the `Rule` interface (not a closure on `Step`, as the plan sketched): the rule that produced a decision knows its reasons, so a rule provider's rule (C.3) names itself the same way. `Settings::ruleName()` is the one sanitised origin lookup. Kept in `Shield::explain()`: `always`, `app`, a `check` feed (decide()'s own steps) and the on-demand budgets (no step of the chain -- the first version dropped them and ModesTest caught it). Reviewed with the code-review skill, no workflow.
- **Finding from C.1:** a first version built `Step` objects in the constructor and cost the compiled-settings setup 4 µs per request (8.5 → 12.7); the committed version keeps the constructor's inline rule list (zero extra cost) and derives the steps when asked (`Step::fromRules()` over `Step::table()`, the one table of key, stage, rule class and words; inactive stages get a null rule so the inspector can name them). `ChainTest` guards that the table's order is the constructor's, rule object for rule object. The inspector keeps its words per step key (a `switch`), the budgets and the cache keep their own tries (nothing counted, no `$known`), unknown keys (a rule provider's, C.3) get a plain outcome. The always-check (`challenge <paths>`) is not a step: `decide()` handles it after the loop, the inspector appends it. Reviewed with the code-review skill, no workflow.
- **Finding from B.10:** the hook is a callable where the class has no settings (`PageHook::asker()` into `Gate` and `Responder`), `PageHook::ask()` where it has (`Access`); the first plugin that answers wins, null means the shield's page. The check page's context is exactly `ChallengePage::render()`'s inputs, so a plugin can wrap the shield's page. Three hook kinds are recorded now (`ruleCounts`, `sink`, `pages`); `RuleProvider` and `Handler` come with phase C. Reviewed with the code-review skill, no workflow.
- **Finding from B.9:** `Shield::failed()` is public now (the log reports a failing sink through the same once-a-minute throttle); the throttle keys on the message, so a test that asserts the error-log line must make its message unique per run (SinkPlugin puts the store directory into it) -- the same trap PluginTest has when the suite runs twice within a minute. `Live` is a `Sink` instance over its static `push()`; `Log::sinks()` makes the plugin sinks with `new $class($settings)` per noted request (refusals and flags only). Reviewed with the code-review skill, no workflow.
- **Finding from B.8:** `$s->hooks` is filled for the first time: `Settings::compiledExt()` walks the plugin classes and records each interface of `Settings::HOOKS` (`ruleCounts` => `RuleCounts`) by `instanceof`; a class that is not loadable is skipped (check warns about it as before). `Report\Counts` is the only reader; the statistics page lost its `rules` view (the core route serves it, the tab stays), so `StatsPage::viewFor('/rs/waf/rules')` is null now (two tests adapted). The rules page carries a small stylesheet of its own in the core frame. Reviewed with the code-review skill, no workflow.
- **Finding from B.7:** the principal is opaque: `Access::fromLink()` accepts any signed id (letters, digits, `-`), the core keeps no group table; `StatsExtension::siteFor()` replaces `Access::site()`, `StatsExtension::groupId()` wraps `Settings::principal()`. A `dashboard-access` line for an id no `stats-group` has compiles and `StatsExtension::check()` warns (AccessTest adapted: it used to be refused). `Vocabulary::word()` gained `serverWide` (stats-group is refused inside a site block as before). Reviewed with the code-review skill, no workflow.
- **Finding from B.6:** the shield serves a route in `Shield::run()` right after the refusals, for passing requests only, guarded by one `stripos` against `dashboard-path` (every route lies below it) -- `Dashboard::routeFor()`; the page classes implement `RoutePage::serve()` and return a `Response`. A `restrict` rule sees the path as the request has it, so behind a front controller it must read `restrict **/rs/** …` (the demo does; the test does). Every offered extension compiles now (`Settings::compiledExt()` iterates `Vocabulary::extensions()`, raw `[]` when the rules wrote nothing) -- before, an extension without a `set` line had no routes and no plugin. The `rules` page is served by `SetupPage::serve()`: with the statistics on it renders through the statistics' frame (until B.8), else in the core frame. A customer asking an `admin` route gets 403 (DemoTest adapted). Reviewed with the code-review skill (fallback), no workflow.
- **Finding from B.5:** a route entry is `['key' => string, 'ext' => ?string (null = the core), 'tab' => ?array{en, de} (null = no tab, e.g. a start-page alias), 'role' => 'admin'|'reader', 'order' => int]`, keyed by the full path; `Settings::compiledExt()` builds `$s->routes` from the raw `routes` key, `Routes::core($s->dashboardPath)` and each offered extension's `routes($checked[$id])` -- the method takes the compiled slot only, so an extension whose paths hang under `dashboard-path` copies `$base->dashboardPath` into its slot in `compile()` (`RsTestExtension` does; `StatsExtension` has its own `path`, which may lie outside `dashboard-path`). `Frame::tabs()` and `Access::links()` take `Settings` as their first parameter now, `Extension::routes()` takes `array $compiled`; `Settings::$statsPath`/`statsPath()` and `Frame::TABS` are gone. `Settings::from([])` never has only the core's routes, because `Config::defaults()` carries `ext.stats` and the bootstrap offers `StatsExtension` -- a test that wants the core alone runs inside `ExtensionTest::withRegistry()`. `StatsPage::viewFor()` keeps answering `'rules'` for the core's `/waf/rules` until B.8 (the demo renders that page through it). Cost: routes are compiled on every `Settings::from()`, so the bench line "setup from an array" rose from about 31 to about 42 µs (Routes::compile 3.9 µs, a second construction of Settings, the path check); the passing path and the compiled-settings-file setup are unchanged. Accepted as compile-time cost; a follow-up may compile routes before the first construction (compile() would then take the raw array instead of Settings).
- **Finding from B.3/B.4:** `Extension::plugins(array $compiled)` is how a plugin gets appended: `Settings::compiledExt()` adds what it returns to `$s->plugins` (unique, order kept) after `compile()`, so `Shield` and `check` know no plugin by name any more. The tests that had to change: `ExtensionTest` (its `withRegistry()` must snapshot and re-offer `Vocabulary::extensions()` after `forget()`, or the bootstrap-offered `StatsExtension` vanishes for every later test file; `ext` always carries `stats` from `Config::defaults()`), `SettingsTest`, `PluginTest`, `StatsTest`, `StatsSitesTest` (removed properties → `StatsExtension::of()`, top-level `'stats'`/`'crawlerLog'` keys → `'ext' => ['stats' => …]`). `Vocabulary::set()` gained `name` (dotted for `crawlerLog.*`), `serverWide` and `many` (`set stats` writes `enabled` and `parts`), `word()` gained `paths` (the parser compiles the patterns first, so `origins['written']` keeps the glob); `Frame.php` read `statsHosts` too and now reads the slot for display only, until B.8. Review finding, applied: an eager `class_exists()` + `Vocabulary::offer()` in `bootstrap.php` would have loaded `StatsExtension`, `Extension` and `Vocabulary` (four `is_file()` through the autoloader) on every passing request, against ADR 0008; the bootstrap now only names the shipped extensions in the constant `REQUEST_SHIELD_EXTENSIONS` and `Vocabulary` resolves the list on its first lookup (`extension()`, `extensions()`, `offer()`, the word/key index) -- a test spawns a CLI process and asserts that none of the three classes is declared after `require bootstrap.php`. The names stay in the bootstrap, not in `src/`, because of the phase-B criterion `grep Stats src/ bin/ bootstrap.php = 0` (D.2 adjusts the bootstrap anyway). Composer installs never load `bootstrap.php`, so the constant that names the shipped extension is also defined by `plugins/stats/shipped.php` (composer.json autoload `files`; the test "a Composer install without bootstrap.php" runs a child PHP against `vendor/autoload.php`). A constant cannot grow: a second shipped extension needs a list mechanism -- decide at E.2/H.1 (the single file has no such problem).
- **Review:** B.3/B.4 were reviewed by two read-only agents inside a workflow (one for correctness, one for plan compliance) instead of the single skill run. Earlier: `pr-review-toolkit` is not installed on the machine that wrote phase 0 and A.1–A.2; the fallback (code-review skill, sonnet, low) was used — for phase 0 (documents only) once over the whole phase, for the code steps once per step. A2.1 likewise (not installed on that machine either; the skill runs as a fork, so on the session's model, not sonnet -- no findings).
- **Static analysis on this machine:** PHPStan runs; `composer taint` (Psalm 6) crashes with `Class "Composer\InstalledVersions" not found` because the local Composer is 1.10 (its autoloader lacks the class). CI runs it (`static-analysis.yml`); on a machine with Composer ≥ 2 run `composer install` and `composer taint` before pushing code steps.
- **Finding from A.1:** the bench command in `AGENTS.md` needs `-d opcache.file_update_protection=0`, otherwise the "setup" lines show milliseconds for freshly compiled settings (fixed in AGENTS.md).
- **Finding from B.2:** the registry is per process (`Vocabulary::offer()`), so a `plugin <class>` line offers the extension for every later reading in that process too -- harmless (its words only ever write into its own slot), and the tests call `Vocabulary::forget()`. `Extension::compile()` runs per `Settings::from()`, so a site block gets its own compiled slot. A `set` key's value is typed by the parser (the core's `typed()`), an extension adds a check per key; the shape of a whole slot is `compile()`'s. The `stats` set key is the one core key with logic of its own -- it stays in `set()` until B.3 moves it into the stats extension.
- **Finding from B.1:** the three slots are filled from the raw settings array for now (`ext`, `hooks`, `routes` keys, shape-checked), so they can be tested and round-tripped before an `Extension` exists; B.2 made `Extension::compile()` the writer of `ext`; `hooks` is recorded by `instanceof` in the steps that add a hook (B.9, B.10, C.3, C.4), `routes` in B.5.
- **Finding from A2.3:** the "red without the change" for a limits-only test is the limits themselves: with every limit set to 1 the test prints the measured sizes (that is how the numbers were taken); the gzip limit (4096) has 31 bytes of room until E.2 minifies the page -- a text change on the page may need the number raised, on purpose.
- **Finding from A2.2:** `ChallengeJsTest` needed no change: the page script takes the cookie name from the page (`RS.cookie`). The demo page reads the pass cookie's expiry itself (base36 now); nothing else outside the shield parses it.
- **Finding from A2.1:** `X-RS-Access` exists only in proposal 0027 (renamed there and in its SVG); the code header comes with B.7. Not in the plan's table: the 429 JSON answer for API clients carries `Request-Shield-Challenge` and takes `Request-Shield-Solution` (no `X-`, rare, only on a refusal) -- left as they are; an owner decision whether A2.3 shortens them too (`RS-Challenge`/`RS-Solution`).
- **Finding from A3.4:** under `TESTS_HOSTING=minimal` the demos died with 500 (`putenv()` disabled; they tell the rules their subdirectory that way) and one DemoTest server did not end on `proc_terminate()`, so the whole run hung for an hour with no CPU — `tests/run.php` has no per-test timeout. Fixed the demos (`function_exists('putenv')` guard, the placeholders' default `/`); the two subdirectory cases skip under minimal hosting. Still open: a watchdog in the runner (kill and fail a test after N seconds) — a small step for phase F.
- **Finding from A3.1:** an end-to-end test that starts the server through `env …` must `exec env …` — otherwise `proc_terminate()` ends the shell and the server lives on (BootstrapTest left ten servers running and doubled the bench numbers). Pattern for every E2E test: the command starts with `exec`. The default directories moved out of the temp dir, so `.request-shield/` is in `.gitignore` (the demo tests make one next to the demo rules).
- **Finding from A.3:** under PHP's built-in server APCu is *on* (its SAPI `cli-server` is not "cli" to APCu), so with rule files the shield re-reads the sources only every `recheck` seconds (10) — end-to-end tests that rewrite a rule file must set `set recheck 0` (RobustnessTest does). Worth a line in `docs/features/rule-files.md` when F.1 touches it.
- **Finding from A.2:** the "once a minute" throttle of error-log lines was per process only (`static` is reset per request under PHP-FPM and the built-in server) — it now also uses APCu or a marker file in the temp dir, so a broken deploy logs one line a minute, not one per visitor. A3.1 should move the marker next to the compiled settings (`store-dir/cache`).
