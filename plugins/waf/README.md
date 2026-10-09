# request-shield WAF pages

`cjw-network/request-shield-waf` -- a plugin of
[request-shield](https://github.com/cjw-network/request-shield), the mini web application firewall for PHP that
runs before the site's code.

The firewall's pages below `<dashboard-path>/waf`: Rules & setup, Live and Lists, the demo's pages and the `examples` command. The pages read their data where the API reads it, so this package needs `request-shield-api`.

## Where it is developed

In the monorepo, [`plugins/waf`](https://github.com/cjw-network/request-shield/tree/main/plugins/waf), together
with the core and the other plugins: its tests, issues and pull requests are
there. This package is a read-only copy of that directory.

## Install

Until v1.0 the plugin ships inside the core package and its single-file
build; from v1.0 on it is a package of its own:

```sh
composer require cjw-network/request-shield-waf
```

It needs the core, `cjw-network/request-shield` and `cjw-network/request-shield-api`. The core knows the plugin's words without a `plugin` line
once its classes are there.

## Switch it on

In the rule file:

```text
set dashboard-path /rs       # the pages' place; guarded like every page of the dashboard
```

## Docs

- [RSF06-01 The active rules page](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF06-01-active-rules-page.md)
- [RSF06-02 Live view and lists](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF06-02-live-and-lists.md)
- [Plugins](https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF06-04-plugins.md)

## License

MIT, see [LICENSE](LICENSE). Security reports: see [SECURITY.md](SECURITY.md).
