# The browser challenge (proof of work)

*In plain words, for site owners: [the browser check, explained](../explained/browser-check.md).*

## What it does

A client past a budget's `challengeAt` gets, instead of the page, a small page
(about 5 KB, no external resource, nothing to click) whose script solves an
**ALTCHA-compatible proof of work**: find the number `n` with
`sha256(salt . n) == challenge`. It puts the solution in a cookie and reloads;
the shield checks it (one hash, one HMAC), sets a **pass cookie** and lets the
request through. With the pass cookie the client is not challenged again until
it expires (the hard `limit` still applies).

- **Difficulty grows** from `difficulty.min` at the threshold to
  `difficulty.max` at the limit (`maxnumber`; the browser needs `maxnumber / 2`
  hashes on average: about 0.1–0.25 s at the threshold).
- **Stateless:** challenge and pass cookie are signed (HMAC) with `secret`;
  the pass cookie is bound to the client bucket and (by default) its
  User-Agent. Solutions are single-use and expire (`solutionTtl`).
- **Only GET and HEAD are challenged**: a reload cannot repeat a form's POST;
  those get 429 (see proposal 0001 for more).
- **Search engines** that would be challenged are verified by reverse and
  forward DNS (Googlebot, Bingbot, DuckDuckBot, Applebot, YandexBot, …) and
  passed; the answer is remembered for a day per address.
- **Always-checked paths** (`alwaysPaths`): a login or admin page checks every
  visitor once per pass cookie, whatever the budgets say, for every method — a
  bot cannot POST to a login it never loaded (a POST without a pass gets 429).
- **Exempt paths** (APIs, feeds) are never challenged.
- Without JavaScript or cookies the page says what is missing; after three
  failed attempts in a tab it stops instead of looping.

## Use cases

- Scrapers and simple bots: they either run the script (and pay CPU per
  address) or stop.
- A login form: bots that try passwords must first pass the check for each
  address (`alwaysPaths`).
- Burst traffic from one address that is not malicious: a person solves once
  and does not notice.

## Configuration

```php
'budgets' => ['requests' => ['limit' => 600, 'window' => 60, 'challengeAt' => 120]],
'challenge' => [
    'secret' => 'at least 32 characters, the same on every server',   // null: made once in storeDir
    'passTtl' => 3600, 'solutionTtl' => 300,
    'difficulty' => ['min' => 50000, 'max' => 500000],
    'bindUserAgent' => true,
    'searchEngines' => true,            // false: none; or pattern => host suffixes
    'exemptPaths' => ['#^/api/#', '#\.(xml|rss|json)$#'],
    'alwaysPaths' => ['#^/(login|admin)(/|$)#'],   // checked for every visitor
    'texts' => ['lang' => 'de', 'title' => 'Einen Moment bitte', 'text' => '…'],
],
```

## Cost

Only for challenged clients: page about 12 µs, solution check about 9 µs,
pass cookie check about 5 µs. The passing path does not change.

## Limits

A determined attacker with real browsers pays the CPU and passes; the point is
the cost per address, not a wall. The script is plain ES5 with its own
SHA-256 (`crypto.subtle` only exists on HTTPS pages); it is tested in Node
against the PHP check.
