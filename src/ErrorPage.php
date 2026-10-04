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

    /** The statuses a page of the site's own may be for; 4xx: every one of them. */
    public const STATUSES = ['400', '403', '404', '405', '414', '429', '431', '4xx'];

    /** The largest page of the site's own: it lives in the compiled settings. */
    public const MAX = 65536;

    /**
     * A page of the site's own (set error-page <status> <file>): read when the
     * rules are compiled, checked -- there, not too large, UTF-8. "{lang}" in
     * the name gives one page per language (pause.{lang}.html: pause.de.html,
     * pause.en.html …); else one page for all ("" ).
     *
     * @return array{status: string, pages: array<string, string>, files: list<string>} the status, language => HTML, the files read
     * @throws \InvalidArgumentException saying what is wrong
     */
    public static function load(string $spec, string $dir): array
    {
        $parts = preg_split('/\s+/', trim($spec)) ?: [];
        if (count($parts) !== 2 || !in_array(strtolower($parts[0]), self::STATUSES, true)) {
            throw new \InvalidArgumentException('error-page <status> <file>: a status the shield refuses with (' . implode(', ', self::STATUSES) . ') and an HTML file');
        }
        [$status, $name] = [strtolower($parts[0]), $parts[1]];
        $path = $name[0] === '/' ? $name : rtrim($dir, '/') . '/' . $name;
        $files = [];
        if (strpos($path, '{lang}') !== false) {
            $re = '#^' . str_replace('\{lang\}', '([a-z]{2,3}(?:-[a-z0-9]{2,8})?)', preg_quote(basename($path), '#')) . '$#';
            // The folder read as it is (no glob: a folder's name may hold [ ] * ?).
            foreach (@scandir(dirname($path)) ?: [] as $f) {
                if (preg_match($re, $f, $m) === 1) {
                    $files[$m[1]] = dirname($path) . '/' . $f;
                }
            }
            ksort($files);
            if ($files === []) {
                throw new \InvalidArgumentException("error-page $status: no file $name for any language");
            }
        } else {
            $files[''] = $path;
        }
        $pages = [];
        foreach ($files as $lang => $f) {
            $html = is_file($f) && is_readable($f) ? @file_get_contents($f, false, null, 0, self::MAX + 1) : false;
            if ($html === false) {
                throw new \InvalidArgumentException("error-page $status: cannot read " . basename($f));
            }
            if (strlen($html) > self::MAX) {
                throw new \InvalidArgumentException("error-page $status: " . basename($f) . ' is larger than 64 KB');
            }
            if (preg_match('//u', $html) !== 1) {
                throw new \InvalidArgumentException("error-page $status: " . basename($f) . ' is not UTF-8');
            }
            $pages[(string) $lang] = $html;
        }
        // The folder too, for {lang}: a language's page added later is noticed like a changed rule file.
        return ['status' => $status, 'pages' => $pages, 'files' => array_merge(array_values($files), strpos($path, '{lang}') !== false ? [dirname($path)] : [])];
    }

    /**
     * The site's own page for a status in a language: its own status's, else
     * 4xx's -- each for the language, else the one for all; null: none (the
     * built-in one answers).
     *
     * @param array<int|string, array<string, string>> $pages status => language => HTML
     */
    public static function own(array $pages, int $status, string $lang): ?string
    {
        foreach ([(string) $status, '4xx'] as $key) {
            $byLang = $pages[$key] ?? [];
            $page = $byLang[$lang] ?? $byLang[explode('-', $lang)[0]] ?? $byLang[''] ?? null;
            if ($page !== null) {
                return $page;
            }
        }
        return null;
    }

    /**
     * The placeholders of a page of the site's own, filled in and escaped:
     * {status} {title} {text} {wait} {home} {lang} {reference}. Anything else
     * in braces stays as it is (CSS and scripts keep theirs).
     *
     * @param array<string, string> $texts
     */
    public static function fill(string $html, Decision $d, array $texts, ?string $home, ?string $reference): string
    {
        $t = $texts + Texts::all('en');
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return strtr($html, [
            '{status}' => (string) $d->status, '{title}' => $e(Texts::status($d->status, $t)), '{text}' => $e(self::sentence($d, $t)),
            '{wait}' => (string) max(0, $d->retryAfter), '{home}' => $e($home ?? '/'), '{lang}' => $e($t['lang'] ?? 'en'), '{reference}' => $e($reference ?? ''),
        ]);
    }

    /** Letters and digits that cannot be mistaken for each other when read aloud or typed (no 0/O, 1/I/L, U). */
    private const REFERENCE = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * A reference for one refusal (0030): eight random characters, "7KQ2-M4XD"
     * -- on the page, in the JSON and in the log line (ref=…), so "I got an
     * error at 14:31" becomes one line in the log. It means nothing without the log.
     */
    public static function reference(): string
    {
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= self::REFERENCE[random_int(0, strlen(self::REFERENCE) - 1)] . ($i === 3 ? '-' : '');
        }
        return $out;
    }
}
