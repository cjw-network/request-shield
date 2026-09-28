# Budgets and stores

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

## Cost

About 1 µs (APCu, memory); the file store about 30 µs (it touches the disk).
Tested: 8 processes × 250 hits on the file store count exactly 2,000.

## Limits

Counters belong to one server (APCu) or one file system (files). A site on
several servers counts per server unless they share the store directory.
