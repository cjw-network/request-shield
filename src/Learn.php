<?php

declare(strict_types=1);

/*
 * This file is part of cjw-network/request-shield.
 * (c) JAC Systeme GmbH (CJW Network) -- MIT licence, see LICENSE.
 */

namespace CjwNetwork\RequestShield;

/**
 * A learning run (proposal 0016): the requests of one developer -- or of the
 * site's end-to-end tests -- recorded by their shape, to build rules from.
 *
 * `request-shield learn <main.rules> start [--for=2h] [--from=<address>]`
 * writes <store-dir>/learn.json: until when, a token's SHA-256, and the
 * addresses it is limited to. The settings read it when they are compiled
 * (like the list files), so a request without a run costs one null check.
 *
 * A request is recorded when the run has not ended, it carries the token --
 * the cookie rs-learn (set and cleared with the bookmarks `start` prints) or
 * the header Request-Shield-Learn (for tests) -- and, with --from, comes from
 * one of those addresses. The token only marks: it never lets a request past
 * a check.
 *
 * Recorded is the shape, never a value: method, host, path, the type of each
 * query parameter and form field (int, number, id, word, list, text), the
 * content type, what the shield decided and the status the site answered
 * with -- one JSON line per request in <store-dir>/learned.jsonl.
 */
final class Learn
{
    public const COOKIE = 'rs-learn';
    public const HEADER = 'request-shield-learn';
    /** The recording stops growing here: a forgotten run fills no disk. */
    public const MAX_BYTES = 10485760;
    /** At most so much of a page is read for what it offers (forms, links, scripts). */
    public const MAX_PAGE = 2097152;
    /** At most so many query parameters and form fields in a line: it stays small. */
    public const MAX_FIELDS = 200;
    /** At most this long a run (--for). */
    public const MAX_FOR = 604800;

    /** The run's state file in a store directory. */
    public static function stateFile(string $storeDir): string
    {
        return rtrim($storeDir, '/') . '/learn.json';
    }

    /** The recording in a store directory. */
    public static function recordFile(string $storeDir): string
    {
        return rtrim($storeDir, '/') . '/learned.jsonl';
    }

    /**
     * Starts a run: a new token, the state file written. The recording of an
     * earlier run is cleared unless $keep.
     *
     * @param list<string> $from addresses or ranges the run is limited to ([] = any)
     * @return string the token (shown once; only its hash is kept)
     */
    public static function start(string $storeDir, int $for, array $from, bool $keep, int $now): string
    {
        if ($for < 60 || $for > self::MAX_FOR) {
            throw new \InvalidArgumentException('--for takes 1m to 7d');
        }
        foreach ($from as $a) {
            Rules\Lists::check($a);          // an address or a range, or it says what is wrong
        }
        if (!Files::dir($storeDir)) {
            throw new \RuntimeException("cannot create $storeDir");
        }
        $token = bin2hex(random_bytes(16));
        $state = ['until' => $now + $for, 'token' => hash('sha256', $token), 'from' => $from, 'started' => $now];
        if (!Files::write(self::stateFile($storeDir), json_encode($state) . "\n")) {
            throw new \RuntimeException('cannot write ' . self::stateFile($storeDir));
        }
        $record = self::recordFile($storeDir);
        if (!$keep || !is_file($record)) {
            // A new recording, in file-mode (it holds paths): the requests only append to it.
            Files::write($record, '');
        }
        return $token;
    }

    /** Ends the run: the state file removed; the recording stays. Whether one was running. */
    public static function stop(string $storeDir): bool
    {
        $f = self::stateFile($storeDir);
        return is_file($f) && @unlink($f);
    }

    /**
     * The run as the settings keep it, or null: read when the rules are compiled.
     *
     * @return array{until: int, token: string, from: list<string>}|null
     */
    public static function read(string $storeDir): ?array
    {
        $raw = @file_get_contents(self::stateFile($storeDir));
        $s = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($s) || !is_int($s['until'] ?? null) || !is_string($s['token'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $s['token']) !== 1) {
            return null;
        }
        $from = [];
        foreach ((array) ($s['from'] ?? []) as $a) {
            if (is_string($a) && self::isRange($a)) {
                $from[] = $a;
            }
        }
        return ['until' => $s['until'], 'token' => $s['token'], 'from' => $from];
    }

