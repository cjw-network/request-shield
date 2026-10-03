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
 * The dashboard's pages, as the compiled settings carry them (0031 B.5):
 * `$s->routes` is full path => entry, the core's pages (Routes::core()) and
 * what every offered extension declared (Extension::routes()), sorted by
 * their order. Everything that lists, links or recognises a page derives
 * from it -- the frame's tabs, the statistics' links, the pace's exemption
 * for the dashboard's own requests -- so no table of pages lives anywhere
 * else. No dependency on Report/*: a build without the pages has the
 * registry all the same.
 *
 * An entry: key (what the page is: live, lists, sites …), ext (the
 * extension's id, null for the core), tab ([English, German] label, null
 * for a page without a tab -- a start alias), role (admin or reader: who
 * may open it, the reader being a customer signed in to its statistics)
 * and order (the tabs' order). Paths are stored as declared; a request
 * matches them without regard to capitals or a trailing "/".
 *
 * @phpstan-type Entry array{key: string, ext: ?string, tab: ?array{0: string, 1: string}, role: string, order: int}
 */
final class Routes
{
    /** Who may open a page: the admin, or a reader (a customer with its statistics). */
    public const ROLES = ['admin', 'reader'];

    /**
     * The core's pages below dashboard-path: the firewall's start (/waf,
     * which is the live view), Rules & setup, Live and Lists.
     *
     * @return array<string, Entry>
     */
    public static function core(string $dashboardPath): array
    {
        $waf = $dashboardPath . '/waf';
        return [
            $waf => ['key' => 'live', 'ext' => null, 'tab' => null, 'role' => 'admin', 'order' => 60],
            "$waf/rules" => ['key' => 'rules', 'ext' => null, 'tab' => ['Rules & setup', 'Regeln & Einrichtung'], 'role' => 'admin', 'order' => 50],
            "$waf/live" => ['key' => 'live', 'ext' => null, 'tab' => ['Live', 'Live'], 'role' => 'admin', 'order' => 60],
            "$waf/lists" => ['key' => 'lists', 'ext' => null, 'tab' => ['Lists', 'Listen'], 'role' => 'admin', 'order' => 70],
        ];
    }

    /**
     * One entry, shape-checked: key a string, tab null or two strings, role
     * admin or reader, order an int; ext optional (null: the core). A wrong
     * one is an InvalidArgumentException naming $name.
     *
     * @param mixed $route
     * @return Entry
     */
    public static function entry($route, string $name, string $path): array
    {
        if (!is_array($route) || !is_string($route['key'] ?? null) || $route['key'] === '') {
            throw Settings::wrong($name, "for $path: an entry with a key (what the page is)");
        }
        $tab = $route['tab'] ?? null;
        if ($tab !== null && !(is_array($tab) && count($tab) === 2 && is_string($tab[0] ?? null) && is_string($tab[1] ?? null))) {
            throw Settings::wrong($name, "for $path: a tab of two labels [English, German], or null");
        }
        $role = $route['role'] ?? null;
        if (!is_string($role) || !in_array($role, self::ROLES, true)) {
            throw Settings::wrong($name, "for $path: a role, " . implode(' or ', self::ROLES));
        }
        if (!is_int($route['order'] ?? null)) {
            throw Settings::wrong($name, "for $path: an order (an integer, the tabs' order)");
        }
        $ext = $route['ext'] ?? null;
        if ($ext !== null && !is_string($ext)) {
            throw Settings::wrong($name, "for $path: ext null (the core) or an extension's id");
        }
        return ['key' => $route['key'], 'ext' => $ext, 'tab' => $tab === null ? null : [$tab[0], $tab[1]], 'role' => $role, 'order' => $route['order']];
    }

