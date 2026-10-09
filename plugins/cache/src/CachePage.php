<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Cache;

use CjwNetwork\RequestShield\Capability;
use CjwNetwork\RequestShield\Challenge\Secret;
use CjwNetwork\RequestShield\Frame;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Response;
use CjwNetwork\RequestShield\RoutePage;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Texts;

/**
 * The HTTP cache's page in the dashboard (proposal 0047's area "Cache";
 * RSF04-03): what it holds against its caps -- the answers in memory
 * (APCu, an upper bound: MemoryCache's hour counters) and on the disk -- its
 * settings, the way to the response times by hit and miss (the statistics),
 * and purges: by tag, below a path, everything. A purge is a POST with the
 * page's token (an HMAC of the viewer's address and the hour, as the lists'
 * page has it); only the administrator sees the page.
 */
final class CachePage implements RoutePage
{
    private const T = [
        'en' => ['title' => 'HTTP cache', 'intro' => 'The pages the shield keeps and gives out before the application starts -- what they take against the caps, and purging them.',
            'off' => 'off', 'on' => 'on', 'settings' => 'Settings', 'kept' => 'kept for %s unless the answer says otherwise', 'hosts' => 'for %s',
            'memory' => 'Memory (APCu)', 'memNone' => 'No answers in memory: %s.', 'memNoApcu' => 'APCu is not here', 'memOff' => 'http-cache-memory-object 0',
            'memUsed' => 'at most %s of %s (http-cache-memory) -- answers up to %s each; APCu has %s of %s free',
            'disk' => 'Disk', 'diskUsed' => '%s answers, %s of %s (http-cache-disk)', 'diskNoCap' => '%s answers, %s -- no cap (http-cache-disk 0)',
            'daily' => 'Per day', 'dailyOff' => 'With the statistics and their part times (set stats requests pages times) a bar per day shows hits and misses here.',
            'k.hit' => 'from the cache', 'k.miss' => 'asked the site, kept', 'k.nostore' => 'asked the site, not to be kept', 'k.past' => 'not for the cache',
            'rate' => '%s %% hits', 'asTable' => 'As a table', 'day' => 'Day', 'none' => 'Nothing counted in these days yet.',
            'times' => 'Hits and misses', 'timesLink' => 'The response times by hit and miss are in the statistics', 'timesOff' => 'With the statistics and their part times (set stats requests pages times) the dashboard shows the response times by hit and miss.',
            'purge' => 'Purge', 'tags' => 'Tags', 'tagsHint' => 'c52 l2 article-3 -- what the pages carry (xkey, X-Cache-Tags …)', 'path' => 'Below a path', 'pathHint' => '/news/ -- every host',
            'all' => 'Everything', 'purgeTags' => 'Purge the tags', 'purgePath' => 'Remove below the path', 'purgeAll' => 'Empty the cache',
            'doneTags' => 'Purged: %s -- their pages are made again on the next request.', 'donePath' => 'Removed %s answers below %s.', 'doneAll' => 'Emptied: %s answers removed, every page in memory out of date.',
            'bad' => 'The form is out of date (or came from elsewhere) -- nothing purged. Load the page and try again.', 'noTags' => 'No tag to purge.', 'noPath' => 'A path starts with /.',
            'failed' => 'The purge could not be written (the cache\'s folder) -- nothing changed.'],
        'de' => ['title' => 'HTTP-Cache', 'intro' => 'Die Seiten, die der Shield hält und ausgibt, bevor die Anwendung startet -- was sie gegen die Grenzen belegen, und wie man sie verwirft.',
            'off' => 'aus', 'on' => 'an', 'settings' => 'Einstellungen', 'kept' => 'gehalten für %s, wenn die Antwort nichts anderes sagt', 'hosts' => 'für %s',
            'memory' => 'Speicher (APCu)', 'memNone' => 'Keine Antworten im Speicher: %s.', 'memNoApcu' => 'APCu ist nicht da', 'memOff' => 'http-cache-memory-object 0',
            'memUsed' => 'höchstens %s von %s (http-cache-memory) -- Antworten bis %s; APCu hat %s von %s frei',
            'disk' => 'Platte', 'diskUsed' => '%s Antworten, %s von %s (http-cache-disk)', 'diskNoCap' => '%s Antworten, %s -- ohne Grenze (http-cache-disk 0)',
            'daily' => 'Pro Tag', 'dailyOff' => 'Mit der Statistik und ihrem Teil times (set stats requests pages times) zeigt hier ein Balken pro Tag Treffer und Miss.',
            'k.hit' => 'aus dem Cache', 'k.miss' => 'Website gefragt, gespeichert', 'k.nostore' => 'Website gefragt, nicht speicherbar', 'k.past' => 'nicht für den Cache',
            'rate' => '%s %% Treffer', 'asTable' => 'Als Tabelle', 'day' => 'Tag', 'none' => 'In diesen Tagen ist noch nichts gezählt.',
            'times' => 'Treffer und Miss', 'timesLink' => 'Die Antwortzeiten nach Treffer und Miss stehen in der Statistik', 'timesOff' => 'Mit der Statistik und ihrem Teil times (set stats requests pages times) zeigt das Dashboard die Antwortzeiten nach Treffer und Miss.',
            'purge' => 'Verwerfen', 'tags' => 'Tags', 'tagsHint' => 'c52 l2 article-3 -- was die Seiten tragen (xkey, X-Cache-Tags …)', 'path' => 'Unter einem Pfad', 'pathHint' => '/news/ -- jeder Host',
            'all' => 'Alles', 'purgeTags' => 'Tags verwerfen', 'purgePath' => 'Unter dem Pfad entfernen', 'purgeAll' => 'Cache leeren',
            'doneTags' => 'Verworfen: %s -- ihre Seiten werden beim nächsten Aufruf neu gebaut.', 'donePath' => '%s Antworten unter %s entfernt.', 'doneAll' => 'Geleert: %s Antworten entfernt, jede Seite im Speicher veraltet.',
            'bad' => 'Das Formular ist veraltet (oder kam von woanders) -- nichts verworfen. Seite neu laden und noch einmal.', 'noTags' => 'Kein Tag zum Verwerfen.', 'noPath' => 'Ein Pfad beginnt mit /.',
            'failed' => 'Das Verwerfen konnte nicht geschrieben werden (der Ordner des Caches) -- nichts geändert.'],
    ];

