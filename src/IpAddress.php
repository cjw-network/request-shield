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
 * IP helpers: CIDR matching for IPv4 and IPv6, and the bucket a client is
 * counted in.
 */
final class IpAddress
{
    /**
     * Whether $ip lies in one of $ranges ("10.0.0.0/8", "2001:db8::/32",
     * "127.0.0.1", "::1").
     *
     * @param list<string> $ranges
     */
    public static function inRanges(string $ip, array $ranges): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($ranges as $range) {
            if (self::inRange($bin, $range)) {
                return true;
            }
        }
        return false;
    }

    private static function inRange(string $bin, string $range): bool
    {
        $slash = strpos($range, '/');
        $net = @inet_pton($slash === false ? $range : substr($range, 0, $slash));
        if ($net === false || strlen($net) !== strlen($bin)) {
            return false;
        }
        $bits = $slash === false ? strlen($bin) * 8 : (int) substr($range, $slash + 1);
        if ($bits < 0 || $bits > strlen($bin) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && strncmp($bin, $net, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /**
     * The key a client's budget is kept under. IPv4 as it is; IPv6 by its
     * /64 prefix (by default), since one subscriber usually holds a whole
     * /64 and rotates freely inside it.
     */
    public static function bucket(string $ip, int $ipv6Prefix = 64): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'invalid';
        }
        if (strlen($bin) === 4) {
            return $ip;
        }
        $ipv6Prefix = max(0, min(128, $ipv6Prefix));
        $bytes = intdiv($ipv6Prefix, 8);
        $masked = substr($bin, 0, $bytes);
        if ($ipv6Prefix % 8 !== 0) {
            $masked .= chr(ord($bin[$bytes]) & ((0xFF << (8 - $ipv6Prefix % 8)) & 0xFF));
            $bytes++;
        }
        $masked .= str_repeat("\0", 16 - $bytes);
        return inet_ntop($masked) . '/' . $ipv6Prefix;
    }
}
