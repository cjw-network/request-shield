<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

/**
 * Counters for the dashboard (proposals 0012, 0014): how many requests the
 * shield let through, checked or refused, by which rule, and what each known
 * crawler did -- per hour, kept while requests pass, so nobody has to read
 * the log to see it.
 *
 * With APCu a counter is one apcu_inc() (~0.2 µs); without, a request appends
 * one short line to the hour's file (lock-free, O_APPEND). The first request
 * after an hour is over moves the finished hours into one small JSON file per
 * day (<dir>/d-<yyyymmdd>.json); hours are kept for $hours days, the day's
 * totals for $days days -- then they are added to their month's file
 * (<dir>/m-<yyyymm>.json), kept for $months months (0: for good), so a year
 * is twelve small files.
 *
 * A counter's name ("what"): "a:<action>", "r:<rule>", "c:<crawler>:<event>",
 * "p:<crawler>:<path>", "o:<bot family>", "m:<action>" (what monitor mode would
 * have done), "s:<status>" (the answer's status code), "n:<path>" (a page the
 * site did not find), "nr:<path>|<referrer>" (where a link to it was: a path
 * of the site itself, or another site's host), "sm:<sitemap>|<status>" (a
 * sitemap asked for, and the answer), "pg:<people|crawlers|bots>|<path>" (a
 * page the site answered with 200 and HTML), "pd:<people|crawlers|bots>|<folder>"
 * (its first two folders, for a subtree's views), "pb:<refused|checked|throttled>|<path>"
 * (a page the shield stopped, and how), "smc:<sitemap>|<crawler>" (a verified
 * crawler read it). "l:<crawler>|<time>|<address>" is not counted: the
 * crawler's last visit ("l:sitemap:<path>@<crawler>|…": its last read of a
 * sitemap).
 */
final class Stats
{
    /** "rshield:stat:<site>:" -- each statistics directory its own, so sites on one PHP-FPM pool (one APCu) stay apart. */
    private string $prefix;

    /** Pages kept per crawler and hour (and in a day's totals), pages not found, referrers per page not found; the rest count as "(other)". */
    public const PAGES = 50;

    public const REFERRERS = 5;

    /** The most visited pages kept per kind of visitor and hour (and in a day's totals). */
    public const TOP = 100;

    /** Sitemaps kept a day (and five times as many sitemap-crawler pairs); the rest count as "(other)". */
    public const SITEMAPS = 20;

    /**
     * "Now": one more request by a person in this minute -- with APCu only,
     * never written to disk (it is gone after ten minutes).
     */
    public function minute(float $now): void
    {
        if ($this->apcu) {
            apcu_inc($this->prefix . 'min:' . intdiv((int) $now, 60), 1, $ok, 600);
        }
    }

    /** People's requests in the last $minutes minutes (this one included); null without APCu. */
    public function lastMinutes(int $minutes, float $now): ?int
    {
        if (!$this->apcu) {
            return null;
        }
        $keys = [];
        for ($m = intdiv((int) $now, 60), $i = 0; $i < $minutes; $i++) {
            $keys[] = $this->prefix . 'min:' . ($m - $i);
        }
        $sum = 0;
        foreach ((array) apcu_fetch($keys) as $n) {
            $sum += is_int($n) ? $n : 0;
        }
        return $sum;
    }

    public function __construct(
        private string $dir,
        private bool $apcu,
        private int $hours = 7,
        private int $days = 400,
        private ?string $crawlerLog = null,
        private int $crawlerLogDays = 30,
        private int $flush = 60,
        private int $months = 0,
    ) {
        $this->prefix = 'rshield:stat:' . substr(md5($dir), 0, 8) . ':';
    }

    /** Where the websites no stats-hosts name are counted (not a name a website can have). */
    public const OTHER = '(other)';