    public static function serve(Settings $s, Request $request, array $route, array $ctx): Response
    {
        $links = $ctx['links'];
        $lang = Texts::language($ctx['lang'], $ctx['accept']) === 'de' ? 'de' : 'en';
        $message = $ctx['method'] === 'POST' ? self::handle($s, $ctx['post'], $ctx['ip'], $lang) : null;
        return Response::html(200, self::render($s, ['action' => $links['cache'] ?? $ctx['prefix'] . $route['path'], 'message' => $message, 'links' => $links, 'lang' => $lang,
            'ip' => $ctx['ip'], 'home' => $ctx['home'], 'homeLabel' => $ctx['homeLabel'], 'title' => self::T[$lang]['title'] . ' — ' . $ctx['homeLabel']]));
    }

    /** The form's token: an HMAC of the viewer's address and the hour (the last hour's still counts). */
    public static function token(Settings $s, string $ip, ?int $now = null): string
    {
        return self::sign($s, $ip, intdiv($now ?? time(), 3600));
    }

    private static function sign(Settings $s, string $ip, int $hour): string
    {
        return substr(hash_hmac('sha256', "cache|$ip|$hour", Secret::resolve($s->challenge->secret, $s->storeDir)), 0, 32);
    }

    /**
     * A purge the page's forms sent: do=tags (tags), do=path (path), do=all.
     *
     * @param array<mixed> $post
     * @return array{ok: bool, message: string}
     */
    public static function handle(Settings $s, array $post, string $ip, string $lang, ?int $now = null): array
    {
        $t = self::T[$lang];
        $hour = intdiv($now ?? time(), 3600);
        $token = is_string($post['token'] ?? null) ? $post['token'] : '';
        if ($token === '' || !(hash_equals(self::sign($s, $ip, $hour), $token) || hash_equals(self::sign($s, $ip, $hour - 1), $token))) {
            return ['ok' => false, 'message' => $t['bad']];
        }
        $o = CacheExtension::of($s);
        $nowF = microtime(true);
        $do = is_string($post['do'] ?? null) ? $post['do'] : '';
        if ($do === 'tags') {
            $tags = Tags::split(is_string($post['tags'] ?? null) ? $post['tags'] : '');
            if ($tags === []) {
                return ['ok' => false, 'message' => $t['noTags']];
            }
            return (new Tags($o['dir'], Capability::apcu()))->purge($tags, $nowF)
                ? ['ok' => true, 'message' => sprintf($t['doneTags'], implode(' ', $tags))] : ['ok' => false, 'message' => $t['failed']];
        }
        if ($do === 'path') {
            $path = trim(is_string($post['path'] ?? null) ? $post['path'] : '');
            if ($path === '' || $path[0] !== '/') {
                return ['ok' => false, 'message' => $t['noPath']];
            }
            $removed = (new FileCache($o['dir']))->purge($path);
            return MemoryCache::forget($o['dir'], Capability::apcu(), $nowF)
                ? ['ok' => true, 'message' => sprintf($t['donePath'], (string) $removed, $path)] : ['ok' => false, 'message' => $t['failed']];
        }
        if ($do === 'all') {
            $removed = (new FileCache($o['dir']))->purge();
            return MemoryCache::forget($o['dir'], Capability::apcu(), $nowF)
                ? ['ok' => true, 'message' => sprintf($t['doneAll'], (string) $removed)] : ['ok' => false, 'message' => $t['failed']];
        }
        return ['ok' => false, 'message' => $t['bad']];
    }

