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
 * The statistics, as a plugin of the shield (proposal 0023): counts what the
 * shield decided per hour -- actions, rules, answers, known crawlers and their
 * pages, bot families, pages not found and their links, sitemaps, page views,
 * what was stopped where -- and writes the per-crawler logs (set crawler-log).
 *
 * Registered by `set stats on` (or `set crawler-log …`) on its own; what it
 * counts is set with the `stats` words of the rule file. A request the shield
 * answered itself is counted at once; one the site answers, when it has ended
 * (with the site's status).
 */
final class StatsPlugin implements Plugin
{
    /** What the shield did to a page it stopped, as the statistics call it. */
    private const BLOCKED = [Decision::REJECT => 'refused', Decision::CHALLENGE => 'checked', Decision::THROTTLE => 'throttled'];

    private ?Stats $stats = null;

    /** @var list<string> the keys of a request that goes on to the site, counted when it ends */
    private array $pending = [];

    public function __construct(private Settings $settings)
    {
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
        $s = $this->settings;
        // stats-skip: a path that is no page of the site (a map proxy's tiles) -- not counted when it
        // passes; refused or checked, it still is (an attack there stays visible).
        // The dashboard's own pages (the statistics, the live view's feed every few
        // seconds): looking at the numbers must not change them.
        if ($decision->passes() && $would === null && (($s->statsSkip !== [] && self::skipped($s->statsSkip, $request->matchPath()))
            || (stripos($request->path, $s->dashboardPath) !== false || stripos($request->path, $s->statsPath) !== false) && \CjwNetwork\RequestShield\Report\Frame::isPage($s, $request->matchPath()))) {
            $this->waiting = false;
            return;
        }
        $parts = $s->statsEnabled ? $s->statsParts : [];
        $keys = [];
        if (in_array('requests', $parts, true)) {
            $keys[] = 'a:' . $decision->action;
            if ($decision->action !== Decision::ALLOW && $rule !== null) {
                $keys[] = 'r:' . str_replace(' ', '_', $rule);
            }
            if ($would !== null) {
                $keys[] = 'm:' . $would->action;
            }
        }
        $crawling = in_array('crawlers', $parts, true);
        $paging = in_array('pages', $parts, true);
        // A crawler is looked at only for its statistics, the pages' visitors or its log.
        $id = !$crawling && !$paging && $s->crawlerLogDir === null ? null : $seen->crawler();
        $who = 'people';
        if ($id !== null) {
            $verified = $seen->verified();
            $who = $verified ? 'crawlers' : 'bots';
            if ($crawling) {
                $keys[] = "c:$id:seen";
                $keys[] = "c:$id:" . ($verified ? 'verified' : 'claimed');
            }
            if ($verified && $crawling) {
                $keys[] = "c:$id:" . [Decision::ALLOW => 'allowed', Decision::ALLOW_UNCACHED => 'allowed', Decision::CHALLENGE => 'checked',
                    Decision::THROTTLE => 'throttled', Decision::REJECT => 'refused'][$decision->action];
                if ($request->path === '/robots.txt') {
                    $keys[] = "c:$id:robots";
                }
                // The page, without its query; spaces and the like escaped (a key is one word).
                $keys[] = "p:$id:" . self::word($request->path);
                $keys[] = "l:$id|" . (int) $now . '|' . $request->clientIp;
                if (self::isSitemap($request->path)) {
                    // Which crawler read which sitemap, and when last: does it see the new content?
                    $keys[] = 'smc:' . self::word($request->path) . "|$id";
                    $keys[] = 'l:sitemap:' . self::word($request->path) . "@$id|" . (int) $now . '|-';
                }
            }
            if ($s->crawlerLogDir !== null && ($s->crawlerLogKinds === [] || in_array($seen->crawlerKind(), $s->crawlerLogKinds, true))) {
                // A verified crawler's address is its operator's: in full. One that
                // only claims the name may be a person: as the log keeps addresses.
                Log::append($s->crawlerLogDir . '/' . $id . '/' . date('Y-m-d', (int) $now) . '.log',
                    Log::line($s, $request, $verified ? $decision : $decision->claiming($id), $rule, $now, false, $verified ? $request->clientIp : null, $s->crawlerLogQuery),
                    $s->logMaxSize);
            }
        } elseif (in_array('bots', $parts, true) || $paging) {
            $family = $seen->botFamily();
            if ($family !== null) {
                $who = 'bots';
                if (in_array('bots', $parts, true)) {
                    $keys[] = 'o:' . $family;
                }
            }
        }
        // A page the shield stopped: which, and how (refused, checked, told to
        // wait) -- whoever asked. Its query left out, as for page views.
        if ($paging && isset(self::BLOCKED[$decision->action])) {
            $keys[] = 'pb:' . self::BLOCKED[$decision->action] . '|' . self::word($request->path);
        }
        // A form sent (proposal 0028): which, from which page, how it ended. Never what was typed.
        $this->formOut = null;
        $forms = in_array('forms', $parts, true);
        if ($forms && isset(self::FORM_METHODS[$request->method]) && !self::isApi($s, $request)) {
            $area = self::backendOf($s, $request->matchPath());
            if ($area !== null) {
                // The editors' area: one entry per area, not per address.
                $keys[] = 'fb:' . self::word($area);
                $out = 'fbo:' . self::word($area) . '|';
            } else {
                $form = self::word($request->path);
                $keys[] = 'f:' . $form;
                $keys[] = 'ff:' . $form . '|' . self::sentFrom($s, $request);
                $out = 'fo:' . $form . '|';
            }
            if (isset(self::BLOCKED[$decision->action])) {
                $keys[] = $out . ($decision->reason === 'cross-site' ? 'cross-site' : self::BLOCKED[$decision->action]);
            } else {
                $this->formOut = $out;                  // saved or an error: the site's answer, when it has ended
            }
        }
        if (!$s->statsEnabled) {
            return;
        }
        $stats = $this->stats;
        if ($stats === null) {
            // stats-hosts: the website's own statistics (a name the rules do not know: "other").
            $site = Stats::siteOf($s, $seen->host());
            $stats = $this->stats = Stats::of($s, $site);
            if ($site !== null) {
                self::tendOthers($s, $site, $now);
            }
        }
        $requests = in_array('requests', $parts, true);
        if ($requests && $who === 'people') {
            $stats->minute($now);           // "now" on the visitors page: one APCu counter a minute
        }
        if (!$continues || (!$requests && !in_array('not-found', $parts, true) && !$crawling && !$paging && $this->formOut === null)) {
            if ($requests) {
                $keys[] = 's:' . $decision->status;          // the shield answered itself
            }
            if ($crawling && self::isSitemap($request->path)) {
                $keys[] = 'sm:' . self::word($request->path) . '|' . $decision->status;
            }
            if ($keys !== []) {
                $stats->count($keys, $now);
            }
            $this->pending = [];
            return;
        }
        // Goes on to the site: counted when it ends, with the status the site answered.
        $this->pending = $keys;
        $this->who = $who;
        $this->waiting = true;
    }

    /** @var int the hour (since 1970) this process last tended the other websites' statistics */
    private static int $tended = -1;

    /**
     * Once per process and hour: the websites this request does not count in
     * have their finished hour rolled up and APCu written out too -- a quiet
     * website would otherwise wait for its next visitor.
     */
    private static function tendOthers(Settings $s, string $site, float $now): void
    {
        $hour = intdiv((int) $now, 3600);
        if (self::$tended === $hour) {
            return;
        }
        self::$tended = $hour;
        foreach (array_merge($s->statsHosts, [Stats::OTHER]) as $name) {
            if ($name !== $site) {
                Stats::of($s, $name)->tend($now);
            }
        }
    }

    private string $who = 'people';

    /** @var ?string the key a form's outcome is counted under ("fo:/kontakt|", "fbo:/admin/**|"), when the site answers it */
    private ?string $formOut = null;

    /** The methods that send a form. */
    private const FORM_METHODS = ['POST' => true, 'PUT' => true, 'PATCH' => true, 'DELETE' => true];

    /** Whether a request goes to the site's API (api-path): counted as now, not as a form. */
    private static function isApi(Settings $s, Request $request): bool
    {
        foreach ($s->challenge->apiPaths as $p) {
            if (@preg_match($p, $request->matchPath()) === 1) {
                return true;
            }
        }
        return false;
    }

    /** The editors' area a path is in (backend <paths>), as written: "/admin/**"; null outside. */
    private static function backendOf(Settings $s, string $path): ?string
    {
        foreach ($s->backend as $p) {
            if (@preg_match($p, $path) === 1) {
                return $s->origin('written', $p) ?? $p;
            }
        }
        return null;
    }

    /**
     * Where a form was sent from, as a word: the path of the website's own page
     * (the Referer's, without its query), "@<host>" for another website (its
     * host only), "=" for this website without the page (an Origin, no Referer),
     * "-" when the browser does not say.
     */
    private static function sentFrom(Settings $s, Request $request): string
    {
        $ref = trim((string) $request->header('referer'));
        $origin = trim((string) $request->header('origin'));
        $source = $ref !== '' ? $ref : ($origin !== '' && $origin !== 'null' ? $origin : '');
        if ($source === '') {
            return '-';
        }
        $host = parse_url($source, PHP_URL_HOST);
        $host = is_string($host) ? rtrim(strtolower($host), '.') : '';
        $own = Shield::ownNames($s);
        $mine = $host !== '' && ($own === [] ? $host === $request->host : self::named($host, $own));
        if (!$mine) {
            return '@' . ($host === '' ? '?' : self::word($host));
        }
        if ($ref === '') {
            return '=';                                 // this website (its Origin), the page not said
        }
        $path = parse_url($ref, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? self::word($path) : '/';
    }

    /** @param list<string> $names */
    private static function named(string $host, array $names): bool
    {
        foreach ($names as $name) {
            if ($name === $host || (strncmp($name, '*.', 2) === 0 && strlen($host) > strlen($name) - 1 && substr($host, 1 - strlen($name)) === substr($name, 1)
                && strpos(substr($host, 0, 1 - strlen($name)), '.') === false)) {
                return true;
            }
        }
        return false;
    }

    private bool $waiting = false;

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
        if (!$this->waiting || $this->stats === null) {
            return;
        }
        $this->waiting = false;
        $s = $this->settings;
        $parts = $s->statsParts;
        $keys = $this->pending;
        if ($status > 0) {
            $requests = in_array('requests', $parts, true);
            $missing = in_array('not-found', $parts, true);
            $crawling = in_array('crawlers', $parts, true);
            foreach (self::statusKeys($request, $status) as $k) {
                if (strncmp($k, 'sm:', 3) === 0 ? $crawling : (strncmp($k, 's:', 2) === 0 ? $requests : $missing)) {
                    $keys[] = $k;
                }
            }
            // A form: saved (2xx, 3xx: the usual redirect after saving) or an error (4xx, 5xx).
            if ($this->formOut !== null) {
                $keys[] = $this->formOut . ($status < 400 ? 'saved' : 'error');
            }
            // A page view: GET, 200, HTML -- counted by who came.
            if (in_array('pages', $parts, true) && $status === 200 && $request->method === 'GET' && self::isHtml($headers)) {
                $keys[] = 'pg:' . $this->who . '|' . self::word($request->path);
                // Its first folders too (stats-depth, 2: /news/, /news/2026/): how many views a subtree got, exactly.
                foreach (self::folders($request->path, $s->statsDepth) as $folder) {
                    $keys[] = 'pd:' . $this->who . '|' . self::word($folder);
                }
            }
        }
        if ($keys !== []) {
            $this->stats->count($keys, $now);
        }
    }

    /** @param list<string> $patterns */
    private static function skipped(array $patterns, string $path): bool
    {
        foreach ($patterns as $p) {
            if (@preg_match($p, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /** A path as one word of a counter's name: spaces, "*" (a count in the file store) and the like escaped. */
    private static function word(string $v): string
    {
        return substr((string) preg_replace_callback('/[^\x21-\x29\x2b-\x7b\x7d\x7e]/', static fn (array $m): string => rawurlencode($m[0]), $v), 0, 200);
    }

    /**
     * The first folders of a path, $depth of them: /news/2026/10/x -> /news/,
     * /news/2026/ (2); /news/ -> /news/ (its own page belongs to it).
     *
     * @return list<string>
     */
    public static function folders(string $path, int $depth = 2): array
    {
        $parts = explode('/', trim($path, '/'));
        if (substr($path, -1) !== '/') {
            array_pop($parts);                  // the page itself; a folder's own page (/news/) belongs to it
        }
        $out = [];
        $prefix = '/';
        foreach (array_slice($parts, 0, $depth) as $part) {
            if ($part === '') {
                break;
            }
            $prefix .= $part . '/';
            $out[] = $prefix;
        }
        return $out;
    }

    /**
     * Whether the answer is a page: its Content-Type is HTML, or none was set
     * (PHP's default is text/html).
     *
     * @param list<string> $headers headers_list()
     */
    public static function isHtml(array $headers): bool
    {
        foreach ($headers as $h) {
            if (strncasecmp($h, 'content-type:', 13) === 0) {
                return stripos($h, 'text/html') !== false || stripos($h, 'application/xhtml') !== false;
            }
        }
        return true;
    }

    /** sitemap.xml, sitemap_index.xml, sitemap-news.xml, …, also .gz, in any folder. */
    public static function isSitemap(string $path): bool
    {
        return preg_match('~(?:^|/)sitemap[\w.-]*\.xml(?:\.gz)?$~i', $path) === 1;
    }

    /**
     * The status the site answered; for a page it did not find (404, 410),
     * the page and where a link to it was: a page of the site itself (a broken
     * link to fix), or the other site's host -- never a whole foreign URL.
     *
     * @return list<string>
     */
    public static function statusKeys(Request $request, int $status): array
    {
        $keys = ['s:' . $status];
        if (self::isSitemap($request->path)) {
            $keys[] = 'sm:' . self::word($request->path) . '|' . $status;     // which sitemaps exist: 200, 404
        }
        if ($status !== 404 && $status !== 410) {
            return $keys;
        }
        $path = self::word($request->path);
        $keys[] = 'n:' . $path;
        $ref = (string) $request->header('referer');
        $parts = $ref === '' ? false : parse_url($ref);
        if (is_array($parts) && isset($parts['host'])) {
            $own = strcasecmp($parts['host'], $request->host) === 0;
            $keys[] = 'nr:' . $path . '|' . ($own ? self::word($parts['path'] ?? '/') : self::word(strtolower($parts['host'])));
        }
        return $keys;
    }
}
