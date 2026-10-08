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
 * The modes of what the shield creates: its folders (store-dir, the log's,
 * the statistics', the lists', the feeds', the HTTP cache's) and its files
 * (the log, the statistics, the lists, the feeds, marks, a learning run, the
 * advice) -- `set dir-mode` and `set file-mode`, by default 0700 and 0600:
 * only the user PHP runs as. A server's own rules for new folders (0750,
 * 02770 so the group is inherited, 0755) are set exactly: a new folder or
 * file gets its mode with chmod() after it is made, so the umask takes
 * nothing away from it. What exists already keeps its mode.
 *
 * Never allowed: writing for everyone, and a mode that keeps PHP itself from
 * writing (the owner's rw, for a folder rwx). The secret and the compiled
 * settings stay 0600 in a 0700 folder whatever is set: they hold the key.
 *
 * The modes are taken from the settings when they are made (Settings'
 * constructor) -- the one place every request and every command passes
 * before it writes.
 */
final class Files
{
    private static int $fileMode = 0600;

    private static int $dirMode = 0700;

    /** @var array<string, true> files this process has made or found (appending): no second look */
    private static array $known = [];

    /** Whether a mode may be a file's: the owner reads and writes, nobody else writes everyone's, no special bits. */
    public static function fileModeOk(int $mode): bool
    {
        return ($mode & ~0777) === 0 && ($mode & 0600) === 0600 && ($mode & 0002) === 0 && ($mode & 0111) === 0;
    }

    /** Whether a mode may be a folder's: the owner rwx, not writable for everyone; setgid and sticky allowed. */
    public static function dirModeOk(int $mode): bool
    {
        return ($mode & ~03777) === 0 && ($mode & 0700) === 0700 && ($mode & 0002) === 0;
    }

    /** "0640", "640", "02770" as a number; null when it is no mode. */
    public static function parseMode(string $value): ?int
    {
        return preg_match('/^0?[0-7]{3,4}$/', $value) === 1 ? (int) octdec($value) : null;
    }

    /** Takes the modes of the settings (Settings' constructor); a mode that is not allowed keeps the default. */
    public static function modes(int $file, int $dir): void
    {
        self::$fileMode = self::fileModeOk($file) ? $file : 0600;
        self::$dirMode = self::dirModeOk($dir) ? $dir : 0700;
    }

    public static function fileMode(): int
    {
        return self::$fileMode;
    }

    public static function dirMode(): int
    {
        return self::$dirMode;
    }

    /** A folder, with the parents it lacks, each in dir-mode exactly; true when it is there. */
    public static function dir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        $missing = [];
        for ($d = $dir; $d !== '' && $d !== '.' && $d !== '/' && !is_dir($d); $d = dirname($d)) {
            $missing[] = $d;
            if (dirname($d) === $d) {
                break;
            }
        }
        if (!@mkdir($dir, self::$dirMode & 0777, true) && !is_dir($dir)) {
            return false;               // another process may have made it in between: is_dir() decides
        }
        foreach ($missing as $d) {
            @chmod($d, self::$dirMode);   // exactly: setgid, and what the umask took
        }
        return true;
    }

    /**
     * A file written whole: a temporary file in file-mode, then renamed -- a
     * reader never sees half of it, and it never has another mode.
     */
    public static function write(string $file, string $data): bool
    {
        if (!self::dir(dirname($file))) {
            return false;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $data) === false || !@chmod($tmp, self::$fileMode) || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** The mode for a temporary file before it is renamed into place (for code that writes it itself). */
    public static function own(string $file): bool
    {
        return @chmod($file, self::$fileMode);
    }

    /**
     * A line added to a file (O_APPEND: the lines of parallel requests do not
     * overwrite each other). A new file gets file-mode before anything is in
     * it: made empty and exclusive, its mode set, then appended to. Once per
     * process and file -- a file this process knows is appended to at once;
     * when that fails (its folder was removed), it is made anew, once.
     */
    public static function append(string $file, string $data): bool
    {
        if (isset(self::$known[$file])) {
            if (@file_put_contents($file, $data, FILE_APPEND) !== false) {
                return true;
            }
            unset(self::$known[$file]);
        }
        $new = @fopen($file, 'xb');
        if ($new === false && !is_file($file) && self::dir(dirname($file))) {
            $new = @fopen($file, 'xb');
        }
        if ($new !== false) {
            fclose($new);
            @chmod($file, self::$fileMode);
        }
        if (count(self::$known) >= 256) {
            self::$known = [];
        }
        self::$known[$file] = true;
        return @file_put_contents($file, $data, FILE_APPEND) !== false;
    }

    /** Forgets that a file is known (it was moved away: the next append makes it anew, in file-mode). */
    public static function forget(string $file): void
    {
        unset(self::$known[$file]);
    }
}
