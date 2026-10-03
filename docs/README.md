# Documentation

- **Explained** — for site owners, in plain words:
  [the parts and their switches](explained/parts.md) ·
  [the browser check](explained/browser-check.md)
- **Features** — what each part does, use cases, configuration, cost, limits:
  [trusted proxies](features/trusted-proxies.md) ·
  [hard rejects](features/hard-rejects.md) ·
  [cacheable definition](features/cacheable-definition.md) ·
  [budgets and stores](features/budgets.md) ·
  [browser challenge](features/browser-challenge.md) ·
  [access rules](features/access-rules.md) ·
  [rule files](features/rule-files.md) ·
  [forms only from the website itself (`post-origin`)](features/forms-from-the-website.md) ·
  [examples next to the rules (`expect`, `request-shield test`)](features/rule-examples.md) ·
  [log and rule IDs](features/log-and-rule-ids.md) ·
  [active rules page](features/active-rules-page.md) ·
  [the site asks for the check](features/app-challenges.md) ·
  [the check inside the form](features/browser-check-in-the-form.md) ·
  [known query parameters](features/known-parameters.md) ·
  [modes: monitor and strict](features/modes.md) ·
  [IP lists and automatic bans](features/ip-lists.md) ·
  [the live view and the lists in the dashboard](features/live-and-lists.md) ·
  [public blocklists (feeds)](features/blocklist-feeds.md) ·
  [known crawlers](features/known-crawlers.md) ·
  [statistics](features/statistics.md) ·
  [plugins](features/plugins.md) ·
  [settings](features/settings.md)
- **Privacy** — what the shield processes about visitors, feature by feature,
  and the GDPR: [privacy and the GDPR](privacy.md)
- **Use cases** — scenarios end to end:
  [shared hosting DoS guard](use-cases/shared-hosting-dos-guard.md) ·
  [page cache pollution](use-cases/page-cache-pollution.md) ·
  [scrapers and bots](use-cases/scraping-and-bots.md) ·
  [behind a load balancer](use-cases/behind-a-load-balancer.md) ·
  [an Exponential site](use-cases/exponential.md)
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
  [0031 a robust core, everything else a plugin](proposals/0031-robust-core-plugins.md) (draft — the plan; [steps and progress](proposals/0031-steps.md))
  (0002, a single-file build, is reserved)
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
  [0006 the core and its plugins](adr/0006-core-and-plugins.md)

New proposals: copy the shape of 0001, next number, status **Draft**.
