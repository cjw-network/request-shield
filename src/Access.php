<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\Store\Store;

/**
 * Who may read the statistics (proposal 0023, phase 4): the admin ('*')
 * everything, a principal (a customer) only what the pages let it see. Three ways in:
 *
 *  - a token, once, by the login form: a signed session cookie for
 *    dashboard-session (8 hours); the token never in an address;
 *  - a signed link the customer's own hosting panel or CMS makes on its
 *    server (Access::link(), 10 minutes, at most an hour): opened, it sets the
 *    cookie and redirects to the address without the signature;
 *  - Authorization: Bearer <token>, for the JSON.
 *
 * Only tokens' SHA-256 hashes are in the rule file (dashboard-access); a token is
 * 32 random bytes, so a fast hash is enough (a slow one is for passwords
 * people choose). Wrong tokens and signatures count against a budget of their
 * own (10 a minute per address, then 429) and are logged without the token.
 * Without dashboard-access lines nothing is asked: the site's own rules (restrict)
 * decide, as before.
 */
final class Access
{
    public const COOKIE = 'rsd';

    /** Wrong tokens or signatures a minute per address before 429. */
    public const TRIES = 10;

    /** The longest a signed link may last, in seconds. */
    public const LINK_MAX = 3600;

    private const T = [
        'en' => [
            'title' => 'Statistics', 'intro' => 'Please sign in with the access token you were given.', 'token' => 'Access token', 'go' => 'Sign in',
            'wrong' => 'That token is not right (or no longer valid).', 'link' => 'That link is not right, or it has expired -- ask for a new one.',
            'many' => 'Too many tries from this address -- please wait a minute.', 'out' => 'Signed out.', 'origin' => 'This form can only be sent from this page.',
            'signout' => 'Sign out',
        ],
        'de' => [
            'title' => 'Statistik', 'intro' => 'Bitte mit dem Zugangs-Token anmelden, das Sie bekommen haben.', 'token' => 'Zugangs-Token', 'go' => 'Anmelden',
            'wrong' => 'Dieses Token stimmt nicht (oder gilt nicht mehr).', 'link' => 'Dieser Link stimmt nicht oder ist abgelaufen -- bitte einen neuen anfordern.',
            'many' => 'Zu viele Versuche von dieser Adresse -- bitte eine Minute warten.', 'out' => 'Abgemeldet.', 'origin' => 'Dieses Formular kann nur von dieser Seite gesendet werden.',
            'signout' => 'Abmelden',
        ],
    ];

    /** Whether the dashboard asks who is reading (dashboard-access lines are there). */
    public static function enabled(Settings $s): bool
    {
        return $s->dashboardAccess !== [];
    }

    /**
     * A new token: 32 random bytes, URL-safe -- and the SHA-256 the rule file keeps.
     *
     * @return array{0: string, 1: string}
     */
    public static function token(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return [$token, hash('sha256', $token)];
    }

