<?php

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Tests;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Pages;
use CjwNetwork\RequestShield\Plugin;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;

/**
 * A plugin with the Pages capability, for the tests (0031 B.10): every page in
 * one recognisable line (its kind and status in the title), the kinds it
 * leaves to the core listed in `set rs-test-mark core:<kind>`; with `set fail-at
 * pages` it throws -- the core's page must go out all the same.
 */
final class PagesPlugin implements Plugin, Pages
{
    public function __construct(private Settings $settings)
    {
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
    }

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
    }

    public function page(string $kind, array $ctx): ?string
    {
        $test = is_array($this->settings->ext['rs-test'] ?? null) ? $this->settings->ext['rs-test'] : [];
        if (($test['failAt'] ?? null) === 'pages') {
            throw new \RuntimeException('the pages plugin failed, as asked (' . $this->settings->storeDir . ')');
        }
        if (in_array('core:' . $kind, is_array($test['marks'] ?? null) ? $test['marks'] : [], true)) {
            return null;                                // this kind: the core's page
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $status = is_int($ctx['status'] ?? null) ? (string) $ctx['status'] : '-';
        $lang = is_string($ctx['lang'] ?? null) ? $ctx['lang'] : 'en';
        $extra = $kind === Pages::CHALLENGE && is_array($ctx['challenge'] ?? null) ? ' data-field="' . $e(is_string($ctx['field'] ?? null) ? $ctx['field'] : '') . '"' : '';
        return '<!doctype html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><title>PAGES:' . $e($kind) . ':' . $status . '</title></head><body' . $extra . '><p>' . $e($kind) . ' by the site</p></body></html>';
    }
}
