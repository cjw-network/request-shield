# 0031 — A robust core, everything else a plugin: one file, extension points, a demo and tests for every feature, installable by an AI

| | |
|---|---|
| Status | **Draft** (the plan; steps and progress in [0031-steps.md](0031-steps.md)) |
| Proposed | 2026-10-03 |
| Affects | everything: the core's boundary, the plugin interfaces, the build, the repository, the documentation, the tests |
| Relates to | [0002 single-file build](0002-single-file-build.md) · [0023 plugins](0023-plugins-hosts-customers.md) (phase 6) · [0029 rule examples](0029-rule-examples.md) (phase 2) · [ADR 0006](../adr/0006-core-and-plugins.md) |

## Summary

request-shield grew in two weeks from a DoS guard to a mini WAF with rule
files, a browser check, a statistics plugin, dashboard pages and demos (136
commits since v0.2.0). This proposal fixes the direction before v1.0:

- The **core** — the mini WAF that reads the rule files — becomes **robust**
  (an error in the shield never takes the site down) and **trivial to
  integrate**: **one file**, `wget` and three lines.
- A **mini edition without any backend**; editions with bundled plugins later.
- **Extension points at every important place**, resolved at compile time so
  they cost nothing when unused. A visual WAF backend, a statistics backend, an
  HTTP-cache backend, an API and CMS adapters are all **plugins**.
- **Every feature has documentation, a demo and tests under one id**
  (`RSF<group>.<n>`), enforced by a test.
- **An AI installs it from one prompt**: downloads the file, activates it,
  derives the rules for the application, verifies them. "Security must not be
  a luxury."
- Priorities, in this order: **security, speed, documentation.**

Nothing is live yet, so there is **no backward compatibility to keep**: no
aliases, no transition periods. The SemVer policy below starts with v1.0.0.

## Owner decisions (2026-10-03)

| Question | Decision |
|---|---|
| What is in "the one file"? | The **mini** core without any backend: check, decide, answer, challenge, log, the rule compiler, the shipped rule sets embedded. Editions with more plugins later, as separate files (see *Editions*). |
| Compatibility | None needed. Restructure freely; aliases and transition code are not written. |
| Abbreviation | **`rs`** = RequestShield. Headers `X-RS…` instead of `X-Request-Shield…`, cookies `rs*`, feature ids `RSF…`. Every byte on the wire counts. |
| Hosting | Runs fast **with APCu**, and **without it on minimal requirements** (shared hosting): PHP ≥ 8.0 and nothing else. |
| Features | Every feature: docs, a demo and tests under its id. |
| Working mode | See [0031-steps.md](0031-steps.md): the work is done in the session; subagents only for read-heavy searches (at most 2, Explore, haiku); exactly one review (`pr-review-toolkit:code-reviewer`, sonnet) on the uncommitted diff before each commit. |

## Principles (each becomes an ADR)

1. **Fail safe is a contract, not an intention.** Any exception inside the
   shield lets the request pass (`allow-uncached 'shield error'`), logged once a
   minute. A rule file that fails to compile keeps the last good compiled
   settings; without any, the shield runs as `mode off` and logs. `check` stays
   strict.
2. **Nothing unused costs anything.** Extension points are resolved **at
   compile time** into the compiled settings (`$s->hooks`, `$s->routes`,
   `$s->ext`); the request path only tests `($s->hooks['x'] ?? []) !== []`. No
   `class_exists`, no reflection, no `stat()` per request.
3. **Plugins can tighten, never loosen.** No veto or override of a decision.
   Own rules yes (`Decision::stricter()`), own pages yes, own rule-file words
   yes — in their own namespace `ext.<id>`.
4. **One source of truth.** The rule vocabulary is a declarative table; the
   reference docs, error messages, setup page and demos are generated from it.
   Demos come from `expect` lines, not from PHP tables.
5. **Every feature: docs, a demo and tests under one id `RSF<n>.<m>`.** A test
   (`FeatureContractTest`) enforces it; `php tests/run.php RSF2.6` runs exactly
   that feature's tests.
6. **One URL, one file, one signature.** `releases/latest/download/request-shield.php`
   plus checksum and signature; the file is also the CLI.
7. **Every transmitted byte counts.** `X-RS…` headers, three-letter cookie
   names, the pass cookie in base64url instead of hex (same security, ~45
   instead of ~71 bytes on every request), internal app→shield headers never
   leave the server, the challenge page minified; the limits are numbers in
   `tests/WireBytesTest.php`.
8. **Fast with APCu, minimal without.** PHP ≥ 8.0 is the only requirement; a
   writable directory and APCu are *tiers* (S0/S1/S2) the shield detects and
   `check` names. No feature may fail without its tier — it switches off and
   says so.
9. **Written for people.** Docs by perspective (admin, hoster, editor,
   customer, developer, AI), plain language with a glossary, no page without a
   diagram; every UI explains itself in one sentence and links, with its RSF id,
   into the docs. Checked by tests (`DocsStyleTest`, `UiHelpTest`).
10. **The AI proposes, the owner decides.** `monitor` first; `enforce` never
    without the owner having seen `test` output and the log; no guessed address
    ranges; no personal data in rules or examples.

## Where we stand (v0.3.0 + unreleased, 2026-10-03; verified in the code)

