# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Use case: a pentest asks for rate limits on the forms**
  (`docs/use-cases/form-rate-limits.md`): forms built by a script and sent as
  JSON to one endpoint get `allow POST`, `post-origin same` and a budget on
  the endpoint, watched first, with `expect` lines as the proof; one limit
  per kind of form through `consume()`; the limits named (the 429 is HTML
  until 0030, per address only). Proposal **0034 budgets for everyone
  together** (Draft): one counter for all senders of a form, and how not to
  lock out real applicants with it. Proposal 0030 answers in JSON a request
  that sends JSON. The use case shows the browser check before sending: on
  opening a job offer (rules only, with its costs), or when the form is sent,
  its script solving the task (ten lines, tried against a real server);
  proposal **0035 the check for forms that send JSON** (Draft) makes that a
  promised `RS.fetch()`. Proposal **0036 forms per page** (Draft): for each
  page, how often it was viewed, how often a form was sent from it and how
  often that succeeded -- the outcome per page, an optional header that says
  how it ended, more pages per form, the check's task not counted as a send.
- **Docs for people, by role, in plain language** (0031 step F.8):
  `docs/for/` has a page each for admins, hosters, editors, customers and
  developers -- what they see, what the numbers mean, what to do when, and a
  typical day, each with its diagram; `docs/glossary.md` explains 36 words.
  CONTRIBUTING has a section "Plain language", and `tests/DocsStyleTest.php`
  checks it: glossary words linked, no sentence over 30 words on the pages
  for people, and a picture on every page (the pages still without one are
  listed, 0031 F.9 empties the list). The browser check's explanation has
  shorter sentences.
