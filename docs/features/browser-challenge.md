# The browser challenge (proof of work)

*In plain words, for site owners: [the browser check, explained](../explained/browser-check.md).*

## Languages

The check page and the shield's own answers (a pause, "not found", "no
access") are in the visitor's language: the one their browser asks for
(`Accept-Language`) among those there are texts for — **English and German
built in** — else English; `language: 'de'` (`set language de`) fixes one.
Own texts per language: `'texts' => ['de.title' => '…', 'title' => '…']`
(`set text.de.title …`; without a language for all). Those answers carry
`Vary: Accept-Language`.

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
- **Forms are checked and sent again:** a POST that meets the check gets the
  check page carrying the form's fields, which sends them again once solved —
  nothing typed is lost. Forms with files, or larger than 256 KB, cannot be
  carried: the page asks to send them again (the check inside the form avoids
  that). APIs (JSON, `api-path`) get the task as a header.
- **Known crawlers** — search engines and AI crawlers that behave — are
  never given the check: verified by their operators' published address
  lists or by DNS, never by the name they send
  ([known crawlers](known-crawlers.md)). A site can check or refuse them by
  kind or one by one.
- **Always-checked paths** (`alwaysPaths`): a login or admin page checks every
  visitor once per pass cookie, whatever the budgets say, for every method — a
  bot cannot POST to a login it never loaded (a POST without a pass gets the check first).
- **Exempt paths** (APIs, feeds) are never challenged.
- Without JavaScript or cookies the page says what is missing; after three
  failed attempts in a tab it stops instead of looping.


## No internet needed — and a DMZ

The check itself makes **no connection to anyone**: the task, its signature,
the check of the answer and the pass cookie are all made on the site's own
server with its own secret; the browser gets everything from the site. It works
in a closed network, a DMZ, without any outside service.

The **only** contact with the outside is DNS, to verify a crawler that has no
published address list, or is not on it (Yandex, Baidu, Amazonbot; Google and
Bing also by DNS): only for a request that would get the check **and** names
such a crawler, and remembered for a day per address. Without a DNS
server that answers (a DMZ), such a lookup waits for the resolver's timeout; so
at most `dns-lookups` new lookups a minute are made for all requests together
(default 30) — past that, a claimed crawler counts as not verified at once and
nothing is remembered, so a flood of fake crawlers cannot make requests wait.

In a DMZ without DNS: `set crawler-verify ranges` — crawlers are verified by
their published address lists only, a comparison on the server: Google, Bing,
Apple, DuckDuckGo, OpenAI, Anthropic, Perplexity, Common Crawl. Those only DNS
can verify are then ordinary visitors (checked where the site checks). The
lists come with each release; `php bin/request-shield crawlers <site.rules>
update` fetches newer ones where there is internet, and `store-dir/crawlers/`
is deployed like any other file ([known crawlers](known-crawlers.md#without-internet-a-dmz)).
`set dns-lookups 0` or a short resolver timeout (`options timeout:1 attempts:1`
in `/etc/resolv.conf`) also keep DNS from making anyone wait.

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