    /**
     * The counters of these settings: in store-dir/stats, with APCu where the
     * store would use it -- with stats-hosts, a website's in
     * store-dir/stats/hosts/<name> ($site: a name of statsHosts, or OTHER).
     */
    public static function of(Settings $s, ?string $site = null): self
    {
        $apcu = $s->store === 'apcu' || ($s->store === 'auto' && Store\ApcuStore::usable());
        $dir = $s->storeDir . '/stats' . ($site === null ? '' : '/hosts/' . str_replace('*', '+', $site));
        return new self($dir, $apcu, $s->statsHours, $s->statsDays, $s->crawlerLogDir, $s->crawlerLogDays, $s->statsFlush, $s->statsMonths);
    }

    /** @var \WeakMap<Settings, array<string, true>>|null the names of stats-hosts, per settings (gone with them) */
    private static ?\WeakMap $names = null;

    /**
     * Whose statistics a request counts in: the website it names (lower case,
     * without port and trailing dot) if stats-hosts names it -- exactly, or by
     * *.domain (one label more) -- else OTHER; null without stats-hosts (one
     * statistics). The Host header comes from the client: a made-up name gets
     * no statistics of its own.
     */
    public static function siteOf(Settings $s, string $host): ?string
    {
        if ($s->statsHosts === []) {
            return null;
        }
        self::$names ??= new \WeakMap();
        $names = self::$names[$s] ??= array_fill_keys($s->statsHosts, true);
        if (isset($names[$host])) {
            return $host;
        }
        $dot = strpos($host, '.');
        if ($dot !== false && $dot > 0 && isset($names['*' . substr($host, $dot)])) {
            return '*' . substr($host, $dot);
        }
        return self::OTHER;
    }

    /**
     * The statistics to read for $site: that website's, or (null) every
     * website's and the shared directory's -- what was counted before
     * stats-hosts was set stays in the sum.
     *
     * @return list<self>
     */
    public static function all(Settings $s, ?string $site = null): array
    {
        if ($s->statsHosts === []) {
            return [self::of($s)];
        }
        if ($site !== null) {
            return [self::of($s, $site)];
        }
        $out = [self::of($s)];
        foreach (array_merge($s->statsHosts, [self::OTHER]) as $name) {
            $out[] = self::of($s, $name);
        }
        return $out;
    }

    /**
     * read() of several statistics, added up: the counters exactly; the lists
     * in them (pages, referrers …) are summed per entry, as a day's hours are.
     *
     * @param list<self> $all
     * @return array{days: array<string, array<string, int>>, hours: array<string, array<string, int>>, months: array<string, array<string, int>>, last: array<string, array{0: int, 1: string}>}
     */
    public static function readAll(array $all, string $fromDay, string $toDay): array
    {
        $out = ['days' => [], 'hours' => [], 'months' => [], 'last' => []];
        foreach ($all as $stats) {
            $r = $stats->read($fromDay, $toDay);
            foreach (['days', 'hours', 'months'] as $part) {
                foreach ($r[$part] as $k => $counts) {
                    $out[$part][(string) $k] = self::add($out[$part][(string) $k] ?? [], $counts);
                }
            }
            foreach ($r['last'] as $id => $seen) {
                if ($seen[0] > ($out['last'][$id][0] ?? 0)) {
                    $out['last'][$id] = $seen;
                }
            }
        }
        foreach (['days', 'hours', 'months'] as $part) {
            ksort($out[$part]);
            foreach ($out[$part] as &$counts) {
                ksort($counts);
            }
            unset($counts);
        }
        return $out;
    }