    private static function isRange(string $a): bool
    {
        try {
            Rules\Lists::check($a);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Whether this request belongs to the run.
     *
     * @param array{until: int, token: string, from: list<string>} $run
     */
    public static function marks(array $run, Request $request, float $now): bool
    {
        if ($now >= $run['until']) {
            return false;
        }
        $token = $request->cookie(self::COOKIE) ?? $request->header(self::HEADER);
        if ($token === null || !hash_equals($run['token'], hash('sha256', $token))) {
            return false;
        }
        return $run['from'] === [] || IpAddress::inRanges($request->clientIp, $run['from']);
    }

    /**
     * Records the request's shape when the run marks it -- the line written when
     * the request ends, with the site's status.
     */
    public static function note(Settings $s, Request $request, Decision $d, float $now): void
    {
        if ($s->learn === null || !self::marks($s->learn, $request, $now)) {
            return;
        }
        $line = self::shape($request, $_POST, $d, $now);
        $file = self::recordFile($s->storeDir);
        if (!$d->passes()) {
            // The shield answers itself (it empties the buffers first): no page of the
            // site to read -- the line when the request has ended, with its status.
            register_shutdown_function(static function () use ($line, $file): void {
                self::append($file, $line, http_response_code(), null);
            });
            return;
        }
        // The site's answer, read as it is sent (never changed, in chunks of 8 KB so
        // PHP holds no more than that): what it offers -- forms, links, addresses in
        // its scripts -- goes into the line too. The line is written when the request
        // has ended (the shutdown functions run before the buffer's last flush): by the
        // buffer then, with the final status; by the shutdown function when the site
        // ended the buffer earlier -- without "found" when it threw the page away.
        $st = new LearnPage();
        register_shutdown_function(static function () use ($st, $line, $file): void {
            try {
                $st->ending = true;
                if ($st->ended && !$st->written) {
                    $st->written = true;
                    self::append($file, $line, http_response_code(), $st->found);
                }
            } catch (\Throwable $e) {
                // the recording's trouble, never the visitor's
            }
        });
        ob_start(static function (string $buffer, int $phase) use ($st, $line, $file, $request): string {
            try {
                if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                    $st->body = '';                 // what the site threw away was never sent
                    $st->discarded = ($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0;
                } elseif (!$st->full) {
                    $st->body .= $buffer;
                    if (strlen($st->body) > self::MAX_PAGE) {
                        $st->body = substr($st->body, 0, self::MAX_PAGE);
                        $st->full = true;
                    }
                }
                if (($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0 && !$st->ended) {
                    $st->ended = true;
                    $st->found = $st->discarded ? null : self::offered($st->body, $request);
                    $st->body = '';
                    if ($st->ending && !$st->written) {
                        $st->written = true;
                        self::append($file, $line, http_response_code(), $st->found);
                    }
                }
            } catch (\Throwable $e) {
                // the recording's trouble, never the visitor's: the answer goes out as it is
            }
            return $buffer;
        }, 8192);
    }

    /**
     * What an answer offers, when it is HTML (the site's Content-Type, else PHP's
     * default_mimetype -- that one is not in headers_list()) and not encoded.
     *
     * @return array{forms: list<array{action: string, method: string, fields: array<string, string>}>, links: list<string>, scripts: list<string>, hosts: list<string>}|null
     */
    private static function offered(string $body, Request $request): ?array
    {
        $type = '';
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0) {
                $type = $h;
            } elseif (stripos($h, 'content-encoding:') === 0) {
                return null;                        // gzip the site made: not readable here
            }
        }
        return stripos($type !== '' ? $type : (string) ini_get('default_mimetype'), 'text/html') !== false ? self::found($body, $request) : null;
    }

    /**
     * One line to the recording -- unless it is full (MAX_BYTES).
     *
     * @param array<string, mixed> $line
     * @param int|bool $status http_response_code()
     * @param array<string, mixed>|null $found what the page offered
     */
    private static function append(string $file, array $line, $status, ?array $found): void
    {
        $line['status'] = is_int($status) ? $status : null;
        if ($found !== null) {
            $line['found'] = $found;
        }
        $size = @filesize($file);
        $json = self::line($line);
        if ($json === null || ($size !== false && $size > self::MAX_BYTES)) {
            return;
        }
        Files::append($file, $json . "\n", true);
    }

    /**
     * What a page of the site offers: its forms (where they go, how, which fields
     * by type), its links and the addresses its inline scripts name -- on this
     * site by path with the names of their parameters, on the site's other hosts
     * (same domain) by host. Never a value: no field's content, no parameter's.
     *
     * @return array{forms: list<array{action: string, method: string, fields: array<string, string>}>, links: list<string>, scripts: list<string>, hosts: list<string>}
     */
    public static function found(string $html, Request $request): array
    {
        $out = ['forms' => [], 'links' => [], 'scripts' => [], 'hosts' => []];
        $place = static function (string $url) use ($request, &$out): ?string {
            $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url === '' || $url[0] === '#' || preg_match('#^(mailto|tel|javascript|data|blob):#i', $url) === 1) {
                return $url === '' ? self::target($request->path, '') : null;
            }
            if (preg_match('#^(?:https?:)?//([^/?\#:]+)(?::\d+)?([^?\#]*)(\?[^\#]*)?#i', $url, $m) === 1) {
                $host = strtolower($m[1]);
                if ($host !== strtolower($request->host)) {
                    if (self::sameDomain($host, strtolower($request->host)) && !in_array($host, $out['hosts'], true) && count($out['hosts']) < 50) {
                        $out['hosts'][] = $host;
                    }
                    return null;
                }
                return self::target($m[2] === '' ? '/' : $m[2], $m[3] ?? '');
            }
            $q = strpos($url, '?');
            $path = (string) preg_replace('/#.*$/', '', $q === false ? $url : substr($url, 0, $q));
            if ($path === '') {
                $path = $request->path;
            } elseif ($path[0] !== '/') {
                $path = rtrim((string) preg_replace('#[^/]*$#', '', $request->path), '/') . '/' . $path;     // relative to this page
            }
            return self::target($path, $q === false ? '' : (string) preg_replace('/#.*$/', '', substr($url, $q)));
        };
        $forms = self::blocks($html, 'form', 50);
        if ($forms !== []) {
            foreach ($forms as $f) {
                $a = self::attributes($f[1]);
                $action = $place($a['action'] ?? '');
                if ($action === null) {
                    continue;                   // to another website, or to another of the site's hosts (in hosts)
                }
                $fields = [];
                if (preg_match_all('#<(input|select|textarea|button)\b([^>]*)>#i', $f[2], $els, PREG_SET_ORDER) > 0) {
                    foreach ($els as $el) {
                        $ea = self::attributes($el[2]);
                        $name = $ea['name'] ?? '';
                        if ($name !== '' && count($fields) < self::MAX_FIELDS) {
                            $tag = strtolower($el[1]);
                            $fields[$name] = $tag === 'input' ? strtolower($ea['type'] ?? 'text') : $tag;
                        }
                    }
                }
                $out['forms'][] = ['action' => $action, 'method' => strtoupper($a['method'] ?? 'get') === 'POST' ? 'POST' : 'GET', 'fields' => $fields];
            }
        }
        if (preg_match_all('#<a\b[^>]*?\bhref\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $html, $links) > 0) {
            foreach ($links[1] as $raw) {
                $t = $place(trim($raw, '"\''));
                if ($t !== null && !in_array($t, $out['links'], true) && count($out['links']) < 500) {
                    $out['links'][] = $t;
                }
            }
        }
        $scripts = self::blocks($html, 'script', 200);
        if ($scripts !== []) {
            foreach (array_column($scripts, 2) as $js) {
                // A quoted address: a path ("/api/v1/messages", `/api/${id}`) or a full one to a host of the site.
                if (preg_match_all('#(["\'`])((?:https?:)?//[a-z0-9.-]+(?::\d+)?/[^"\'`\s]*|/[a-z0-9_.~${-][a-z0-9_.~/${}-]*(?:\?[^"\'`\s]*)?)\1#i', $js, $m) > 0) {
                    foreach ($m[2] as $url) {
                        $t = $place((string) preg_replace('/\$\{[^}]*\}/', '*', $url));      // `/api/${id}` -> /api/*
                        if ($t !== null && !in_array($t, $out['scripts'], true) && count($out['scripts']) < 200) {
                            $out['scripts'][] = $t;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * A tag's blocks: [all, attributes, inner] for each <tag …>…</tag> (an unclosed
     * one runs to the end) -- found by position, not by one expression over the page
     * (a block of a megabyte would exhaust PCRE's backtracking and lose them all).
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function blocks(string $html, string $tag, int $max): array
    {
        $out = [];
        $at = 0;
        while (count($out) < $max && preg_match('#<' . $tag . '\\b([^>]*)>#i', $html, $m, PREG_OFFSET_CAPTURE, $at) === 1) {
            $start = (int) $m[0][1] + strlen($m[0][0]);
            $end = stripos($html, '</' . $tag, $start);
            $inner = $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
            $out[] = [$m[0][0], $m[1][0], $inner];
            $at = $end === false ? strlen($html) : $end + 1;
        }
        return $out;
    }

    /** A target as the recording keeps it: the path, and the names of its parameters (never a value). */
    private static function target(string $path, string $query): string
    {
        $names = [];
        foreach (explode('&', ltrim($query, '?')) as $pair) {
            $n = urldecode(explode('=', $pair, 2)[0]);
            $b = strpos($n, '[');
            $n = $b === false ? $n : substr($n, 0, $b);
            if ($n !== '' && !in_array($n, $names, true)) {
                $names[] = $n;
            }
        }
        $parts = [];
        $segments = explode('/', $path);
        foreach ($segments as $seg) {
            if ($seg === '..') {
                array_pop($parts);          // ../ as a browser resolves it, never above the root
            } elseif ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }
        $last = end($segments);
        $path = '/' . implode('/', $parts) . ($parts !== [] && ($last === '' || $last === '.' || $last === '..') ? '/' : '');
        return $path . ($names === [] ? '' : '?' . implode('&', $names));
    }

    /** Endings under which a name has three labels (example.co.uk): the common two-label public suffixes. */
    private const SUFFIXES = ['co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'org.nz', 'co.jp', 'or.jp', 'ne.jp',
        'co.za', 'com.br', 'com.tr', 'co.in', 'com.cn', 'com.mx', 'com.ar', 'co.kr', 'com.sg', 'com.hk', 'co.at', 'or.at'];

    /**
     * Whether two hosts are of one domain (www.example.org, api.example.org; also
     * www.example.co.uk, shop.example.co.uk -- but not evil.co.uk). A heuristic:
     * the common two-label endings, not the whole public suffix list.
     */
    public static function sameDomain(string $a, string $b): bool
    {
        $base = static function (string $h): string {
            $l = explode('.', $h);
            return implode('.', array_slice($l, in_array(implode('.', array_slice($l, -2)), self::SUFFIXES, true) ? -3 : -2));
        };
        $ip = static fn (string $h): bool => filter_var(trim($h, '[]'), FILTER_VALIDATE_IP) !== false;
        if ($ip($a) || $ip($b)) {
            return false;               // an address is no domain: another one is another host
        }
        return strpos($a, '.') !== false && $base($a) === $base($b);
    }

    /**
     * A tag's attributes, lower-case names; values as written (entities decoded later).
     *
     * @return array<string, string>
     */
    private static function attributes(string $tag): array
    {
        $out = [];
        if (preg_match_all('#([a-z][a-z0-9_:-]*)\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?#i', $tag, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $a) {
                $out[strtolower($a[1])] ??= trim($a[2] ?? '', '"\'');
            }
        }
        return $out;
    }

    /**
     * A shape as one JSON line -- a name that is not UTF-8 replaced, not the line lost.
     *
     * @param array<string, mixed> $shape
     */
    public static function line(array $shape): ?string
    {
        $json = json_encode($shape, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : null;
    }

    /**
     * A request's shape: what it was, never what it held. Form fields are the
     * ones PHP parsed ($_POST: a form, not a JSON body).
     *
     * @param array<mixed> $post the form fields ($_POST)
     * @return array{t: int, method: string, host: string, path: string, query: array<string, string>, form: array<string, string>, type: ?string, decided: string, status: ?int}
     */
    public static function shape(Request $request, array $post, Decision $d, float $now): array
    {
        $query = [];
        foreach ($request->queryPairs() as [$name, $value]) {
            if (count($query) < self::MAX_FIELDS || isset($query[$name])) {
                $query[$name] = self::wider($query[$name] ?? null, self::typeOf($value));
            }
        }
        $form = [];
        foreach ($post as $name => $value) {
            if (count($form) >= self::MAX_FIELDS) {
                break;
            }
            $form[(string) $name] = is_array($value) ? 'list' : self::typeOf(is_scalar($value) ? (string) $value : '');
        }
        $type = $request->header('content-type');
        return ['t' => (int) $now, 'method' => $request->method, 'host' => $request->host, 'path' => $request->path,
            'query' => $query, 'form' => $form, 'type' => $type === null ? null : strtolower(trim(explode(';', $type)[0])),
            'decided' => $d->action, 'status' => null];
    }

    /** The narrowest type a value fits (QueryRule's types); '' for an empty value. */
    public static function typeOf(string $value): string
    {
        if ($value === '') {
            return '';
        }
        foreach (['int', 'number', 'id', 'word', 'list'] as $t) {
            if (Rule\QueryRule::fits($t, $value)) {
                return $t;
            }
        }
        return 'text';
    }

    /** Of two types seen for one name, the one both fit. */
    public static function wider(?string $a, string $b): string
    {
        if ($a === null || $a === '' || $a === $b) {
            return $a === null || $a === '' ? $b : $a;
        }
        if ($b === '') {
            return $a;
        }
        $order = ['int' => 0, 'number' => 1, 'id' => 2, 'word' => 3, 'list' => 4, 'text' => 5];
        if (($a === 'number' && $b === 'id') || ($a === 'id' && $b === 'number')) {
            return 'word';          // 1.5 is no id, a_b no number: both are words
        }
        return ($order[$a] ?? 5) >= ($order[$b] ?? 5) ? $a : $b;
    }

    /**
     * What a recording holds, for `learn status|stop`.
     *
     * @return array{requests: int, paths: int, methods: array<string, int>, parameters: int, forms: int, offered: array{forms: int, links: int, scripts: int, hosts: list<string>}}
     */
    public static function summary(string $storeDir): array
    {
        $out = ['requests' => 0, 'paths' => 0, 'methods' => [], 'parameters' => 0, 'forms' => 0, 'offered' => ['forms' => 0, 'links' => 0, 'scripts' => 0, 'hosts' => []]];
        $offered = ['forms' => [], 'links' => [], 'scripts' => [], 'hosts' => []];
        $h = @fopen(self::recordFile($storeDir), 'r');
        if ($h === false) {
            return $out;
        }
        $paths = [];
        $params = [];
        $forms = [];
        while (($l = fgets($h)) !== false) {
            $r = json_decode($l, true);
            if (!is_array($r) || !is_string($r['path'] ?? null) || !is_string($r['method'] ?? null)) {
                continue;
            }
            $out['requests']++;
            $paths[$r['path']] = true;
            $out['methods'][$r['method']] = ($out['methods'][$r['method']] ?? 0) + 1;
            foreach (array_keys((array) ($r['query'] ?? [])) as $n) {
                $params[(string) $n] = true;
            }
            if (($r['form'] ?? []) !== []) {
                $forms[$r['method'] . ' ' . $r['path']] = true;
            }
            $f = is_array($r['found'] ?? null) ? $r['found'] : [];
            foreach ((array) ($f['forms'] ?? []) as $form) {
                if (is_array($form) && is_string($form['method'] ?? null) && is_string($form['action'] ?? null)) {
                    $offered['forms'][$form['method'] . ' ' . $form['action']] = true;
                }
            }
            foreach (['links', 'scripts', 'hosts'] as $k) {
                foreach ((array) ($f[$k] ?? []) as $v) {
                    if (is_string($v)) {
                        $offered[$k][$v] = true;
                    }
                }
            }
        }
        fclose($h);
        $out['paths'] = count($paths);
        $out['parameters'] = count($params);
        $out['forms'] = count($forms);
        $out['offered'] = ['forms' => count($offered['forms']), 'links' => count($offered['links']), 'scripts' => count($offered['scripts']), 'hosts' => array_map('strval', array_keys($offered['hosts']))];
        return $out;
    }

    /**
     * The two bookmarks that set and clear the cookie in a browser.
     *
     * @return array{start: string, stop: string}
     */
    public static function bookmarks(string $token, int $seconds): array
    {
        return [
            'start' => "javascript:document.cookie='" . self::COOKIE . "=$token; path=/; max-age=$seconds; SameSite=Lax';alert('request-shield: recording on')",
            'stop' => "javascript:document.cookie='" . self::COOKIE . "=; path=/; max-age=0';alert('request-shield: recording off')",
        ];
    }
}

/** What a learning run keeps of one answer while it is sent (Learn::note()). */
final class LearnPage
{
    public string $body = '';
    public bool $full = false;
    public bool $discarded = false;
    public bool $ended = false;
    public bool $ending = false;
    public bool $written = false;
    /** @var array{forms: list<array{action: string, method: string, fields: array<string, string>}>, links: list<string>, scripts: list<string>, hosts: list<string>}|null */
    public ?array $found = null;
}
