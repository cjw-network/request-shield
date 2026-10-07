# Documentation

- **For people, by role** — what you see, what the numbers mean, what you do
  when, and a typical day:
  [admins](for/admins.md) · [hosters](for/hosters.md) ·
  [editors](for/editors.md) · [customers](for/customers.md) ·
  [developers](for/developers.md); the words explained:
  [glossary](glossary.md)
- **Explained** — for site owners, in plain words:
  [the parts and their switches](explained/parts.md) ·
  [the browser check](explained/browser-check.md)
- **Features** — what each part does, use cases, configuration, cost, limits;
  each has an id `RSF<gg>-<nn>`, its page is named by it (in the order the shield asks its
  questions; `php tests/run.php RSF02-06` runs one feature's tests):

  | Id | Question | Feature |
  |---|---|---|
  | RSF01-01 | Who is asking | [trusted proxies](features/RSF01-01-trusted-proxies.md) |
  | RSF01-02 | Who is asking | [IP lists and automatic bans](features/RSF01-02-ip-lists.md) |
  | RSF01-03 | Who is asking | [public blocklists (feeds)](features/RSF01-03-blocklist-feeds.md) |
  | RSF01-04 | Who is asking | [known crawlers](features/RSF01-04-known-crawlers.md) |
  | RSF02-01 | What is asked | [hard rejects](features/RSF02-01-hard-rejects.md) |
  | RSF02-02 | What is asked | [blocked paths](features/RSF02-02-blocked-paths.md) |
  | RSF02-03 | What is asked | [access rules](features/RSF02-03-access-rules.md) |
  | RSF02-04 | What is asked | [forms only from the website itself (`post-origin`)](features/RSF02-04-forms-from-the-website.md) |
  | RSF02-05 | What is asked | [known query parameters](features/RSF02-05-known-parameters.md) |
  | RSF02-06 | What is asked | [attack patterns](features/RSF02-06-attack-patterns.md) |
  | RSF03-01 | How often | [budgets and stores](features/RSF03-01-budgets.md) |
  | RSF03-02 | How often | [browser challenge](features/RSF03-02-browser-challenge.md) |
  | RSF03-03 | How often | [the check inside the form](features/RSF03-03-browser-check-in-the-form.md) |
  | RSF03-04 | How often | [the site asks for the check](features/RSF03-04-app-challenges.md) |
  | RSF04-01 | What a cache may keep | [cacheable definition](features/RSF04-01-cacheable-definition.md) |
  | RSF04-02 | What a cache may keep | planned: cache keys without tracking (proposal 0020) |
  | RSF04-03 | What a cache may keep | [the HTTP cache (a plugin)](features/RSF04-03-http-cache.md) |
  | RSF05-01 | Operating | [rule files](features/RSF05-01-rule-files.md) |
  | RSF05-02 | Operating | [settings](features/RSF05-02-settings.md) |
  | RSF05-03 | Operating | [modes: monitor and strict](features/RSF05-03-modes.md) |
  | RSF05-04 | Operating | [examples next to the rules (`expect`, `request-shield test`)](features/RSF05-04-rule-examples.md) |
  | RSF05-05 | Operating | [log and rule IDs](features/RSF05-05-log-and-rule-ids.md) |
  | RSF05-06 | Operating | [error pages](features/RSF05-06-error-pages.md) |
  | RSF05-07 | Operating | [the single file](features/RSF05-07-single-file.md) |
  | RSF06-01 | Watching & connecting | [active rules page](features/RSF06-01-active-rules-page.md) |
  | RSF06-02 | Watching & connecting | [the live view and the lists in the dashboard](features/RSF06-02-live-and-lists.md) |
  | RSF06-03 | Watching & connecting | [statistics](features/RSF06-03-statistics.md) |
  | RSF06-04 | Watching & connecting | [plugins](features/RSF06-04-plugins.md) |
  | RSF06-05 | Watching & connecting | [the API](features/RSF06-05-api.md) |
- **Reference** — written from the code, never by hand
  (`docs/tools/gen-reference.php`): [the rule file](reference/rule-files.md) ·
  [the set keys](reference/settings.md) · [the command line](reference/cli.md) · [the API](reference/api.md)
- **Privacy** — what the shield processes about visitors, feature by feature,
  and the GDPR: [privacy and the GDPR](privacy.md)
- **For AI agents** — [llms.txt](../llms.txt) is the entry;
  [install](llm/install.md) ([dry run](llm/install-dryrun.md)) ·
  [write the rules](llm/write-rules.md) · [check them](llm/check.md) ·
  [writing rule examples](llm/write-rule-examples.md); the prompts:
  [install a site](llm/prompts/install.md) · [review the project](llm/prompts/review.md)
- **Use cases** — scenarios end to end:
  [shared hosting DoS guard](use-cases/shared-hosting-dos-guard.md) ·
  [page cache pollution](use-cases/page-cache-pollution.md) ·
  [scrapers and bots](use-cases/scraping-and-bots.md) ·
  [behind a load balancer](use-cases/behind-a-load-balancer.md) ·
  [an Exponential site](use-cases/exponential.md) ·
  [a pentest asks for rate limits on the forms](use-cases/form-rate-limits.md) ·
  [a developer hardens the application, and tests it](use-cases/harden-and-test.md) ·
  [the sign-in, hardened: three tries, then longer and longer](use-cases/login-backoff.md) ·
  [an accessible website, hardened: no puzzle for anyone](use-cases/accessible-hardening.md)
- **Proposals** — planned features, open for discussion (status in each):
  [0001 earn back a spent budget](proposals/0001-earn-back-a-spent-budget.md) (implemented) ·
  [0003 human-readable rule files](proposals/0003-human-readable-rule-files.md) (implemented) ·
  [0004 modes: monitor and strict](proposals/0004-modes-monitor-and-strict.md) (implemented) ·
  [0005 versioned rule sets](proposals/0005-versioned-rule-sets.md) (implemented) ·
  [0006 the site asks for the check](proposals/0006-the-site-asks-for-the-check.md) (implemented) ·
  [0008 match blocks](proposals/0008-match-blocks.md) (first step implemented) ·
  [0009 typed query parameters](proposals/0009-typed-query-parameters.md) (implemented) ·
  [0010 the browser check inside the form](proposals/0010-browser-check-in-the-form.md) (implemented) ·
  [0011 known crawlers](proposals/0011-known-crawlers.md) (implemented) ·
  [0012 a dashboard](proposals/0012-dashboard.md) (counters implemented) ·
  [0013 IP lists](proposals/0013-ip-lists.md) (implemented, dashboard tab to come) ·
  [0014 crawler statistics](proposals/0014-crawler-statistics.md) (implemented, dashboard tab to come) ·
  [0015 page statistics](proposals/0015-page-statistics.md) (draft) ·
  [0016 the rule advisor](proposals/0016-rule-advisor.md) (draft) ·
  [0017 detecting cross-site scripting](proposals/0017-detecting-xss.md) (draft) ·
  [0018 audience statistics](proposals/0018-audience-statistics.md) (draft) ·
  [0019 the SEO and GEO dashboard](proposals/0019-seo-geo-dashboard.md) (draft) ·
  [0020 cache keys without tracking parameters](proposals/0020-cache-keys-without-tracking.md) (draft) ·
  [0021 rules from the CMS](proposals/0021-rules-from-the-cms.md) (draft) ·
  [0022 the visitors page](proposals/0022-visitors-page.md) (draft) ·
  [0023 plugins, statistics per website, a view for each customer](proposals/0023-plugins-hosts-customers.md) (phase 1 implemented) ·
  [0024 rules per website](proposals/0024-rules-per-website.md) (phase 1 implemented) ·
  [0025 public blocklists](proposals/0025-blocklist-feeds.md) (phases 1–3 implemented; .htaccess dropped after measuring) ·
  [0026 the live view and the lists in the dashboard](proposals/0026-live-view-and-lists.md) (phases 1–3 implemented) ·
  [0027 protected areas: passwords, one-time codes](proposals/0027-protected-areas.md) (draft) ·
  [0028 forms: counted, and sent only from the website itself](proposals/0028-forms.md) (phase 1 implemented; phase 3 with 0008) ·
  [0029 examples next to the rules: expect, a test command, a generated overview](proposals/0029-rule-examples.md) (phase 1 implemented) ·
  [0030 error pages: a quiet page of the shield's own, or the site's](proposals/0030-error-pages.md) (accepted) ·
  [0031 a robust core, everything else a plugin](proposals/0031-robust-core-plugins.md) (draft — the plan; [steps and progress](proposals/0031-steps.md)) ·
  [0002 the single-file build](proposals/0002-single-file-build.md) (draft; part of 0031) ·
  [0032 check levels](proposals/0032-check-levels.md) (draft; largely superseded by 0004) ·
  [0033 event log](proposals/0033-event-log.md) (draft; partly built, the rest a `Sink` plugin) ·
  [0034 budgets for everyone together](proposals/0034-shared-budgets.md) (draft) ·
  [0035 the check for forms that send JSON](proposals/0035-checked-json-forms.md) (draft) ·
  [0036 forms per page: viewed, sent, saved](proposals/0036-forms-per-page.md) (draft) ·
  [0037 named values: an address range, a set of paths, written once](proposals/0037-named-values.md) (draft) ·
  [0038 without JavaScript, and without friction: a fallback and invisible signals](proposals/0038-checks-without-friction.md) (draft) ·
  [0039 a page cache that speaks the known dialects: tags, purges, roles, memory](proposals/0039-cache-compatible.md) (draft) ·
  [0040 in a PHP application server: Qbix and Exponential Velocity](proposals/0040-php-app-servers.md) (draft) ·
  [0041 a risk score per request, shown in the live view](proposals/0041-risk-score.md) (draft) ·
  [0042 a harder task for forms: ALTCHA's v2 work](proposals/0042-pow-v2-for-forms.md) (draft)
  (0007 is unused)
- **Roadmap for 0012–0016** (the reasons in [0012](proposals/0012-dashboard.md#roadmap-for-00120016)):
  counters and crawler statistics first (they answer "did GPTBot crawl the
  site, or was it refused?"), then the dashboard read-only, then the IP lists
  (its first write), the rule advisor, page statistics, and the optional
  language model last. Each is off by default and costs a passing request
  nothing until switched on.
- **Architecture decisions**:
  [0001 run in PHP first](adr/0001-run-in-php-before-the-application.md) ·
  [0002 uncached, not refused](adr/0002-outside-the-definition-is-uncached-not-refused.md) ·
  [0003 append-only file counters](adr/0003-append-only-file-counters.md) ·
  [0004 stateless challenge and pass](adr/0004-stateless-signed-challenge-and-pass.md) ·
  [0005 settings compiled for OPcache](adr/0005-settings-compiled-for-opcache.md) ·
  [0006 the core and its plugins](adr/0006-core-and-plugins.md) ·
  proposed with 0031:
  [0007 an error in the shield lets the request pass](adr/0007-fail-safe-pass-through.md) ·
  [0008 extension points resolved at compile time](adr/0008-extension-points-resolved-at-compile-time.md) ·
  [0009 plugins tighten, never loosen](adr/0009-plugins-tighten-never-loosen.md) ·
  [0010 one signed file per edition](adr/0010-single-file-and-editions.md) ·
  [0011 one source for reference, demos and tests](adr/0011-one-source-for-reference-demos-and-tests.md) ·
  [0012 one repository with split mirrors, signed releases](adr/0012-repository-structure-and-release-signing.md) ·
  [0013 hosting tiers](adr/0013-hosting-tiers.md) ·
  [0014 every byte counts: the `rs` prefix](adr/0014-wire-bytes-and-the-rs-prefix.md)

New proposals: copy the shape of 0001, next number, status **Draft**.