    /**
     * The compiled table: the routes given in the settings array (shape
     * checked already), the core's and the extensions' (id => what its
     * routes() returned, with the id set as ext). Two routes on one path --
     * capitals and a trailing "/" aside -- are a mistake naming both owners.
     * Sorted by order, then path.
     *
     * @param array<string, Entry> $given from the settings array ('routes')
     * @param array<string, array<string, mixed>> $extensions id => path => entry (without ext)
     * @return array<string, Entry>
     */
    public static function compile(array $given, string $dashboardPath, array $extensions): array
    {
        /** @var list<array{0: string, 1: Entry, 2: string}> path, entry, owner */
        $all = [];
        foreach (self::core($dashboardPath) as $path => $entry) {
            $all[] = [$path, $entry, 'the core'];
        }
        foreach ($extensions as $id => $routes) {
            foreach ($routes as $path => $route) {
                if ($path === '' || $path[0] !== '/') {
                    throw Settings::wrong('routes', "a path starting with \"/\" from the extension $id, not \"$path\"");
                }
                $entry = self::entry($route, 'routes', $path);
                $entry['ext'] = $id;
                $all[] = [$path, $entry, "the extension $id"];
            }
        }
        foreach ($given as $path => $entry) {
            $all[] = [$path, $entry, 'the settings (routes)'];
        }
        $owner = [];
        $orders = [];
        $paths = [];
        $entries = [];
        foreach ($all as [$path, $entry, $who]) {
            $norm = self::norm($path);
            if (isset($owner[$norm])) {
                throw Settings::wrong('routes', "one page per path: $path is declared by {$owner[$norm]} and by $who");
            }
            $owner[$norm] = $who;
            $orders[] = $entry['order'];
            $paths[] = $path;
            $entries[] = $entry;
        }
        // By order, then path -- sorted in C, not through a callback per comparison.
        array_multisort($orders, SORT_NUMERIC, $paths, SORT_STRING, $entries);
        /** @var array<string, Entry> */
        return array_combine($paths, $entries);
    }

    /**
     * The route a request path asks for: the path itself, or its end
     * (/demo/index.php/rs/waf/live asks for /rs/waf/live) -- the longest
     * such, so a page below another is itself. For what the dashboard's own
     * requests may skip (the pace), never for who may open them. Null when
     * none; the entry comes with its 'path'.
     *
     * @return array{key: string, ext: ?string, tab: ?array{0: string, 1: string}, role: string, order: int, path: string}|null
     */
    public static function match(Settings $s, string $path): ?array
    {
        $p = self::norm($path);
        $best = null;
        $len = -1;
        foreach ($s->routes as $route => $entry) {
            $r = self::norm($route);
            $n = strlen($r);
            if ($n > $len && ($p === $r || ($n < strlen($p) && substr_compare($p, $r, -$n) === 0))) {
                $best = $entry + ['path' => $route];
                $len = $n;
            }
        }
        return $best;
    }

    /** Which page a path is exactly (its key), or null; capitals and a trailing "/" do not matter. */
    public static function page(Settings $s, string $path): ?string
    {
        $p = self::norm($path);
        foreach ($s->routes as $route => $entry) {
            if ($p === self::norm($route)) {
                return $entry['key'];
            }
        }
        return null;
    }

    /**
     * The pages with a tab, in order: key => $prefix . path (the first
     * path per key).
     *
     * @return array<string, string>
     */
    public static function links(Settings $s, string $prefix = ''): array
    {
        $out = [];
        foreach ($s->routes as $path => $entry) {
            if ($entry['tab'] !== null && !isset($out[$entry['key']])) {
                $out[$entry['key']] = $prefix . $path;
            }
        }
        return $out;
    }

    /**
     * The tabs in order: key => [English, German].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tabs(Settings $s): array
    {
        $out = [];
        foreach ($s->routes as $entry) {
            if ($entry['tab'] !== null && !isset($out[$entry['key']])) {
                $out[$entry['key']] = $entry['tab'];
            }
        }
        return $out;
    }

    /** A path as compared: lower case, no trailing "/". */
    private static function norm(string $path): string
    {
        return strtolower(rtrim($path, '/'));
    }
}
