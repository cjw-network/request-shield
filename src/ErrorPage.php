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
 * The shield's own page for a refusal (proposal 0030): the frame of the check
 * page -- a card, a quiet ring with a :-( or the site's logo, a title and one
 * sentence in the visitor's language, how long to wait after too many
 * requests, the way home. It never says why: no rule, no pattern, no list.
 * Self-contained -- inline CSS and SVG, no script, nothing loaded -- because a
 * visitor refused here is refused for the site's stylesheet too. An API gets
 * the same as JSON.
 */
final class ErrorPage
{
    /** For the built-in page only: it loads nothing and runs nothing, and nobody frames it. */
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'none'";

    /** A face that is the same on every device: drawn, not an emoji. */
    private const FACE = '<g class="fc"><circle cx="60" cy="60" r="27"/><circle class="e" cx="50" cy="54" r="3.2"/><circle class="e" cx="70" cy="54" r="3.2"/><path d="M47 73q13-11 26 0"/></g>';

    private const CSS = ':root{--bg:#f6f7f9;--fg:#222;--m:#5b6470;--trk:#dde1e6}'
        . '@media(prefers-color-scheme:dark){:root{--bg:#16181c;--fg:#e6e6e6;--m:#a0a8b3;--trk:#2b3038}}'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font:16px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--fg)}'
        . 'main{max-width:28rem;padding:2rem;text-align:center}h1{font-size:1.3rem;margin:.8rem 0 .5rem}p{margin:.4rem 0}.ref{font-size:.85rem;color:var(--m)}'
        . '.home{margin-top:2rem;font-size:.9rem}.home a{color:inherit;opacity:.7}'
        . 'svg{display:block;margin:0 auto;color:var(--m)}.t{fill:none;stroke:var(--trk);stroke-width:6}'
        . '.fc circle{fill:none;stroke:var(--m);stroke-width:3.5}.fc .e{fill:var(--m);stroke:none}.fc path{fill:none;stroke:var(--m);stroke-width:3.5;stroke-linecap:round}';

    /**
     * The page.
     *
     * @param array<string, string> $texts in the visitor's language (Texts::all())
     * @param ?string $logo the site's logo for the ring's middle (set challenge-logo); null: the :-(
     */
    public static function render(Decision $d, array $texts, ?string $home = null, ?string $logo = null, ?string $reference = null, ?int $time = null): string
    {
        $t = $texts + Texts::all('en');
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = Texts::status($d->status, $t);
        return '<!doctype html><html lang="' . $e($t['lang'] ?? 'en') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $e($title) . '</title><style>' . self::CSS . '</style></head><body><main>'
            . '<svg viewBox="0 0 120 120" width="120" height="120" aria-hidden="true" focusable="false"><circle class="t" cx="60" cy="60" r="52"/>'
            . ($logo !== null ? '<g class="l">' . $logo . '</g>' : self::FACE) . '</svg>'
            . '<h1>' . $e($title) . '</h1><p>' . $e(self::sentence($d, $t)) . '</p>'
            . ($reference !== null ? '<p class="ref">' . $e(($t['reference'] ?? 'Reference') . ' ' . $reference . ($time !== null ? ' · ' . gmdate('H:i', $time) . ' UTC' : '')) . '</p>' : '')
            . ($home !== null ? '<p class="home"><a href="' . $e($home) . '">' . $e($t['home']) . '</a></p>' : '')
            . '</main></body></html>';
    }

    /**
     * The sentence for a status: what to do, never why; 429 with how long to wait.
     *
     * @param array<string, string> $t
     */
    public static function sentence(Decision $d, array $t): string
    {
        $text = Texts::sentence($d->status, $t);
        return str_replace('%s', (string) max(1, $d->retryAfter), $text);
    }

    /**
     * The same for an API: status, what it is in words, the reference, how long to wait.
     *
     * @return array{status: int, error: string, reference?: string, retryAfter?: int}
     */
    public static function json(Decision $d, ?string $reference = null): array
    {
        $out = ['status' => $d->status, 'error' => str_replace('-', ' ', Texts::key($d->status))];
        if ($reference !== null) {
            $out['reference'] = $reference;
        }
        if ($d->retryAfter > 0) {
            $out['retryAfter'] = $d->retryAfter;
        }
        return $out;
    }
}