    /** The directory these counters are kept in. */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * One request: add one to each counter (and note a crawler's last visit).
     *
     * @param list<string> $keys
     */
    public function count(array $keys, float $now): void
    {
        // The hour's name, made once an hour (gmdate() costs half a microsecond).
        $n = intdiv((int) $now, 3600);
        if (self::$hourN !== $n) {
            [self::$hourN, self::$hour, self::$closedHour] = [$n, gmdate('YmdH', (int) $now), self::closed($now)];
        }
        $hour = self::$hour;
        if ($this->apcu) {
            foreach ($keys as $k) {
                if (strncmp($k, 'l:', 2) === 0) {
                    [$id, $rest] = explode('|', substr($k, 2), 2) + ['', ''];
                    apcu_store($this->prefix . 'last:' . $id, $rest, 86400 * 8);
                    continue;
                }
                if (self::group($k) !== null) {
                    $k = $this->page($hour, $k);
                    if ($k === '') {
                        continue;
                    }
                }
                apcu_inc($this->prefix . $hour . ':' . $k, 1, $ok, 86400 * 8);
            }
            $this->tend($now);
            return;
        }
        $file = $this->dir . '/h-' . $hour . '.log';
        if (@file_put_contents($file, implode(' ', $keys) . "\n", FILE_APPEND) === false) {
            // The directory is missing: made once, the line written again.
            @mkdir($this->dir, 0750, true);
            @file_put_contents($file, implode(' ', $keys) . "\n", FILE_APPEND);
        }
        $this->tend($now);
    }

    /**
     * Housekeeping, once per process and hour: the finished hour rolled up;
     * with APCu, every $flush seconds what it holds written to the hour's file
     * (a restart of PHP-FPM loses at most that much). Called by count(), and
     * for the websites a request did not count in (a quiet website's hour is
     * rolled up too).
     */
    public function tend(float $now): void
    {
        $n = intdiv((int) $now, 3600);
        if (self::$hourN !== $n) {
            [self::$hourN, self::$hour, self::$closedHour] = [$n, gmdate('YmdH', (int) $now), self::closed($now)];
        }
        $closed = self::$closedHour;
        if ($this->apcu) {
            // Once per process and hour: has the finished hour been rolled up?
            if ((self::$checked[$this->dir] ?? null) !== $closed) {
                self::$checked[$this->dir] = $closed;
                if (apcu_fetch($this->prefix . 'rolled') !== $closed) {
                    $this->later(fn () => $this->roll($now));
                }
            }
            if ($this->flush > 0 && $now - (self::$flushed[$this->dir] ?? 0.0) >= $this->flush) {
                self::$flushed[$this->dir] = $now;
                if (apcu_add($this->prefix . 'flush', 1, $this->flush)) {
                    $this->later(fn () => $this->flush());
                }
            }
            return;
        }
        if ((self::$checked[$this->dir] ?? null) !== $closed) {
            self::$checked[$this->dir] = $closed;
            if (!is_file($this->dir . '/rolled-' . $closed)) {
                $this->later(fn () => $this->roll($now));
            }
        }
    }

    /**
     * Housekeeping (roll-up, flush) after the visitor has the answer: on
     * PHP-FPM (fastcgi_finish_request) and LiteSpeed the request is finished
     * first, so a large hour's roll-up (seconds at 1,000 requests a second in
     * file mode) never makes anyone wait. On the command line: at once.
     */
    private function later(\Closure $work): void
    {
        if (PHP_SAPI === 'cli') {
            $work();
            return;
        }
        register_shutdown_function(static function () use ($work): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $work();
        });
    }

    private static ?int $hourN = null;

    private static string $hour = '';

    private static string $closedHour = '';

    /** @var array<string, float> directory => when this process last looked whether to flush */
    private static array $flushed = [];

    /** @var array<string, string> directory => the finished hour this process has checked the roll-up for (a PHP-FPM worker serves many requests) */
    private static array $checked = [];

    /**
     * A counter that belongs to a group with a limit (a crawler's pages,
     * pages not found, a missing page's referrers): the group, its limit and
     * the key everything past it is counted under. Null for the others.
     *
     * @return array{0: string, 1: int, 2: string}|null
     */
    private static function group(string $key): ?array
    {
        if (strncmp($key, 'p:', 2) === 0) {
            $g = substr($key, 0, (int) strpos($key, ':', 2));
            return [$g, self::PAGES, $g . ':(other)'];
        }
        if (strncmp($key, 'n:', 2) === 0) {
            return ['n', self::PAGES, 'n:(other)'];
        }
        if (strncmp($key, 'nr:', 3) === 0) {
            $g = substr($key, 0, (int) strpos($key, '|'));
            return [$g, self::REFERRERS, $g . '|(other)'];
        }
        if (strncmp($key, 'pd:', 3) === 0) {
            // A limit for each folder level: the many deep ones never crowd out the few above.
            $bar = (int) strpos($key, '|');
            $g = substr($key, 0, $bar);
            return [$g . '#' . max(0, substr_count($key, '/', $bar) - 1), self::TOP * 2, $g . '|(other)'];
        }
        if (strncmp($key, 'pg:', 3) === 0 || strncmp($key, 'pb:', 3) === 0) {
            $g = substr($key, 0, (int) strpos($key, '|'));
            return [$g, self::TOP, $g . '|(other)'];
        }
        if (strncmp($key, 'sm:', 3) === 0) {
            return ['sm', self::SITEMAPS, 'sm:(other)|0'];
        }
        if (strncmp($key, 'smc:', 4) === 0) {
            return ['smc', self::SITEMAPS * 5, 'smc:(other)|(other)'];
        }
        return null;
    }

