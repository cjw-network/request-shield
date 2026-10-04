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
