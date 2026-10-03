# RSF1.1 Trusted proxies and client identity

## What it does

Works out who the client is, and which scheme and host it asked for, the way
the rest of the shield (and your application) should see it:

- `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host` are read **only
  when the direct peer is a trusted proxy** (your load balancer, reverse proxy
  or CDN). `X-Forwarded-For` is read right to left and stops at the first
  address no trusted proxy vouches for, so a client cannot put an address in
  front of its own.
- From everyone else these headers are **removed from `$_SERVER`** before the
  application runs (`stripUntrustedForwarded`), so the application cannot be
  told another client, scheme or host either.
- The client's **bucket** — the key its budgets are counted under — is its IPv4
  address, or its IPv6 **/64** prefix (`ipv6Prefix`), since one subscriber
  usually holds a whole /64 and can rotate freely inside it.

## Use cases

- A site behind a TLS-ending load balancer: the application sees the visitor's
  address and `https`, not the balancer's.
- Cache pollution: a page cache keyed by host cannot be filled with pages for
  hosts a client invents in `X-Forwarded-Host`.
- Budgets that cannot be dodged by sending a fake `X-Forwarded-For`.

## Configuration

```php
'trustedProxies' => ['10.0.0.0/8', '2001:db8:100::/48', '192.0.2.10'],
'stripUntrustedForwarded' => true,
'ipv6Prefix' => 64,
```

## Cost

Part of building the request: about 1–2 µs.

## Limits

The shield cannot know your network: without `trustedProxies`, a site behind
a proxy sees the proxy as its only client — list it.