    /**
     * The page.
     *
     * @param array<string, mixed> $o action, message (handle()'s answer), links, lang, ip, home, homeLabel, title, now
     */
    public static function render(Settings $s, array $o): string
    {
        $lang = ($o['lang'] ?? 'en') === 'de' ? 'de' : 'en';
        $t = self::T[$lang];
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mb = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, $lang === 'de' ? ',' : '.', '') . ' MB' : number_format($b / 1024, 1, $lang === 'de' ? ',' : '.', '') . ' KB';
        $c = CacheExtension::of($s);
        $action = is_string($o['action'] ?? null) ? $o['action'] : '';
        /** @var array<string, string> $links */
        $links = array_filter((array) ($o['links'] ?? []), 'is_string');
        $token = self::token($s, is_string($o['ip'] ?? null) ? $o['ip'] : '', is_int($o['now'] ?? null) ? $o['now'] : null);
        $form = static fn (string $do, string $fields, string $button, string $class = ''): string => '<form method="post" action="' . $e($action) . '" class="purge">'
            . '<input type="hidden" name="token" value="' . $e($token) . '"><input type="hidden" name="do" value="' . $do . '">' . $fields
            . '<button' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $e($button) . '</button></form>';
        $o['help'] = Help::link('RSF04-03', '', $s->docsUrl, $lang);

