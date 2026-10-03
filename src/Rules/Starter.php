<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

/**
 * `request-shield init --app=<app> [--docroot=<dir>] [--out=<file>] [--force]`
 * (0031 E.6): a commented starter rule file for an application
 * (rules/starter/<app>.rules, embedded in the single file), in monitor mode,
 * with an example for each rule. Written to --out, or printed. Refused: a
 * file that exists (unless --force), and a file inside the document root --
 * the rules, the log and the store never belong where a browser can read
 * them. The written file is compiled once, so it is known to work.
 * Exit 0: written or printed; 2: refused.
 */
final class Starter
{
    /**
     * @param resource $out
     * @param resource $err
     */
    public static function run(?string $app, ?string $docroot, ?string $file, bool $force, $out, $err): int
    {
        $apps = Shipped::starters();
        $text = $app === null ? null : Shipped::starter($app);
        if ($text === null) {
            fwrite($err, 'request-shield: init --app=' . implode('|', $apps) . " [--docroot=<dir>] [--out=<file>] [--force]\n"
                . ($app === null ? '' : "       no starter for \"$app\"\n"));
            return 2;
        }
        if ($file === null) {
            fwrite($out, $text);
            return 0;
        }
        $dir = realpath(dirname($file));
        if ($dir === false) {
            fwrite($err, 'request-shield: ' . dirname($file) . " does not exist -- create it first (outside the document root)\n");
            return 2;
        }
        if ($docroot !== null) {
            $root = realpath($docroot);
            if ($root === false) {
                fwrite($err, "request-shield: the document root $docroot does not exist\n");
                return 2;
            }
            if ($dir === $root || strncmp($dir . '/', $root . '/', strlen($root) + 1) === 0) {
                fwrite($err, "request-shield: $file is inside the document root $root -- a browser could read the rules, the log and the store; choose a directory beside it\n");
                return 2;
            }
        }
        $target = $dir . '/' . basename($file);
        if (substr($target, -6) !== '.rules') {
            fwrite($err, "request-shield: $file is not a rule file (.rules)\n");
            return 2;
        }
        if (is_file($target) && !$force) {
            fwrite($err, "request-shield: $target exists -- --force writes over it\n");
            return 2;
        }
        // Compiled beside the target first, moved in only when it works: a file that
        // exists (--force) stays as it was when the starter does not compile here.
        $tmp = $dir . '/.' . basename($target, '.rules') . '.' . bin2hex(random_bytes(4)) . '.rules';
        if (@file_put_contents($tmp, $text) === false) {
            fwrite($err, "request-shield: cannot write into $dir\n");
            return 2;
        }
        try {
            RuleFile::read([$tmp]);
        } catch (RuleFileException $e) {
            @unlink($tmp);
            fwrite($err, "request-shield: the starter does not compile here: " . $e->getMessage() . "\n");
            return 2;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            fwrite($err, "request-shield: cannot write $target\n");
            return 2;
        }
        fwrite($out, "written: $target ($app, monitor mode)\n"
            . "next: add the site's rules, then  request-shield check $target  and  request-shield test $target\n"
            . ($docroot === null ? "note: without --docroot nothing checked that the file lies outside the document root\n" : ''));
        return 0;
    }
}
