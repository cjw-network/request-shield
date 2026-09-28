# ADR 0001 — Run in PHP, before the application

- Status: accepted (2026-09-28)

## Context

The protection should work where there is nothing but PHP: shared hosting,
no root, no web server configuration beyond `.htaccess`/`.user.ini`.

## Decision

A PHP library that runs as `auto_prepend_file` or as the first line of the
front controller, before the framework's autoloader and database; no
dependencies, PHP 8.1+.

## Consequences

- Works on any PHP host, with any framework.
- A request still reaches PHP: the shield makes it cheap (µs instead of
  100+ ms), it does not keep it out. Volumetric floods remain the job of the
  network, the hoster or a CDN — said plainly in the README.
- Every microsecond on the passing path counts: measured with every change
  (`bench/overhead.php`).
