# RSF4.1 The cacheable definition

`Request::cacheKey()` (0031 C.4) names the answer for an HTTP cache: scheme and host in lower case, the path as the application routes it, the parameters sorted -- nothing of the client; whether the answer may be kept is the decision's (`cacheable()`). A cache is a plugin with the `Handler` capability ([plugins](plugins.md#capabilities-what-a-plugin-can-do-for-the-pages)).

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
