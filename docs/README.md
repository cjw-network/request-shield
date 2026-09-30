# Documentation

- **Explained** — for site owners, in plain words:
  [the browser check](explained/browser-check.md)
- **Features** — what each part does, use cases, configuration, cost, limits:
  [trusted proxies](features/trusted-proxies.md) ·
  [hard rejects](features/hard-rejects.md) ·
  [cacheable definition](features/cacheable-definition.md) ·
  [budgets and stores](features/budgets.md) ·
  [browser challenge](features/browser-challenge.md) ·
  [access rules](features/access-rules.md) ·
  [rule files](features/rule-files.md) ·
  [log and rule IDs](features/log-and-rule-ids.md) ·
  [active rules page](features/active-rules-page.md) ·
  [the site asks for the check](features/app-challenges.md) ·
  [the check inside the form](features/browser-check-in-the-form.md) ·
  [settings](features/settings.md)
- **Use cases** — scenarios end to end:
  [shared hosting DoS guard](use-cases/shared-hosting-dos-guard.md) ·
  [page cache pollution](use-cases/page-cache-pollution.md) ·
  [scrapers and bots](use-cases/scraping-and-bots.md) ·
  [behind a load balancer](use-cases/behind-a-load-balancer.md)
- **Proposals** — planned features, open for discussion (status in each):
  [0001 earn back a spent budget](proposals/0001-earn-back-a-spent-budget.md) (accepted) ·
  [0003 human-readable rule files](proposals/0003-human-readable-rule-files.md) (implemented) ·
  [0004 modes: monitor and strict](proposals/0004-modes-monitor-and-strict.md) ·
  [0005 versioned rule sets](proposals/0005-versioned-rule-sets.md) (implemented) ·
  [0006 the site asks for the check](proposals/0006-the-site-asks-for-the-check.md) (implemented) ·
  [0008 match blocks](proposals/0008-match-blocks.md) (first step implemented) ·
  [0009 typed query parameters](proposals/0009-typed-query-parameters.md) ·
  [0010 the browser check inside the form](proposals/0010-browser-check-in-the-form.md) (implemented)
  (0002, a single-file build, is reserved)
- **Architecture decisions**:
  [0001 run in PHP first](adr/0001-run-in-php-before-the-application.md) ·
  [0002 uncached, not refused](adr/0002-outside-the-definition-is-uncached-not-refused.md) ·
  [0003 append-only file counters](adr/0003-append-only-file-counters.md) ·
  [0004 stateless challenge and pass](adr/0004-stateless-signed-challenge-and-pass.md) ·
  [0005 settings compiled for OPcache](adr/0005-settings-compiled-for-opcache.md)

New proposals: copy the shape of 0001, next number, status **Draft**.
