<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Waf;

use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

/**
 * The firewall's pages (0031 G.3, RSF06-01, RSF06-02): Rules & setup, Live
 * and Lists below <dashboard-path>/waf, and the demo's pages and the
 * `examples` command. A plugin, not the core: the mini file decides and
 * answers without them; the pages read their data where the API reads it
 * (Api\LiveRows, Api\ListsChanges), so this plugin needs the API's.
 */
final class WafExtension implements Extension
{
    public static function id(): string
    {
        return 'waf';
    }

    public static function vocabulary(Vocabulary $v): void
    {
    }

    /** @return array{base: string} */
    public static function compile(array $raw, Settings $base): array
    {
        return ['base' => $base->dashboardPath . '/waf'];
    }

    public static function plugins(array $compiled): array
    {
        return [];
    }

    /** The firewall's start (/waf, which is the live view), Rules & setup, Live and Lists. */
    public static function routes(array $compiled): array
    {
        $waf = is_string($compiled['base'] ?? null) ? $compiled['base'] : '/rs/waf';
        return [
            $waf => ['key' => 'live', 'tab' => null, 'role' => 'admin', 'order' => 60, 'page' => LivePage::class],
            "$waf/rules" => ['key' => 'rules', 'tab' => ['Rules & setup', 'Regeln & Einrichtung'], 'role' => 'admin', 'order' => 50, 'page' => SetupPage::class],
            "$waf/live" => ['key' => 'live', 'tab' => ['Live', 'Live'], 'role' => 'admin', 'order' => 60, 'page' => LivePage::class],
            "$waf/lists" => ['key' => 'lists', 'tab' => ['Lists', 'Listen'], 'role' => 'admin', 'order' => 70, 'page' => ListsPage::class],
        ];
    }

    public static function commands(): array
    {
        return ['examples' => Cli\ExamplesCommand::class];
    }

    public static function check(Settings $s): array
    {
        return [];
    }
}