- **Diagrams from text, and the demo on GitHub Pages** (0031 step F.7):
  `docs/diagrams/*.dg` -- a title, rows of boxes, arrows, a note -- become
  SVGs in the shield's house style, light and dark (`docs/tools/diagram.php`,
  `--check` in the tests); the first three show blocked paths, attack
  patterns and the single file on their pages. `.github/workflows/pages.yml`
  publishes the demo as `request-shield examples … --html` records it, with
  the diagrams (the repository's Pages setting must be "GitHub Actions").
- **The feature contract is met** (0031 step F.6): every feature has its page,
  its tests, a demo group with an effect and a near miss (or its reason in
  `examples/demo/.demo-exempt`: settings, the single file, plugins, the two
  that the page's every row already shows -- examples and rule IDs -- and the
  two the page itself decides), and every request-path feature an end-to-end
  test; the contract's lists of gaps are empty.
- **The demo shows site blocks and strict mode** (0031 step F.6, RSF05-01):
  `site strict.example { set mode strict … }` -- a rule of one website, and
  strict mode there (eleven made-up addresses count as 22); the other website
  is not touched. The demo page and `request-shield examples` read the
  examples inside site blocks too, each row knows its website.
- **Blocked paths have their page** (0031 step F.6, RSF02-02):
  `docs/features/RSF02-02-blocked-paths.md` -- `@scanners` and `@wordpress`
  rule by rule, a path of your own, taking one back or opening it somewhere,
  what it costs, and that on a site without a front controller the shield
  sees only what reaches PHP. Every feature has its page now.
- **Attack patterns have their page, and the demo shows them** (0031 step F.6,
  RSF02-06): `docs/features/RSF02-06-attack-patterns.md` -- what `@attacks`
  looks at, the 15 rules, a pattern of your own, what it costs (about 1.4 µs
  per request, about 3.5 µs with a free-text parameter) and where it stops
  (no form bodies); the demo includes `@attacks`: script and disguised SQL in
  the search, an attack tool by its name, a search that only looks alike.
- **The demo shows forms only from the website itself** (0031 step F.6,
  RSF02-04): `post-origin same missing allow` -- a form sent from another
  website is refused, one from the site's own pages passes, one without Origin
  and Referer too. Every request-path feature has an end-to-end test now.
- **The demo shows a crawler that proves who it is** (0031 step F.6,
  RSF01-04): DemoBot, a crawler of the demo's own with its address list in
  `examples/demo/crawlers/demo-bot.json` (documentation ranges), blocked by the
  site -- from its addresses it is believed, the same name from elsewhere is an
  ordinary visitor.
- **`feed <name> from <file>`, and the demo shows a blocklist** (0031 step
  F.6, RSF01-03): a list of the site's own in a file beside the rules -- read
  when they compile, watched like a rule file, never fetched and never too
  old (`feeds … update` leaves it alone); for a server without the internet,
  or a demo. The demo keeps out `feeds/demo-blocklist.txt` (documentation
  ranges). `request-shield test` decides a feed line's example instead of
  skipping it as not in effect.
- **The demo shows the deny list and bans** (0031 step F.6, RSF01-02): an
  address kept out (`[DEMO-DENY] deny 203.0.113.66`) next to its neighbour,
  and a scanner banned at its sixth refusal (watched in the demo, decided
  switched on by `test`). `DemoTest` proves a watched rule by the log line on
  the page, not by the answer.
- **The demo shows trusted proxies** (0031 step F.6, RSF01-01): a group with
  the admin area behind the proxy `198.51.100.1` -- through it the visitor's
  address decides, the header from anyone else is not believed. The page's
  answer reads `X-Forwarded-For` as the rules do (`Inspector::request()` takes
  the trusted proxies).
- **Each demo group is its feature's end-to-end test** (0031 step F.6): `DemoTest`
  runs every `# demo:` group on a real server under the feature's id -- each
  `expect` row sent through the shield as a real request from the example's
  own address (the test server trusts 127.0.0.1 as a proxy:
  `trust ${REQUEST_SHIELD_DEMO_TRUST:-…}` in the demo's rules), its answer
  (status, `X-RS`) held to the row, and the page's own answer too. The feature
  contract reads the test names as the runner loaded them. Request-path
  features without an end-to-end test: 10 → 3.
- **The reference and the examples from one source** (0031 step F.5):
  `src/Rules/Reference.php` holds every rule and set key in words (taken from
  the rule files page's two tables, which are written from it now, plus rows
  for `match`, `site`, `ids`, `version`, `replace`, `plugin`);
  `docs/tools/gen-reference.php` writes those tables and `docs/reference/`
  (`rule-files.md`, `settings.md`, `cli.md`), each row with its feature;
  `docs/tools/sync-examples.php` puts each feature's demo group into its page.
  New commands: `request-shield vocabulary [--json]` and `request-shield
  examples <rules> --markdown [--feature=…] | --html [--out=…] | --coverage`
  (the `--html` page is the demo recorded by `test`, for a static host).
  Both tools have `--check`, which the tests run.
- **The demo from its rules** (0031 step F.4): the demo's front page draws its
  rows from the `# demo:` groups at the end of `examples/demo/request-shield.rules`
  (13 groups, one per feature, 53 examples -- `request-shield test` decides
  the same lines), through `Report\DemoSite` and `Report\ExamplesPage`;
  "Show the answer" is decided on the server (`/__answer`) with the live rules
  and store, nothing counted; the site's pages moved to `pages.php`.
  `DemoTest` goes through every group and row. `expect … by built-in` names a
  fixed check without an ID; `Shield::store()` gives the store a shield counts
  in.

- **Demo groups and more for `expect`** (0031 step F.3): `# demo: RSF02-06
  <slug> <title>` opens a group in a rule file -- the comment lines below
  explain it, the examples after it belong to it -- and `# try: GET /path
  <what to look at>` is a row that is shown, not decided; `expect … ua
  "<User-Agent>"` sets the visitor's User-Agent, and a header value may be
  quoted (spaces, `\"`). Each example's result carries the status and the
  headers the visitor would get (`Responder::headerLines()`). The feature
  contract reads the groups from the parser now.
- **The feature contract** (0031 step F.2): `tests/FeatureContractTest.php`
  holds every feature of the docs index to its parts -- a page, tests under
  its id, an end-to-end test on the request path, a `# demo:` group with an
  effect and a near miss (or a reason in `examples/demo/.demo-exempt`) -- and
  every rule word and set key to its feature (`Vocabulary::FEATURES`); the
  other way, every id a test, a demo group or a list names must be a feature.
  What is missing today is a list in the test that only shrinks (step F.6
  empties it): two features without a page, 22 without a demo group, 10
  without an end-to-end test under their id.
- **Feature ids** (0031 step F.1): every feature page's title begins with
  its id `RSF<gg>-<nn>` (`# RSF02-03 Access rules …`) and its file is named
  by it (`docs/features/RSF02-03-access-rules.md`), `docs/README.md` lists
  the features as a table by id -- the five without a page of their own yet
  (RSF02-02, RSF02-06, RSF04-02, RSF05-06, RSF06-05) named where they are documented
  or planned -- and every feature's tests begin with its id.
  `php tests/run.php RSF02-06` runs exactly that feature's tests, `RSF02` its
  group. RSF05-07 is new: the single file and its releases.
- **The guides for agents** (0031 step E.7): `docs/llm/install.md` (find
  the places, download and verify, `init`, the shield's own directory for the
  PHP user, switch it on and see that it is on, see it work, report),
  `docs/llm/write-rules.md` (what to look at in an application and which line
  each finding becomes, every example checked with `test`) and
  `docs/llm/check.md` (`check`, `test`, `trace`, the log, when to enforce).
  The install guide was followed literally in a `php:8.0-apache` container;
  `docs/llm/install-dryrun.md` holds the log and what the first pass found:
  a shield directory PHP could not write to (the shield let everything
  through and logged nothing -- `check` as root did not show it), and
  `/.env` never reaching PHP on a site without a front controller.
  `tests/LlmDocsTest.php` holds every command and option the guides name to
  the command line. The install prompt and `llms.txt` follow them.
- **`init`, `verify`, `self-update`** (0031 step E.6): `request-shield init
  --app=plain|wordpress|symfony|exponential` writes a commented starter rule
  file in monitor mode (refused inside `--docroot`, or over a file without
  `--force`); `verify <file>` checks the checksum from `SHA256SUMS` and, with
  a release key and sodium, the minisign signature (pure PHP,
  `Release\Minisign`); `self-update [--check] [--to=vX.Y.Z] [--major]`
  replaces the single file and the statistics file beside it with a signed
  release, every file checked before any is replaced, the old ones kept as
  `.prev`. Until the release key exists, `verify` checks the checksum only and
  `self-update` refuses.
- **The release workflow** (0031 step E.5): a tag `vX.Y.Z` on `main` is
  checked (`build/release-check.php`: `Shield::VERSION` and the changelog's
  section name it), every edition is built twice and compared, the suite runs
  against the built file, and `request-shield.php`, `request-shield-stats.php`
  and `SHA256SUMS` are published with a build provenance attestation and the
  changelog's section as notes. Actions pinned by commit. The minisign
  signature comes with the release key, later.
- **CI plans its legs per event** (0031 step E.4): a `plan` job asks
  `build/ci-plan.php` -- a pull request runs 3 legs (PHP 8.0 file store, 8.4
  APCu, 8.4 APCu against the single file) plus minimal hosting, a push to main
  14, nightly and by hand all 24 plus a check that two builds of every edition
  are byte-identical. `tests/CiPlanTest.php` holds the numbers.
- **The suite runs against the single file** (0031 step E.3):
  `REQUEST_SHIELD_ENTRY=<built request-shield.php> php tests/run.php` loads
  the built file (and the statistics file beside it) instead of
  `bootstrap.php`; every end-to-end server and command-line test takes the
  library from `rsEntry()` and `rsCli()`. CI runs it as the leg `single` and
  checks the built file with `php -l` on every PHP version.
- **The single file** (0031 step E.2, proposal 0002): `php build/single-file.php`
  builds `request-shield.php`, the mini edition in one file -- the core, the
  command line and the shipped rule sets embedded; included it protects the
  site with the rules it finds (bootstrap.php's order), run directly it is the
  command line. `--edition=stats` builds the statistics as
  `request-shield-stats.php`; `waf` and `api` say which step they wait for.
  Deterministic; every declaration is conditional, so a second include (with
  or without OPcache) does nothing. `tests/SingleFileTest.php` builds both and
  puts a request through the file; `bench/single-file.php` compares it with
  the source tree. See [the single file](docs/features/RSF05-07-single-file.md).
- **`Rules\Shipped`, the one reader of `rules/`** (0031 step E.1): the
  shipped rule sets, the feed catalog, the crawlers' address lists and the
  ready crawlers are read through one class, so the single-file build (E.2)
  can embed them -- with its constants filled nothing reads `rules/`, and the
  file that holds the data is watched instead (an update rebuilds the
  settings). `Settings::RULES_DIR` and `RuleFile::shipped()` are gone (a
  plugin calls `Shipped::crawlers()`, `Shipped::rules()` …); a shipped
  address list is named `@<name>` where a path stood
  (`RuleFile::crawlerListFiles()`, `CrawlerLists::read()`/`update()`).
  `RuleFile::shippedReady()` is what `rules/crawlers.php` holds, as an array.
- **The plugins guide rewritten around extensions** (0031 step D.3):
  `docs/features/RSF06-04-plugins.md` explains the two interfaces (`Extension` at
  compile time, `Plugin` per request), the capabilities, `plugin … from`, the
  served pages and the commands in one place; ADR 0008 (extension points
  resolved at compile time) and ADR 0009 (plugins tighten, never loosen) are
  accepted. With this, phase D of 0031 (CLI and namespace) is complete.
- **The statistics plugin in its own namespace, and plugins from a file**
  (0031 step D.2): `plugins/stats/src` is `CjwNetwork\RequestShield\Stats\…`
  (`Stats\StatsExtension`, `Stats\StatsPlugin`, `Stats\Report\StatsPage`, …;
  composer.json and the bootstrap's autoloader map it); `plugin <class> from
  <file>` names the file that holds a plugin for a site without Composer --
  loaded when the rules are compiled and when the shield makes its plugins, a
  file that is not there is a warning (`check` names it) and the plugin is
  left out. Compiled settings format 50.
- **The extensions' commands** (0031 step D.1): `Extension::commands()`
  names the commands an extension adds to `bin/request-shield` (name => a
  `Cli\Command` with `usage()` and `run(Cli\Context)`); the script runs them
  from a table, after reading the rules and parsing the common options, and
  its usage lists their lines. `stats` is the statistics plugin's command now
  (`plugins/stats/src/Cli/StatsCommand.php`); its syntax is unchanged.
- **The Handler capability** (0031 step C.4): a plugin answers a passing
  request itself, after every rule, the check and the shield's own pages --
  an HTTP cache hit, a page of its own -- with a `Response` that goes out as
  it is; null means on to the application, a failure is noted once a minute
  and the application runs; one array access when no plugin has it.
  `Request::cacheKey()` names an answer for a cache (scheme and host lower
  case, the routed path, the parameters sorted). Compiled settings format 49.
  With this, phase C of 0031 (the rule chain) is complete.
- **The RuleProvider capability** (0031 step C.3): a plugin adds rules of
  its own to the chain -- steps with a key, a stage (never before the lists)
  and a rule; the shield puts each after its stage's steps, the trace and the
  rules page show it like any other, `Rule::explain()` names it. A provided
  rule can only tighten; one that throws says nothing for that request and
  is noted once a minute, a provider that throws adds nothing. The rules page
  draws "the way" from the chain (the post-origin step was missing there).
  Compiled settings format 48.
- **Each rule names its own decisions** (0031 step C.2): `Rule::explain()`
  gives the rule behind a decision (the ID or file:line it was written at,
  `built-in` for a fixed check) and null for any other; `Shield::explain()`
  asks the chain's rules and names only what no rule produces (an
  always-checked path, a `check` feed, the application's own check, a budget
  the site counts itself). The log and the pages read as before.
- **The rule chain is one list** (0031 step C.1): `Shield::chain()` returns
  the steps the shield checks, in order, each with a key and a stage
  (`Rule\Step`, derived from the rules when a trace or a page asks -- a
  request pays nothing; a step whose settings are not in use has no rule);
  `request-shield trace` and the rules page's tester walk that list instead of
  a copy of their own -- a rule added to the chain shows up in the trace by itself.
- **The Pages capability** (0031 step B.10): a plugin draws the pages the
  shield answers with itself -- the refusal page (`error`), the browser check
  (`challenge`) and the dashboard's login form (`access-login`) -- in the
  site's look; null or a failure means the shield's own page (a failure is
  noted once a minute). Headers and cookies stay the shield's; asked only when
  the shield answers itself, never for a passing request. Compiled settings
  format 47. With this, phase B of 0031 (decoupling) is complete
  ([docs](docs/features/RSF06-04-plugins.md#capabilities-what-a-plugin-can-do-for-the-pages)).
- **The Sink capability** (0031 step B.9): `Log::note()` hands its record --
  every request the shield did something about -- to the live view and to
  every plugin with `Sink` (`$s->hooks['sink']`, recorded when the rules are
  compiled); a sink that throws is left out for that request and noted in
  PHP's error log once a minute, the log and the other sinks still get it. The
  live view is the first sink (`Live` implements it). Compiled settings format
  46 ([docs](docs/features/RSF06-04-plugins.md#capabilities-what-a-plugin-can-do-for-the-pages)).
- **Capabilities on plugins, the first: RuleCounts** (0031 step B.8): the
  compiler records which plugin class implements which capability into the
  compiled settings (`hooks`, by `instanceof` when the rules are compiled); the
  rules and setup page asks `Report\Counts`, which asks the plugins with
  `RuleCounts` (the statistics plugin has it) -- the core's pages name no
  plugin any more. Rules & setup is the core's page in the core's frame with
  its own styling; the statistics page has no `rules` view (its tab leads to
  the core's page). Compiled settings format 45
  ([docs](docs/features/RSF06-04-plugins.md#capabilities-what-a-plugin-can-do-for-the-pages)).
- **Who may open the dashboard is the core's, who sees what is the pages'**
  (0031 step B.7): `dashboard-access "<principal>"|* sha256:<hash> [until
  <day>]` and `set dashboard-session` replace `stats-access` and
  `stats-session`; the principal is an opaque id to the shield (`"Customer A"`
  → `customer-a`, a signed link or a token for any id holds), and the
  statistics map it to the `stats-group` of the same name -- `stats-group` is
  the statistics extension's word now (`ext.stats.groups`), a principal
  without a group sees no statistics and `check` says so. The CLI command is
  `access-token` (was `token`). Compiled settings format 44
  ([docs](docs/features/RSF06-03-statistics.md#who-sees-what-tokens-a-login-signed-links)).
- **The shield serves the dashboard's pages itself** (0031 step B.6): a
  request for a route below `dashboard-path` (`/rs/waf/live`, `/rs/stats/…`,
  an extension's own) is answered before the application -- behind
  `Access::gate()` and the route's role (`admin` pages refuse a customer with
  403), `no-store` and `noindex` on every answer, a POST change with the
  page's token. A route's `page` names the class that serves it
  (`RoutePage::serve()` returns a `Response`); the core's pages, the
  statistics' and the test extension's have one. A route nobody guards (no
  `restrict` rule covers it, no login) answers 403, and `request-shield check`
  warns, naming the rule to add (`restrict /rs/** to <addresses>`). The demo
  wires nothing any more; a customer asking an administrator's page gets 403
  instead of a reduced view. Every offered extension now compiles, with an
  empty slot when the rules said nothing of it, so its defaults, routes and
  plugins apply ([docs](docs/features/RSF06-04-plugins.md#extensions-words-and-settings-of-their-own)).
- **`Extension::plugins()`**: an extension names the `Plugin` classes to run per
  request given its compiled slot; the compiler appends them to the settings'
  plugins, so `Shield` no longer adds `StatsPlugin` itself (0031 B.4) and the
  `check` warning about a missing statistics plugin comes from
  `StatsExtension::check()` (`request-shield check` asks every offered
  extension). `Vocabulary::set()` takes `serverWide` (refused inside a site
  block) and `many` (one key, several values: `set stats on|off|<parts>`),
  `Vocabulary::word()` takes `paths` (the parser compiles the paths, inside a
  match block the block's), and the type `path` is there for extensions.
- **Extensions: words and settings of their own** (0031 step B.2, ADR 0008):
  a class implementing `Extension` registers rule words and `set` keys with
  `Rules\Vocabulary` (`word()`, `set()`, typed like the core's); the parser
  asks the registry before it calls a word or key unknown, writes into the
  extension's slot `ext.<id>` only, and `Extension::compile()` checks the
  slot with the base settings in hand when the rules are compiled. Offered
  by `plugin <class>` (from that line on) or, for the shipped ones the
  bootstrap names (`REQUEST_SHIELD_EXTENSIONS`), on the registry's first
  lookup; a request pays nothing. `tests/support/RsTestExtension.php` is the
  smallest one (`set fail-at <stage>` for the fail-safe tests)
  ([docs](docs/features/RSF06-04-plugins.md#extensions-words-and-settings-of-their-own)).
- **Settings carry the extensions' slots** (0031 step B.1): `ext` (extension
  id => its checked settings), `hooks` (capability => the plugins that have
  it) and `routes` (the pages under `dashboard-path`) are the last constructor
  parameters of `Settings`, checked for shape, compiled, exported and imported
  like every other setting; the core reads none of them yet (B.2 onwards fill
  them). Compiled settings format 41: every installation compiles once more
  after the update, nothing to do.
- **The bytes on the wire have limits** (0031 step A2.3, ADR 0014 accepted):
  `tests/WireBytesTest.php` holds them as numbers -- the pass cookie at most
  48 bytes with its name (today 47), the check page at most 4096 bytes
  gzipped (today 4065), the headers the shield adds to the check page and to
  the answer that hands out the pass, and no header or cookie of the shield
  on a passing request unless `debug-header` is on; `bench/overhead.php`
  prints the sizes ([docs](docs/features/RSF03-02-browser-challenge.md#cost)).
- **Fail safe** (0031 step A.2, ADR 0007): an exception anywhere inside
  `Shield::protect()`, `protectFile()`, `requirePass()`, `consume()`,
  `widget()` or the check the application asks for lets the request through
  as `allow-uncached` with the reason `shield error`; PHP's error log gets one
  line a minute per cause -- across requests and workers too (APCu, else a
  marker in the store directory), so a broken deploy is one line a minute,
  not one per visitor. Before, a throwing hook or store was a 500 for the
  visitor. `tests/RobustnessTest.php`.
- **A rule file that does not compile never takes the site down** (0031 step
  A.3): on the request path the last good compiled settings stay in force
  (one line a minute in the error log, a `.failed` marker until the file
  changes); at a first install the shield runs switched off and says so; a
  compiled settings file cut short is deleted and compiled anew.
  `Settings::load()` for tools still throws; `check` is where a mistake is an
  error ([docs](docs/features/RSF05-02-settings.md#when-the-rules-cannot-be-compiled)).
- **The tier an installation runs at** (0031 step A3.1, ADR 0013): `check`
  and `version site.rules` print S0 (no writable directory, no APCu: the
  stateless rules only, settings compiled on every request), S1 (files) or
  S2 (APCu), and what is not active in it ([docs](docs/features/RSF05-02-settings.md#what-this-installation-can-do-the-tiers)).

### Changed
- **The command line is the class `Cli`** (0031 step E.2, first part):
  `src/Cli.php` holds what `bin/request-shield` was, `Cli::main($argv)`; the
  script is three lines that call it. The single file runs the same class
  when it is run directly. Commands, options, output and exit codes are
  unchanged; the tool's code is under PHPStan now.
- **The dashboard's pages are a registry** (0031 step B.5): `Settings::$routes`
  is compiled from the core's `<dashboard-path>/waf` pages (`Routes::core()`)
  and what every offered extension declares with
  `Extension::routes(array $compiled)` (its compiled slot in, full paths out):
  path => key, extension, tab labels (English, German), role `admin|reader`,
  order. Two routes on one path refuse to compile, naming both owners.
  `Frame::links()`, `isPage()`, `pageFor()` and `tabs()`, `StatsPage::links()`
  and `viewFor()`, `Shield::dashboardOnly()` (the pace's exemption for the
  dashboard's own requests, now without `Report` and without the stats path)
  and `Access::links()` (a reader's tabs are the routes whose role is `reader`)
  derive from it; `Frame::TABS` is gone, `Frame::tabs()` and `Access::links()`
  take the settings as their first parameter. `set stats-path` is the
  statistics extension's (`'ext' => ['stats' => ['path' => …]]` in PHP arrays,
  default `<dashboard-path>/stats`); `Settings::$statsPath` and `statsPath()`
  are removed. Compiled settings format 43: every installation compiles once
  more after the update, nothing to do.
- **The statistics are an extension** (0031 steps B.3, B.4):
  `plugins/stats/src/StatsExtension.php` registers `set stats`, `stats-flush`,
  `stats-months`, `stats-depth`, `stats-hours`, `stats-days`, `stats-hosts`,
  the `crawler-log` keys and the word `stats-skip` with `Rules\Vocabulary`
  (the rule-file syntax is unchanged) and compiles them into `ext.stats`
  (`enabled`, `parts`, `hours`, `days`, `months`, `flush`, `depth`, `hosts`,
  `skip`, `crawlerLog`), which `StatsPlugin` and the statistics' pages read
  through `StatsExtension::of($settings)`. The `Settings` properties
  `statsEnabled`, `statsHours`, `statsDays`, `statsParts`, `statsFlush`,
  `statsMonths`, `statsDepth`, `statsHosts`, `statsSkip`, `crawlerLogDir`,
  `crawlerLogKinds`, `crawlerLogDays` and `crawlerLogQuery` and the constant
  `Settings::STATS_PARTS` (now `StatsExtension::PARTS`) are gone; in a PHP
  settings array (`Settings::from()`, `Config::defaults()`) the keys live
  under `'ext' => ['stats' => [..., 'crawlerLog' => [...]]]` instead of the
  top-level `'stats'` and `'crawlerLog'`. `StatsPlugin` is appended to the
  settings' plugins by `StatsExtension::plugins()` when the rules are
  compiled -- the `Shield` no longer wires it. `stats-group`, `stats-access`
  and `stats-session` stay in the core until 0031 B.7 (`Access` and `Frame`
  read them); `stats-path` moved in B.5. The extension is only named
  (`REQUEST_SHIELD_EXTENSIONS`: by `bootstrap.php`, or with Composer by
  `plugins/stats/shipped.php` through the package's autoload `files`);
  `Rules\Vocabulary` loads and offers it when the rules are compiled, if
  `plugins/stats` is there -- a passing request loads no class for it. Compiled settings format 42: every installation
  compiles once more after the update, nothing to do
  ([docs](docs/features/RSF06-03-statistics.md)).
- **Shorter cookies** (0031 step A2.2, ADR 0014): the cookies are `rsp` (the
  pass; was `rs_pass`), `rss` (the solution; was `rs_solution`) and `rsd`
  (the statistics session; was `rs_stats`), and the pass cookie is
  `2.<expires base36>.<tag base64url 11>.<mac base64url 22>` -- 43 bytes
  instead of 66, the same 64-bit client tag and 128-bit MAC. A browser sends
  it with every request while the pass lasts; a v1 pass is not read any more
  (the visitor is checked once more). `cookie` and `solution-cookie` in the
  rule file still set other names.
- **Shorter header names** (0031 step A2.1, ADR 0014): the debug header is
  `X-RS: <action> <reason>; rule=<ID>` (was `X-Request-Shield`), a watched
  rule's verdict `X-RS-Monitor` (was `X-Request-Shield-Monitor`), and the
  site asks for the check with `X-RS-Check: 1[; fresh=N]` (was
  `X-Request-Shield-Challenge: required`) -- `rs` = RequestShield. The
  internal header is taken out before the page or the check page goes out
  (`tests/DemoTest.php`). No old name is read any more.
- **A CI leg "minimal hosting"** (0031 step A3.4, ADR 0013 accepted): the
  servers the end-to-end tests start run as a tight shared host would have
  them -- no APCu, no `allow_url_fopen`, the shell functions disabled, 64 MB
  (`TESTS_HOSTING=minimal`); `tests/HostingTiersTest.php` sends the same
  requests at S0, S1 and S2 and expects the same decisions.
- **Updates fetch with curl where `allow_url_fopen` is off** (0031 step A3.3):
  `feeds update` and `crawlers update` use `file_get_contents` or, without
  it, curl (`Http::get()`); where neither can, they say so and what to do
  (run the update elsewhere, copy `store-dir/feeds` and `store-dir/crawlers`).
- **The live view works without APCu** (0031 step A3.2): with the file store
  `set live on` keeps its rows in `<store-dir>/live.log` (full addresses, the
  log's line format, rotated past 500 KB, `live-keep` honoured) instead of
  needing APCu; `set store memory` is the only case that reads the log
  instead. Every use of APCu now goes through `Capability::apcu()`.
- **The defaults leave the system's temp dir**: the compiled settings go to
  `.request-shield/` next to the settings file, the store (counters, the
  secret, lists, feeds, statistics) to `.request-shield/store` there, unless
  `set store-dir` or `protectFile()`'s directory say otherwise. A shared host
  shares `/tmp` between customers; the owner's directory is theirs.
  `protect($array)` without a file keeps the temp dir.

### Added (continued)
- **`bootstrap.php` finds the rules on its own** (0031 step A.4): `REQUEST_SHIELD_CONFIG`
  if set (and then nothing else), else `request-shield.rules` next to it, else
  `config/request-shield.rules`, else `config/request-shield.php`. Before, a
  `.rules` file needed the variable; the README's three-step install now
  holds. `tests/BootstrapTest.php` runs a copy of the library.
- **`request-shield version [site.rules]`** (0031 step A.1): the library's
  version (`Shield::VERSION`, `0.4.0-dev` on main) and build, PHP, whether
  APCu is there, the shipped rule sets' versions; with a rule file also the
  store in use, the mode and the versions the site's files name.
- **The plan for v1.0** (proposal 0031, with its step list and resume prompt
  in `docs/proposals/0031-steps.md`): a robust core, compile-time extension
  points, a mini single-file edition without any backend (proposal 0002),
  hosting tiers, the `rs` prefix for headers and cookies, feature ids (`RSF…`)
  with docs, a demo and tests for every feature, the API and CMS adapters as
  plugins, the repository and release structure; ADRs 0007–0014 (proposed);
  `llms.txt` and the two prompts (`docs/llm/prompts/`) for AI agents; the two
  older drafts as proposals 0032 and 0033; `tests/DocsTest.php` keeps the
  numbers unique and the links whole.
- **Forms in the statistics** (proposal 0028, phase 2; the part `forms`): each
  form (POST, PUT, PATCH, DELETE outside `api-path`) with how many were sent,
  where from (the website's own page, "this website", another website by its
  host only, or none), and how they ended (saved, an error, stopped: refused,
  checked, told to wait, from another website). The card "Forms" on the
  visitors page (stopped links to the live view), `bin/request-shield stats`,
  the JSON. `backend <paths>` marks the editors' area: its forms count as one
  entry per area. Never what was typed
  ([docs](docs/features/RSF06-03-statistics.md#forms-sent-from-where-how-they-ended)).
- **Forms only from the website itself** (proposal 0028, phase 1):
  `post-origin same [missing check|allow|refuse] [except <paths>]`. A POST,
  PUT, PATCH or DELETE must come from one of the website's own names (the
  `host` rule's, the `site` block's, else the name it was sent to): `Origin`,
  else `Referer`. Another website: 403 ("a form sent from another website");
  neither header: the browser check by default (a pass gets through, the form
  is sent again). Never for `except` paths (payment callbacks, single
  sign-on), `api-path` and addresses let in; `monitor post-origin` to try it.
  In the trace ("Where forms come from"), the rules page, the log and the live
  view ([docs](docs/features/RSF02-04-forms-from-the-website.md)). The Exponential rules
  use it (`EXP-ORIGIN`). Examples take `header <Name>:<value>`
  (`expect POST /contact header Origin:https://evil.example 403`).
- **Examples next to the rules** (proposal 0029, phase 1): `expect <METHOD>
  <address> passes|uncached|answered|check|<4xx> [by <ID>] [from <address>]
  [with pass] [times <n>]` below a rule, and `bin/request-shield test` decides
  them, each on a fresh store, with the rules switched on (`--as-written` as
  they are), lists the site's rules without an example, exits 1 when one fails
  and writes JUnit for CI (`--junit`). Never part of the settings a request
  loads. The built-in `scanners`, `wordpress` and `tracking` rules and the
  Exponential rules carry examples (72 and 69); an instruction for language
  models to propose them: `docs/llm/write-rule-examples.md`
  ([docs](docs/features/RSF05-04-rule-examples.md)).
- **A budget for one area** (proposal 0008, second step): `limit` inside a
  `match` block, or `limit … at <paths>`, counts only the requests to that
  area (a search: 10 a minute, while reading pages never uses it up); also
  on demand, in site blocks, watched with `monitor`. An area's budget needs a
  name of its own, so the site-wide pace is never replaced by accident. The
  trace says "not counted at this address" elsewhere
  ([docs](docs/features/RSF03-01-budgets.md#a-budget-for-one-area)).
- **Rules for an Exponential site** (`examples/exponential/`): the frontend
  (`/content/view/…` and admin modules refused, internal files, downloads of
  uploaded archives, view parameters as numbers, a POST only where Exponential
  takes forms, the browser check before login and contact forms, the search's
  fields typed with its time filter and its own budget (a `limit` inside its
  `match` block), pace and a watched
  ban), and a main file for each kind of admin: the siteaccess `/admin`, or a
  host of its own. In monitor mode as shipped; checked against a crawl of a
  real installation; every rule explained in
  [docs/use-cases/exponential.md](docs/use-cases/exponential.md). A click demo
  (`php -S 127.0.0.1:8095 examples/exponential/router.php`): a pretend
  Exponential site with every case as a numbered test.
- **The demo's customer menu** (proposal 0023, phase 5): `/customer-menu`, a
  pretend hosting panel whose "Statistics" item is a signed link to Customer A's
  statistics only; two public demo tokens in its rules; a "Sign out" link on a
  customer's statistics page. `Access::gate()` takes `admin` (the site knows its
  administrator: everything, no form; `?rs-login=1` shows it anyway). The plugin
  guide: what the statistics plugin owns (its words, its address, its pages, who
  may read them) and what a plugin with pages should do the same way
  ([docs](docs/features/RSF06-04-plugins.md#the-statistics-plugin-a-plugin-with-pages-of-its-own)).
- **Access to the statistics per group** (proposal 0023, phase 4):
  `stats-access "Customer A"|* sha256:<hash> [until …]` (only the token's hash;
  `bin/request-shield token` prints a token and the line), `set stats-session`.
  `Access::gate()`: a login form (token by POST, `Origin` checked) and a signed
  session cookie, signed links from a customer's own panel (`Access::link()`,
  10 minutes, at most an hour), `Authorization: Bearer` for the JSON; wrong
  tries counted (10 a minute, then 429) and logged without the token. A
  customer sees only its group — the statistics page enforces it (`who`):
  no Rules & setup, no server overview, no live view or lists, the protection
  without the rules ([docs](docs/features/RSF06-03-statistics.md#who-sees-what-tokens-a-login-signed-links)).
- `set stats-path`: the statistics plugin's own address (default
  `<dashboard-path>/stats`); the core's pages stay at `<dashboard-path>/waf/`.
- Several websites read together (all of them, a group): every page, section,
  page not found and sitemap with its website in front (`a.de/news/x`), and the
  "path starts with" filter takes `a.de/news/`. The pages card, "In short" and
  the pages not found use the full width of the statistics page.
- **Groups of websites and an overview of all websites** (proposal 0023,
  phase 3): `stats-group "Customer A" a.de www.a.de b.de` — a group's
  statistics add its websites up, the website switch has a section per group,
  `bin/request-shield stats --group=`. The new first tab **All websites**
  (`/rs/sites`) shows each group with its websites, the rest and the other
  hosts side by side: page views, the change against the period before, people,
  crawlers, bots, stopped, not found, a small curve — sorted by traffic
  ([docs](docs/features/RSF06-03-statistics.md#all-websites-at-a-glance-rssites)).
- **`stats-skip <paths>`**: paths that are no pages of the site (a map
  proxy's tiles, an image resizer) are left out of the statistics when they
  pass; refused or checked they are still counted, and every rule applies to
  them as before. Also in match and site blocks
  ([docs](docs/features/RSF06-03-statistics.md#paths-that-are-not-counted-stats-skip)).
- **Statistics per website** (proposal 0023, phase 2): `set stats-hosts a.de
  www.a.de *.b.de` (or `host`, `sites`) — each named website counted in its
  own directory (`stats/hosts/<name>/`), any other Host as "other hosts" (a
  made-up name gets no statistics of its own). The pages get a website switch
  (all added up · each · other hosts), kept in every link and the JSON;
  `bin/request-shield stats --site=`. 0.15–0.35 µs per request to find the
  website ([docs](docs/features/RSF06-03-statistics.md#statistics-per-website)).
- The live view links each rule ID to where it is written: its line on the
  rules page, a list entry on the lists page (`LivePage::json(…, ['links' => …])`).
- **Public blocklists as feeds** (proposal 0025): `feed <name> [<https-url>]
  deny|check|count|ban-signal <n> [at <paths>]` — a catalog of ten lists
  (`rules/feeds.json`: Spamhaus DROP, DShield, Feodo, FireHOL level 1, ET,
  blocklist.de, Stop Forum Spam, Tor exits, AWS, Google Cloud; with their
  terms), fetched by cron (`bin/request-shield feeds <main.rules> update`:
  HTTPS, validators, at most as often as each list allows, a list that shrank
  to less than half kept), the site's own network and too-wide ranges taken
  out, compiled into the same table as the deny list (2–3 µs). `deny`: 403
  after the deny list; `check`: the browser check; `ban-signal <n>`: a signal
  counts n times; `count`: watched only. Never exempt addresses, trusted
  proxies, verified crawlers. `set feeds-max-age` (3 d): an older list is not
  used. `feeds … export --format=plain|nginx|nftables|ipset [--write=…]`: the
  deny list and the deny feeds as the fewest CIDR blocks, for the level below
  PHP. A `.htaccess` export was measured and not built: 2,000 ranges made a
  static file 8× slower ([docs](docs/features/RSF01-03-blocklist-feeds.md)).
- **The live view and the lists in the dashboard** (proposal 0026):
  `/rs/live` shows what the shield stops right now (the website, the address,
  the request, what happened, why in words, and where from: a list, a ban, a
  feed, the site's own rule, a built-in one, the pace, the crawler policy),
  updated every 3 s with a cursor, filters kept in the address, pause.
  `set live on` keeps the last 2,000 requests stopped in APCu with the full
  address for `live-keep` (1 h; never on disk; ~5 µs per stopped request), so
  "keep out" takes exactly that address; without it the log is read.
  `/rs/lists`: add an entry with a comment of one's own, extend, change,
  remove, search; the active bans with "lift" (`Store::marks()`); the guards
  of the command line plus never the viewer's own address; POST with a token
  (HMAC of the secret, address and hour). `set ban-keep file`: bans survive a
  restart of APCu. `Report\LivePage`, `ListsPage`, `LogTail`, `Frame`, `Live`
  ([docs](docs/features/RSF06-02-live-and-lists.md)).
- **IP lists and automatic bans** (proposal 0013): `deny <addresses> [until
  …]` keeps an address out with 403 before every other check; `exempt … until`
  lets one in for a while — never counted, never checked, still refused for
  blocked paths and attack patterns. The list files `allow.rules`/`deny.rules`
  in `lists-dir` (default `<store-dir>/lists`) hold only those lines and are
  kept with `bin/request-shield deny|allow|unlist|lists` (guards for trusted
  proxies and wide ranges). The compiled settings are rebuilt when an entry
  ends. `ban after <n> limits|refusals|checks|<budget> in <time> for <time>`:
  nothing but 429 with `Retry-After` for a while, longer for a repeat
  (`ban-growth`, `ban-max`), never for addresses let in, trusted proxies or
  verified crawlers, one ban for every website; `monitor ban` to watch first.
  The store has `mark()`/`marked()`. ~1 µs per request with APCu
  ([docs](docs/features/RSF01-02-ip-lists.md)). Big lists: the deny entries compile into
  one sorted table (`IpTable`), so loading the settings costs the same with a
  million entries as with none and a lookup 2–3 µs; while one request rebuilds
  the settings, the others keep the last ones (`bench/big-lists.php`).
- **Rules per website** (proposal 0024, phase 1): `site <names> { … }` blocks
  in one rule file — the rules outside them for every website, a block adds
  (and sets) its own: `match` blocks, `include`, `no-limit` of a base budget …
  Names exact, `*.domain` (one label) or `default`. Which name decides: `set
  site-from server-name` (the default — the web server's, not the visitor's)
  or `host`. Compiled per website with the base, all checked together; a
  request loads its website's settings without a second check. Budgets of the
  base count across all websites (the defence); a `limit` in a site block
  counts on that website only, under its own counter (`<site>@<name>`, also when
  the browser check frees it). `check` lists the sites, `trace` takes the
  website from the address.
- **Plugins** (proposal 0023, phase 1): the core tells its plugins what it
  decided (`decided()`) and how a request the site answered ended (`ended()`,
  with the status and headers); `Seen` gives them the website, the known crawler
  and whether its address proves it, a bot's family — worked out on demand.
  Named in a rule file (`plugin <class>`) or the PHP settings (`'plugins'`);
  read only; an error in a plugin is logged and never reaches the visitor;
  `check` warns about one it cannot find. No plugin, no cost. A guide with a
  tested example: [docs/features/RSF06-04-plugins.md](docs/features/RSF06-04-plugins.md).
- **The visitors page** ("Visitors & pages", proposal 0022 phase 1): six
  numbers with their change against the period before (page views by people,
  requests by people, crawler visits, bot requests, stopped, not found), one
  chart of the number picked with the period before dashed, "now" (people's
  requests in the last 5 minutes, with APCu), and two cards with tabs: pages
  (pages, sections, stopped, not found) and crawlers & AI (search, AI
  assistants, AI training). Periods: also this month, last month and any range
  (`from`/`to`). Tabs and the chart are radio buttons and CSS — no script; the
  minute refresh keeps what was picked. `StatsReport::periods()` reads only the
  numbers of a span; the buckets count page views (`views`); the report lists
  the pages stopped most (`stopped`). 4–6 ms to render, 7–8 KB gzipped.
- A friendlier check page: a ring around the site's logo that fills with the
  browser's progress while a dot circles it, a smile when it is done (the page
  goes on at once, nobody waits for it), a calm "!" when the check cannot
  finish; dark mode, `prefers-reduced-motion`, nothing moves without
  JavaScript. `set challenge-logo logo.svg` puts the site's own logo in the
  middle -- read once when the settings are compiled, checked strictly (no
  scripts, handlers, outside links or styles; at most 16 KB) and inlined. Inline
  SVG and CSS only: the page is 8.7 KB instead of 6.2 KB (4.1 KB instead of
  3.1 KB gzip), built as fast as before (~11 µs)
  ([docs](docs/features/RSF03-02-browser-challenge.md#how-it-looks)).
- Known query parameters and their types (proposal 0009): `query <name> <type>
  … [at <paths>]` (types `int`, `number`, `word`, `id`, `list`, `text`, `any`,
  `/regex/`; names with `*`; inside `match` blocks too). The attack patterns
  see only what could hold an attack -- `text`, unknown parameters and values
  not of their type, name and value -- and `query strict` answers anything
  else with 404 before they run (6.7 instead of 17.7 µs for such a request with
  `@attacks`). An empty value is of every type. `rules/tracking.rules`
  (`include @tracking`, IDs `TRACK-…`) declares the marketing tags. The rules
  page, `trace`, `show` and `Shield::explain()` name them
  ([docs](docs/features/RSF02-05-known-parameters.md)).
- The demo declares its parameters, includes `@tracking` and runs `query
  strict`.
- Modes (proposal 0004): `set mode off | monitor | enforce | strict`.
  `monitor` checks and counts everything and logs what it would decide
  (`monitor-reject …`, `X-Request-Shield: monitor …`) but refuses nobody;
  `strict`, for a site under attack, checks from a quarter of each limit,
  gives a pass for at most 15 minutes, starts the difficulty at twice its
  minimum and counts addresses a cache must not keep twice; `off` does
  nothing. `monitor` before a rule (`monitor block /old-api/**`, also
  `restrict`, `allow`, `limit`, `challenge`, `query strict`) watches just that
  rule: logged, not enforced, a shared budget counted once. `challenge <paths>
  max-age 5m` asks for a pass from the last five minutes there. The rules page,
  `show` and `trace` name the mode and the watched rules
  ([docs](docs/features/RSF05-03-modes.md)).
- The demo watches a rule (`/old/api`) and has a checkout with `max-age 20s`.

- Known crawlers (proposal 0011): search engines and AI crawlers that behave
  are never given the browser check -- verified by their operators' published
  address lists (shipped in `rules/crawlers/`, a lookup of about half a
  microsecond) or by DNS, never by the name they send. 19 crawlers in
  `rules/crawlers.rules` in four kinds (`search`, `ai-search`, `ai-user`,
  `ai-training`): Google, Bing, Apple, DuckDuckGo, Yandex, Baidu, Qwant,
  Seznam, OpenAI, Anthropic, Perplexity, Amazon, Common Crawl. `crawlers <kind>
  allow|check|block` and `crawler <ID> …` decide per kind or one by one
  (default allow; block answers 403); `set crawler-verify ranges` verifies
  without DNS (a DMZ); a site's own crawler with `crawler <kind> ua … dns …
  ranges ./file.json`. A request that only claims a crawler's name is logged
  with `claimed=<ID>`. `bin/request-shield crawlers <site.rules> [update]`
  lists them and fetches current lists into store-dir; `bin/update-crawler-lists`
  refreshes the shipped lists before a release (a weekly workflow reports
  changes); `trace --ua=…`. The rules page lists the crawlers and counts false
  claims ([docs](docs/features/RSF01-04-known-crawlers.md)).

- Statistics (proposals 0012, 0014): `set stats on` counts, per hour, what the
  shield did (let through, checked, told to wait, refused, the rule behind it,
  the answer's status code -- the site's own at the end of the request), what
  each known crawler did (verified or only claiming the name, let through,
  checked, refused, robots.txt, its top 50 pages a day, its last visit), the
  pages the site did not find and where the links to them are (the site's own
  page, or another site's host), and other bots by family. The parts can be
  switched on one by one (`set stats requests crawlers not-found bots`). With
  APCu a count is one `apcu_inc()`, written to disk every `stats-flush`
  seconds (default 60) so a restart of PHP-FPM loses at most that; without,
  one appended line. Finished hours go into one JSON file per day (hours kept 7
  days, days 400). `bin/request-shield stats [--days=7] [--json]`,
  `Report\StatsReport::build()` and the rules page show it -- with sentences
  such as "OpenAI's training crawler (CRAWL-GPTBOT) came 1,204× in the last 7
  days: every time refused (as set: block)" and "Broken link: /news/x links to
  /old, which was not found". Optional: one log per known crawler and day
  (`set crawler-log <dir>`). About +7 µs a request with APCu in the container
  (+27 µs with files, one appended line). Days, weeks, months and years:
  old days are summed into month files (kept for good, or `stats-months`);
  `stats --from=… --to=… --by=day|week|month|year --crawler=<ID>`
  ([docs](docs/features/RSF06-03-statistics.md)).
- The statistics page, `Report\StatsPage::render()`: tiles with the last 48
  hours, stacked bars for who came (people, crawlers, bots) and what the
  shield did, the answers as a ring, a bar per crawler, sitemaps, pages not
  found with their referrers, rules and bot families -- inline SVG and CSS, dark
  mode, English and German, refreshing itself every minute. The demo shows it
  at `/stats` (and has a `sitemap.xml`; unknown pages answer 404).
- Sitemaps in the statistics: which exist (the site's answer) and which
  verified crawler read which, how often and when last.
- Statistics at scale: housekeeping (flush, roll-up) runs after the response
  (PHP-FPM, LiteSpeed); every limited list stays small under a flood of
  made-up addresses; each statistics directory has its own APCu names (sites on
  one PHP-FPM pool no longer count into each other); a report reads only the
  days it shows. Measured: exact counts under 32 parallel requests, -5 to -10 %
  throughput with APCu at ~6,000 requests a second.

- The most visited pages, by people, crawlers and bots (statistics part
  `pages`, on with `set stats on`): page views (GET, 200, HTML) per path, the
  top 100 an hour for each kind of visitor, and their first two folders, so a
  subtree's views are exact -- `stats --path=/news/` and a "path starts with"
  filter on the statistics page, with "most visited sections".
- What the shield stopped, per page: refused, checked and told to wait, whoever
  asked (the same part `pages`, the top 100 an hour for each). Each page and
  section shows it next to its views; sorted by it ("sorted by: stopped /
  refused / checked / told to wait", `stats --sort=blocked`, `'sort'`), the list
  shows the pages the shield stopped most -- also ones nobody ever saw, like
  `/wp-login.php`. The Protection view starts there.
- **Rules & setup**, a fourth view of the statistics page (`/rs/rules`,
  `'view' => 'rules'`; `Report\SetupPage`): the way of a request through the
  shield, every check in order, on or off, with what it answers; every rule in
  words with its ID, where it is written and how often it decided; every
  technical setting (the secret never shown); a rule tester (an address, a
  visitor, a User-Agent: every step, the diagram, the rule that decides,
  nothing counted); the way as a picture (13 checks on or off, the site or the
  shield's answer, then the log and the statistics and when they write); the
  rules as the rule files hold them, one collapsible part per file, the ID
  first; in English and German. The
  Protection view's rules now say what each does and where it is written, and
  link there. The rules page's groups (`RulesPage::groups(…, $lang)`), the mode,
  the tracer (`new Inspector($s, $store, 'de')`, a step's English `key` beside
  its `check`), `Diagram::trace(…, $lang)` and `Describe::duration()`/`span()`/
  `verdict()`/`reason()` speak German too.
- `set stats-depth 1…4` (default 2): how many folder levels a section's views
  are counted for exactly -- 3 where a language or siteaccess takes the first
  level (`/de/news/2026/`). Each level has its own limit of 200 sections an
  hour, so the many deep ones never crowd out the few above; `check` notes when
  a level overflowed. Measured: one more counter, ~2–3 µs per page view with APCu
  (~0.3 % of a core at 1,000 page views a second), none measurable with files.
- The statistics page in views: an overview, **Visitors & pages** for editors
  and **Protection** for admins, with tabs -- or one of them embedded on its
  own (`'view' => 'all'|'site'|'shield'`, `'tabs' => false`). Each view has its
  own address under `set dashboard-path` (default `/rs`: `/rs/dashboard`,
  `/rs/stats`, `/rs/shield`; `/admin/rs` if wanted); `StatsPage::links()` and
  `::viewFor()` help a site route them; the filters stay GET parameters. The
  demo has them at `/rs/…`, restricted to this machine.

### Fixed
- **`request-shield test` skipped an example of a `deny` line** as "not in
  effect here" -- a deny line is in the list, not in the origins; it is in
  effect now (0031 step F.6).
- **An example's path that starts with `//`** (`expect GET //admin/ 403`) was
  read as an address with the host `admin` and the path `/`, and passed; it
  is the path `//admin/` now, as a browser sends it (0031 step F.4).
- **The statistics on a path of their own are served** (open from the 0031
  D.3 review): with `set stats-path` outside `dashboard-path` (such as
  `/admin/statistics`) the shield never served those pages, because its
  pre-filter looked for `dashboard-path` only. The compiled settings carry
  the prefixes the routes lie below (`routeBases`; just `dashboard-path` by
  default, so one `stripos` as before). A `plugin … from <file>` file is
  watched on every compile, also when its class is loaded already; the
  statistics' unreachable "plugin not installed" warning is gone. Compiled
  settings format 51.
- A ban (`ban after …`) was named by no rule in `explain()`, the trace and
  the log of a banned request's later answers; with one ban rule, it is now
  named by it.
- A POST refused with 405 was named by the last `allow POST` line read, e.g.
  an admin area's own (`match /admin/** { allow POST }`) for a POST to a
  frontend page. It is now named by the first line that allows the method.
- Looking at the statistics changed them: the dashboard's own requests (its
  pages, the live view's feed every 3 s) were counted as requests by people.
  They are left out when they pass; refused or checked they still count. The
  overview of all websites says "requests by people", "crawler visits", "bot
  requests" (with what each counts), as the tiles do.
- An open live view counted against the site's pace limit (its feed every
  3 s) and ran into 429 or the browser check: the dashboard's own pages no
  longer count against the budgets when a `restrict` rule covers them and
  allows the address asking; without one they count as before.
- Two `query` lines naming the same parameter (at different paths) were both
  shown as the later rule on the rules pages; each line now keeps its own ID.
- `set widget-path` accepted a path with `..` in it (`/x/../y`), which could
  never match; it is refused now, like `dashboard-path`.

### Changed
- **The statistics plugin's pages have their own addresses** under
  `<dashboard-path>/stats/`: `/rs/stats/overview`, `/rs/stats/visitors`,
  `/rs/stats/protection`, `/rs/stats/sites`; `/rs/stats` is the plugin's start
  (all websites with `stats-hosts`, else the overview). The core's — the
  firewall's — pages under `<dashboard-path>/waf/`: `/rs/waf/live`,
  `/rs/waf/lists`, `/rs/waf/rules` (`/rs/waf`: the live view). The old addresses
  (`/rs/dashboard`, `/rs/shield`, `/rs/sites`, `/rs/live`, `/rs/lists`,
  `/rs/rules`) are gone: a site that routes them itself (`StatsPage::viewFor()`,
  `Frame::pageFor()`) needs nothing; links and bookmarks to them need the new ones.
- Finding the website for the statistics keeps the last settings' names
  instead of a `WeakMap` (0.10 µs; a crash seen once in CI on PHP 8.0 with APCu).
- **The statistics are the first plugin** (`plugins/stats/`, `StatsPlugin`):
  `set stats on` (or `set crawler-log`) brings them, nothing changes for a site.
  Their classes moved there and keep their names (`Stats`, `Report\StatsReport`,
  `Report\StatsPage`, `Report\VisitorsPage`); `Shield::folders()`,
  `isHtml()`, `isSitemap()` and `statusKeys()` are now `StatsPlugin::…`; a bot's
  family is `Seen::family()` (`Stats::botFamily()` asks it). `Shield::record()`
  tells the plugins. Measured as before: no difference.
- The query string is parsed once per request, for every check that reads it.

## [0.3.0] — 2026-09-30

### Added
- Rule files: the settings one rule per line (`host`, `trust`, `block`,
  `cache-path`, `limit`, `challenge`, `restrict`, `allow`, `set`, `include`,
  …), from a main file, its includes and further sources such as a CMS
  extension's; merged in order, checked when read (errors name `file:line`),
  compiled for OPcache and, with APCu, checked for changes every 10 seconds
  without a `stat()` in between (~5.5 µs setup). `${NAME:-default}` for
  environment variables. `bin/request-shield check|show|reload`
  ([docs](docs/features/RSF05-01-rule-files.md), proposal 0003).
- Access rules: `restrict <paths> to <addresses>` (403 for everyone else) and
  `allow <METHODS> <paths>` (405 elsewhere), matched against the path as the
  application routes it — `//admin`, `/%61dmin` and case do not get past
  ([docs](docs/features/RSF02-03-access-rules.md)).
- Rule IDs: every decision that stops or flags a request names its rule
  (`SITE-10`, `site.rules:12`, `SCAN-BACKUP`, `built-in`) in `X-Request-Shield`,
  `$_SERVER['REQUEST_SHIELD_RULE']` and `Shield::currentRule()`; looked up only
  for such requests.
- An optional log (`set log`, `log-level stop|flag|all|off`, `log-ip
  masked|full`, one rotation at `log-max-size`), one line per request the
  shield stopped or flagged, with the full URL; the address first, anonymised
  by default and written as its network (`198.51.100.0/24`)
  ([docs](docs/features/RSF05-05-log-and-rule-ids.md)).
- `Shield::active()`: the shield `protect()` ran with, so the application
  counts on-demand budgets against the same settings and request
  (`Shield::active()->consume('misses')`); refusals are logged.
- The active rules page (`Report\RulesPage`): the rules in plain words with
  their origin and how often each decided in the last 24 hours, the latest
  activity, and a check that tries any address step by step without counting
  it; refreshes itself. `bin/request-shield trace`
  ([docs](docs/features/RSF06-01-active-rules-page.md)).
- `unblock [<what>] at <paths> [for <addresses>]`: blocked paths let through at
  some paths only — an admin's file reader that has to open `.env` or a
  backup — optionally only for some addresses; the path check is never lifted;
  `check` warns about exceptions for everyone
  ([docs](docs/features/RSF02-03-access-rules.md#exceptions-an-admins-file-reader)).
- Rule IDs of one's own: `[SITE-10]` before a rule names it in decisions,
  the log, the rules page and `trace` instead of `file:line`; `ids <NS>
  [required]` gives a file its number block; an ID used twice is an error
  naming both places; the comment after a rule is its description.
- The built-in blocks are rule files shipped with the library
  (`rules/scanners.rules`, `rules/wordpress.rules`, IDs `SCAN-…`, `WP-…`):
  `include @wordpress`, `unblock [SCAN-CGI]`, `unblock @scanners`
  ([docs](docs/features/RSF05-01-rule-files.md#the-built-in-rules)).
- Versioned rule sets: `version <word>` per rule file, revisions per rule
  (`[SCAN-BACKUP@1]`); a rule that takes back, replaces or opens another names
  the revision reviewed, and `check` and the rules page warn when a library
  update changed it. `replace [ID@n] <rule>` swaps a rule in one line and keeps
  its ID. The built-in rules have `version 2026.09.1` and `rules/CHANGELOG.md`
  (proposal 0005).
- The browser check explained in plain words (`docs/explained/browser-check.md`),
  on the active rules page and, after passing it, in the demo.
- What visitors read is in their language: the check page and the shield's
  own answers in English or German by `Accept-Language` (else English, or
  fixed with `set language de`); own texts per language
  (`set text.de.title …`, `'texts' => ['de.title' => …]`), further languages by
  their texts; `Vary: Accept-Language`.
- The site asks for the browser check: `Shield::active()->requirePass([fresh])`
  before acting on sent content — without a pass the check, and the form is
  sent again by itself afterwards (fields carried in the page, the form token
  too; not files or over 256 KB; a button without JavaScript) — and the
  response header `X-Request-Shield-Challenge: required` on a page
  (`set app-challenge on`). Proposal 0006
  ([docs](docs/features/RSF03-04-app-challenges.md)).
- `set home /`: the shield's own pages (404, 403, a pause, the check page) link
  back to the site ("To the home page", in the visitor's language); the rules
  page takes a link back too. The demo leads back to its front page from
  everywhere.
- Diagrams, drawn as SVG without a library: the path of a request through the
  checks on the rules page, the browser check step by step, and how the shield
  sits in front of a site (README, `docs/explained/`).
- The browser check inside the form (proposal 0010): `set widget-path
  /request-shield` and `Shield::active()->widget()` put a small box into a form
  that fetches a task from the shield's endpoint on the first input, solves it
  while the visitor types and puts the answer into the form; `requirePass()`
  and checked pages take it from there — no check page, files included. The
  page stays cacheable; `widget.js` is a file (CSP), the task ALTCHA's format;
  texts in the visitor's language. Demo: `/contact`.
- `match <path> { … }` in rule files (proposal 0008, first step): the rules of
  an area in one place, their paths the block's — `restrict to …`,
  `allow POST`, `challenge`, `block`, `unblock [ID] for …`, nested blocks,
  blocks by regex. Exactly the rules written out: the same settings, the same
  cost; the rules page shows each rule's area.
- Earn a spent budget back (proposal 0001): `limit … on-exceeded challenge`
  (`'onExceeded' => 'challenge'`) — past the limit the browser check instead of a
  pause; solved, the counter for that budget starts again; no pass gets past it,
  only a solution bound to the budget; twice as hard per solve within an hour,
  from `difficulty-min` up to `difficulty-max`. Forms come back after the
  check; APIs (JSON, `api-path`) get the task as JSON and in
  `Request-Shield-Challenge`, answered with `Request-Shield-Solution`; verified
  crawlers get the pause. `consume($budget, answer: true)` lets the shield
  answer a refusal itself. The default stays the pause. `Store` has `reset()`
  (an interface change for own stores).
- Proposal 0004: modes (`off`, `monitor`, `enforce`, `strict`), `monitor` for
  single rules, a fresh check per path.
- `challenge.alwaysPaths`: paths every visitor has to pass the browser check
  for (once per pass cookie), whatever the budgets say — for a login or admin
  page; a POST without a pass gets 429
  ([docs](docs/features/RSF03-02-browser-challenge.md)).
- A demo site, `examples/demo/`: one example per feature, the check included;
  `php -S 127.0.0.1:8080 examples/demo/router.php`, or in any subdirectory
  of a web server, with rewrite rules (`.htaccess`) or as `index.php/…`; its
  counters and secret stay outside the document root. It shows the full URL,
  the request's headers (those the shield removed struck out), the answer's
  headers, and each example's status and headers in place; it runs on a rule
  file, with a search page (its own budget), an edit form (POST only there),
  an admin area and an API restricted by address, a pass that expires after a
  minute, and the shield's log. Tested end to end, at
  the root and in a subdirectory.
- PHP 8.0 support (the Red Hat Enterprise Linux 9 baseline): no `readonly`
  properties at runtime any more — public ones are marked `@readonly`, which
  PHPStan enforces —, no string-key unpacking, `array_is_list()` and `xxh128`
  only where PHP has them. CI tests PHP 8.0 too. Cost on PHP 8.1 unchanged.
- Attack rules look inside the request: `block query|header <Name>|headers|anywhere
  <regex>`, matched on the normalised values (decoded twice, lower case, SQL
  comments out) and answered with 403 "attack"; `unblock`, `unblock at` and
  `replace` work for them. The rules are one combined expression per target,
  checked when read — a passing request without them does nothing extra. The
  rules page, `bin/request-shield show|check|trace` and `Shield::explain()`
  name them like every other rule
  ([docs](docs/features/RSF05-01-rule-files.md#attack-patterns)).
- `rules/attacks.rules` (`include @attacks`): a reviewed set against SQL
  injection, cross-site scripting, code and shell injection, file inclusion,
  Log4Shell and the known attack tools and exploit paths, after the OWASP Core
  Rule Set's first level (IDs `ATK-…`, `version 2026.09.1`).

### Fixed
- A form sent past `challenge-at` without a valid pass got "Please try again in
  10 seconds" instead of the check -- for example after the pass ran out while
  the check inside the form showed ✓ (it had been given no answer, since the
  visitor held a pass then). Such a form now gets the check page, which sends
  it again once solved, as `requirePass()` and a spent budget's check already
  did. The widget endpoint also says until when the pass holds (`until`); the
  box fetches a task before it runs out, and a pass with less than 30 seconds
  left gets one anyway.
- A form the check page sent again after solving was refused its answer (the
  answer comes in a cookie, which a POST was allowed only for `requirePass()`
  and a spent budget), got the check page again and, on the third try, "The
  check did not succeed". The check page's own resent form counts now. And
  `requirePass()` carried a used answer of the check inside the form (a
  reload of the page the form was sent to sends it again) into the resent
  form, where it won over the new one -- the same loop. It is taken out first
  now.
- Fake search engine crawlers could make requests wait where DNS does not
  answer (a DMZ): each new address claiming to be Googlebot started a DNS
  lookup that waited for the resolver's timeout. At most `dns-lookups` new
  lookups a minute are made now, for all requests together (default 30); past
  that a claimed crawler counts as not verified at once, and nothing is
  remembered. `set dns-lookups 0` for no lookups at all. The check itself never
  needed the internet (documented).
- The check page said "The check did not succeed" on the fourth check in a
  browser tab, however far apart and on whatever pages: its guard against a
  loop counted every check page of the tab and was never reset. It now counts
  only attempts at the same address within a minute.
- The check inside the form sent an answer that had been used or had expired
  -- after the back button, or a form filled in for minutes -- which then got
  the check page. It now starts again when the page comes back from the
  browser's cache, and fetches a new answer when the one it has is about to
  expire.
- `unblock regex <expression>` and `unblock … at <paths>` did not find a
  content rule: its pattern is kept case-insensitive and the lookup missed it.
- `rules/attacks.rules` blocked `/hnap1` and `/gponform/**` never: the paths
  were written with capital letters while the path is matched lower-cased.
- `block query @scanners` (or an `[ID]`) silently became a pattern matching its
  own name; it is an error now.
- `trace` and the rules page's check had no step for the attack rules: a
  refused request looked unblocked there.
- The file store could lose a count on the very first hits of a new
  directory: processes creating it at the same moment made a recursive
  `mkdir()` fail in one of them. It now tries again (reproduced: 1 of 240 runs
  of 8 processes lost one hit; after the fix 0 of 360).

### Changed
- Faster: the blocked paths are matched as one expression (compiled once), so
  a clean request costs one match however many blocks there are (7.7 instead of
  8.2 µs per request with the defaults); attack rules skip a target when its
  raw value cannot hold what every one of its patterns starts with (Log4Shell:
  `${`), and white space is only normalised where there is any. With
  `include @attacks` a clean request costs about 8.6 µs more instead of 10.5
  (PHP 8.1, measured end to end).
- The end-to-end tests take their ports from the operating system: a guessed
  port could belong to another service, which then answered the test.
- The compiled settings record every source file and the environment
  variables used (format 4: rebuilt once after the update); `Settings` has the
  log, the access rules and the rules' origins. `Shield::consume()` takes the
  request from `protect()` when none is given.
- The README opens with what the shield does for a website, in plain words (a
  mini web application firewall); the package description and keywords follow.
- Composer and release archives contain only what runs on a server (`src/`,
  `bootstrap.php`, `config/`, license and readme); tests, benchmarks, docs and
  tool settings stay in the repository (`.gitattributes`).

## [0.2.0] — 2026-09-28

### Added
- The browser challenge: an ALTCHA-compatible proof of work for clients past a
  budget's `challengeAt`, a signed pass cookie bound to client and User-Agent,
  single-use solutions, difficulty growing towards the limit, verified search
  engine crawlers and exempt paths never challenged
  ([docs](docs/features/RSF03-02-browser-challenge.md)).
- `Shield::protectFile()`: settings checked once and compiled into a PHP file
  OPcache serves, rebuilt when the file changes; `bootstrap.php` uses it
  ([docs](docs/features/RSF05-02-settings.md)).
- Typed settings: a wrong type is an error naming the key.
- Documentation: features, use cases, proposals, architecture decisions.
- Tests report skipped cases; the challenge page's script is tested in Node.
- CI on GitHub: tests on PHP 8.1–8.5 with and without APCu (a skipped test
  fails the APCu jobs), a smoke benchmark, PHPStan (level max), Psalm taint
  analysis uploaded to code scanning, `composer validate`/`audit`, Dependabot.
- `SECURITY.md`, `CONTRIBUTING.md`, `AGENTS.md`.

### Fixed
- A settings file rewritten within the same second was compiled with its old
  contents (OPcache judged it by the unchanged mtime) and kept them; it is now
  invalidated in OPcache before it is read again. Found by CI, which runs the
  tests with OPcache on.

### Changed
- `Request` reads headers lazily from `$_SERVER` (about 40 % less time per
  request).

## [0.1.0] — 2026-09-28

### Added
- The core: trusted proxies and client identity (IPv6 /64), hard rejects
  (methods, sizes, path sanity, hosts, scanner paths), the cacheable
  definition, per-client budgets with 429, on-demand budgets via
  `Shield::consume()`, APCu, file and memory stores, `bootstrap.php` for
  `auto_prepend_file`.

[Unreleased]: https://github.com/cjw-network/request-shield/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/cjw-network/request-shield/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/cjw-network/request-shield/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/cjw-network/request-shield/releases/tag/v0.1.0
