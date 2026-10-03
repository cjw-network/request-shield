<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Release;

/**
 * `request-shield verify <file> [--sums=<file>] [--sig=<file>] [--key=<key>]`
 * (0031 E.6): is a downloaded file the one that was released? The checksum
 * from SHA256SUMS (next to the file unless --sums names it), and -- with a
 * release key (Shipped::PUBKEY, or --key) and sodium -- the minisign
 * signature (<file>.minisig unless --sig), whose trusted comment must name the
 * same checksum. Without a key or without sodium the checksum alone, and the
 * output says so. Exit 0: the file is the released one; 1: it is not; 2: what
 * is needed is missing.
 */
final class Verify
{
    /**
     * @param resource $out
     * @param resource $err
     */
    public static function run(string $file, ?string $sums, ?string $sig, string $key, $out, $err): int
    {
        $data = @file_get_contents($file);
        if ($data === false) {
            fwrite($err, "request-shield: cannot read $file\n");
            return 2;
        }
        $name = basename($file);
        $hash = hash('sha256', $data);
        $sums ??= dirname($file) . '/SHA256SUMS';
        $list = @file_get_contents($sums);
        if ($list === false) {
            fwrite($err, "request-shield: no $sums -- download SHA256SUMS from the same release (or name it with --sums=)\n");
            return 2;
        }
        $listed = self::listed($list, $name);
        if ($listed === null) {
            fwrite($err, "request-shield: $sums does not list $name\n");
            return 1;
        }
        if (!hash_equals($listed, $hash)) {
            fwrite($err, "request-shield: $name does NOT match SHA256SUMS -- a broken or changed download; do not use it\n");
            return 1;
        }
        fwrite($out, "checksum: ok ($name sha256:$hash)\n");
        if ($key === '') {
            fwrite($out, "signature: not checked -- this build has no release key yet; the checksum only shows the download is complete, not who made it\n");
            return 0;
        }
        if (!Minisign::available()) {
            fwrite($out, "signature: not checked -- PHP has no sodium here; check it with minisign elsewhere: minisign -Vm $name -P <key>\n");
            return 0;
        }
        $sig ??= "$file.minisig";
        $signature = @file_get_contents($sig);
        if ($signature === false) {
            fwrite($err, "request-shield: no $sig -- download the .minisig from the same release (or name it with --sig=)\n");
            return 2;
        }
        try {
            $comment = Minisign::verify($data, $signature, $key);
        } catch (\RuntimeException $e) {
            fwrite($err, "request-shield: $name: " . $e->getMessage() . "\n");
            return 1;
        }
        if (preg_match('/ sha256:([0-9a-f]{64})$/', $comment, $m) !== 1 || !hash_equals($m[1], $hash)) {
            fwrite($err, "request-shield: the signature's comment names another checksum: \"$comment\"\n");
            return 1;
        }
        fwrite($out, "signature: ok ($comment)\n");
        return 0;
    }

    /** The checksum SHA256SUMS gives for a file name ("<hex>  <name>", or "<hex> *<name>"), null when it lists none. */
    public static function listed(string $sums, string $name): ?string
    {
        foreach (preg_split('/\r\n|\n/', $sums) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64}) [ *](.+)$/', trim($line), $m) === 1 && $m[2] === $name) {
                return $m[1];
            }
        }
        return null;
    }
}