        $h = Frame::tabs($s, $links, 'cache', $lang) . '<p class="note">' . $e($t['intro']) . '</p>';
        $msg = is_array($o['message'] ?? null) ? $o['message'] : null;
        if ($msg !== null && is_string($msg['message'] ?? null)) {
            $h .= '<div class="msg ' . (($msg['ok'] ?? false) ? 'ok' : 'bad') . '" role="status">' . $e($msg['message']) . '</div>';
        }
        // What it holds against its caps.
        $h .= '<div class="grid">';
        $h .= '<div class="card">' . Frame::h2($t['memory'], $s, 'RSF04-03', 'memory-first-the-disk-when-needed', $lang);
        if (!Capability::apcu() || $c['memoryObject'] <= 0 || $c['memory'] <= 0) {
            $h .= '<p class="note">' . $e(sprintf($t['memNone'], !Capability::apcu() ? $t['memNoApcu'] : $t['memOff'])) . '</p>';
        } else {
            $used = (new MemoryCache($c['dir'], $c['memoryObject'], $c['memory']))->bytes(microtime(true));
            $sma = apcu_sma_info(true);
            $num = static fn (string $k): int => is_array($sma) && (is_int($sma[$k] ?? null) || is_float($sma[$k] ?? null)) ? (int) $sma[$k] : 0;
            $total = $num('num_seg') * $num('seg_size');
            $h .= self::bar($used, $c['memory']) . '<p>' . $e(sprintf($t['memUsed'], $mb($used), $mb($c['memory']), $mb($c['memoryObject']), $mb($num('avail_mem')), $mb($total))) . '</p>';
        }
        $h .= '</div><div class="card">' . Frame::h2($t['disk'], $s, 'RSF04-03', 'memory-first-the-disk-when-needed', $lang);
        $st = (new FileCache($c['dir']))->stats();
        $h .= ($c['disk'] > 0 ? self::bar($st['bytes'], $c['disk']) . '<p>' . $e(sprintf($t['diskUsed'], (string) $st['entries'], $mb($st['bytes']), $mb($c['disk']))) . '</p>'
            : '<p>' . $e(sprintf($t['diskNoCap'], (string) $st['entries'], $mb($st['bytes']))) . '</p>') . '</div></div>';
        // Per day: what the cache did, from the statistics' counts (0046's times).
        $h .= '<div class="card">' . Frame::h2($t['daily'], $s, 'RSF06-03', 'how-fast-the-site-answered', $lang);
        $daily = self::daily($s, 14, is_int($o['now'] ?? null) ? $o['now'] : null);
        $h .= $daily === null ? '<p class="note">' . $e($t['dailyOff']) . '</p>' : self::chart($daily, $t, $lang) . '</div>';
        if ($daily === null) {
            $h .= '</div>';
        }
        // The times: the statistics' (0046).
        $h .= '<div class="card">' . Frame::h2($t['times'], $s, 'RSF06-03', 'how-fast-the-site-answered', $lang)
            . (isset($links['all']) ? '<p><a href="' . $e($links['all'] . '?lang=' . $lang) . '">' . $e($t['timesLink']) . ' →</a></p>' : '<p class="note">' . $e($t['timesOff']) . '</p>') . '</div>';
        // Purges.
        $h .= '<div class="card">' . Frame::h2($t['purge'], $s, 'RSF04-03', 'tags-and-purges-what-the-cms-already-sends', $lang)
            . $form('tags', '<label>' . $e($t['tags']) . ' <input name="tags" size="30" placeholder="' . $e($t['tagsHint']) . '" aria-label="' . $e($t['tags']) . '"></label> ', $t['purgeTags'])
            . $form('path', '<label>' . $e($t['path']) . ' <input name="path" size="30" placeholder="' . $e($t['pathHint']) . '" aria-label="' . $e($t['path']) . '"></label> ', $t['purgePath'])
            . $form('all', '<span>' . $e($t['all']) . '</span> ', $t['purgeAll'], 'danger') . '</div>';
        // The settings it runs with.
        $h .= '<div class="card">' . Frame::h2($t['settings'], $s, 'RSF04-03', 'configuration', $lang) . '<p><span class="badge">' . $e($c['enabled'] ? $t['on'] : $t['off']) . '</span> '
            . $e(sprintf($t['kept'], $c['ttl'] . ' s')) . ($c['hosts'] !== [] ? ' · ' . $e(sprintf($t['hosts'], implode(' ', $c['hosts']))) : '') . '</p></div>';
        $title = is_string($o['title'] ?? null) ? $o['title'] : $t['title'];
        return Frame::page($title, $lang, $h, $o, self::CSS);
    }

    /** The cache kinds, bottom to top of a day's bar (the statistics' words). */
    public const KINDS = ['hit', 'miss', 'nostore', 'past'];

    /**
     * What the cache did per day over the last $days days -- the statistics'
     * counts of answers by cache kind (rt: … with the part times), the
     * running hour included; null without the statistics or their times.
     *
     * @return array<string, array{hit: int, miss: int, nostore: int, past: int}>|null day (yyyymmdd) => counts
     */
    public static function daily(Settings $s, int $days = 14, ?int $now = null): ?array
    {
        if (!class_exists(\CjwNetwork\RequestShield\Stats\Report\StatsReport::class) || !class_exists(\CjwNetwork\RequestShield\Stats\StatsExtension::class)) {
            return null;
        }
        $st = \CjwNetwork\RequestShield\Stats\StatsExtension::of($s);
        if (!$st['enabled'] || !in_array('times', $st['parts'], true)) {
            return null;
        }
        $now ??= time();
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[gmdate('Ymd', $now - $i * 86400)] = ['hit' => 0, 'miss' => 0, 'nostore' => 0, 'past' => 0];
        }
        $read = \CjwNetwork\RequestShield\Stats\Report\StatsReport::read($s, null, (string) array_key_first($out), gmdate('Ymd', $now));
        foreach ($read['days'] as $day => $counts) {
            if (!isset($out[$day])) {
                continue;
            }
            foreach ($counts as $key => $n) {
                if (strncmp((string) $key, 'rt:', 3) === 0) {
                    $kind = explode('|', (string) $key)[1] ?? '';
                    if (isset($out[$day][$kind])) {
                        $out[$day][$kind] += $n;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The days as stacked bars (one per day, the kinds bottom to top in a
     * fixed order, 2 px apart), each with its share of hits above it, a
     * legend, a tooltip on every part and the numbers as a table.
     *
     * @param array<string, array{hit: int, miss: int, nostore: int, past: int}> $daily
     * @param array<string, string> $t
     */
    private static function chart(array $daily, array $t, string $lang): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = static fn (int $v): string => number_format($v, 0, ',', $lang === 'de' ? '.' : ',');
        $totals = array_map('array_sum', $daily);
        if ($totals === [] || array_sum($totals) === 0) {
            return '<p class="note">' . $e($t['none']) . '</p>';
        }
        $max = max(1, ...array_values($totals));
        $w = 700;
        $top = 22;
        $plot = 150;
        $slot = $w / count($daily);
        $bar = min(34.0, $slot * 0.62);
        $svg = '';
        $i = 0;
        foreach ($daily as $day => $c) {
            $x = $i * $slot + ($slot - $bar) / 2;
            $y = $top + $plot;
            $label = date($lang === 'de' ? 'd.m.' : 'M j', (int) strtotime($day . ' UTC'));
            foreach (self::KINDS as $k) {
                if ($c[$k] <= 0) {
                    continue;
                }
                $height = max(1.5, $plot * $c[$k] / $max);
                $y -= $height;
                $svg .= '<rect class="k-' . $k . '" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bar, 1) . '" height="' . round(max(0.5, $height - 2), 1) . '" rx="3">'
                    . '<title>' . $e($label . ' · ' . $t['k.' . $k] . ': ' . $n($c[$k])) . '</title></rect>';
            }
            $asked = $c['hit'] + $c['miss'];
            if ($asked > 0) {
                $svg .= '<text class="rate" x="' . round($x + $bar / 2, 1) . '" y="' . round($y - 5, 1) . '" text-anchor="middle">' . (int) round(100 * $c['hit'] / $asked) . '%</text>';
            }
            if (count($daily) <= 14 || $i % 2 === 0) {
                $svg .= '<text class="day" x="' . round($x + $bar / 2, 1) . '" y="' . ($top + $plot + 16) . '" text-anchor="middle">' . $e($label) . '</text>';
            }
            $i++;
        }
        $legend = '';
        foreach (self::KINDS as $k) {
            $legend .= '<span class="key"><i class="k-' . $k . '"></i>' . $e($t['k.' . $k]) . '</span>';
        }
        $rows = '';
        foreach ($daily as $day => $c) {
            $asked = $c['hit'] + $c['miss'];
            $rows .= '<tr><td>' . $e(date($lang === 'de' ? 'd.m.Y' : 'Y-m-d', (int) strtotime($day . ' UTC'))) . '</td>';
            foreach (self::KINDS as $k) {
                $rows .= '<td class="num">' . $e($n($c[$k])) . '</td>';
            }
            $rows .= '<td class="num">' . ($asked > 0 ? $e(sprintf($t['rate'], (string) (int) round(100 * $c['hit'] / $asked))) : '–') . '</td></tr>';
        }
        return '<div class="legend">' . $legend . '</div>'
            . '<svg class="days" viewBox="0 0 ' . $w . ' ' . ($top + $plot + 24) . '" role="img" aria-label="' . $e($t['daily']) . '">'
            . '<line class="base" x1="0" x2="' . $w . '" y1="' . ($top + $plot) . '" y2="' . ($top + $plot) . '"/>' . $svg . '</svg>'
            . '<details><summary>' . $e($t['asTable']) . '</summary><div class="wrap"><table><thead><tr><th>' . $e($t['day']) . '</th>'
            . implode('', array_map(static fn (string $k): string => '<th class="num">' . $e($t['k.' . $k]) . '</th>', self::KINDS)) . '<th class="num"></th></tr></thead><tbody>' . $rows . '</tbody></table></div></details>';
    }

    /** A bar: how much of the cap is taken (0 to 100 %). */
    private static function bar(int $used, int $cap): string
    {
        $p = $cap > 0 ? max(0, min(100, (int) round(100 * $used / $cap))) : 0;
        return '<div class="bar" role="img" aria-label="' . $p . ' %"><span style="width:' . $p . '%"></span></div>';
    }

    private const CSS = '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.grid .card{margin:0}'
        . '.bar{height:10px;border-radius:999px;background:var(--line);overflow:hidden;margin:6px 0}.bar span{display:block;height:100%;background:var(--a)}'
        . 'form.purge{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 8px}'
        // The days' bars: four kinds, fixed order and colours (validated for colour blindness, light and dark).
        . ':root{--k-hit:#1e8a52;--k-miss:#2f62c9;--k-nostore:#c07a00;--k-past:#8b5cf6}'
        . '@media (prefers-color-scheme:dark){:root{--k-hit:#36a873;--k-miss:#5a86e6;--k-nostore:#b98228;--k-past:#9277e6}}'
        . 'svg.days{width:100%;height:auto;display:block;margin:4px 0}svg.days .base{stroke:var(--line);stroke-width:1}'
        . 'svg.days .day,svg.days .rate{fill:var(--m);font-size:11px}svg.days rect:hover{opacity:.8}'
        . '.k-hit{fill:var(--k-hit);background:var(--k-hit)}.k-miss{fill:var(--k-miss);background:var(--k-miss)}.k-nostore{fill:var(--k-nostore);background:var(--k-nostore)}.k-past{fill:var(--k-past);background:var(--k-past)}'
        . '.legend{display:flex;flex-wrap:wrap;gap:14px;font-size:13px;color:var(--m)}.legend .key i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:6px;vertical-align:-1px}'
        . 'details summary{cursor:pointer;color:var(--a);font-size:13px}td.num,th.num{text-align:right}';
}
