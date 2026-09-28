# Documentation

- **Features** — what each part does, use cases, configuration, cost, limits:
  [trusted proxies](features/trusted-proxies.md) ·
  [hard rejects](features/hard-rejects.md) ·
  [cacheable definition](features/cacheable-definition.md) ·
  [budgets and stores](features/budgets.md) ·
  [browser challenge](features/browser-challenge.md) ·
  [settings](features/settings.md)
- **Use cases** — scenarios end to end:
  [shared hosting DoS guard](use-cases/shared-hosting-dos-guard.md) ·
  [page cache pollution](use-cases/page-cache-pollution.md) ·
  [scrapers and bots](use-cases/scraping-and-bots.md) ·
  [behind a load balancer](use-cases/behind-a-load-balancer.md)
- **Proposals** — planned features, open for discussion (status in each):
  [0001 earn back a spent budget](proposals/0001-earn-back-a-spent-budget.md)
- **Architecture decisions**:
  [0001 run in PHP first](adr/0001-run-in-php-before-the-application.md) ·
  [0002 uncached, not refused](adr/0002-outside-the-definition-is-uncached-not-refused.md) ·
  [0003 append-only file counters](adr/0003-append-only-file-counters.md) ·
  [0004 stateless challenge and pass](adr/0004-stateless-signed-challenge-and-pass.md) ·
  [0005 settings compiled for OPcache](adr/0005-settings-compiled-for-opcache.md)

New proposals: copy the shape of 0001, next number, status **Draft**.
