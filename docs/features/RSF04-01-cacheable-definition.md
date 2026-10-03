# RSF04-01 The cacheable definition

`Request::cacheKey()` (0031 C.4) names the answer for an HTTP cache: scheme and host in lower case, the path as the application routes it, the parameters sorted -- nothing of the client; whether the answer may be kept is the decision's (`cacheable()`). A cache is a plugin with the `Handler` capability ([plugins](RSF06-04-plugins.md#capabilities-what-a-plugin-can-do-for-the-pages)).

## What it does

Says which answers a page cache may keep. A request outside the definition is
**not refused** — the definition can lag behind the site, a page published a
second ago — but answered as `allow-uncached`: the application renders it, and
no cache stores it. So random paths and parameters can never fill a cache.

- `cacheable.query`: the query parameter names a cacheable URL may carry
  (`null`: any, `[]`: none).
- `cacheable.paths`: regular expressions a cacheable path matches (`null`: any).
- An adapter's URL index (the `$known` callback): `true` = cacheable,
  `false` = not, `null` = no opinion (the patterns decide).
- Anything but GET and HEAD is never cacheable.

## Use cases

- `?utm_source=…`, `?x=<random>` or invented paths on a site with a page cache
  (Varnish, LSCache, Exponential's HTTP cache): answered, never stored.
- An application reads the decision and skips its own cache write.

## Configuration

```php
'cacheable' => ['query' => ['page'], 'paths' => ['#^/[a-z0-9_/-]*$#']],
```

In the application:

```php
if (!\CjwNetwork\RequestShield\Shield::current()?->cacheable()) {
    // answer, but do not store
}
```

or `$_SERVER['REQUEST_SHIELD'] === 'allow-uncached'`.

## Cost

About 0.5 µs.

## Limits

The shield only marks; the cache has to honour the mark. Adapters for
Exponential, WordPress and Ibexa (planned) do that for their caches.

## Examples from the demo

What the demo's rules decide for this feature -- the same lines `request-shield test` checks and the demo's front page shows (`php -S 127.0.0.1:8080 examples/demo/router.php`).

<!-- examples: docs/tools/sync-examples.php from examples/demo/request-shield.rules -- do not edit; run the tool. -->
**RSF04-01 · What a cache may keep**

Every page passes; only some may be kept by a cache. Unknown paths and parameters reach the site, but marked so a cache does not keep them -- made-up URLs cannot fill it.

| Request | The rules decide | |
|---|---|---|
| `/` | passes; a cache may keep it — from another address (198.51.100.7) | A normal page |
| `/page/about` | passes; a cache may keep it — from another address (198.51.100.7) | A known page |
| `/?utm_source=newsletter` | passes, but a cache must not keep it — from another address (198.51.100.7) | A link from a newsletter: a known marketing tag, not for a cache |
| `/random/abc` | passes, but a cache must not keep it — from another address (198.51.100.7) | An unknown path: the site answers it (200 or 404), a cache must not keep it |
<!-- /examples -->
