<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

/**
 * The rule file's words and set keys in words (0031 F.5): what each one is
 * written like, what it sets and what it does -- the one source of the
 * tables in docs/features/RSF05-01-rule-files.md and of docs/reference/*,
 * which docs/tools/gen-reference.php writes from here (`--check` in the
 * tests). Each word and key of the core and of the shipped extension is in a
 * row; Vocabulary::FEATURES names the feature each belongs to. Markdown,
 * links relative to the rule files page. Never read on a request.
 */
final class Reference
{
    /**
     * The rules: the words a row is about, how it is written, what it sets, what it does --
     * and its feature where it is not its first word's (block query … is an attack pattern).
     *
     * @var list<array{words: list<string>, syntax: string, setting: string, about: string, feature?: string}>
     */
    public const RULES = [
        ['words' => ['host'], 'syntax' => '`host <names>`',
            'setting' => '`hosts`', 'about' => 'the site\'s hosts; others 404'],
        ['words' => ['trust'], 'syntax' => '`trust <addresses or ranges>`',
            'setting' => '`trustedProxies`', 'about' => 'who may send `X-Forwarded-*`'],
        ['words' => ['method'], 'syntax' => '`method <METHODS>`',
            'setting' => '`methods`', 'about' => 'methods allowed at all'],
        ['words' => ['allow'], 'syntax' => '`allow <METHODS> <paths>`',
            'setting' => '`methodPaths`', 'about' => 'those methods only there, else 405 ([access rules](RSF02-03-access-rules.md))'],
        ['words' => ['restrict'], 'syntax' => '`restrict <paths> to <addresses or ranges>`',
            'setting' => '`restricted`', 'about' => 'only those addresses, else 403 ([access rules](RSF02-03-access-rules.md))'],
        ['words' => ['block', 'unblock'], 'syntax' => '`block <paths>` / `unblock <paths>`',
            'setting' => '`blockedPaths`', 'about' => '404 before the site sees it / take a block back'],
        ['words' => ['block'], 'feature' => 'RSF02-06', 'syntax' => '`block query|header <Name>|headers|anywhere <regex>`',
            'setting' => '`contentRules`', 'about' => 'attack patterns in the query or the headers, 403'],
        ['words' => ['unblock'], 'syntax' => '`unblock [<what>] at <paths> [for <addresses>]`',
            'setting' => '`blockExceptions`', 'about' => 'blocked paths let through at some paths only (an admin\'s file reader) ([access rules](RSF02-03-access-rules.md#exceptions-an-admins-file-reader))'],
        ['words' => ['query'], 'syntax' => '`query <name> <type> … [at <paths>]` / `query strict`',
            'setting' => '`queryParams`, `queryStrict`', 'about' => 'the query parameters the site takes and their types (`int`, `number`, `word`, `id`, `list`, `text`, `any`, `/regex/`); only `text` and the unknown ones go to the attack patterns; `strict`: anything else 404 ([known parameters](RSF02-05-known-parameters.md))'],
        ['words' => ['cache-path'], 'syntax' => '`cache-path <paths>`',
            'setting' => '`cacheable.paths`', 'about' => 'what a cache may keep; `any`: every path (default)'],
        ['words' => ['cache-query'], 'syntax' => '`cache-query <names>`',
            'setting' => '`cacheable.query`', 'about' => 'parameters a cached URL may have; `any` (default), `none`'],
        ['words' => ['limit'], 'syntax' => '`limit <name> <n>/<unit> [challenge-at <n>] [on-demand] [on-exceeded challenge] [at <paths>]`',
            'setting' => '`budgets`', 'about' => 'units `s`, `sec`, `min`, `hour`, `day`, also `20/10s`; `on-exceeded challenge`: past the limit the check that frees the counter instead of a pause ([budgets](RSF03-01-budgets.md#past-the-limit-a-pause-or-earn-it-back)); `at <paths>` (or inside a `match` block): only requests there count ([an area\'s budget](RSF03-01-budgets.md#a-budget-for-one-area))'],
        ['words' => ['post-origin'], 'syntax' => '`post-origin same [missing check|allow|refuse] [except <paths>]` / `post-origin except <paths>`',
            'setting' => '`postOrigin`', 'about' => 'forms (POST, PUT, PATCH, DELETE) only from the website\'s own pages: `Origin`, else `Referer`; another website 403, neither the browser check ([forms from the website](RSF02-04-forms-from-the-website.md))'],
        ['words' => ['backend'], 'syntax' => '`backend <paths>`',
            'setting' => '`backend`', 'about' => 'the editors\' area: its forms counted apart in the statistics, one entry per area ([forms in the statistics](RSF06-03-statistics.md#forms-sent-from-where-how-they-ended)); inside a `match` block without paths'],
        ['words' => ['api-path'], 'syntax' => '`api-path <paths>`',
            'setting' => '`challenge.apiPaths`', 'about' => 'the site\'s API: a check there is JSON with a header, not a page'],
        ['words' => ['no-limit'], 'syntax' => '`no-limit <name>`',
            'setting' => '`budgets`', 'about' => 'switch a budget off, the default one too'],
        ['words' => ['challenge'], 'syntax' => '`challenge <paths> [max-age <duration>]`',
            'setting' => '`challenge.alwaysPaths`, `challenge.alwaysMaxAge`', 'about' => 'always check the browser there; `max-age 5m`: a pass from the last five minutes there ([modes](RSF05-03-modes.md))'],
        ['words' => ['monitor'], 'syntax' => '`monitor <rule>`',
            'setting' => '`monitorRules`', 'about' => 'before `block`, `restrict`, `allow`, `limit`, `challenge`, `ban`, `feed`, `query strict`: logged as it would decide, not enforced ([modes](RSF05-03-modes.md))'],
        ['words' => ['stats-group'], 'syntax' => '`stats-group "<name>" <websites>`',
            'setting' => '`ext.stats.groups`', 'about' => 'websites of one customer, read together and each on its own; counted apart without naming them in `stats-hosts`; above the site blocks ([groups](RSF06-03-statistics.md#groups-websites-per-customer))'],
        ['words' => ['dashboard-access'], 'syntax' => '`dashboard-access "<principal>"|* sha256:<hash> [until <day>]`',
            'setting' => '`dashboardAccess`', 'about' => 'who may open the dashboard: the admin (`*`) or a principal (a customer\'s group in the statistics); only the token\'s hash (`bin/request-shield access-token`); above the site blocks ([who sees what](RSF06-03-statistics.md#who-sees-what-tokens-a-login-signed-links))'],
        ['words' => ['stats-skip'], 'syntax' => '`stats-skip <paths>`',
            'setting' => '`ext.stats.skip`', 'about' => 'not in the statistics when they pass (a map proxy\'s tiles); refused or checked they are counted; protected all the same ([statistics](RSF06-03-statistics.md#paths-that-are-not-counted-stats-skip))'],
        ['words' => ['challenge-exempt'], 'syntax' => '`challenge-exempt <paths>`',
            'setting' => '`challenge.exemptPaths`', 'about' => 'never challenge there (APIs, feeds)'],
        ['words' => ['exempt'], 'syntax' => '`exempt <addresses or ranges> [until <day>[T<hh:mm>]]`',
            'setting' => '`exempt.ips`', 'about' => 'never counted and never checked, still refused for blocked paths and attack patterns ([IP lists](RSF01-02-ip-lists.md))'],
        ['words' => ['deny'], 'syntax' => '`deny <addresses or ranges> [until <day>[T<hh:mm>]]`',
            'setting' => '`deny`', 'about' => 'kept out: 403 before every other check ([IP lists](RSF01-02-ip-lists.md))'],
        ['words' => ['feed'], 'syntax' => '`feed <name> [<https-url> | from <file>] deny|check|count|ban-signal <n> [at <paths>] [format <f>] [wide-ok]`',
            'setting' => '`feeds`', 'about' => 'a public blocklist, fetched by `request-shield feeds … update`; above the site blocks ([feeds](RSF01-03-blocklist-feeds.md))'],
        ['words' => ['ban'], 'syntax' => '`ban after <n> <signal> in <time> for <time>`',
            'setting' => '`bans`', 'about' => 'a client past `<n>` signals (`limits`, `refusals`, `checks`, a budget\'s name) is answered only 429 for a while; above the site blocks ([IP lists](RSF01-02-ip-lists.md#bans))'],
        ['words' => ['crawlers', 'crawler'], 'syntax' => '`crawlers <kind> allow|check|block` / `crawler <ID> allow|check|block`',
            'setting' => '`crawlerPolicy`', 'about' => 'what the site does with verified crawlers, by kind (`search`, `ai-search`, `ai-user`, `ai-training`) or one by one ([known crawlers](RSF01-04-known-crawlers.md))'],
        ['words' => ['crawler'], 'syntax' => '`crawler <kind> ua /<pattern>/ [dns <suffixes>] [ranges <lists>]`',
            'setting' => '`crawlers`', 'about' => 'a crawler of the site\'s own, verified by DNS or an address list (`ranges ./ours.json`)'],
        ['words' => ['expect'], 'syntax' => '`expect <METHOD> <address> <outcome> [by <ID>] [from <address>] [with pass] [times <n>]`',
            'setting' => '—', 'about' => 'an example of what the rules decide, below the rule it is about; decided by `request-shield test`, never by a request ([examples](RSF05-04-rule-examples.md))'],
        ['words' => ['match'], 'syntax' => '`match <paths> { … }`',
            'setting' => '—', 'about' => 'the rules of an area in one place: the lines inside apply to its paths only ([match blocks](#match-blocks-the-rules-of-an-area-in-one-place))'],
        ['words' => ['site'], 'syntax' => '`site <names> { … }`',
            'setting' => '`sites`', 'about' => 'rules for some websites, added to the base ([site blocks](#site-blocks-rules-per-website))'],
        ['words' => ['ids'], 'syntax' => '`ids <PREFIX> [required]`',
            'setting' => '—', 'about' => 'the namespace of this file\'s IDs; `required`: every rule needs one ([IDs](#ids-namespaces-and-descriptions))'],
        ['words' => ['version'], 'syntax' => '`version <version>`',
            'setting' => '—', 'about' => 'this file\'s own version, shown by `check` and on the rules page ([versions](#versions-revisions-and-replacing-a-rule))'],
        ['words' => ['replace'], 'syntax' => '`replace [<ID>] <rule>`',
            'setting' => '—', 'about' => 'a rule swapped in one line, keeping its ID -- and its count in the log ([replacing](#versions-revisions-and-replacing-a-rule))'],
        ['words' => ['plugin'], 'syntax' => '`plugin <class> [from <file>]`',
            'setting' => '`plugins`, `pluginFiles`', 'about' => 'a [plugin](RSF06-04-plugins.md), told what was decided and how a request ended; `from` names the file that holds the class, relative to the rule file, for a site without Composer (loaded when the rules are compiled and when the shield makes its plugins); the statistics need none (`set stats on`); a class that is an [extension](RSF06-04-plugins.md#extensions-words-and-settings-of-their-own) brings words and `set` keys of its own, known from its `plugin` line on'],
        ['words' => ['set'], 'syntax' => '`set <key> <value>`',
            'setting' => 'any other setting', 'about' => 'see below'],
        ['words' => ['include'], 'syntax' => '`include <path or glob>`',
            'setting' => '—', 'about' => 'further rule files, relative to this one'],
    ];