    /**
     * The key, or "(other)" past its group's limit in this hour: two more
     * APCu calls, only for crawlers' pages and pages not found.
     */
    private function page(string $hour, string $key): string
    {
        $g = self::group($key);
        if ($g === null) {
            return $key;
        }
        [$group, $limit, $other] = $g;
        if (apcu_exists($this->prefix . $hour . ':' . $key)) {
            return $key;                    // admitted already
        }
        // A missing page's referrers only for missing pages that made the list:
        // random paths must not open a group each.
        if (strncmp($key, 'nr:', 3) === 0 && !apcu_exists($this->prefix . $hour . ':n:' . substr($group, 3))) {
            return '';
        }
        $counter = $this->prefix . 'pages:' . $hour . ':' . md5((string) $group);
        $taken = apcu_fetch($counter);
        if (is_int($taken) && $taken >= $limit) {
            return (string) $other;         // full: no more guard keys (a flood of random paths stays small)
        }
        if (apcu_add($this->prefix . 'seen:' . $hour . ':' . md5($key), 1, 86400 * 8)) {
            // Seen for the first time this hour: one of the first, or "(other)".
            return apcu_inc($counter, 1, $ok, 86400 * 8) > $limit ? (string) $other : $key;
        }
        return (string) $other;
    }

    /**
     * The newest hour that is over, with a minute's grace for requests still
     * running: at 10:00:30 that is 08, at 10:01 09. It and the hours before
     * it are rolled up.
     */
    private static function closed(float $now): string
    {
        return gmdate('YmdH', (int) $now - 3660);
    }

    /**
     * Writes what APCu holds into the hours' files ("name*count"), and takes
     * exactly that much out of APCu -- counts added meanwhile stay. The files
     * are read with APCu's counters and rolled up like them.
     */
    public function flush(): void
    {
        if (!$this->apcu) {
            return;
        }
        $lines = [];
        foreach (new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '(\d{10}|last):(.+)$/') as $key => $entry) {
            if (!is_array($entry) || !preg_match('/^' . preg_quote($this->prefix, '/') . '(\d{10}|last):(.+)$/', (string) $key, $m)) {
                continue;
            }
            $value = $entry['value'] ?? null;
            if ($m[1] === 'last') {
                if (is_string($value)) {
                    $lines[gmdate('YmdH', (int) $value)][] = 'l:' . $m[2] . '|' . $value;
                }
                continue;
            }
            $n = is_int($value) ? $value : 0;
            if ($n > 0 && apcu_dec((string) $key, $n) !== false) {
                $lines[$m[1]][] = $m[2] . '*' . $n;
            }
        }
        if ($lines !== [] && !is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        foreach ($lines as $hour => $tokens) {
            @file_put_contents($this->dir . '/h-' . $hour . '.log', implode(' ', $tokens) . "\n", FILE_APPEND);
        }
    }

