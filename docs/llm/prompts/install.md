# The install prompt

What a site owner pastes into an AI agent that has access to their PHP
application's files and server. The project promises that an agent following
[llms.txt](../../../llms.txt) and `docs/llm/*` can complete it; the
[review prompt](review.md) checks that promise (criteria F1–F4).

[docs/llm/install.md](../install.md) is the guide it points to, followed in a
container ([the dry run](../install-dryrun.md)). Until the first release is
published, the download address has no file yet; the releases are signed
once the release key exists (0031 step H.2a).

```text
Protect this PHP site with request-shield (https://github.com/cjw-network/request-shield), a
dependency-free mini WAF that runs before the application. Read its llms.txt first and follow
docs/llm/install.md, docs/llm/write-rules.md and docs/llm/check.md literally. Then:

1. Download https://github.com/cjw-network/request-shield/releases/latest/download/request-shield.php
   with its SHA256SUMS (and .minisig, once the releases are signed) into a directory OUTSIDE the
   document root (e.g. ../request-shield/), and check it: php request-shield.php verify request-shield.php.
   Give the shield its directory .request-shield/ there (mode 700, owned by the user PHP runs as).
   Never put the file, .request-shield/ or the rules inside the document root.
2. Detect the application type from its files (wp-config.php = WordPress; composer.json with
   symfony/* or ibexa/*; config.php + settings/ = Exponential; else plain PHP) and run
   php request-shield.php init --app=<type> --docroot=<path> --out=../request-shield/request-shield.rules
3. Derive the site's rules from the application and complete request-shield.rules: public routes and the
   sitemap (cache-path), the query parameters the code reads (query <name> <type>), forms and where
   they post (allow POST, post-origin), admin paths (challenge; restrict only with address ranges I
   give you — never guess them), APIs (api-path), include @tracking and @attacks, a request limit
   with challenge-at. Keep `set mode monitor` and `set log` on.
4. Add `expect` lines next to every rule (what it refuses, and at least one near miss it must let
   through), using the site's real public addresses and documentation IP ranges only.
5. Activate it: .user.ini or .htaccess `auto_prepend_file`, or the first line of the front
   controller — choose from what this server uses, and show me the exact line before writing it.
6. Run php request-shield.php check request-shield.rules (as the PHP user) and
   php request-shield.php test request-shield.rules until both exit 0; then request the front page
   and a PHP page with an attack pattern in the query, and show me the log lines it wrote.
7. Report: the files you changed, each rule with a one-line reason, the test output, and what I
   must do next (read .request-shield/shield.log for a few days, then change monitor to enforce).

Do not switch to enforce yourself. Do not put any secret, token or real visitor address into any
file. Do not install anything else.
```
