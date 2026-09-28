# Settings, checked once

## What it does

The configuration is one PHP array (`src/Config.php` has every key with its
default). It is **checked** into typed values — a wrong type is an error that
names the key — and, with `Shield::protectFile()` or `bootstrap.php`,
**compiled**: the checked values are written once as a PHP file (0600) that
OPcache serves from memory, and rebuilt when the settings file's mtime or size
changes.

## Use cases

- Every request on a busy site: settings cost about 8 µs instead of 10–11 µs,
  most of it one `stat()` of the settings file to notice changes.
- Deploying a wrong setting: the error names it at once
  (`request-shield: 'budgets.requests.limit' must be an integer`).

## Configuration

```php
\CjwNetwork\RequestShield\Shield::protectFile('/path/to/request-shield.php');   // compiled
\CjwNetwork\RequestShield\Shield::protect($configArray);                       // checked every time
```

The compiled file goes to `sys_get_temp_dir()/request-shield/` unless
`protectFile()` is given another directory.

## Limits

A settings file changed twice within one second to the same size keeps the
first change until the next write; edit tools rarely manage that.
