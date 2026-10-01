<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

use CjwNetwork\RequestShield\IpTable;
use CjwNetwork\RequestShield\Settings;

/**
 * The addresses kept out, for the level below PHP (proposal 0025): the deny
 * list and the feeds named "deny", as the fewest CIDR blocks, for a firewall
 * (nftables, ipset), nginx or a plain list.
 *
 * Not for .htaccess: Apache reads it on every request, static files too, and
 * measured with 2,000 "Require not ip" ranges a static file was served 8x
 * slower (500: 3-4x, 10,000: 28x) -- the shield's own lookup costs 2-3 us at
 * any size (docs/proposals/0025-blocklist-feeds.md).
 *
 * Never a block that touches a trusted proxy or an address let in (they are
 * left out, and counted); never a feed with "at <paths>" (a firewall knows
 * no paths); never a running ban (minutes long, per server).
 */
final class FeedExport
{
    public const FORMATS = ['plain', 'nginx', 'nftables', 'ipset'];

    /**
     * @return array{cidrs: list<string>, left: list<string>} the blocks, and those left out (they touch a trusted proxy or an address let in)
     */
    public static function cidrs(Settings $s): array
    {
        $keep = array_merge($s->trustedProxies, $s->exemptIps);
        $out = ['cidrs' => [], 'left' => []];
        $tables = [$s->denyTable];
        if (isset($s->feedTables['deny'])) {
            $tables[] = $s->feedTables['deny'];
        }
        // One table of both: overlaps merged, neighbours joined.
        $entries = [];
        foreach ($tables as $t) {
            if ($t !== []) {
                $entries[] = [IpTable::cidrs($t), 'x'];
            }
        }
        foreach (IpTable::cidrs(IpTable::build($entries)) as $cidr) {
            $b = IpTable::bounds($cidr);
            $touches = false;
            foreach ($keep as $k) {
                $kb = IpTable::bounds($k);
                if ($b !== null && $kb !== null && strlen($kb[0]) === strlen($b[0]) && strcmp($b[0], $kb[1]) <= 0 && strcmp($kb[0], $b[1]) <= 0) {
                    $touches = true;
                    break;
                }
            }
            $out[$touches ? 'left' : 'cidrs'][] = $cidr;
        }
        return $out;
    }

    /**
     * The blocks in a format.
     *
     * @param list<string> $cidrs
     */
    public static function render(array $cidrs, string $format): string
    {
        $v4 = array_values(array_filter($cidrs, static fn (string $c): bool => strpos($c, ':') === false));
        $v6 = array_values(array_filter($cidrs, static fn (string $c): bool => strpos($c, ':') !== false));
        $stamp = 'generated ' . date('Y-m-d H:i') . ' by request-shield feeds export -- do not edit, it is written again';
        switch ($format) {
            case 'plain':
                return implode("\n", $cidrs) . ($cidrs === [] ? '' : "\n");
            case 'nginx':
                return "# $stamp\n" . implode('', array_map(static fn (string $c): string => "deny $c;\n", $cidrs));
            case 'ipset':
                $h = "# $stamp\n# ipset restore < this file\n";
                foreach (['request-shield-4' => [$v4, 'inet'], 'request-shield-6' => [$v6, 'inet6']] as $set => [$list, $family]) {
                    $h .= "create $set hash:net family $family -exist\nflush $set\n";
                    foreach ($list as $c) {
                        $h .= "add $set $c\n";
                    }
                }
                return $h;
            case 'nftables':
                return "# $stamp\n# nft -f this file\ntable inet request_shield {\n" . self::nftSet('deny4', 'ipv4_addr', $v4) . self::nftSet('deny6', 'ipv6_addr', $v6)
                    . "    chain input {\n        type filter hook input priority -10; policy accept;\n        ip saddr @deny4 drop\n        ip6 saddr @deny6 drop\n    }\n}\n";
        }
        throw new \InvalidArgumentException('format ' . implode(', ', self::FORMATS) . " -- not \"$format\"");
    }

    /** @param list<string> $list */
    private static function nftSet(string $name, string $type, array $list): string
    {
        return "    set $name {\n        type $type\n        flags interval\n        auto-merge\n"
            . ($list === [] ? '' : "        elements = {\n            " . implode(",\n            ", $list) . "\n        }\n") . "    }\n";
    }
}
