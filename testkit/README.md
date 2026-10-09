# request-shield testkit

`cjw-network/request-shield-testkit` -- the test runner and helpers that
[request-shield](https://github.com/cjw-network/request-shield) and its plugins test themselves with. No framework:
a test file returns an array of name => closure, and a closure passes when it
returns without an exception.

```php
<?php // tests/CacheTest.php
return [
    'RSF04-03 a page is kept' => function (): void {
        [$status, $cache] = fetchTwice('/news');
        same(200, $status, 'answered');
        truthy($cache === 'hit', 'from the cache');
    },
];
```

```php
<?php // tests/run.php
require __DIR__ . '/../vendor/autoload.php';
exit(testkitRun(__FILE__, array_values(array_map('strval', $argv)), glob(__DIR__ . '/*Test.php') ?: []));
```

```sh
php tests/run.php                 # all
php tests/run.php RSF04-03        # one feature's tests, by the id their names begin with
php tests/run.php "Cache > kept"  # a part of "File > test name"
TESTS_ISOLATE=1 php tests/run.php # each file in its own process: a crash names its test
```

- `same($expected, $actual, $what)`, `truthy($bool, $what)` -- the checks;
  `skip($why)` -- a test that cannot run here, counted and named (with
  `TESTS_FAIL_ON_SKIP=1` it counts as a failure).
- `freePort()`, `nodeBinary()` -- for tests that start a server or run a
  page's script.
- `testkitRun(..., $before)` -- `$before($name)` runs before each test and may
  skip it.
- In GitHub Actions a failure is also an annotation.

Developed in the monorepo, [`testkit/`](https://github.com/cjw-network/request-shield/tree/main/testkit); this
package is a read-only copy of that directory, published when a plugin's
tests need it outside the monorepo.

## License

MIT, see [LICENSE](LICENSE). Security reports: see [SECURITY.md](SECURITY.md).
