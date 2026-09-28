# Behind a load balancer or CDN

**Situation:** a balancer ends TLS and forwards to `app:8080`; every request
comes from the balancer's address. Without configuration all visitors share
one budget (and one gets everyone throttled), and the application trusts
whatever `X-Forwarded-*` a client sends.

**With the shield:**

```php
'trustedProxies' => ['10.0.0.5', '10.0.0.6'],      // the balancers (or a CDN's published ranges)
```

The client is the right-most address in `X-Forwarded-For` that no trusted
proxy added; scheme and host come from `X-Forwarded-Proto`/`-Host` only via a
balancer, and from anyone else those headers are removed before the
application runs.

Feature: [trusted proxies](../features/trusted-proxies.md).
