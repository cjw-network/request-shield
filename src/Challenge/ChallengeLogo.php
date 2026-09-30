<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Challenge;

/**
 * The picture in the middle of the check page's ring: a plain shield, or the
 * site's own logo (set challenge-logo logo.svg). A site's logo is read once,
 * when the settings are compiled, checked strictly -- an SVG is a document
 * that can carry scripts -- and inlined: no extra request, nothing per page.
 *
 * Refused: more than 16 KB, not well-formed XML, a DOCTYPE, a root other than
 * <svg>, <script>, <foreignObject>, <iframe>, <object>, <embed>, <style>
 * (it would style the whole page), on…= attributes, links other than local
 * "#…" fragments, "javascript:" anywhere in a value, url() other than
 * url(#…) and @import in a style, animations that change links or handlers.
 * IDs get a prefix, so the logo cannot take over the page's own.
 */
final class ChallengeLogo
{
    public const MAX_BYTES = 16384;

    /** Where the logo sits in the ring's 120 x 120 picture. */
    private const BOX = 'x="32" y="32" width="56" height="56"';

    /** A plain shield in the page's accent colour (currentColor): nobody's logo. */
    public const DEFAULT = '<svg ' . self::BOX . ' viewBox="0 0 24 26" class="d"><path d="M12 1.5 21.5 5.2V12c0 6.1-4 10.8-9.5 12.5C6.5 22.8 2.5 18.1 2.5 12V5.2Z" fill="currentColor" fill-opacity=".12" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M12 6.5 17 8.4V12c0 3.3-2 6-5 7.2-3-1.2-5-3.9-5-7.2V8.4Z" fill="currentColor" fill-opacity=".35"/></svg>';

    /**
     * The logo in $file, checked, as markup for the ring's middle.
     *
     * @throws \InvalidArgumentException naming $setting, with the reason
     */
    public static function load(string $file, string $setting): string
    {
        $size = @filesize($file);
        $svg = $size === false ? false : @file_get_contents($file);
        if ($svg === false) {
            throw new \InvalidArgumentException("$setting: cannot read the logo $file");
        }
        return self::check($svg, $setting);
    }

    /** @throws \InvalidArgumentException */
    public static function check(string $svg, string $setting): string
    {
        $no = static function (string $why) use ($setting): \InvalidArgumentException {
            return new \InvalidArgumentException("$setting: the logo is refused -- $why (a site's own logo must be an SVG it trusts, without scripts or outside links)");
        };
        if (strlen($svg) > self::MAX_BYTES) {
            throw $no('larger than 16 KB (' . strlen($svg) . ' bytes); simplify it, e.g. with svgo');
        }
        if (stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<!ENTITY') !== false) {
            throw $no('it has a DOCTYPE or entities');
        }
        if (!class_exists(\DOMDocument::class)) {
            throw $no('PHP has no DOM extension here to check it');
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $doc->documentElement;
        if (!$ok || $root === null) {
            throw $no('it is not well-formed XML');
        }
        if (strtolower((string) $root->localName) !== 'svg') {
            throw $no("its root is <{$root->localName}>, not <svg>");
        }
        $ids = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            $name = strtolower((string) $el->localName);
            if (in_array($name, ['script', 'foreignobject', 'iframe', 'object', 'embed', 'style', 'handler', 'listener'], true)) {
                throw $no("it has a <{$el->localName}> element" . ($name === 'style' ? ' (it would style the whole page; use attributes, e.g. svgo --enable=inlineStyles)' : ''));
            }
            foreach (iterator_to_array($el->attributes) as $attr) {
                /** @var \DOMAttr $attr */
                $a = strtolower((string) $attr->localName);
                $v = (string) $attr->value;
                $flat = strtolower((string) preg_replace('/[\s\x00-\x1f]+/', '', html_entity_decode($v, ENT_QUOTES | ENT_HTML5)));
                if (strncmp($a, 'on', 2) === 0) {
                    throw $no("it has an event handler ({$attr->nodeName}=…)");
                }
                if (($a === 'href' || $a === 'src') && strncmp($v, '#', 1) !== 0) {
                    throw $no("it links outside itself ({$attr->nodeName}=\"" . substr($v, 0, 40) . '")');
                }
                if (strpos($flat, 'javascript:') !== false || strpos($flat, 'vbscript:') !== false) {
                    throw $no("a value holds a script address ({$attr->nodeName})");
                }
                if ($a === 'style' && (preg_match('/url\((?!#)|url\(\s*[\'"](?!#)/', $flat) === 1 || strpos($flat, '@import') !== false || strpos($flat, 'expression(') !== false)) {
                    throw $no('a style loads something from outside (url(), @import)');
                }
                if (in_array($a, ['fill', 'stroke', 'filter', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end'], true) && preg_match('/url\((?!#)/', $flat) === 1) {
                    throw $no("{$attr->nodeName} points outside (url())");
                }
                if ($a === 'attributename' && ($flat === 'href' || $flat === 'xlink:href' || strncmp($flat, 'on', 2) === 0)) {
                    throw $no('an animation changes a link or a handler');
                }
                if ($a === 'id') {
                    $ids[$v] = true;
                }
            }
        }
        // Where it goes and how large: its own viewBox, the ring's box.
        $viewBox = $root->getAttribute('viewBox');
        if ($viewBox === '') {
            $w = (float) $root->getAttribute('width');
            $h = (float) $root->getAttribute('height');
            if ($w <= 0 || $h <= 0) {
                throw $no('it has neither a viewBox nor a width and height');
            }
            $viewBox = "0 0 $w $h";
        }
        foreach (['x', 'y', 'width', 'height', 'viewBox', 'class', 'style'] as $a) {
            $root->removeAttribute($a);
        }
        // No comments or processing instructions in the page: nothing but the picture.
        $extra = (new \DOMXPath($doc))->query('//comment()|//processing-instruction()');
        foreach ($extra === false ? [] : iterator_to_array($extra) as $node) {
            if ($node instanceof \DOMNode && $node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
        // IDs prefixed (and their references), so the logo keeps its own.
        foreach ($doc->getElementsByTagName('*') as $el) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                /** @var \DOMAttr $attr */
                $v = (string) $attr->value;
                if (strtolower((string) $attr->localName) === 'id') {
                    $attr->value = 'rsl-' . $v;
                } elseif ($ids !== [] && (strncmp($v, '#', 1) === 0 || strpos($v, 'url(#') !== false)) {
                    $attr->value = (string) preg_replace_callback('/(^#|url\(#)([^)\s]+)/', static fn (array $m): string => isset($ids[$m[2]]) ? $m[1] . 'rsl-' . $m[2] : $m[0], $v);
                }
            }
        }
        $markup = (string) $doc->saveXML($root);
        return (string) preg_replace('/^<svg\b/', '<svg ' . self::BOX . ' viewBox="' . htmlspecialchars($viewBox, ENT_QUOTES) . '"', $markup, 1);
    }
}