    /**
     * Moves the finished hours into the day files; drops hours past $hours
     * days and days past $days days, and per-crawler logs past theirs. One
     * request does it; the others go on.
     */
    public function roll(float $now): void
    {
        $closed = self::closed($now);
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            return;
        }
        $lock = @fopen($this->dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;             // another request is at it
        }
        try {
            [$hours, $last] = $this->collect($closed, true);
            $byDay = [];
            foreach ($hours as $hour => $counts) {
                // (PHP makes "2026093010" an integer key: back to a string.)
                $hour = (string) $hour;
                $byDay[substr($hour, 0, 8)][substr($hour, 8, 2)] = $counts;
            }
            foreach ($byDay as $day => $hs) {
                $day = (string) $day;
                $file = $this->dir . '/d-' . $day . '.json';
                $d = self::load($file);
                foreach ($hs as $h => $counts) {
                    $h = sprintf('%02d', $h);
                    $d['hours'][$h] = self::cap(self::add($d['hours'][$h] ?? [], $counts));
                }
                foreach ($last as $id => $seen) {
                    if (gmdate('Ymd', (int) $seen[0]) === $day && ($seen[0] > ($d['last'][$id][0] ?? 0))) {
                        $d['last'][$id] = $seen;
                    }
                }
                self::save($file, $d);
            }
            // Every crawler's last visit in one small file: a report of a week
            // need not open every day file of the year to find it.
            if ($last !== []) {
                $all = self::load($this->dir . '/last.json');
                foreach ($last as $id => $seen) {
                    if ($seen[0] > ($all['last'][$id][0] ?? 0)) {
                        $all['last'][$id] = $seen;
                    }
                }
                self::save($this->dir . '/last.json', ['hours' => [], 'total' => [], 'last' => $all['last']]);
            }
            $this->expire($now);
            if ($this->apcu) {
                apcu_store($this->prefix . 'rolled', $closed, 86400 * 8);
            } else {
                foreach (glob($this->dir . '/rolled-*') ?: [] as $old) {
                    @unlink($old);
                }
                @touch($this->dir . '/rolled-' . $closed);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The counters not yet in a day file: hours up to $until (and, for
     * reading, the running one), taken out when $take -- APCu's and the
     * hours' files (without APCu, or written by a flush).
     *
     * @return array{0: array<array-key, array<string, int>>, 1: array<string, array{0: int, 1: string}>} hour => counts, crawler => [time, address]
     */
    private function collect(?string $until, bool $take): array
    {
        $hours = [];
        $last = [];
        if ($this->apcu) {
            $pattern = '/^' . preg_quote($this->prefix, '/') . '(\d{10}|last):(.+)$/';
            foreach (new \APCUIterator($pattern) as $key => $entry) {
                if (!is_array($entry) || !preg_match($pattern, (string) $key, $m)) {
                    continue;
                }
                $value = $entry['value'] ?? null;
                if ($m[1] === 'last') {
                    $v = explode('|', is_string($value) ? $value : '', 2);
                    $last[$m[2]] = [(int) $v[0], $v[1] ?? ''];
                    continue;
                }
                if ($until !== null && $m[1] > $until) {
                    continue;
                }
                if (is_int($value) && $value > 0) {
                    $hours[$m[1]][$m[2]] = ($hours[$m[1]][$m[2]] ?? 0) + $value;
                }
                if ($take) {
                    apcu_delete((string) $key);
                }
            }
            if ($take) {
                foreach (new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '(seen|pages):(\d{10}):/') as $key => $entry) {
                    if (preg_match('/:(\d{10}):/', (string) $key, $m) && ($until === null || $m[1] <= $until)) {
                        apcu_delete((string) $key);
                    }
                }
            }
        }
        foreach (glob($this->dir . '/h-*.log') ?: [] as $file) {
            $hour = substr(basename($file, '.log'), 2);
            if ($until !== null && $hour > $until) {
                continue;
            }
            $h = @fopen($file, 'rb');
            if ($h === false) {
                continue;
            }
            while (($line = fgets($h)) !== false) {
                foreach (explode(' ', trim($line)) as $k) {
                    if ($k === '') {
                        continue;
                    }
                    if (strncmp($k, 'l:', 2) === 0) {
                        $v = explode('|', substr($k, 2), 3);
                        if (count($v) === 3 && (int) $v[1] >= ($last[$v[0]][0] ?? 0)) {
                            $last[$v[0]] = [(int) $v[1], $v[2]];
                        }
                        continue;
                    }
                    // "name" is one, "name*12" twelve (written by a flush).
                    $star = strrpos($k, '*');
                    $n = 1;
                    if ($star !== false && ctype_digit(substr($k, $star + 1))) {
                        [$k, $n] = [substr($k, 0, $star), (int) substr($k, $star + 1)];
                    }
                    $hours[$hour][$k] = ($hours[$hour][$k] ?? 0) + $n;
                }
            }
            fclose($h);
            if ($take) {
                @unlink($file);
            }
        }
        return [$hours, $last];
    }

    /** Hours past their days summed into the day's totals; days past theirs removed; old crawler logs removed. */
    private function expire(float $now): void
    {
        $keepHours = gmdate('Ymd', (int) $now - $this->hours * 86400);
        $keepDays = gmdate('Ymd', (int) $now - $this->days * 86400);
        foreach (glob($this->dir . '/d-*.json') ?: [] as $file) {
            $day = substr(basename($file, '.json'), 2);
            if ($day < $keepDays) {
                // Into its month, then gone: the month keeps the total.
                $d = self::load($file);
                $total = $d['total'];
                foreach ($d['hours'] as $counts) {
                    $total = self::add($total, $counts);
                }
                $monthFile = $this->dir . '/m-' . substr($day, 0, 6) . '.json';
                $m = self::load($monthFile);
                $last = $m['last'];
                foreach ($d['last'] as $id => $seen) {
                    if ($seen[0] > ($last[$id][0] ?? 0)) {
                        $last[$id] = $seen;
                    }
                }
                self::save($monthFile, ['hours' => [], 'total' => self::cap(self::add($m['total'], $total)), 'last' => $last, 'days' => array_values(array_unique(array_merge($m['days'], [$day])))]);
                @unlink($file);
            } elseif ($day < $keepHours) {
                $d = self::load($file);
                if ($d['hours'] !== []) {
                    $total = $d['total'];
                    foreach ($d['hours'] as $counts) {
                        $total = self::add($total, $counts);
                    }
                    self::save($file, ['hours' => [], 'total' => self::cap($total), 'last' => $d['last']]);
                }
            }
        }
        if ($this->months > 0) {
            $keepMonths = gmdate('Ym', (int) strtotime(gmdate('Y-m-01', (int) $now) . ' -' . $this->months . ' months'));
            foreach (glob($this->dir . '/m-*.json') ?: [] as $file) {
                if (substr(basename($file, '.json'), 2) < $keepMonths) {
                    @unlink($file);
                }
            }
        }
        if ($this->crawlerLog !== null) {
            $keep = date('Y-m-d', (int) $now - $this->crawlerLogDays * 86400);
            foreach (glob($this->crawlerLog . '/*/*.log') ?: [] as $file) {
                if (basename($file, '.log') < $keep) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * What was counted from $fromDay to $toDay (yyyymmdd, both included): each
     * day's totals, the hours still kept, every crawler's last visit. The
     * running hour included.
     *
     * Days older than $days are only in their month's total ("months": yyyymm =>
     * counts, for every month file the range touches).
     *
     * @return array{days: array<string, array<string, int>>, hours: array<string, array<string, int>>, months: array<string, array<string, int>>, last: array<string, array{0: int, 1: string}>}
     */
    public function read(string $fromDay, string $toDay): array
    {
        $days = [];
        $hours = [];
        $last = [];
        $months = [];
        foreach (glob($this->dir . '/m-*.json') ?: [] as $file) {
            $month = substr(basename($file, '.json'), 2);
            if ($month >= substr($fromDay, 0, 6) && $month <= substr($toDay, 0, 6)) {
                $months[$month] = self::load($file)['total'];
                ksort($months[$month]);
            }
        }
        ksort($months);
        $last = self::load($this->dir . '/last.json')['last'];
        foreach (glob($this->dir . '/d-*.json') ?: [] as $file) {
            $day = substr(basename($file, '.json'), 2);
            if ($day < $fromDay || $day > $toDay) {
                continue;               // only the days asked for are opened
            }
            $d = self::load($file);
            foreach ($d['last'] as $id => $seen) {
                if ($seen[0] > ($last[$id][0] ?? 0)) {
                    $last[$id] = $seen;
                }
            }
            $days[$day] = $d['total'];
            foreach ($d['hours'] as $h => $counts) {
                $hours[$day . sprintf('%02d', $h)] = $counts;
                $days[$day] = self::add($days[$day], $counts);
            }
        }
        [$live, $liveLast] = $this->collect(null, false);
        foreach ($live as $hour => $counts) {
            $hour = (string) $hour;
            $day = substr($hour, 0, 8);
            if ($day >= $fromDay && $day <= $toDay) {
                $hours[$hour] = self::add($hours[$hour] ?? [], $counts);
                $days[$day] = self::add($days[$day] ?? [], $counts);
            }
        }
        foreach ($liveLast as $id => $seen) {
            if ($seen[0] > ($last[$id][0] ?? 0)) {
                $last[$id] = $seen;
            }
        }
        ksort($days);
        ksort($hours);
        foreach ($days as &$counts) {
            ksort($counts);         // the same order from files and APCu
        }
        unset($counts);
        foreach ($hours as &$counts) {
            ksort($counts);
        }
        unset($counts);
        return ['days' => $days, 'hours' => $hours, 'months' => $months, 'last' => $last];
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    public static function add(array $a, array $b): array
    {
        foreach ($b as $k => $n) {
            $a[(string) $k] = ($a[(string) $k] ?? 0) + $n;
        }
        return $a;
    }

    /**
     * Each group at its limit: the most counted kept, the rest as "(other)".
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function cap(array $counts): array
    {
        $groups = [];
        foreach ($counts as $k => $n) {
            $g = self::group((string) $k);
            if ($g !== null && (string) $k !== $g[2]) {
                $groups[$g[0]]['keys'][(string) $k] = $n;
                $groups[$g[0]]['limit'] = $g[1];
                $groups[$g[0]]['other'] = $g[2];
            }
        }
        foreach ($groups as $g) {
            if (count($g['keys']) <= $g['limit']) {
                continue;
            }
            arsort($g['keys']);
            foreach (array_slice($g['keys'], $g['limit'], null, true) as $k => $n) {
                unset($counts[$k]);
                $counts[$g['other']] = ($counts[$g['other']] ?? 0) + $n;
            }
        }
        // Referrers only for the pages not found that stayed on the list.
        foreach ($counts as $k => $n) {
            $k = (string) $k;
            if (strncmp($k, 'nr:', 3) === 0 && !isset($counts['n:' . substr($k, 3, (int) strpos($k, '|') - 3)])) {
                unset($counts[$k]);
            }
        }
        return $counts;
    }

    /**
     * A day's or a month's file (a month: the days summed into it).
     *
     * @return array{hours: array<string, array<string, int>>, total: array<string, int>, last: array<string, array{0: int, 1: string}>, days: list<string>}
     */
    private static function load(string $file): array
    {
        $d = @json_decode((string) @file_get_contents($file), true);
        $d = is_array($d) ? $d : [];
        /** @var array{hours: array<string, array<string, int>>, total: array<string, int>, last: array<string, array{0: int, 1: string}>, days: list<string>} */
        return ['hours' => is_array($d['hours'] ?? null) ? $d['hours'] : [], 'total' => is_array($d['total'] ?? null) ? $d['total'] : [],
            'last' => is_array($d['last'] ?? null) ? $d['last'] : [], 'days' => is_array($d['days'] ?? null) ? array_values(array_filter($d['days'], 'is_string')) : []];
    }

    /** @param array<string, mixed> $d */
    private static function save(string $file, array $d): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_SLASHES)) !== false) {
            @chmod($tmp, 0640);
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * The family of a client that says it is a tool, not a browser, and is no
     * known crawler: python, curl, wget, go, java, node, php, perl, headless,
     * scrapy, empty, other (a "bot", "crawler", "spider" of its own). Null
     * for a browser.
     */
    public static function botFamily(string $userAgent): ?string
    {
        return Seen::family($userAgent);
    }
}
