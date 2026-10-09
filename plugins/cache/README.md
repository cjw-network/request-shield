# request-shield HTTP cache

`cjw-network/request-shield-cache` -- a plugin of
[request-shield](https://github.com/cjw-network/request-shield), the mini web application firewall for PHP that
runs before the site's code.

Keeps the public answers of the addresses a cache may keep and answers them before the application starts: on disk with a cap, in APCu when there is room; the visitor's role in the key (`Shield::cacheContext()`), tags and purges (Exponential's and Ibexa's dialects, the CLI, the API). Off by default -- a request pays nothing for it then.

## Where it is developed

In the monorepo, [`plugins/cache`](https://github.com/cjw-network/request-shield/tree/main/plugins/cache), together
with the core and the other plugins: its tests, issues and pull requests are
there. This package is a read-only copy of that directory.

## Install

Until v1.0 the plugin ships inside the core package and its single-file
build; from v1.0 on it is a package of its own:

```sh
composer require cjw-network/request-shield-cache
```

It needs the core, `cjw-network/request-shield`. The core knows the plugin's words without a `plugin` line
once its classes are there.

## Switch it on

In the rule file:

```text
set http-cache on
set http-cache-hosts www.example.org example.org
```

## Docs

- [RSF04-03 The HTTP cache](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF04-03-http-cache.md)
- [RSF04-01 What a cache may keep](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF04-01-cacheable-definition.md)
- [Plugins](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF06-04-plugins.md)

## License

MIT, see [LICENSE](LICENSE). Security reports: see [SECURITY.md](SECURITY.md).
