# 0034 — Budgets for everyone together

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-04 |
| Affects | the budgets (`limit`), the settings compiler, the shield's budget step, the docs |
| Relates to | [budgets](../features/RSF03-01-budgets.md) · [the check inside the form](../features/RSF03-03-browser-check-in-the-form.md) · [0030 error pages](0030-error-pages.md) · [use case: rate limits on the forms](../use-cases/form-rate-limits.md) |

## The problem

Every budget counts per sender: per IPv4 address, per IPv6 /64. That stops
one bot that sends a form a thousand times. It does not stop a thousand
addresses that send it once each, a botnet or a cheap proxy service. A
pentest that asks for "a rate limit on the application form" often means
both: per sender, and for the form as a whole.

## The idea

One word makes a budget count all senders together:

```text
[FORM-ALL] limit forms-all 200/hour shared at /forms/submit
```

- **One counter** for the website (per `site` block, so one website's flood
  does not lock another's forms), next to the per-sender budgets, which stay.
- **`shared` needs `at` or a `match` block**: a shared budget for every
  request of a site would be a switch that turns the site off.
- **`check` warns** when a shared limit is below a per-sender limit on the
  same paths: it would decide first.

## Past it: the hard part

A shared limit is a lever for an attacker too: whoever fills it locks out
every real applicant. That is a denial of service the shield would do
itself. Three answers, from blunt to fair:

1. **429 for everyone** until the window frees. Simple, and exactly the
   lockout above. Only where losing the form for an hour is acceptable.
2. **The browser check for everyone** past `challenge-at`, 429 past the
   limit. A real browser passes in a moment; a botnet pays computing time for
   every address. For a form sent by a script as JSON, the check has to run
   inside the form ([the widget](../features/RSF03-03-browser-check-in-the-form.md)),
   so the request arrives with a pass.
3. **Senders with a pass are not counted** against the shared budget, only
   those without. Real visitors who passed the check keep the form; the
   flood without passes meets the limit.

Recommended: 2 and 3 together. `shared` with `challenge-at`, passes not
counted, 429 only past the limit for those without a pass.

## Cost

One more counter per counted request, only on the paths of a shared budget:
about 0.1 µs with APCu. The file store appends one byte to one file that all
senders share; `O_APPEND` keeps that exact under contention. Nothing on any
other path.

## Open questions for the owner

- Is lockout (1) offered at all, or only with a pass exemption (3)?
- Per website, or also per server for hosters (`shared server`)?
- Should a shared budget that is spent raise an alert (a plugin's job)?