- **Size.** `src/` 67 files, ~14,500 LOC (`Rules/RuleFile.php` 2360,
  `Settings.php` 1698, `Shield.php` 1250); `plugins/stats/src/` 5 files, ~3,200
  LOC; `rules/` ships `@attacks @scanners @crawlers @tracking @wordpress`,
  `feeds.json`, 13 crawler lists and a generated `crawlers.php` (330 KB).
- **Request flow.** `bootstrap.php` → `Shield::protectFile()` →
  `Settings::loadFor()` (compiled, OPcache) → `Shield::protect()`:
  `Request::fromServer` → `decide()` over a **rule chain hard-wired in the
  constructor** (`Shield.php:89-150`) → `settle()`/`Gate` → `signals()` (bans) →
  monitor rewrite → `explain()` → `record()` (plugins) → `Responder` or on to the
  application. The chain is hard-coded **three times**: the constructor,
  `Report\Inspector::trace()`, `Shield::explain()`; `SetupPage` draws its own.
- **Extension points today.** Exactly two read-only hooks: `Plugin::decided()`
  and `Plugin::ended()`. Adapter hooks: the `$known` callback, `consume()`,
  `requirePass()`, `widget()`, `protectFile(..., $sources)`, store injection.
  **No hooks** for own rules, response/status pages, the cache decision, the
  challenge page, the settings compiler (`RuleFile` rejects unknown words; the
  stats words live in the core parser, 42 places), CLI commands, routes
  (`Frame.php:43` knows the stats pages), log/live sinks. `Settings` has a
  positional constructor with ~100 parameters.
- **Core ↔ plugin coupling.** `Shield.php:506-507` (`StatsPlugin::class`),
  `Shield.php:742-745` (`dashboardOnly()` uses `statsPath` and `Frame::isPage`),
  `Settings.php` (21 stats/crawler-log/access fields), `RuleFile.php` (12
  `stats-*` set keys, keywords `stats-group|stats-access|stats-skip`),
  `Frame.php:43-45`, `RulesPage.php:106`, `bin/request-shield` (`stats`,
  `token`), `bootstrap.php` and `composer.json` (one namespace on two
  directories), `Access.php` (stats only).
- **Robustness gap (confirmed).** `Shield::protect()` has **no** try/catch, nor
  do `Settings::load()/build()`. A `RuleFileException` after a deploy, or a
  throwable in a rule, is a fatal error: **500 for every visitor** — against
  `AGENTS.md` ("fail safe"). There is no fallback to the last good compiled
  settings. The stores are already fail safe.
- **Single-file readiness.** CSS, JS and the logo are heredoc constants. What
  blocks one file: `__DIR__`-relative reads of `rules/` (`Settings::RULES_DIR`,
  `Settings.php:392`, `RuleFile::shipped()`, `RuleFile.php:345/411/2240`,
  `Feeds.php:55`, `bin:625`); `bootstrap.php` falls back to
  `config/request-shield.php` only, not `.rules` (the README story is wrong:
  four steps, not three). No version literal, no `version` command.
- **Cache.** "Cacheable" is yes/no (`ALLOW` vs `ALLOW_UNCACHED` + reason). No
  cache backend, keys, Vary or `Cache-Control` for allowed requests.
- **Dashboard.** The shield does not serve it: each site wires `/rs/...` in its
  front controller (reference `examples/demo/index.php:175-270`).
- **Demos.** Two, with tables hard-coded in PHP; "Show the answer" is a
  client-side `fetch` that cannot show redirects or cross-host rows. Without a
  demo: `post-origin`, feeds, trusted proxies, `site` blocks,
  `@attacks`/`@wordpress`, `strict`, error pages, crawlers in action. `expect`
  lines in shipped sets: scanners 16, wordpress 6, tracking 2, **attacks 0,
  crawlers 0**. `expect` cannot set a User-Agent.
- **Tests/CI.** Own runner (`tests/run.php`), 30 files, 9 end-to-end with
  `php -S` (the path to `bootstrap.php` hard-coded); 12 CI legs on every push
  and PR; no release pipeline; not on Packagist.
- **APCu use outside `Store/`:** `Live` (APCu only), settings freshness, the
  crawler DNS cache, `Stats`. Default cache dir is `sys_get_temp_dir()`.
- **Proposal numbers.** 0002 is reserved for the single-file build with no
  document; two local drafts collided with 0002 and 0003 (renumbered to 0032
  and 0033 by this proposal). 0007 is unused.

## Target architecture

### The core's boundary

**Core = everything a request needs to be decided, answered and logged, plus the
rule compiler, plus the CLI that checks rule files.**