    /** Whose a token is ('*' or a principal's id), or null. */
    public static function whoseToken(Settings $s, string $token): ?string
    {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }
        $hash = hash('sha256', $token);
        $who = null;
        foreach ($s->dashboardAccess as $a) {
            if (hash_equals($a['hash'], $hash)) {
                $who = $a['who'];                      // no early return: every line compared
            }
        }
        return $who;
    }

    /**
     * A signed link's query for a principal (or '*'), made on the customer's
     * panel or CMS with the shared secret -- no token in it, valid $ttl
     * seconds (at most an hour). The principal is opaque to the shield: what
     * it may see is the pages' business (the statistics: its group's websites).
     */
    public static function link(Settings $s, string $principal, int $ttl = 600, ?int $now = null): string
    {
        $who = $principal === '*' ? '*' : Settings::principal($principal);
        $exp = ($now ?? time()) + max(1, min(self::LINK_MAX, $ttl));
        return http_build_query(['rs-g' => $who, 'rs-exp' => $exp, 'rs-sig' => self::sign($s, "link|$who|$exp")]);
    }

    /**
     * The answer to a request for a dashboard page: who reads ('*', a
     * principal's id), or a page to send instead (the login form, a redirect, 429).
     *
     * @param array<mixed> $get $_GET
     * @param array<mixed> $post $_POST
     * @param array<string, mixed> $o store, now, lang, accept, action (the page's address), home, homeLabel, always (ask even without dashboard-access lines),
     *                                admin (the site's own login says this reader is the admin -- a CMS's signed-in administrator; a customer's
     *                                cookie or link still wins, and ?rs-login=1 shows the form)
     * @return array{who: ?string, status: int, headers: list<string>, body: ?string}
     */
    public static function gate(Settings $s, Request $request, array $get, array $post, array $o = []): array
    {
        $now = is_int($o['now'] ?? null) ? $o['now'] : time();
        $headers = self::headers($s);
        if (!self::enabled($s) && !($o['always'] ?? false)) {
            return self::answer('*', 200, $headers, null);
        }
        $store = ($o['store'] ?? null) instanceof Store ? $o['store'] : Shield::storeFor($s);
        $lang = Texts::language(is_string($o['lang'] ?? null) ? $o['lang'] : 'auto', is_string($o['accept'] ?? null) ? $o['accept'] : $request->header('accept-language'));
        $lang = $lang === 'de' ? 'de' : 'en';
        $t = self::T[$lang];
        $here = self::without((string) ($request->rawUri), ['rs-g', 'rs-exp', 'rs-sig', 'rs-logout']);
        $bucket = 'access:' . IpAddress::bucket($request->clientIp, $s->ipv6Prefix);
        $tooMany = static fn (): bool => $store->peek($bucket, 60, (float) $now) >= self::TRIES;
        $wrong = static function (string $why) use ($s, $request, $store, $bucket, $now): void {
            $store->hit($bucket, 60, (float) $now);
            Log::note($s, $request, Decision::reject(401, 'access'), $why, (float) $now);
        };
        $page = static fn (int $status, string $message, string ...$more): array => self::answer(null, $status, [...$headers, ...array_values($more)], self::page($s, $lang, $message, $here, ['status' => $status] + $o));

        if (isset($get['rs-logout'])) {
            return $page(200, $t['out'], self::cookieHeader($s, '', 1, $request));
        }
        // A signed link from the customer's own panel: the cookie, then the address without the signature.
        if (isset($get['rs-sig'])) {
            if ($tooMany()) {
                return $page(429, $t['many'], 'Retry-After: 60');
            }
            $who = self::fromLink($s, $get, $now);
            if ($who === null) {
                $wrong('dashboard-access link');
                return $page(403, $t['link']);
            }
            return self::answer(null, 303, [...$headers, ...[self::cookieHeader($s, $who, $now + $s->dashboardSession, $request), 'Location: ' . $here]], null);
        }
        // The login form: a token, by POST, from this very page.
        if (isset($post['rs-token'])) {
            if ($tooMany()) {
                return $page(429, $t['many'], 'Retry-After: 60');
            }
            $origin = (string) $request->header('origin');
            if ($origin !== '' && strcasecmp((string) parse_url($origin, PHP_URL_HOST), (string) preg_replace('/:\d+$/', '', $request->host)) !== 0) {
                return $page(403, $t['origin']);
            }
            $who = self::whoseToken($s, is_string($post['rs-token']) ? trim($post['rs-token']) : '');
            if ($who === null) {
                $wrong('dashboard-access token');
                return $page(401, $t['wrong']);
            }
            return self::answer(null, 303, [...$headers, ...[self::cookieHeader($s, $who, $now + $s->dashboardSession, $request), 'Location: ' . $here]], null);
        }
        // A program: Authorization: Bearer <token>.
        $auth = (string) $request->header('authorization');
        if (strncasecmp($auth, 'Bearer ', 7) === 0) {
            if ($tooMany()) {
                return self::answer(null, 429, [...$headers, ...['Retry-After: 60']], null);
            }
            $who = self::whoseToken($s, trim(substr($auth, 7)));
            if ($who === null) {
                $wrong('dashboard-access bearer');
                return self::answer(null, 401, $headers, null);
            }
            return self::answer($who, 200, $headers, null);
        }
        $who = self::fromCookie($s, (string) $request->cookie(self::COOKIE), $now);
        if ($who !== null) {
            return self::answer($who, 200, $headers, null);
        }
        if (($o['admin'] ?? false) === true && !isset($get['rs-login'])) {
            return self::answer('*', 200, $headers, null);       // the site knows its administrator
        }
        return $page(401, $t['intro']);
    }

    /**
     * @param list<string> $headers
     * @return array{who: ?string, status: int, headers: list<string>, body: ?string}
     */
    private static function answer(?string $who, int $status, array $headers, ?string $body): array
    {
        return ['who' => $who, 'status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /** Who a session cookie says (checked: its signature, its end, the tokens it was made with), or null. */
    public static function fromCookie(Settings $s, string $value, ?int $now = null): ?string
    {
        if (preg_match('/^(\*|[a-z0-9-]{1,61})\.(\d{1,12})\.([0-9a-f]{8})\.([0-9a-f]{32})$/', $value, $m) !== 1) {
            return null;
        }
        [, $who, $exp, $gen, $sig] = $m;
        if ((int) $exp < ($now ?? time()) || !hash_equals(self::sign($s, "session|$who|$exp|$gen"), $sig) || !hash_equals(self::generation($s, $who), $gen)) {
            return null;
        }
        return $who;
    }

    /**
     * Who a signed link says, or null (wrong, expired, or longer than an hour).
     *
     * @param array<mixed> $get
     */
    public static function fromLink(Settings $s, array $get, ?int $now = null): ?string
    {
        $who = is_string($get['rs-g'] ?? null) ? $get['rs-g'] : '';
        $exp = is_string($get['rs-exp'] ?? null) && ctype_digit($get['rs-exp']) ? (int) $get['rs-exp'] : 0;
        $sig = is_string($get['rs-sig'] ?? null) ? $get['rs-sig'] : '';
        $now ??= time();
        if (($who !== '*' && preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $who) !== 1) || $exp < $now || $exp > $now + self::LINK_MAX || !hash_equals(self::sign($s, "link|$who|$exp"), $sig)) {
            return null;
        }
        return $who;
    }

    /**
     * The dashboard's tabs for a reader: the admin ('*') gets every link, a
     * customer the pages whose route is a reader's (its statistics: all its
     * websites, visitors and pages, the protection) -- never Rules & setup,
     * the overview of the server, the live view or the lists.
     *
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public static function links(Settings $s, string $who, array $links): array
    {
        if ($who === '*') {
            return $links;
        }
        $reader = [];
        foreach ($s->routes as $r) {
            if ($r['role'] === 'reader') {
                $reader[$r['key']] = 1;
            }
        }
        return array_intersect_key($links, $reader);
    }

    /**
     * The headers every statistics page sends: never indexed, never kept, never framed elsewhere.
     *
     * @return list<string>
     */
    public static function headers(Settings $s): array
    {
        return ['X-Robots-Tag: noindex, nofollow', 'Cache-Control: private, no-store', 'Referrer-Policy: same-origin', "Content-Security-Policy: frame-ancestors 'self'"];
    }

    /** The Set-Cookie header: who, until when, signed; HttpOnly, SameSite=Lax (a signed link arrives from another site), Secure on HTTPS. */
    private static function cookieHeader(Settings $s, string $who, int $until, Request $request): string
    {
        $value = $who === '' ? '' : $who . '.' . $until . '.' . self::generation($s, $who) . '.' . self::sign($s, "session|$who|$until|" . self::generation($s, $who));
        return 'Set-Cookie: ' . self::COOKIE . '=' . $value . '; Path=/; Max-Age=' . ($who === '' ? 0 : max(0, $until - time())) . '; HttpOnly; SameSite=Lax'
            . ($request->scheme === 'https' ? '; Secure' : '');
    }

    /** The tokens a reader's sessions were made with: a token removed or changed ends them. */
    private static function generation(Settings $s, string $who): string
    {
        $hashes = [];
        foreach ($s->dashboardAccess as $a) {
            if ($a['who'] === $who) {
                $hashes[] = $a['hash'];
            }
        }
        sort($hashes);
        return substr(hash('sha256', $who . '|' . implode(',', $hashes)), 0, 8);
    }

    private static function sign(Settings $s, string $data): string
    {
        return substr(hash_hmac('sha256', 'stats|' . $data, Secret::resolve($s->challenge->secret, $s->storeDir)), 0, 32);
    }

    /** @param list<string> $drop */
    private static function without(string $uri, array $drop): string
    {
        $q = strpos($uri, '?');
        if ($q === false) {
            return $uri;
        }
        parse_str(substr($uri, $q + 1), $params);
        foreach ($drop as $d) {
            unset($params[$d]);
        }
        return substr($uri, 0, $q) . ($params === [] ? '' : '?' . http_build_query($params));
    }

    /** @param array<string, mixed> $o */
    private static function page(Settings $s, string $lang, string $message, string $action, array $o): string
    {
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '<form class="card" method="post" action="' . $e($action) . '" style="max-width:520px">'
            . '<p>' . $e($message) . '</p><label>' . $e($t['token']) . '<br><input type="password" name="rs-token" autocomplete="current-password" required style="width:100%"></label>'
            . '<p><button class="primary">' . $e($t['go']) . '</button></p></form>';
        $title = is_string($o['title'] ?? null) ? $o['title'] : $t['title'];
        // The site's own login form (the Pages hook, 0031 B.10), else the shield's.
        return PageHook::ask($s, Pages::ACCESS_LOGIN, ['status' => is_int($o['status'] ?? null) ? $o['status'] : 401, 'message' => $message, 'action' => $action, 'lang' => $lang, 'texts' => $t,
            'home' => $o['home'] ?? null, 'homeLabel' => $o['homeLabel'] ?? null, 'title' => $title]) ?? Frame::page($title, $lang, $body, $o + ['help' => \CjwNetwork\RequestShield\Help::link('RSF06-03', 'who-sees-what-tokens-a-login-signed-links', $s->docsUrl, $lang)]);
    }

    /** A sign-out link for the pages: the current address with rs-logout. */
    public static function signOut(string $uri, string $lang = 'en'): string
    {
        $t = self::T[$lang === 'de' ? 'de' : 'en'];
        $url = self::without($uri, ['rs-logout']);
        return '<a href="' . htmlspecialchars($url . (strpos($url, '?') === false ? '?' : '&') . 'rs-logout=1', ENT_QUOTES) . '">' . htmlspecialchars($t['signout'], ENT_QUOTES) . '</a>';
    }
}