    /**
     * The set keys: the keys a row is about (text.<key> stands for many), its label as written, the value.
     *
     * @var list<array{keys: list<string>, label: string, value: string}>
     */
    public const SETTINGS = [
        ['keys' => ['secret'], 'label' => '`secret`',
            'value' => 'at least 32 characters; better `${SHIELD_SECRET}` than in the file'],
        ['keys' => ['store'], 'label' => '`store`',
            'value' => '`auto`, `apcu`, `file`, `memory`'],
        ['keys' => ['store-dir'], 'label' => '`store-dir`',
            'value' => 'where file counters, a generated secret, the lists, the feeds and the statistics live; default `.request-shield/store` next to the main rule file (never the system\'s temp dir, which a shared host shares between customers)'],
        ['keys' => ['pass-ttl', 'solution-ttl'], 'label' => '`pass-ttl`, `solution-ttl`',
            'value' => '`3600`, `30m`, `1h`, `1d`'],
        ['keys' => ['difficulty-min', 'difficulty-max'], 'label' => '`difficulty-min`, `difficulty-max`',
            'value' => 'numbers'],
        ['keys' => ['cookie', 'solution-cookie'], 'label' => '`cookie`, `solution-cookie`',
            'value' => 'cookie names'],
        ['keys' => ['bind-user-agent', 'search-engines', 'debug-header', 'strip-untrusted-forwarded'], 'label' => '`bind-user-agent`, `search-engines`, `debug-header`, `strip-untrusted-forwarded`',
            'value' => '`on` / `off` (`search-engines off`: no crawler is recognised)'],
        ['keys' => ['dns-lookups'], 'label' => '`dns-lookups`',
            'value' => 'new DNS lookups a minute to verify search engines, for all requests together (default 30; `0`: none — a DMZ without DNS)'],
        ['keys' => ['app-challenge'], 'label' => '`app-challenge`',
            'value' => '`on`: the site may ask for the check with the header `X-RS-Check: 1` ([docs](RSF03-04-app-challenges.md))'],
        ['keys' => ['ipv6-prefix', 'max-uri', 'max-query-parameters', 'max-header-bytes'], 'label' => '`ipv6-prefix`, `max-uri`, `max-query-parameters`, `max-header-bytes`',
            'value' => 'numbers'],
        ['keys' => ['challenge-logo'], 'label' => '`challenge-logo`',
            'value' => 'an SVG file (relative to the rule file) for the middle of the check page\'s ring, checked strictly ([how it looks](RSF03-02-browser-challenge.md#how-it-looks))'],
        ['keys' => ['widget-path', 'widget-difficulty'], 'label' => '`widget-path`, `widget-difficulty`',
            'value' => 'the browser check inside a form: its endpoint (`/request-shield`; unset: off) and difficulty ([docs](RSF03-03-browser-check-in-the-form.md))'],
        ['keys' => ['home'], 'label' => '`home`',
            'value' => 'a path (`/`) or an address: the shield\'s own pages (404, a pause, the check page) link to it, "To the home page"'],
        ['keys' => ['language'], 'label' => '`language`',
            'value' => '`auto` (default: the visitor\'s browser language among those there are texts for, else English) or a code: `de`, `en`'],
        ['keys' => ['text.<key>', 'text.<lang>.<key>'], 'label' => '`text.<key>`, `text.<lang>.<key>`',
            'value' => 'what visitors read (the rest of the line): for every language, or for one — `set text.de.title Einen Moment, bitte`. Keys: `title`, `text`, `noscript`, `nocookies`, `failed`, `about` (the check page\'s link for visitors, with `docs-url`); the error pages\' titles `bad-request`, `no-access`, `not-found`, `not-allowed`, `too-long`, `too-many`, `too-large`, `error` and their sentences, the same with `-text` (`too-many-text`: `%s` = seconds to wait), and `reference` ([error pages](RSF05-06-error-pages.md)). English and German are built in; another language comes with its texts (`text.fr.title …`)'],
        ['keys' => ['mode'], 'label' => '`mode`',
            'value' => '`off`, `monitor`, `enforce` (default), `strict` ([modes](RSF05-03-modes.md))'],
        ['keys' => ['crawler-verify'], 'label' => '`crawler-verify`',
            'value' => '`both` (default), `ranges` (the published address lists only: no DNS, for a DMZ), `dns` ([known crawlers](RSF01-04-known-crawlers.md))'],
        ['keys' => ['log', 'log-level', 'log-ip', 'log-max-size'], 'label' => '`log`, `log-level`, `log-ip`, `log-max-size`',
            'value' => '[the log](RSF05-05-log-and-rule-ids.md)'],
        ['keys' => ['error-page'], 'label' => '`error-page`',
            'value' => 'a page of the site\'s own for a status the shield refuses with: `set error-page 404 errors/404.html`, `4xx` for all of them, `{lang}` in the name for one per language (`errors/pause.{lang}.html`); relative to the rule file, HTML up to 64 KB, read when the rules are compiled; placeholders `{status}` `{title}` `{text}` `{wait}` `{home}` `{lang}` `{reference}`; also in a site block -- without one, the shield\'s own page ([error pages](RSF05-06-error-pages.md))'],
        ['keys' => ['docs-url'], 'label' => '`docs-url`',
            'value' => 'where the pages\' `?` links and the command line\'s hints point: the docs\' folder, the repository\'s by default; a copy of your own (`https://docs.example.org/request-shield`, `/docs`), or `off` for no links -- the one-sentence explanations stay ([rules and setup](RSF06-01-active-rules-page.md))'],
        ['keys' => ['api', 'api-write', 'api-origins'], 'label' => '`api`, `api-write`, `api-origins`',
            'value' => 'the API below `<dashboard-path>/api/v1`: `api` on (default) or off; `api-write` on for the endpoints that change something (off by default); `api-origins` the websites whose pages may call it from a browser (CORS) ([the API](RSF06-05-api.md))'],
        ['keys' => ['http-cache', 'http-cache-hosts', 'http-cache-ttl', 'http-cache-cookies', 'http-cache-max-object', 'http-cache-dir'], 'label' => '`http-cache`, `http-cache-hosts`, `http-cache-ttl`, `http-cache-cookies`, `http-cache-max-object`, `http-cache-dir`',
            'value' => 'the HTTP cache: `http-cache` on or off (default); how long an answer is kept when it says nothing (`5m`); the cookies that do not make a page someone\'s own (default: analytics, the pass); the largest answer kept (`1M`); where (default `<store-dir>/http-cache`) ([the HTTP cache](RSF04-03-http-cache.md))'],
        ['keys' => ['stats'], 'label' => '`stats`',
            'value' => '`off` (default), `on`, or the parts: `requests`, `crawlers`, `not-found`, `bots` ([statistics](RSF06-03-statistics.md))'],
        ['keys' => ['dashboard-path'], 'label' => '`dashboard-path`',
            'value' => 'where the statistics pages live: `/rs` (default) gives the statistics under `/rs/stats/` (`overview`, `visitors`, `protection`, `sites`) and `/rs/waf/rules`, `/rs/waf/live`, `/rs/waf/lists`; something in front is fine (`/admin/rs`); the shield serves these pages itself -- a `restrict` rule (`restrict **/rs/** to <addresses>`) or a login must cover them, `check` warns otherwise ([statistics](RSF06-03-statistics.md#the-statistics-page))'],
        ['keys' => ['stats-hours', 'stats-days', 'stats-months', 'stats-flush'], 'label' => '`stats-hours`, `stats-days`, `stats-months`, `stats-flush`',
            'value' => 'days the hours are kept (7), days the day totals are kept (400, then summed into months), months kept (0: for good), seconds between writes to disk with APCu (60)'],
        ['keys' => ['site-from'], 'label' => '`site-from`',
            'value' => 'which name picks a site block: `server-name` (the default, the web server\'s) or `host` (the Host header) — [site blocks](#site-blocks-rules-per-website)'],
        ['keys' => ['dashboard-session'], 'label' => '`dashboard-session`',
            'value' => 'how long a login to the dashboard lasts (`8h`; 1 minute to 30 days)'],
        ['keys' => ['stats-path'], 'label' => '`stats-path`',
            'value' => 'where the statistics plugin\'s pages live (default `<dashboard-path>/stats`): `…/sites`, `/overview`, `/visitors`, `/protection` below it; the core\'s stay at `<dashboard-path>/waf/`'],
        ['keys' => ['stats-hosts'], 'label' => '`stats-hosts`',
            'value' => 'the websites with statistics of their own: names, `*.domain`, `host` (the host rule\'s), `sites` (the site blocks\'); any other name counts as "other hosts" ([statistics per website](RSF06-03-statistics.md#statistics-per-website))'],
        ['keys' => ['stats-depth'], 'label' => '`stats-depth`',
            'value' => 'folder levels a section\'s views are counted for exactly, 1 to 4 (2: `/news/`, `/news/2026/`; 3 where a language takes the first level: `/de/news/2026/`)'],
        ['keys' => ['crawler-log', 'crawler-log-kinds', 'crawler-log-days', 'crawler-log-query'], 'label' => '`crawler-log`, `crawler-log-kinds`, `crawler-log-days`, `crawler-log-query`',
            'value' => 'one log per known crawler and day: its directory, the kinds logged, days kept (30), whether the query is kept ([statistics](RSF06-03-statistics.md#one-log-per-crawler-optional))'],
        ['keys' => ['lists-dir'], 'label' => '`lists-dir`',
            'value' => 'where the list files `allow.rules` and `deny.rules` live (default `<store-dir>/lists`); they hold only `deny` and `exempt` lines ([IP lists](RSF01-02-ip-lists.md#the-list-files))'],
        ['keys' => ['feeds-max-age'], 'label' => '`feeds-max-age`',
            'value' => 'a fetched list older than this is not used (`3d`; at least `1h`) ([feeds](RSF01-03-blocklist-feeds.md))'],
        ['keys' => ['ban-keep'], 'label' => '`ban-keep`',
            'value' => '`memory` (default) or `file`: a ban also as a file in store-dir, so it survives a restart of APCu ([live and lists](RSF06-02-live-and-lists.md#bans-that-survive-a-restart-set-ban-keep-file))'],
        ['keys' => ['live', 'live-keep'], 'label' => '`live`, `live-keep`',
            'value' => '`on`: the live view from memory, with full addresses (APCu); how long an entry stays (1h; 1m to 1d) ([live and lists](RSF06-02-live-and-lists.md))'],
        ['keys' => ['ban-growth', 'ban-max'], 'label' => '`ban-growth`, `ban-max`',
            'value' => 'each ban within a day this many times as long (2), at most (`1d`) ([IP lists](RSF01-02-ip-lists.md#bans))'],
        ['keys' => ['recheck'], 'label' => '`recheck`',
            'value' => 'how often the files are checked for changes, see below'],
    ];
}