| Stays in the core | Moves to the `stats` plugin (namespace `…\Stats\`) |
|---|---|
| `Shield`, `Request`, `Decision`, `Rule/*`, `Budget`, `IpAddress/IpTable`, `Seen`, `Texts`, `Responder`, `Challenge/*`, `Store/*`, `Log`, `Live` (the first `Sink`) | all `stats*`/`crawlerLog*` settings and their parsing |
| `Config`, `Settings`, `Rules/*` (compile happens on the server) | `stats-group`, `stats-skip`, `set stats …` (B.3 moved `set stats …`, `stats-hosts`, `stats-skip` and the `crawler-log` keys; `stats-path` in B.5; `stats-group`, `stats-access`, `stats-session` follow in B.7) |
| `Access` as the **auth service for shield-served pages** (`dashboard-access`/`-session`) | group → websites mapping, per-customer tab filter |
| `Plugin` + the new `Extension` interfaces | the stats pages, routes, tabs |
| `Report/*` (the `/waf/*` pages) — **in the repository's core, but not in the mini file** | `stats`, `token` → core `access-token` |

### Extension points: two interfaces, resolved at compile time

```php
interface Extension {                      // static, compile time
    public static function id(): string;                        // 'stats'
    public static function vocabulary(Vocabulary $v): void;     // own words and set keys
    public static function compile(array $raw, Settings $base): array;  // check ext.<id>.* (throws InvalidArgumentException)
    public static function routes(array $compiled): array;      // its pages: full path => key, tab, role, order (B.5)
    public static function commands(): array;                   // CLI commands
    public static function check(Settings $s): array;           // warnings for `check`
}
interface Plugin { decided(); ended(); }   // as today: per request, read only
```

Optional capabilities **on** `Plugin`, recorded into `$s->hooks` at compile time
by `instanceof` (a new capability is a new interface = MINOR):

| Hook | Sketch | When | Cost unused | Security |
|---|---|---|---|---|
| `RuleProvider::rules(Settings, Store): list<Step>` | `Step{key, stage, Rule, describe, explain}`; stages `lists identity shape paths origin crawlers query content cache budgets` | constructor, after the stage's core steps | 0 | `stricter()` only; never before `lists`; a throw → `null` |
| `Handler::handle(Request, Decision): ?Response` | routes **and** an HTTP-cache hit before the application | `protect()` after `settle()`, passing requests only | one array access | runs after all rules; a throw → the application runs |
| `Pages::page(kind, ctx): ?string` | `error | challenge | access-login` (→ 0030) | only when the shield answers itself | 0 on the pass path | headers stay core; the plugin escapes |
| `Sink::note(...)` | log/live sinks | `Log::note()` | 0 | masking as `Log::mask()` |
| `RuleCounts` | "decided n times" on the rules page | report | 0 | — |

**Deliberately not:** request enrichment (`Request` is a value object; 0020's
tracking strip is a core feature) and decision veto/override (breaks the
fail-safe reasoning, monitor's `$would`, bans and the log).

**Settings:** a generic slot `public array $ext = []` (plugin id ⇒ checked
values) as the last constructor parameter, plus `$hooks` and `$routes`.
`Vocabulary::word()/set()` write only into `ext.<id>`. Shipped extensions are
offered by the bootstrap (`Vocabulary::offer()`), so `set stats on` keeps
working; third-party plugins without Composer load with
`plugin X from request-shield-stats.php` (relative to the rule file,
`require_once` only when `$s->plugins !== []`).

**Routes:** the shield **serves** `dashboard-path/*` itself (behind
`Access::gate()`, roles `admin|reader`, `no-store`, `noindex`, CSRF for POST).
`check` warns when a route has neither `restrict` nor `dashboard-access`.

**The rule chain:** `Shield::chain(): list<Step>` is the single source;
`Inspector`, `explain()` (moves into the rule classes) and `SetupPage` derive
from it.

### The API: a plugin with its own extension point (RSF6.5)

Today's JSON is scattered (`?format=json`, `LivePage::json()`, `stats --json`).
The API becomes **`plugins/api`** (package `request-shield-api`, file
`request-shield-api.php`), not core: the mini file needs none (0 bytes, 0 µs —
routes are compiled, the pass path checks the `dashboard-path` prefix as today),
and the API is the natural **data layer under the WAF backend**: the dashboard
pages consume the same endpoints. `plugins/waf` requires `plugins/api`.

One extension point, three fronts: every data source is one *service* used by
the CLI, the pages and the API. Plugins add endpoints via a capability on
`Extension`:

```php
interface ApiProvider { /** @return list<Endpoint> */ public static function api(): array; }
final class Endpoint {
    public function __construct(
        public string $method,     // GET | POST | DELETE
        public string $path,       // '/stats/sites' → /rs/api/v1/stats/sites
        public string $role,       // 'reader' | 'admin'
        public bool $write,        // only with `set api-write on`
        public string $service,    // class-string<ApiService>: handle(Request, array $params): array
        public array $schema,      // response shape, for OpenAPI and the contract test
        public string $feature,    // 'RSF6.3'
    ) {}
}
```

The host does the common work once: auth (`Access::gate()`, Bearer or session,
roles), the JSON envelope `{ "data", "meta": { "version", "generated", "tier" } }`,
RFC 9457 problem details, cursor pagination, `ETag`, `Cache-Control: no-store`,
rate limiting of failed auth, CORS off by default (`set api-origins`), writes
only with `set api-write on` **and** an admin principal (CSRF for cookie
sessions), every write as an event to the `Sink`s.

v1 endpoints under `/rs/api/v1`: `GET /status`, `GET /rules`, `POST /trace`,
`POST /test`, `POST /check` (admin), `POST /reload` (admin, write),
`GET /live?cursor=`, `GET|POST|DELETE /lists…` (write), `GET /feeds`,
`POST /feeds/update` (write), `GET /crawlers`, `GET /log?cursor=` (admin),
`GET /stats/…` (from the stats plugin via `ApiProvider`; a customer sees only
its group), `GET /cache/…` (cache plugin, later), `GET /openapi.json|yaml`
(generated from `Endpoint[]`, including plugin endpoints — what CMS adapters
and AIs read).

**Two access paths, one data shape.** CMS plugins run in the same PHP process,
so the plugin also offers an **in-process facade**:
`Api::call('GET', '/stats/sites', $params, $who)` returns exactly the array the
HTTP endpoint sends as JSON (same service, same auth check with the CMS admin's
principal). PHP backend pages use the facade; JavaScript admin UIs use HTTP.
`tests/ApiContractTest.php` checks every response against its schema and that
facade and HTTP are byte-identical. `request-shield api --openapi` writes
`docs/reference/api.md` and `openapi.yaml` (`--check` in CI). Versioning
`/v1/`: removing or redefining a field is MAJOR of the API package. Webhooks
are **not** part of the API — a small `Sink` plugin later, if needed.

Security: never the secret, token hashes or raw IPs beyond the log's masking;
`trace`/`test` count and write nothing; `check` reads only the body; write
endpoints are **off** by default; API routes, like all shield routes, are only
reachable with `restrict` or `dashboard-access`.

### CMS adapters as plugins (Exponential, Ibexa/Symfony, WordPress, Drupal)

The shield runs *before* the CMS, so a CMS module cannot start it. An adapter
has two halves: **activation** (one line as early as possible, or
`auto_prepend_file`) and **the CMS module** that brings the shield's functions
*into* the CMS. The rule sets `rules/app/<cms>.rules` live **in the core**
(embedded in the one file, `include @app/wordpress`) so the AI install works
without an adapter; the adapter is the comfort layer. All adapters use the same
core hooks — nothing CMS-specific enters the core:

| Function | Core hook | What the adapter does with it |
|---|---|---|
| Rules from the CMS (0021) | `protectFile(..., $sources)` | A settings page with switches writes `<store-dir>/cms.rules` **outside the docroot**, appended as a source |
| Cacheable definition from routes | `$known` callback **or** export into `cache-path` lines | Route export per CLI (`wp request-shield rules`, `drush request-shield:rules`, `bin/console request-shield:rules`); `$known` only when the router is cheap |
| Websites → `site` blocks (0024) | `site … {}` + `set site-from server-name` | WP multisite, Drupal multisite, Ibexa siteaccesses exported as `site` blocks |
| Do not poison the cache | `$_SERVER['REQUEST_SHIELD'] === 'allow-uncached'` | WP `DONOTCACHEPAGE`; Drupal `page_cache_kill_switch`; Symfony/Ibexa response listener `Cache-Control: private, no-store` |
| Browser check in forms (0010) | `widget()`, `requirePass()` | WP `comment_form`/`login_form`; Drupal `hook_form_alter`; Symfony form type extension + Twig function |
| On-demand budgets | `consume('search'|'login')` | search, failed logins |
| Protected areas (0027) | `X-RS-Access: <area>` / `requireAccess()` | the CMS login opens `restrict` areas for that browser |
| Dashboard inside the CMS backend | page renderers + `Access::link()` | a "Firewall" menu entry renders the pages **inside** the CMS auth |
| **All data in the CMS** | **API plugin**: `Api::call()` in-process, `/rs/api/v1/…` for JS UIs, `openapi.yaml` | PHP backend code calls the facade; Ibexa back office / WP React load via HTTP; an adapter may add its own endpoints via `ApiProvider` |
| Log into the CMS | `Sink` | WP `error_log`/Site Health, Drupal logger channel, Monolog handler |

Specifics: **WordPress** — package type `wordpress-muplugin` plus a release zip
with the one file; activation first line of `wp-config.php`; `@app/wordpress`
with `allow POST /wp-login.php /wp-comments-post.php /wp-admin/** /wp-json/** /xmlrpc.php`,
`api-path /wp-json/**`, `cache-query s p page paged cat tag`,
`challenge /wp-login.php`; today's `@wordpress` ("is *not* WordPress") is
renamed `@not-wordpress`. **Drupal** — type `drupal-module`; activation first
line of `web/index.php`; Drush export of routes and JSON:API paths. **Ibexa /
Symfony** — one bundle, Ibexa part conditional on `ibexa/core`; activation first
line of `public/index.php` before `vendor/autoload.php`; console export of
routes and siteaccesses; response listener; security listener sets
`X-RS-Access`. **Exponential** — the existing `examples/exponential/*.rules`
become `@app/exponential`; the package ships the `config.php` snippet and an
admin module. Every adapter is **thin**: everything reusable lives in the core.

### The single-file build (proposal 0002)

Details in [0002](0002-single-file-build.md). In short: `build/single-file.php`
(PHP, no dependency, deterministic), `Rules\Shipped` replaces the six
`__DIR__` reads (`rules/crawlers.php` is **not** embedded), the bootstrap
search order `REQUEST_SHIELD_CONFIG` → `request-shield.rules` next to the file
→ `config/request-shield.rules` → `config/request-shield.php` (also fixed in
`bootstrap.php`), the file is the CLI when run directly, the header comment is
the install guide, tests run against the built file via `REQUEST_SHIELD_ENTRY`,
`version`/`verify`/`self-update` (CLI only, signature mandatory where `sodium`
exists).

The three lines:

```
wget https://github.com/cjw-network/request-shield/releases/latest/download/request-shield.php -O /var/www/request-shield.php
php /var/www/request-shield.php init --app=plain --out=/var/www/request-shield.rules   # or write the rules by hand
echo 'auto_prepend_file=/var/www/request-shield.php' >> /var/www/html/.user.ini
```

### Editions (to be discussed)

| Asset | Contents | For | Recommendation |
|---|---|---|---|
| `request-shield.php` (**mini**) | the core without `Report/*`: check, decide, answer, challenge, log, compiler, embedded rule sets; CLI `check test trace show …` | shared hosting, "just protect" | **the base, always**; ~0.5 MB |
| `request-shield-waf.php` | the API plugin + `Report/*` pages | the WAF window and/or the API | own asset, loaded with `plugin … from`; the API alone also as `request-shield-api.php` |
| `request-shield-stats.php` | the statistics plugin | agencies, hosters | own asset |
| `request-shield-full.php` | mini + waf + stats | convenience | **only when asked for**; it blurs the line this proposal draws |

### Hosting tiers: fast with APCu, minimal without

**Binding requirement of the mini file:** PHP ≥ 8.0, **no** extension beyond the
ones always present in PHP 8 (`json`, `hash`, `ctype`, `random_bytes`), no
Composer, no shell, no cron. Everything else is a **tier** the shield detects;
`check` and `version` name it; every feature doc says which tier it needs
(`needs: none | dir | apcu` in `Vocabulary`).

| Tier | Environment | What runs | Cost (measured today) |
|---|---|---|---|
| **S0 stateless** | no writable directory, no APCu | everything stateless; settings **compiled per request in memory**; budgets/bans/pass cookie off, challenge only with `set secret` — `check` lists it | compile + checks |
| **S1 file** | one writable directory, no APCu — **the shared-hosting norm** | plus compiled settings (OPcache), file store, budgets, bans, challenge, lists, feeds, statistics in files, **live view as a file ring** (new) | ~42 µs |
| **S2 APCu** | APCu | plus counters in shared memory, freshness without `stat()`, DNS cache and live ring in memory | ~12 µs |

Changes: the compiled-settings cache moves to `store-dir/cache/` (not
`sys_get_temp_dir()`); every direct `apcu_*` use (`Live`, settings freshness,
the crawler DNS cache, `Stats`) goes behind a capability with a file fallback
or a clean switch-off; `curl` fallback for feed/crawler updates without
`allow_url_fopen`; `tests/HostingTiersTest.php` runs the same requests under
S0/S1/S2 (`open_basedir`, `disable_functions`, `allow_url_fopen=0`,
`memory_limit=64M`) with identical decisions; a CI leg "minimal hosting" on
every PR.

### Headers and cookies: as short as possible, still readable

| Today | New | Direction |
|---|---|---|
| `X-Request-Shield: <action> <reason>; rule=<ID>` | `X-RS: …` | shield → browser, debug only |
| `X-Request-Shield-Monitor` | `X-RS-Monitor` | debug only |
| `X-Request-Shield-Challenge: required[; fresh=N]` | `X-RS-Check: 1[; fresh=N]` | app → shield, internal, removed before output |
| `X-Request-Shield-Access: <area>` | `X-RS-Access: <area>` | app → shield, internal |
| `rs_pass` / `rs_solution` / `rs_stats` | `rsp` / `rss` / `rsd` | cookie names (defaults) |

The pass cookie — sent by the browser **on every request** while the pass lasts
— changes from `v1.<10 digits>.<16 hex>.<32 hex>` (~71 bytes with the name) to
`rsp=2.<expires base36>.<tag base64url 11>.<mac base64url 22>` (~45 bytes):
same 128-bit MAC, denser encoding. The challenge page and widget script are
minified by the build. `tests/WireBytesTest.php` holds the limits as numbers.

### Robustness: the guarantees and their tests

| Guarantee | Today | Change |
|---|---|---|
| An exception in the shield → the request passes | **no** | try/catch(\Throwable) around the body of `protect()`/`protectFile()`, `requirePass()`, `consume()`, `widget()`, the `ob_start` callback → `Decision::allowUncached('shield error')`, `error_log` throttled |
| A plugin failure → ignored, logged once a minute | yes (`decided/ended` only) | every hook through `guarded()` |
| A compile error → the last good compiled settings | **no** | catch in `load()/loadFor()`: compiled file present ⇒ `import()` and a `settings-<key>.failed` marker; none ⇒ `mode off` + log |
| Store unavailable → pass | yes | tests added |
| Truncated compiled file | `@include` + format check | a `TypeError` in `import()` → `unlink`, rebuild on the next request |

`tests/RobustnessTest.php` (end to end like `PluginTest`): a throwing
`RuleProvider` rule, a throwing `Handler`, `Pages`, `Sink`; a rule file with a
syntax error after a good compile; broken at first install; an unwritable
`store-dir`; a truncated compiled file; unit tests for store and secret; fault
injection through a test extension `set fail-at <stage>`. Every test must fail
without the change.

### Feature ids and the feature contract

Three kinds of ids, told apart at a glance: **`RSF2.6`** = feature, **`0031`** =
proposal (planning history), **`[SCAN-HIDDEN]`** = rule. Feature ids are
`RSF<group>.<n>`; groups follow the question a request answers, in the order the
shield asks them, so `trace` reads in the same order as the docs. Ids are
never reassigned (gaps allowed).

| Group | Question | Features |
|---|---|---|
| **RSF1 Who is asking** | the other side's identity | RSF1.1 trusted proxies · RSF1.2 IP lists & bans · RSF1.3 public blocklists (feeds) · RSF1.4 known crawlers |
| **RSF2 What is asked** | the request's shape and target | RSF2.1 hard rejects · RSF2.2 blocked paths (scanners) · RSF2.3 access rules · RSF2.4 forms from the website (post-origin) · RSF2.5 known parameters · RSF2.6 attack patterns |
| **RSF3 How often** | pace and proof | RSF3.1 budgets & pace · RSF3.2 browser check · RSF3.3 the check in the form · RSF3.4 the site asks for the check |
| **RSF4 What a cache may keep** | cache hygiene | RSF4.1 cacheable definition · RSF4.2 cache keys without tracking (0020) |
| **RSF5 Operating** | writing and checking rules | RSF5.1 rule files · RSF5.2 settings · RSF5.3 modes · RSF5.4 examples next to the rules · RSF5.5 log & rule ids · RSF5.6 error pages (0030) |
| **RSF6 Watching & connecting** | what the shield does, and how others reach it | RSF6.1 rules page · RSF6.2 live & lists · RSF6.3 statistics (plugin) · RSF6.4 plugins & extensions · RSF6.5 API (plugin) |

A feature **exists** only when all of these are there, under its id — and
`tests/FeatureContractTest.php` enforces it in both directions:

| Part | Where | Form |
|---|---|---|
| Docs | `docs/features/<slug>.md` | H1 `# RSF2.6 …`; what it does, use cases, configuration, cost, limits, ≥ 1 diagram, the generated examples block |
| Demo | `examples/demo/request-shield.rules` | a `# demo: RSF2.6 <slug> <title>` group with ≥ 1 `expect` (a hit **and** a near miss that must pass) — also the executable tests run by `request-shield test` and the rows of the demo page |
| Unit tests | `tests/<Feature>Test.php` | names begin with the id: `'RSF2.6 attack patterns: …'`; `php tests/run.php RSF2.6` runs exactly this feature |
| End-to-end test | same file, `php -S` | mandatory for everything on the request path (groups RSF1–RSF4) |
| UI help | every page/section showing the feature | one sentence + a `?` link with the id to the docs anchor (`Help::link()`) |
| Bench line, reference, changelog | docs "cost", `Vocabulary`, `CHANGELOG.md` | measured with the feature off and on; entries name the id |

The id appears in the docs H1, the demo rule file (`# demo: RSF2.6 …`, rows
`RSF2.6.1`, anchors `#RSF2.6`), `test`/`examples` output, every `trace` step,
the rules/setup pages, the generated reference (`restrict → RSF2.3`) and the UI
help links. Debug header and log keep the **rule id** — no extra byte per
request.

### Demos from `expect` lines (0029 phase 2, generalised)

One source: `examples/demo/request-shield.rules` with `# demo:` groups, the
explanation as comment lines below, `expect` lines with comments; `# try:` rows
for purely visual cases (widget, pass age); `site` blocks for isolated settings
(strict, error pages, per website). Grammar additions: `ua "<User-Agent>"`,
quoted header values; results carry status and headers. One generic front
controller (`Report/DemoSite` + `ExamplesPage`); "Show the answer" becomes
**server-side** (`POST /__answer` replays the example over loopback against the
live store, showing budgets, bans, cross-host, redirects); `examples/exponential`
on the same base. One CLI for three consumers: `test`, `examples --markdown`
(tables between markers in `docs/features/*.md`, `sync-examples.php --check`),
`examples --html` (a **recorded static demo** for GitHub Pages: "recorded from
commit … by `test`"), `examples --coverage`. The gaps (trust, post-origin,
feeds with `feed <name> from <file>`, strict via `site`, sites, `@attacks` with
`expect`, crawlers via demo UA and documentation ranges, error pages after 0030,
bans enforced in a `site` block) are closed one commit each.

### Documentation by perspective, plain language, always with a diagram

`docs/for/<role>.md` entry pages — each answers *what do I see, what do the
numbers mean, what do I do when* — with ≥ 1 diagram and a "typical day"
scenario: **admins** (what happened today, live and lists, monitor → enforce,
false positives, under attack, update/rollback), **hosters** (many sites, one
rule file, customer views, tokens, tiers), **editors** (visitors and pages,
search engines and **AI crawlers**, form statistics, "why do I see the browser
check?" — no security jargon), **customers** (the statistics link), **developers**
(activation, `requirePass`/`widget`/`consume`, the cache signal, API/facade,
adapters, plugins, `expect`), and **AI agents** (`llms.txt`, `docs/llm/*`).

Plain language as a rule (`CONTRIBUTING.md`, `tests/DocsStyleTest.php`): short
sentences; every term explained at first use or linked to `docs/glossary.md`;
numbers with a unit and a comparison; no page without a diagram. UIs explain
themselves: one sentence per section, a `?` link with the RSF id to the docs
anchor (`set docs-url`), empty states that say how to get data, error messages
that end with a docs anchor; a `Help` service in the core renders sentence and
link from `Vocabulary` data; `tests/UiHelpTest.php` checks every page; the
docs' screenshots come from the demo (`examples --html`).

Generated reference, never hand-maintained: `src/Rules/Vocabulary.php` replaces
the four hand-kept word lists in `RuleFile`; `docs/reference/{rule-files,settings,cli,api}.md`
come from it (`gen-reference.php --check`); `request-shield vocabulary` serves
the same from the installed file. Diagrams from a small text DSL
(`docs/diagrams/*.dg` → house-style SVG via `docs/tools/diagram.php`).

For AI agents: `llms.txt` at the root (start here / understand / never),
`docs/llm/install.md`, `write-rules.md`, `check.md`, and the two prompts in
`docs/llm/prompts/` ([install](../llm/prompts/install.md),
[review](../llm/prompts/review.md)). A `request-shield init --app=…` command
writes a commented starter rule file in `monitor` mode and refuses paths inside
the docroot.

## Repository, packaging, releases

### Options

| Criterion | **A. Monorepo + read-only split mirrors** | **B. Multi-repo (org) + meta repo** | **C. Monorepo, Composer path repos only** |
|---|---|---|---|
| One-file build | unaffected | unaffected | unaffected |
| Independent plugin release cadence | yes, tags `stats/v1.2.0` | yes, natively | **no** — path repos are not installable by third parties |
| Cross-cutting change (hook + plugin + docs + test) | **one PR, atomic** | 2–3 PRs, release ordering | one PR |
| Test coupling | one runner, plugin tests see the core at HEAD | plugin CI must install a core version | as A |
| Composer / Packagist | every mirror is a normal package | natural | only the core |
| Docs (humans + AI) | **one place** | scattered, links rot | one place |
| Small-team effort | one clone, ~40 lines of YAML | N repos × settings/secrets/rules | lowest — until a plugin must be installable alone |
| Security advisories | one `SECURITY.md`, GHSA names the Packagist package | N inboxes | one |
| Risks | split action needs a PAT (mirrors only), pin by SHA; mirrors confuse (issues off, banner) | drift; a core change breaks plugin CI a day later | the core dist stays fat |

**Recommendation: A.** Security, speed and docs want *one* place where a change
lands with its tests, bench numbers and docs; the plugin API is explicitly not
stable yet (ADR 0006) and multi-repo punishes exactly that phase; the one-file
build and the AI story are core-only and indifferent. A mirror can become a
real repository with full history later.

### Layout (A)

Nothing is live, so the rebuild is free. Recommendation nonetheless: the
**core stays the root package** (smallest rebuild; docs/CI paths stay;
`git subtree split` only needs the subdirectories). `core/` is an equally valid
choice at the cost of one move commit.

```
/                                   monorepo = core package cjw-network/request-shield
├── composer.json                   require php>=8.0; psr-4 src/; bin/; require-dev: path repos plugins/*, adapters/*, testkit
├── bootstrap.php  bin/  src/  rules/ (incl. rules/app/{wordpress,drupal,symfony,ibexa,exponential}.rules)
├── build/single-file.php           → request-shield.php (mini), -waf.php, -stats.php, -api.php
├── plugins/
│   ├── api/        cjw-network/request-shield-api      (RSF6.5: endpoints, OpenAPI, in-process facade; …\Api\)
│   ├── waf/        cjw-network/request-shield-waf      (Report/* pages; requires request-shield-api; …\Waf\)
│   └── stats/      cjw-network/request-shield-stats    (…\Stats\; requires request-shield ^1.0)
├── adapters/
│   ├── wordpress/  cjw-network/request-shield-wordpress   type wordpress-muplugin; + release zip
│   ├── drupal/     cjw-network/request-shield-drupal      type drupal-module
│   ├── symfony/    cjw-network/request-shield-bundle      Symfony bundle; Ibexa part conditional
│   └── exponential/ cjw-network/request-shield-exponential
├── testkit/        cjw-network/request-shield-testkit   (runner + helpers; published on demand)
├── docs/  examples/  bench/  tests/  .github/workflows/{tests,static-analysis,release,split,crawler-lists}.yml
```

Every subpackage: its own `composer.json` (`require: cjw-network/request-shield ^1.0`,
CMS type for `composer/installers`), `LICENSE`, `README` (absolute links into
the monorepo), `CHANGELOG`, `SECURITY.md`, `.gitattributes`, `tests/`.
`split.yml` mirrors each subdirectory to `github.com/<org>/request-shield-<name>`
(read-only, issues off), registered on Packagist. A tag `wordpress/v1.2.0` in
the monorepo becomes `v1.2.0` in the mirror. Development stays one clone.

### Versions, releases, signing

- `vX.Y.Z` = core + single-file release; `<pkg>/vX.Y.Z` = a plugin or adapter.
  `Shield::VERSION` literal; the release job refuses a mismatch with tag and
  changelog. **v1.0.0** = the first release with the split, the single file and
  this pipeline.
- **MAJOR** for: `Plugin`/`Extension` signatures (new capabilities = new
  interfaces = MINOR), the public API (`protect/protectFile/active/requirePass/consume/widget`,
  `Request/Decision/Seen/Store`, `Settings` properties, `$_SERVER` keys, debug
  header), removing or redefining a rule word, removing a rule id, removing a
  CLI command or exit-code meaning, the store layout without migration, raising
  the PHP floor. **Not** SemVer-relevant: `Settings::FORMAT` (a cache tag).
- `rules/` stays in the core (calendar `version 2026.10.1`, own changelog);
  `crawler-lists.yml` opens a PR instead of failing.
- `release.yml` on a `v*` tag: tag on `main`? version = `Shield::VERSION` =
  changelog section? → build twice + `cmp` → suite against the file →
  `SHA256SUMS` → **minisign** signature (Ed25519; verified with `minisign` or in
  pure PHP via `sodium`; key in `README`/`SECURITY.md`/`Shipped::PUBKEY`) +
  `actions/attest-build-provenance` → GitHub release with `request-shield.php`,
  `.minisig`, `SHA256SUMS`, later the other editions and the WordPress zip.
  Secrets in the `release` environment with a required reviewer; tag ruleset
  `v*`/`*/v*`. Actions pinned by SHA.
- CI legs: PR = 3 (8.0 file source, 8.4 APCu source, 8.4 APCu **single**) +
  minimal hosting + static; push to main = 12 source + 2 single; nightly = all
  + determinism check.
- Branches: delete the 27 merged ones, auto-delete on merge; prefixes
  `feature/ fix/ docs/ proposal/ ci/ chore/`; squash on main with
  "Added:/Fixed:/Updated:"; a PR template; ruleset on `main` (PR required, 0
  approvals while solo, linear history). Proposals stay the planning unit;
  drafts are `draft-<slug>.md`, the number is assigned when the PR opens; a test
  catches duplicate numbers and unlinked proposals.
- **Naming (owner decision, cheapest before Packagist/v1.0):** own org
  `request-shield/*` vs. `cjw-network`. Keep the PHP namespace
  `CjwNetwork\RequestShield` either way.

## Phases

| Phase | Contents | Verified by |
|---|---|---|
| **0 Documents** | this proposal + [0031-steps.md](0031-steps.md), 0002, `llms.txt`, the two prompts, renumbering 0032/0033, `docs/README.md`, ADR placeholders, `DocsTest` | links valid, numbers unique |
| **A Robustness** | fail-safe wrapper, compile fallback, `RobustnessTest`, bootstrap search order, `Shield::VERSION` + `version` | review prompt A1–A3 |
| **A3 Tiers** | cache path → `store-dir/cache`, S0 path, APCu uses behind a capability, `curl` fallback, `check`/`version` name the tier, `HostingTiersTest`, CI leg | A5 |
| **A2 Bytes** | `X-RS…`, `rsp/rss/rsd`, pass cookie v2, internal headers removed, `WireBytesTest`, bytes in the bench | B4 |
| **B Decoupling** | `$ext/$hooks/$routes`, `Extension` + `Vocabulary`, stats words out (B.3 -- deviation: `stats-group`, `stats-access`, `stats-session`, `stats-path` stayed in the core because `Access` and `Frame` read them and the core must not read an extension's slot; B.5 and B.7 move them), `Shield.php:506` gone (B.4), routes registry (B.5: `stats-path` moved into `ext.stats`; routes may lie outside `dashboard-path` for now -- B.6 decides), the shield serves routes (B.6: `Dashboard`, `RoutePage`, `Response`; a route's `page` class; every offered extension compiles), `Access` generalised (B.7: `dashboard-access`/`dashboard-session`, opaque principal; `stats-group` into the stats extension, `Access::site()` → `StatsExtension::siteFor()`), `RuleCounts` (B.8: the first entry in `$s->hooks`, recorded by instanceof), `Sink` (B.9: `Live` the first), `Pages` (B.10: error, challenge, access-login) | `grep Stats src/ bin/ bootstrap.php` = 0 |
| **C Rule chain** | `Step`/`chain()` (C.1: `Rule\Step`, inactive stages with a null rule, the inspector walks it), `explain()` into the rules, `Inspector`/`SetupPage` derived, `RuleProvider`, `Handler` | the `fail-at` test extension |
| **D CLI + namespace** | dispatch table, plugin commands, `…\Stats\`, `plugins.md`, ADRs accepted | CLI tests |
| **E Single file** | `Shipped`, `build/single-file.php`, `REQUEST_SHIELD_ENTRY`, `SingleFileTest`, CI leg, `release.yml`, `verify/self-update`, `init`, `rules/app/*`, `docs/llm/install.md` tested literally | D1–D5, F1–F3 |
| **F Feature contract** | RSF ids in docs and test names, `FeatureContractTest`, `# demo:` groups, `ua`, `DemoSite/ExamplesPage`, `examples --markdown/--html/--coverage`, `Vocabulary` → `docs/reference`, glossary, `docs/for/*`, `Help` service, `UiHelpTest`, `DocsStyleTest`, gaps closed | E1–E6, C3, H3–H5 |
| **G First consumers** | **API plugin**, 0030 error pages as `Pages`, HTTP cache as `Handler` plugin, WAF backend as `request-shield-waf.php` | bench 0 µs without plugins; facade = HTTP |
| **H Split + v1.0** | `split.yml`, mirrors, Packagist, `testkit/`, org decision | `composer require` from a mirror |
| **I CMS adapters** | `rules/app/*` with `expect` and demo groups; packages Exponential → WordPress → Symfony/Ibexa → Drupal | E2E against pseudo-CMS; C2 stays 0 |

Every phase: tests (new ones fail without the change), `bench/overhead.php`
before and after in the PR, `composer phpstan` and `taint` clean, docs and
changelog in the same change, the step ticked in `0031-steps.md`.

## Open questions for the owner

1. **Editions:** only mini + separate plugin files (recommended), or also `full`?
2. **Org/vendor:** keep `cjw-network` or move to `request-shield/*` — decide before Packagist.
3. **`@wordpress` → `@not-wordpress`** and `rules/app/wordpress.rules` as protection *for* WordPress — agreed?
4. **A hosted live demo** on the owner's server (for the check and the widget), in addition to the recorded Pages demo?
5. **Drafts:** [0032 check levels](0032-check-levels.md) against 0004 modes (possibly obsolete), [0033 event log](0033-event-log.md) against the `Sink` hook.
