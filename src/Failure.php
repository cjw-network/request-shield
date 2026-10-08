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
 * Something in the shield failed and the request went on (ADR 0007): noted
 * in PHP's error log, once a minute per cause -- the visitor never sees it.
 * Across requests and workers too (APCu, else a marker file in the given
 * directory): a broken deploy is one line a minute, not one a visitor.
 */
final class Failure
{
    /** @var array<string, int> the cause (what failed, and the message) => when it was last noted, this process */
    private static array $noted = [];

    /**
     * @param string $what which part failed: "shield", "settings", a plugin's class, a hook
     * @param string|false|null $dir where "already noted" is kept across requests: the site's
     *   store or cache directory; null for the temp dir (no settings yet); false for this process only
     */
    public static function note(string $what, string $message, string|false|null $dir): void
    {
        $now = time();
        $cause = hash('crc32b', $what . '|' . substr($message, 0, 200));
        if ($now - (self::$noted[$cause] ?? 0) < 60) {
            return;
        }
        self::$noted[$cause] = $now;
        $dir ??= rtrim(sys_get_temp_dir(), '/') . '/request-shield';
        if ($dir !== false) {
            if (Capability::apcu()) {
                if (!apcu_add('rshield:failed:' . hash('crc32b', $dir) . ':' . $cause, $now, 60)) {
                    return;
                }
            } else {
                $marker = $dir . '/failed-' . $cause;
                $last = @filemtime($marker);
                if ($last !== false && $now - $last < 60) {
                    return;
                }
                Files::dir($dir);
                @touch($marker);
            }
        }
        error_log('request-shield: ' . $message);
    }
}
