# RSF03-01 Budgets and stores

## What it does

Counts requests (or events) per client bucket and window, as a sliding window
estimated from two fixed ones (two integers per key, no list of timestamps):

- above `challengeAt`: the client is challenged (see the browser challenge);
- above `limit`: `429 Too Many Requests` with `Retry-After`;
- exempt addresses (`exempt.ips`) are never counted.

**On-demand budgets** (`'onDemand' => true`) are not counted per request but by
the application or its cache, with `Shield::consume('name', $request)` — a
cache miss, a failed sign-in.

**Stores**, chosen with `store` (`auto`: APCu when usable, else files):

| Store | How | For |
|---|---|---|
| APCu | one atomic `apcu_inc()` | one PHP pool / server |
| files | one byte **appended** per request, the file's size is the count; `O_APPEND` of one byte is atomic, so no lock and no lost count; a small sweep removes old windows | shared hosting without APCu |
| memory | a PHP array | tests, long-running servers |


## Past the limit: a pause, or earn it back

By default a client past a budget's `limit` gets a pause: `429 Too Many
Requests` with `Retry-After` — what every API, tool and crawler understands.
Per budget, a site can let its clients **earn the budget back** instead:

```text
limit requests 600/min challenge-at 300 on-exceeded challenge
limit posts    20/min on-demand on-exceeded challenge      # counted by the site: consume('posts', answer: true)
api-path /api/**                                           # a check there is JSON, not a page
```

- Past the limit comes the browser check; **solved, the client's counter for
  that budget starts again**. No pass cookie, no exempt path gets past it —
  only a solution made for this budget (bound to it, once, for a few minutes).
- **Twice as hard each time** within an hour: from `difficulty-min`, doubled
  per solve, at most `difficulty-max` (defaults 50,000 → … → 500,000); an hour
  without a solve starts again. A person solves once or twice; a bot that keeps
  coming back pays more each time.
- **Forms** come back after the check (the fields in the page, as in
  [the site asks for the check](RSF03-04-app-challenges.md)); a form with files needs the
  [check inside the form](RSF03-03-browser-check-in-the-form.md).
- **APIs** — requests asking for or sending JSON, or on an `api-path` — get
  `429` with the task as JSON and in the header `Request-Shield-Challenge`
  (ALTCHA's format, base64url); the client solves it and repeats the request
  with `Request-Shield-Solution: <payload>`, or waits `Retry-After`.
- **Verified search engines** get the pause (they cannot solve the check).
- Budgets counted by the site: `Shield::active()->consume('posts', answer: true)`
  — the shield answers a refusal itself (the pause, or the check with the form)
  and the request ends there; without `answer` the decision is returned.

![Past the limit: a pause by default; where switched on, an invisible check -- solved, the counter starts again](../proposals/0001-earn-back-a-spent-budget.svg)

Proposal: [0001](../proposals/0001-earn-back-a-spent-budget.md).

## Use cases

- A small DoS guard on shared hosting: a flood from one address ends in 429
  after `limit` requests, without the application running.
- Limiting renders (cache misses) per client, sign-in attempts, form posts.

## Configuration

```php
'budgets' => [
    'requests' => ['limit' => 600, 'window' => 60, 'challengeAt' => 300],
    'misses'   => ['limit' => 60,  'window' => 60, 'onDemand' => true],
],
'exempt' => ['ips' => ['127.0.0.1', '::1', '192.0.2.50']],
'store' => 'auto',
'storeDir' => '/home/you/request-shield/var',
```

## A budget for one area

A budget counts every request by default (or, `on-demand`, what the site counts
itself). Written inside a `match` block, or with `at <paths>`, it counts only
the requests to that area:

```text
match /search/** {
  [SITE-SEARCH] limit searches 10/min on-exceeded challenge   # the search: 10 a minute, then the check
}
[SITE-API]      limit api 120/min at /api/** /v2/**            # the same, written on one line
```

- Requests elsewhere never touch the counter; a client paging through search
  results is counted, one reading the news is not.
- `on-demand` works too: `consume('carts')` from the site counts only for a
  request inside the area.
- In a `site` block: that website, that area. `monitor limit …` inside a block
  is watched as any other.
- **One name, one budget:** an area's budget needs a name of its own. Calling
  it `requests` (the default pace) or reusing a name that counts everywhere is
  an error naming the line, so the site-wide pace is never replaced by
  accident.
- PHP settings: `'budgets' => ['searches' => ['limit' => 10, 'window' => 60, 'paths' => ['#^/search(?:/.*)?$#i']]]`.
- Cost: one pattern match per request for each area's budget (about 0.1 µs).

## Cost

About 1 µs (APCu, memory); the file store about 30 µs (it touches the disk).
Tested: 8 processes × 250 hits on the file store count exactly 2,000.

## Limits

Counters belong to one server (APCu) or one file system (files). A site on
several servers counts per server unless they share the store directory.
