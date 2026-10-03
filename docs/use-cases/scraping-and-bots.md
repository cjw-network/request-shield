# Scrapers and bots, without locking out people or search engines

**Situation:** a scraper fetches thousands of pages an hour from a few
addresses (or rotates through an IPv6 /64). Blocking by address hits office
networks behind one NAT; a CAPTCHA annoys everyone.

**With the shield:** a request budget with `challengeAt`. Past it, a client
solves an invisible proof of work once per hour (`passTtl`); a person sees
"one moment, please" for a fraction of a second. A scraper has to run
JavaScript and pay the CPU for every address and every hour, and gets more
expensive the harder it pushes (difficulty grows towards the limit).
Googlebot, Bingbot and the other major crawlers are verified by DNS and never
challenged. IPv6 clients are counted per /64, so rotating inside it does not
help.

Features: [browser challenge](../features/RSF03-02-browser-challenge.md), [budgets](../features/RSF03-01-budgets.md).
