# request-shield API

`cjw-network/request-shield-api` -- a plugin of
[request-shield](https://github.com/cjw-network/request-shield), the mini web application firewall for PHP that
runs before the site's code.

The shield's data as JSON below `<dashboard-path>/api/v1` -- status, rules, the way of a request, live rows, lists, the log -- for a CMS's backend, a JavaScript page, a script or a language model. Guarded like every page of the dashboard (a restrict rule, or a token). The other plugins add their endpoints to it (the statistics, the cache).

## Where it is developed

In the monorepo, [`plugins/api`](https://github.com/cjw-network/request-shield/tree/main/plugins/api), together
with the core and the other plugins: its tests, issues and pull requests are
there. This package is a read-only copy of that directory.

## Install

Until v1.0 the plugin ships inside the core package and its single-file
build; from v1.0 on it is a package of its own:

```sh
composer require cjw-network/request-shield-api
```

It needs the core, `cjw-network/request-shield`. The core knows the plugin's words without a `plugin` line
once its classes are there.

## Switch it on

In the rule file:

```text
set api off                 # on by default: as guarded as the pages
set api-write on            # the endpoints that change something: off by default
```

## Docs

- [RSF06-05 The API](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF06-05-api.md)
- [Plugins](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF06-04-plugins.md)

## License

MIT, see [LICENSE](LICENSE). Security reports: see [SECURITY.md](SECURITY.md).
