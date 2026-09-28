# Security policy

## Supported versions

The latest release of the 0.x series receives security fixes.

## Reporting a vulnerability

Please do **not** open a public issue. Use GitHub's private vulnerability
reporting instead: **Security → Report a vulnerability** on
https://github.com/cjw-network/request-shield. You will get an answer within a
few working days; please allow time for a fix before disclosing.

Useful in a report: the version, the settings involved (without secrets), the
request that shows the problem, and what you expected.

## Scope

In scope: ways to get past a check the shield claims to make (a hard reject,
a budget, the challenge, the pass cookie, trusted-proxy handling), ways to make
the shield itself slow or fail, and anything that exposes the secret or other
data. Out of scope: volumetric floods that exhaust the network or web server
before PHP runs — see the README's limits.
