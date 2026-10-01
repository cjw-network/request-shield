<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

/**
 * The frame of the dashboard's own pages (live, lists): the same colours and
 * tabs as the statistics, inline CSS, no external file, never indexed.
 */
final class Frame
{
    /** The tabs, in their order: links key => [English, German]. */
    public const TABS = [
        'sites' => ['All websites', 'Alle Websites'], 'all' => ['Dashboard', 'Dashboard'], 'site' => ['Visitors & pages', 'Besucher & Seiten'], 'shield' => ['Protection', 'Schutz'],
        'rules' => ['Rules & setup', 'Regeln & Einrichtung'], 'live' => ['Live', 'Live'], 'lists' => ['Lists', 'Listen'],
    ];

    /**
     * The addresses of the dashboard's pages under dashboard-path: the
     * statistics' four (with the stats plugin) and the core's live and lists.
     *
     * @return array{sites?: string, all: string, site: string, shield: string, rules: string, live: string, lists: string} sites: with stats-hosts
     */
    public static function links(\CjwNetwork\RequestShield\Settings $s, string $prefix = ''): array
    {
        $base = $prefix . $s->dashboardPath;
        return ($s->statsHosts !== [] ? ['sites' => $base . '/sites'] : []) + ['all' => $base . '/dashboard', 'site' => $base . '/stats', 'shield' => $base . '/shield', 'rules' => $base . '/rules', 'live' => $base . '/live', 'lists' => $base . '/lists'];
    }

    /** Which page a path asks for (a key of links()), or null; capitals do not matter. */
    public static function pageFor(\CjwNetwork\RequestShield\Settings $s, string $path): ?string
    {
        $p = strtolower(rtrim($path, '/'));
        foreach (self::links($s) as $key => $link) {
            if ($p === strtolower($link)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * The tabs as links; $current is marked.
     *
     * @param array<string, string> $links key => address
     */
    public static function tabs(array $links, string $current, string $lang): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = '';
        foreach (self::TABS as $key => [$en, $de]) {
            if (isset($links[$key]) && $links[$key] !== '') {
                $h .= '<a class="tab' . ($key === $current ? ' on' : '') . '" href="' . $e($links[$key] . '?lang=' . $lang) . '">' . $e($lang === 'de' ? $de : $en) . '</a>';
            }
        }
        return $h === '' ? '' : '<nav class="tabs">' . $h . '</nav>';
    }

    /** @param array<string, mixed> $o home, homeLabel */
    public static function page(string $title, string $lang, string $body, array $o, string $css = '', string $script = ''): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!doctype html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $e($title) . '</title><style>' . self::CSS . $css . '</style></head><body>'
            . (is_string($o['home'] ?? null) ? '<header><a href="' . $e($o['home']) . '">← ' . $e(is_string($o['homeLabel'] ?? null) ? $o['homeLabel'] : 'Back') . '</a></header>' : '')
            . '<main><h1>' . $e($title) . '</h1>' . $body . '</main>' . ($script !== '' ? '<script>' . $script . '</script>' : '') . '</body></html>';
    }

    private const CSS = <<<'CSS'
:root{--bg:#f4f6f9;--fg:#1d2127;--m:#5b6470;--card:#fff;--line:#e3e7ec;--a:#2f62c9;--through:#1e8a52;--checked:#2f62c9;--throttled:#c07a00;--refused:#c2412d;--watched:#8b5cf6}
@media (prefers-color-scheme:dark){:root{--bg:#121519;--fg:#e7e9ec;--m:#9aa4b0;--card:#1b1f24;--line:#2d333b;--a:#7aa2ff;--through:#4cc38a;--checked:#6f9bff;--throttled:#e0a43c;--refused:#ff7a66;--watched:#a78bfa}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
header{max-width:1240px;margin:0 auto;padding:14px 16px 0}a{color:var(--a)}main{max-width:1240px;margin:0 auto;padding:6px 16px 28px}
h1{font-size:26px;margin:8px 0 4px}h2{font-size:16px;margin:18px 0 8px}.note,.foot{color:var(--m)}.foot{font-size:13px;margin-top:14px}
.tabs{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid var(--line);margin:8px 0 12px}.tab{padding:6px 12px;text-decoration:none;color:var(--m);border-bottom:2px solid transparent;margin-bottom:-1px}
.tab.on{color:var(--fg);border-color:var(--a);font-weight:600}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin:0 0 12px}
input,select,button,textarea{font:inherit;color:var(--fg);background:var(--card);border:1px solid var(--line);border-radius:8px;padding:5px 9px}
button{cursor:pointer}button.primary{background:var(--a);border-color:var(--a);color:#fff}button.danger{color:var(--refused)}
.msg{padding:8px 12px;border-radius:8px;margin:0 0 12px;border:1px solid var(--line);background:var(--card)}.msg.ok{border-color:var(--through)}.msg.bad{border-color:var(--refused);color:var(--refused)}
table{width:100%;border-collapse:collapse;font-size:14px}th{text-align:left;color:var(--m);font-weight:500;font-size:12px;padding:4px 6px;border-bottom:1px solid var(--line)}
td{padding:5px 6px;border-bottom:1px solid var(--line);vertical-align:top}.mono,code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}
.badge{display:inline-block;font-size:12px;padding:0 7px;border-radius:999px;border:1px solid var(--line);white-space:nowrap}
.wrap{overflow-x:auto}
CSS;
}
