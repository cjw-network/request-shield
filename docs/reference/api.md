# Reference: the API

<!-- Written by docs/tools/gen-reference.php from the API's endpoints (ApiProvider) -- do not edit; run the tool. -->

Below `<dashboard-path>/api/v1` (`/rs/api/v1` by default), guarded like the dashboard: a `restrict` rule, or `Authorization: Bearer <token>` (`request-shield access-token`). Every answer is `{"data": …, "meta": {"version", "generated", "tier"}}` with an `ETag` on the data; every problem is RFC 9457 JSON (`type`, `title`, `status`, `detail`). A GET takes its parameters in the query, a POST in a JSON body. *reader*: a customer's token may call it (and sees its group only); *admin*: the administrator only; *a write*: only with `set api-write on`. The same as OpenAPI 3.1: [openapi.yaml](openapi.yaml), and `GET /rs/api/v1/openapi.json` on a site. In the same process: `Api::call($settings, 'GET', '/status')` ([the API](../features/RSF06-05-api.md)).

### The API ([RSF06-05](../features/RSF06-05-api.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `GET /status` | reader | The version, the tier, the mode and the rule sets in force -- what request-shield version says. |
| `GET /openapi.json` | reader | This API described as OpenAPI 3.1 (JSON): every endpoint, what it takes and what it answers -- the document itself, no envelope. |
| `GET /openapi.yaml` | reader | The same as YAML. |

### Rules and setup ([RSF06-01](../features/RSF06-01-active-rules-page.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `GET /rules` | admin | Every rule with an ID: where it is written, what it does, its revision, how often it decided.<br>`days`: the days the counts are of: 1 to 400 (default 7) |
| `POST /trace` | admin | What happens to a request, check by check -- as request-shield trace; nothing is counted.<br>`url` (required): a full address or a path: https://www.example.org/wp-login.php<br>`method`: GET (default), POST, …<br>`ip`: the visitor's address (default 198.51.100.7)<br>`ua`: the User-Agent |

### Examples next to the rules ([RSF05-04](../features/RSF05-04-rule-examples.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `POST /test` | admin | Every example next to the rules, decided on a fresh store -- as request-shield test.<br>`only`: the examples of this rule ID only<br>`asWritten`: monitor as written, not as enforce |

### Rule files ([RSF05-01](../features/RSF05-01-rule-files.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `POST /check` | admin | The rule files read again and compiled: the first mistake, or the warnings and the tier -- as request-shield check. |
| `POST /reload` | admin, a write | Check, then mark the main rule file changed: every server reads the rules on its next check. |

### Live view and lists ([RSF06-02](../features/RSF06-02-live-and-lists.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `GET /live` | admin | What was stopped since the cursor: the live view's rows.<br>`cursor`: where the last call ended (its answer's cursor); none: the newest rows |

### IP lists and bans ([RSF01-02](../features/RSF01-02-ip-lists.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `GET /lists` | admin | The addresses kept out and let in, newest first, searched; and the bans in force.<br>`q`: only entries whose line holds this: an address, an ID, a word of the note<br>`limit`: at most this many entries: 1 to 500 (default 100) |
| `POST /lists` | admin, a write | An address kept out or let in, for a while or for good -- with the dashboard's checks.<br>`kind`: deny (keep out, default) or exempt (let in)<br>`address` (required): an address or a range: 203.0.113.7, 198.51.100.0/24<br>`for`: 1h, 1d, 7d (default), 30d, date (with until), good (for good: a note is needed)<br>`until`: with for=date: 2026-10-07 or 2026-10-07T15:30<br>`note`: why: shown in the lists and the live view<br>`confirm`: a wide range is meant |
| `POST /lists/update` | admin, a write | Another end or another note for an entry, by its ID.<br>`id` (required): the entry's ID: LIST-D3<br>`for`: 1h, 1d, 7d, 30d, date (with until), good<br>`until`: with for=date: 2026-10-07 or 2026-10-07T15:30<br>`note`: the new note |
| `POST /lists/remove` | admin, a write | An entry taken out, by its ID.<br>`id` (required): the entry's ID: LIST-D3 |
| `POST /lists/lift` | admin, a write | A ban lifted before its end, by its bucket.<br>`bucket` (required): as GET /lists names it under bans |

### Public blocklists ([RSF01-03](../features/RSF01-03-blocklist-feeds.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `GET /feeds` | admin | The public blocklists the rules name: action, entries, when fetched, whether in force. |
| `POST /feeds/update` | admin, a write | The lists that are due fetched now -- as request-shield feeds update.<br>`force`: keep a list that shrank to less than half, and fetch one not yet due |

### Known crawlers ([RSF01-04](../features/RSF01-04-known-crawlers.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `GET /crawlers` | admin | The known crawlers: kind, what the site does with each, how they are verified. |

### Log and rule IDs ([RSF05-05](../features/RSF05-05-log-and-rule-ids.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `GET /log` | admin | The log's lines since the cursor, parsed: what was refused or checked, and why.<br>`cursor`: where the last call ended (its answer's cursor); none: the end of the log |

### Statistics ([RSF06-03](../features/RSF06-03-statistics.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `GET /stats/report` | reader | What the counters say about a period: requests, what the shield did, pages, not found, crawlers, bots, forms, the sentences.<br>`days`: the last days: 1 to 400 (default 7)<br>`from`: a period instead: from YYYY-MM-DD (with to)<br>`to`: to YYYY-MM-DD<br>`by`: day (default), week, month or year<br>`site`: one website (stats-hosts), or group:&lt;id&gt;; a customer sees its group only<br>`path`: only pages below it: /news/<br>`crawler`: one known crawler: CRAWL-GOOGLE<br>`sort`: views (default), blocked, refused, checked or throttled |
| `GET /stats/sites` | reader | All websites at a glance: page views, people, crawlers, bots, stopped, not found, and the period before.<br>`days`: the last days: 1 to 400 (default 7)<br>`from`: a period instead: from YYYY-MM-DD (with to)<br>`to`: to YYYY-MM-DD<br>`by`: day (default), week, month or year |

### The HTTP cache ([RSF04-03](../features/RSF04-03-http-cache.md))

| Endpoint | Who | What it answers |
|---|---|---|
| `GET /cache` | admin | The HTTP cache: on or off, how many answers it holds, how many bytes. |
| `POST /cache/purge` | admin, a write | Empties the HTTP cache, or the addresses below a path -- after a page changed.<br>`path`: only the addresses below it: /news/ (every website); none: everything |
