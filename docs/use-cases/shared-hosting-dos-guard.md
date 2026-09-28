# A small DoS guard on shared hosting

**Situation:** a PHP site on shared hosting, no root, no APCu, no Redis. A bot
or a small flood hits it; every request boots the framework and the database,
and the hosting account's PHP workers are gone within seconds.

**With the shield:**

1. Put `request-shield/` outside the document root, copy
   `config/request-shield.dist.php` to `config/request-shield.php`.
2. Prepend it: `.user.ini` → `auto_prepend_file = /home/you/request-shield/bootstrap.php`
   (or `php_value auto_prepend_file …` in `.htaccess`).
3. A budget that fits the site, with the browser check before it:

```php
return [
    'hosts' => ['www.example.org', 'example.org'],
    'blockedPaths' => array_merge(\CjwNetwork\RequestShield\Config::scannerPaths(), \CjwNetwork\RequestShield\Config::wordpressPaths()),
    'budgets' => ['requests' => ['limit' => 300, 'window' => 60, 'challengeAt' => 120]],
    'store' => 'file', 'storeDir' => '/home/you/request-shield/var',
];
```

**What changes:** scanner requests end in a 404 after a few string
comparisons; a client past 120 requests a minute solves one proof of work (a
person does not notice, a bot pays per address); past 300 it waits (429). The
application sees none of these requests. Each costs well under 0.1 ms instead
of 100–200 ms.

**What does not:** a flood still reaches PHP. Against volumetric attacks the
hoster's protection (or a CDN) is what helps; the shield keeps each request
cheap.

Features: [hard rejects](../features/hard-rejects.md), [budgets](../features/budgets.md),
[browser challenge](../features/browser-challenge.md).
