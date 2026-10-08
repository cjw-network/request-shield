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
        if (!is_dir($storeDir) && !@mkdir($storeDir, 0700, true) && !is_dir($storeDir)) {
            throw new \RuntimeException("cannot create $storeDir");
        }
        $token = bin2hex(random_bytes(16));
        $state = ['until' => $now + $for, 'token' => hash('sha256', $token), 'from' => $from, 'started' => $now];
        if (@file_put_contents(self::stateFile($storeDir), json_encode($state) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('cannot write ' . self::stateFile($storeDir));
        }
        @chmod(self::stateFile($storeDir), 0600);
        $record = self::recordFile($storeDir);
        if (!$keep || !is_file($record)) {
            // A new recording, created here so it is its owner's only (it holds paths):
            // the requests only append to it.
            @file_put_contents($record, '', LOCK_EX);
        }
        @chmod($record, 0600);
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
        register_shutdown_function(static function () use ($line, $file): void {
            $status = http_response_code();
            $line['status'] = is_int($status) ? $status : null;
            $size = @filesize($file);
            $json = self::line($line);
            if ($json === null || ($size !== false && $size > self::MAX_BYTES)) {
                return;
            }
            @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
        });
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
     * @return array{requests: int, paths: int, methods: array<string, int>, parameters: int, forms: int}
     */
    public static function summary(string $storeDir): array
    {
        $out = ['requests' => 0, 'paths' => 0, 'methods' => [], 'parameters' => 0, 'forms' => 0];
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
        }
        fclose($h);
        $out['paths'] = count($paths);
        $out['parameters'] = count($params);
        $out['forms'] = count($forms);
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
