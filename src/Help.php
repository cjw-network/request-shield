<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Rules\Vocabulary;

/**
 * The pages explain themselves (0031 F.9): a feature's sentence and a "?"
 * link to its page in the docs, by its id, from Vocabulary::TOPICS. The docs
 * live where `set docs-url` says (the repository's by default; "off": no
 * links, the sentences stay). Only the pages and the command line call it:
 * never a request.
 */
final class Help
{
    /** How a "?" link and a sentence look on the pages (with their colours: --m, --a, --line). */
    public const CSS = '.rs-help{display:inline-block;min-width:1.35em;height:1.35em;line-height:1.3em;margin-left:.3em;border:1px solid var(--line,#ccc);border-radius:999px;'
        . 'font-size:12px;font-weight:600;text-align:center;text-decoration:none;color:var(--m,#5b6470);vertical-align:middle}.rs-help:hover,.rs-help:focus{color:var(--a,#2f62c9);border-color:var(--a,#2f62c9)}'
        . '.rs-about{color:var(--m,#5b6470);margin:2px 0 12px}';

    /** The docs when the rules say nothing: the repository's, as published. */
    public const DOCS = 'https://github.com/cjw-network/request-shield/blob/main/docs';

    /** The address of a feature's page, at an anchor; null for an unknown id or with the links off (""). */
    public static function url(string $id, string $anchor = '', string $docs = self::DOCS): ?string
    {
        $topic = Vocabulary::TOPICS[$id] ?? null;
        if ($topic === null || $docs === '') {
            return null;
        }
        return rtrim($docs, '/') . '/features/' . $id . '-' . $topic[0] . '.md' . ($anchor !== '' ? '#' . $anchor : '');
    }

    /** The browser check in plain words, for visitors (the check page, the box in a form); null with the links off. */
    public static function explained(string $docs = self::DOCS): ?string
    {
        return $docs === '' ? null : rtrim($docs, '/') . '/explained/browser-check.md';
    }

    /** What a feature does, in one sentence; "" for an unknown id. */
    public static function sentence(string $id, string $lang = 'en'): string
    {
        $topic = Vocabulary::TOPICS[$id] ?? null;
        return $topic === null ? '' : ($lang === 'de' ? $topic[3] : $topic[2]);
    }

    /** A feature's short title; the id itself for an unknown one. */
    public static function title(string $id): string
    {
        return Vocabulary::TOPICS[$id][1] ?? $id;
    }

    /** The "?" link to a feature's page (HTML, escaped); "" without one. */
    public static function link(string $id, string $anchor = '', string $docs = self::DOCS, string $lang = 'en'): string
    {
        $url = self::url($id, $anchor, $docs);
        if ($url === null) {
            return '';
        }
        $label = $id . ' ' . self::title($id) . ($lang === 'de' ? ': in der Dokumentation' : ': in the docs');
        return '<a class="rs-help" href="' . self::e($url) . '" target="_blank" rel="noopener" title="' . self::e($label)
            . '" aria-label="' . self::e($label) . '">?</a>';
    }

    /** The sentence and its link, as a paragraph (HTML, escaped); $sentence replaces the feature's own. */
    public static function about(string $id, string $anchor = '', string $docs = self::DOCS, string $lang = 'en', ?string $sentence = null): string
    {
        $text = $sentence ?? self::sentence($id, $lang);
        $link = self::link($id, $anchor, $docs, $lang);
        return '<p class="rs-about">' . self::e($text) . ($link !== '' ? ' ' . $link : '') . '</p>';
    }

    /**
     * The feature a line of a rule file is about: its word (an [ID] and
     * "monitor" before it skipped), for `set` its key; null when none is known.
     */
    public static function featureOfLine(string $line): ?string
    {
        $words = preg_split('/\s+/', trim((string) preg_replace('/^\s*\[[^\]]*\]/', '', $line))) ?: [];
        if (($words[0] ?? '') === 'monitor') {
            array_shift($words);
        }
        $word = (string) ($words[0] ?? '');
        if ($word === 'set' && isset($words[1])) {
            $key = (string) preg_replace('/^text\..*/', 'set', $words[1]);
            return Vocabulary::featureOf($key) ?? 'RSF05-02';
        }
        return $word === '' ? null : Vocabulary::featureOf($word);
    }

    /**
     * Where a mistake is explained: a rule file's "<file>:<line>: …" by the
     * feature of that line's word (rule files when none), a setting's "'<key>' must be …" by the
     * settings page. Null when neither. A file named without its folder is
     * looked for in $dirs (the folders of the rule files the command named).
     *
     * @param list<string> $dirs
     */
    public static function forError(string $message, string $docs = self::DOCS, array $dirs = []): ?string
    {
        $file = null;
        $line = 0;
        if (preg_match('/^(?:request-shield: )?(\S+?):(\d+): /', $message, $m) === 1) {
            $line = (int) $m[2];
            foreach (array_merge([$m[1]], array_map(static fn (string $d): string => rtrim($d, '/') . '/' . $m[1], $dirs)) as $try) {
                if (is_file($try) && is_readable($try)) {
                    $file = $try;
                    break;
                }
            }
        }
        if ($file !== null) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            // A word no feature knows (a typing mistake, an order): how rule files are written.
            $id = is_array($lines) ? self::featureOfLine((string) ($lines[$line - 1] ?? '')) : null;
            return self::see($id ?? 'RSF05-01', '', $docs);
        }
        if (preg_match("/'[A-Za-z.]+' must be /", $message) === 1) {
            return self::see('RSF05-02', '', $docs);
        }
        return null;
    }

    /** For the command line: "see <url>", or the id and title with the links off. */
    public static function see(string $id, string $anchor = '', string $docs = self::DOCS): string
    {
        return 'see ' . (self::url($id, $anchor, $docs) ?? $id . ' ' . self::title($id) . ' in the docs');
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
