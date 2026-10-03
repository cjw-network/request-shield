# The review prompt

Checks the repository against the goals of
[proposal 0031](../../proposals/0031-robust-core-plugins.md): a robust core,
extension points everywhere, a one-file "mini" edition without any backend, a
demo and tests for every feature, installable by an AI from one prompt,
security and speed first. Every criterion is verified **in the repository** —
by reading files and running commands — never by opinion. Run it before a
release and store the result as `docs/reviews/<version>.md`. Criteria for
parts not built yet come out as FAIL; that is the point.

```text
You are reviewing the repository of request-shield (a dependency-free PHP >= 8.0 mini WAF that runs
before the application) against its stated goals: a robust core, extension points everywhere, a
one-file "mini" edition that works without any backend, a demo and tests for every feature,
installable by an AI from one prompt, security and speed first. Verify every criterion IN THE
REPOSITORY by reading files and running the commands given; never by opinion. Quote command output.
Output ONE table: Criterion | Evidence (file:line or command output) | Verdict (PASS / FAIL /
PARTIAL), then the FAILs in priority order with the smallest change that would turn each into a
PASS. Do not modify files.

A. Robustness
A1 Fail-safe: an exception anywhere inside the decision path makes the request PASS (allow-uncached),
   never a 500. Evidence: try/catch(\Throwable) around the body of Shield::protect() and protectFile()
   in src/Shield.php, and a test that injects a throwing rule/store and asserts the site answers 200.
   FAIL if the only catches are around plugin calls.
A2 A rule file that fails to compile after a good compile keeps the LAST GOOD compiled settings; one
   that fails at first install results in pass-through, not a fatal error. Evidence: the catch in
   Settings::load()/loadFor() and an end-to-end test for both cases.
A3 php tests/run.php exits 0 with and without APCu (php -d apc.enable_cli=1); TESTS_FAIL_ON_SKIP=1
   with APCu reports 0 skips. Quote the summary lines.
A4 PHP 8.0 syntax only; composer phpstan (level max) and composer taint exit 0.
A5 Shared hosting: the core needs PHP >= 8.0 and nothing else. Evidence: tests/HostingTiersTest.php
   runs the same requests under S0 (open_basedir + unwritable store-dir), S1 (apc.enabled=0,
   disable_functions=exec,shell_exec,proc_open,popen,system,passthru,putenv,set_time_limit,dl,
   allow_url_fopen=0, memory_limit=64M) and S2 (APCu) with identical decisions, and passes; a CI leg
   "minimal hosting" exists; grep -rn "apcu_" src/ plugins/ shows every use guarded by a capability
   check with a file fallback or a clean switch-off; php bin/request-shield check prints the tier
   and what is inactive in it; README states cost per tier.

B. Performance
B1 Run php -d apc.enable_cli=1 -d opcache.enable_cli=1 bench/overhead.php 20000 three times; the
   median per-request numbers for APCu and file store are within 25 % of what README.md states.
   Quote both. FAIL if README states no numbers.
B2 Nothing unused costs on the passing path: bench once with plugins/stats/feeds/crawler verification
   /routes all off and once with each on; each adds cost only when on. In src/Shield.php every hook
   dispatch is guarded by a compiled-settings check (e.g. ($s->hooks['x'] ?? []) !== []), never by
   class_exists/reflection per request.
B3 grep -rn "stat(\|is_file(\|file_exists(\|filemtime(" src/ — every hit is off the per-request path
   or justified in a comment (one settings-freshness check per request is allowed).
B4 Bytes on the wire: tests/WireBytesTest.php exists and passes with numeric limits (pass cookie
   name+value <= 48 bytes, no X-RS* header on a passing request without debug, internal X-RS-Check /
   X-RS-Access never reach the output, challenge page gzip size limit); header names use the X-RS
   prefix ("rs" = RequestShield), not X-Request-Shield; grep -rn "X-Request-Shield\|rs_pass\|rs_stats"
   src/ plugins/ docs/ examples/ is empty. Quote the measured sizes.

C. Extension points
C1 List every interface/callback a plugin or adapter can implement (Plugin, Extension, RuleProvider,
   Handler, Pages, Sink, ApiProvider, …). For each: the file:line that dispatches it, a test that
   exercises it, the docs/features page that documents it. PARTIAL if any of the three is missing.
C2 Plugins contribute rules, rule-file words, routes/pages and CLI commands without core edits.
   Evidence: grep -rn "StatsPlugin\|plugins/stats\|StatsReport\|StatsPage\|stats-" src/ bin/
   bootstrap.php composer.json — count must be 0; the stats plugin registers itself through the
   hooks only.
C3 The rule vocabulary has ONE source (a declarative table) and the reference docs are generated from
   it: php docs/tools/gen-reference.php --check exits 0; tests assert the table covers the parser.
C4 Plugins can tighten but never loosen: no hook may replace or weaken a Decision (read the dispatch
   code; look for stricter()); a throwing plugin rule counts as pass and is logged once a minute.
C5 API (RSF6.5) is a plugin, not core: grep -rn "api/v1\|openapi" src/ is empty; plugins/api exists
   with an ApiProvider capability other plugins use (the stats plugin's endpoints are declared in
   plugins/stats, not in plugins/api); GET /rs/api/v1/openapi.yaml lists every endpoint including
   plugin ones; tests/ApiContractTest.php proves every response matches its schema and that
   Api::call() (in-process) returns byte-identical data to the HTTP endpoint; write endpoints are
   refused unless `set api-write on` and an admin principal; no secret or token hash appears in
   any response (grep the schemas).

D. Single file ("mini", no backend)
D1 build/single-file.php produces request-shield.php from src/ WITHOUT src/Report/*; the build is
   reproducible (build twice, sha256 identical); php -l passes on PHP 8.0–8.5 (docker php:8.x-cli).
D2 The full test suite runs against the built file (REQUEST_SHIELD_ENTRY or equivalent) and exits 0.
D3 Follow docs/llm/install.md literally in a fresh container with a plain index.php: download from
   releases/latest/download/, verify checksum and signature, three lines of configuration; then
   curl -sI /.env shows the shield's answer and / shows the site's 200. Quote the headers.
D4 The release workflow attaches request-shield.php, SHA256SUMS and a signature; the public key is
   in SECURITY.md and embedded in the file; php request-shield.php version prints version + build.
D5 The built file is also the CLI (php request-shield.php check …) and finds request-shield.rules
   next to itself without REQUEST_SHIELD_CONFIG.

E. Demo and tests per feature
E1 php bin/request-shield examples examples/demo/request-shield.rules --coverage docs/features exits
   0: every docs/features/*.md has a unique feature id in its H1 (RSF<group>.<n>) and a "# demo:"
   group with that id and >= 1 expect line, or is listed in docs/features/.demo-exempt with a
   reason; the same id appears in trace output and on the demo page's anchors.
E2 php bin/request-shield test examples/demo/request-shield.rules exits 0 and lists 0 rules without
   an example; every shipped rules/*.rules and rules/app/*.rules has expect lines for every rule.
E3 php docs/tools/sync-examples.php --check exits 0 (the tables in the docs equal the rules).
E4 The demo runs as its README says (php -S … router.php); tests/DemoTest.php passes; "Show the
   answer" is server-side (shows redirects, cross-host, times N).
E5 A recorded static demo is published (GitHub Pages) from the same expect lines, stating the commit.
E6 Every feature has tests under its id: php tests/run.php RSF<n>.<m> runs >= 1 test for every id
   found in docs/features/*.md; features in groups RSF1–RSF4 (the request path) also have an
   end-to-end test (php -S) under that id. tests/FeatureContractTest.php enforces doc + demo + test
   coverage and exits 0; quote its output.

F. AI-installable
F1 llms.txt exists at the root; every path it lists exists; it contains the "Never" block (monitor
   before enforce; no personal data or secrets in rule files; nothing inside the document root).
F2 docs/llm/install.md, write-rules.md, check.md exist and are command-first.
F3 php bin/request-shield init --app=plain --out=/tmp/x.rules writes a file that passes check and
   starts with set mode monitor; init refuses --out inside --docroot.
F4 Dry run: give docs/llm/prompts/install.md to an agent against a sample app in a container; record
   whether it finished without needing anything the docs should have said.

G. Security
G1 The secret is never logged or output: grep -rn "secret" src/ | grep -i "log\|echo\|print\|header("
   is empty; MACs are compared with hash_equals() only (grep -rn "hash_equals\|== \$sig\|=== \$sig"
   src/Challenge/ src/Access.php).
G2 Everything a page embeds is escaped: sample ten echoes of user-controlled data in src/Report/ and
   src/Challenge/; each goes through htmlspecialchars(…, ENT_QUOTES).
G3 Routes the shield serves are behind restrict or dashboard-access; check warns otherwise; responses
   carry Cache-Control: no-store and X-Robots-Tag: noindex; POST handlers check a CSRF token.
G4 SECURITY.md exists with a private reporting channel; composer audit exits 0; the shield never
   writes inside the document root by default and check warns when store-dir or rules lie under it.

H. Documentation
H1 CHANGELOG.md has an [Unreleased] section; every feature added since the last tag (git log
   <tag>..HEAD --oneline) has an entry.
H2 docs/adr/ holds a decision for: fail-safe pass-through, compile-time resolved extension points,
   plugins tighten-only, the single-file build and editions, generated reference and demos from one
   source, repository structure and release signing, hosting tiers, wire bytes and the rs prefix.
H3 Every docs/features page has: what it does, use cases, configuration, cost, limits, the generated
   examples block, and at least one diagram; docs/README.md links every proposal and no proposal
   number is duplicated.
H4 Perspectives: docs/for/{admins,hosters,editors,customers,developers}.md exist, each answering
   "what do I see / what do the numbers mean / what do I do", each with >= 1 diagram and a scenario,
   written in plain language (every term at first use explained or linked to docs/glossary.md);
   tests/DocsStyleTest.php enforces diagram + glossary links and passes.
H5 Self-explaining UIs: every dashboard page and section (core, stats plugin, challenge page, widget)
   carries a one-sentence explanation and a help link with its RSF id to an existing docs anchor
   (set docs-url configurable); empty states explain how to get data; check/CLI errors end with a
   docs anchor. Evidence: tests/UiHelpTest.php passes; open three pages in the demo and quote the
   help links.
```
