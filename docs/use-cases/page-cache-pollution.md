# Keeping a page cache clean

**Situation:** a page cache (Varnish, LSCache, an application's own) stores a
page per URL and host. Someone requests `/?x=1`, `/?x=2`, … or sends made-up
`X-Forwarded-Host` headers: each is a miss (a render) and a new entry that
pushes real pages out.

**With the shield:**

```php
return [
    'trustedProxies' => ['10.0.0.0/8'],          // only the balancer may forward
    'hosts' => ['www.example.org'],
    'cacheable' => ['query' => ['page'], 'paths' => null],
    'budgets' => ['misses' => ['limit' => 60, 'window' => 60, 'onDemand' => true]],
];
```

- Forged `X-Forwarded-*` from anyone but the balancer are dropped before the
  application — no page for an invented host is rendered or stored.
- An unknown host is a 404; an unknown query parameter is answered
  `allow-uncached`, and the cache does not store it.
- The cache counts its misses with `Shield::consume('misses', $request)`; a
  client that makes the site render 60 times a minute gets 429.

Features: [trusted proxies](../features/RSF01-01-trusted-proxies.md),
[cacheable definition](../features/RSF04-01-cacheable-definition.md), [budgets](../features/RSF03-01-budgets.md).
