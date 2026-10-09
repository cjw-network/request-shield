# request-shield statistics

`cjw-network/request-shield-stats` -- a plugin of
[request-shield](https://github.com/cjw-network/request-shield), the mini web application firewall for PHP that
runs before the site's code.

Counts what the shield let through and refused -- per day, page and site, with answer times and the HTTP caches' results -- without cookies and without storing addresses. Its pages lie below `<dashboard-path>/stats`; with `request-shield-api` its numbers are endpoints too.

## Where it is developed

In the monorepo, [`plugins/stats`](https://github.com/cjw-network/request-shield/tree/main/plugins/stats), together
with the core and the other plugins: its tests, issues and pull requests are
there. This package is a read-only copy of that directory.

## Install

Until v1.0 the plugin ships inside the core package and its single-file
build; from v1.0 on it is a package of its own:

```sh
composer require cjw-network/request-shield-stats
```

It needs the core, `cjw-network/request-shield`. The core knows the plugin's words without a `plugin` line
once its classes are there.

## Switch it on

In the rule file:

```text
set stats on
```

## Docs

- [RSF06-03 Statistics](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF06-03-statistics.md)
- [Plugins](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF06-04-plugins.md)

## License

MIT, see [LICENSE](LICENSE). Security reports: see [SECURITY.md](SECURITY.md).
