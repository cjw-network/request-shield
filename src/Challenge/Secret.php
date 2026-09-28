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
 * The key challenges and pass cookies are signed with: the configured one, or
 * one made once and kept in the store directory (0600), so a site works
 * without being told a secret. Every server of a site must share it.
 */
final class Secret
{
    public static function resolve(?string $configured, string $dir): string
    {
        if ($configured !== null && strlen($configured) >= 32) {
            return $configured;
        }
        $file = rtrim($dir, '/') . '/secret';
        $secret = @file_get_contents($file);
        if (is_string($secret) && strlen($secret) >= 64) {
            return $secret;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $secret = bin2hex(random_bytes(32));
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $secret) !== false) {
            @chmod($tmp, 0600);
            // Two first requests at once: the second rename wins, and both
            // processes read the same file on their next request.
            @rename($tmp, $file);
            $kept = @file_get_contents($file);
            if (is_string($kept) && strlen($kept) >= 64) {
                return $kept;
            }
        }
        return $secret;
    }
}
