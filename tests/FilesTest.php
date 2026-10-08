<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Cli;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Files;
use CjwNetwork\RequestShield\Log;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Store\FileStore;

function filesDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-files-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    return $dir;
}

function modeOf(string $path): string
{
    clearstatcache(true, $path);
    return sprintf('0%03o', (int) fileperms($path) & 07777);
}

/** Runs $test with the umask set and the default modes restored after. */
function withUmask(int $umask, callable $test): void
{
    $old = umask($umask);
    try {
        $test();
    } finally {
        umask($old);
        Files::modes(0600, 0700);
    }
}

return [
    'RSF05-02 file-mode and dir-mode: what may be set -- never writable for everyone, never one that keeps PHP out; octal as written' => function (): void {
        same([0640, 0640, 02770, null, null, null], [Files::parseMode('0640'), Files::parseMode('640'), Files::parseMode('02770'), Files::parseMode('rw-r-----'), Files::parseMode('0890'), Files::parseMode('006400')]);
        same([true, true, true, true], [Files::fileModeOk(0600), Files::fileModeOk(0640), Files::fileModeOk(0660), Files::fileModeOk(0644)]);
        same([false, false, false, false], [Files::fileModeOk(0646), Files::fileModeOk(0400), Files::fileModeOk(0700), Files::fileModeOk(04600)], 'everyone writes; PHP cannot write; x bit; setuid');
        same([true, true, true, true], [Files::dirModeOk(0700), Files::dirModeOk(0750), Files::dirModeOk(02770), Files::dirModeOk(0755)]);
        same([false, false, false], [Files::dirModeOk(0777), Files::dirModeOk(0500), Files::dirModeOk(04700)], 'everyone writes; PHP cannot write; setuid');
        $s = Settings::from([]);
        same([0600, 0700], [$s->fileMode, $s->dirMode], 'by default only the user PHP runs as');
        same([0640, 02750], [Settings::from(['fileMode' => '0640', 'dirMode' => 02750])->fileMode, Settings::from(['dirMode' => '02750'])->dirMode]);
        foreach ([['fileMode' => 0666], ['dirMode' => 0777], ['fileMode' => 'rw'], ['dirMode' => 0600]] as $wrong) {
            try {
                Settings::from($wrong);
                throw new TestFailure('accepted ' . json_encode($wrong));
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), (string) array_key_first($wrong)) !== false, $e->getMessage());
            }
        }
        Files::modes(0600, 0700);
        $dir = filesDir();
        try {
            file_put_contents("$dir/site.rules", "set file-mode 0640\nset dir-mode 02770\n");
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same([0640, 02770], [$s->fileMode, $s->dirMode], 'the rule words');
            same([0640, 02770], [Files::fileMode(), Files::dirMode()], 'taken when the settings are made');
            foreach (["set file-mode 0666\n" => 'file-mode is a mode such as', "set dir-mode 0777\n" => 'dir-mode is a mode such as', "set file-mode rw-r-----\n" => 'not "rw-r-----"'] as $text => $part) {
                file_put_contents("$dir/site.rules", $text);
                try {
                    RuleFile::read(["$dir/site.rules"]);
                    throw new TestFailure("accepted $text");
                } catch (RuleFileException $e) {
                    truthy(strpos($e->getMessage(), 'site.rules:1: ') === 0 && strpos($e->getMessage(), $part) !== false, $e->getMessage());
                }
            }
        } finally {
            Files::modes(0600, 0700);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-02 the modes are set exactly: the umask takes nothing away and adds nothing; each new parent too; setgid kept' => function (): void {
        $dir = filesDir();
        try {
            withUmask(0077, static function () use ($dir): void {
                Files::modes(0640, 02750);
                truthy(Files::dir("$dir/a/b/c"), 'made');
                same(['02750', '02750', '02750'], [modeOf("$dir/a"), modeOf("$dir/a/b"), modeOf("$dir/a/b/c")],
                    'the new levels in dir-mode, the umask 0077 notwithstanding');
                same('0700', modeOf($dir), 'what was there keeps its mode');
                truthy(Files::write("$dir/a/w.json", '{}'), 'written');
                same('0640', modeOf("$dir/a/w.json"));
                truthy(Files::append("$dir/a/log", "1\n") && Files::append("$dir/a/log", "2\n"), 'appended');
                same(['0640', "1\n2\n"], [modeOf("$dir/a/log"), file_get_contents("$dir/a/log")]);
                same([], glob("$dir/a/w.json.*") ?: [], 'no temporary file left');
            });
            withUmask(0, static function () use ($dir): void {
                Files::modes(0600, 0700);
                Files::dir("$dir/open/x");
                Files::append("$dir/open/x/log", "1\n");
                Files::write("$dir/open/x/w", '1');
                same(['0700', '0700', '0600', '0600'], [modeOf("$dir/open"), modeOf("$dir/open/x"), modeOf("$dir/open/x/log"), modeOf("$dir/open/x/w")], 'umask 0: nothing more for others');
                chmod("$dir/open/x/log", 0644);
                Files::append("$dir/open/x/log", "2\n");
                same('0644', modeOf("$dir/open/x/log"), 'a file that is there keeps its mode');
                exec('rm -rf ' . escapeshellarg("$dir/open"));
                truthy(Files::append("$dir/open/x/log", "3\n"), 'its folder removed while the process knew the file: made anew');
                same(["3\n", '0600', '0700'], [file_get_contents("$dir/open/x/log"), modeOf("$dir/open/x/log"), modeOf("$dir/open/x")]);
            });
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-02 what the shield writes gets the modes: the log, a mark in store-dir, a list by the command line (set file-mode, set dir-mode)' => function (): void {
        $dir = filesDir();
        try {
            withUmask(0022, static function () use ($dir): void {
                $s = Settings::from(['storeDir' => "$dir/store", 'fileMode' => 0640, 'dirMode' => 0750, 'log' => ['file' => "$dir/logs/shield.log"]]);
                Log::write($s, Request::fromServer(['REQUEST_URI' => '/x', 'REMOTE_ADDR' => '198.51.100.7']), Decision::reject(404, 'x'), null);
                same(['0750', '0640'], [modeOf("$dir/logs"), modeOf("$dir/logs/shield.log")], 'the log and its folder');
                (new FileStore("$dir/store"))->mark('ban:198.51.100.7', time() + 60, (float) time());
                $marks = array_values(array_filter(explode("\n", (string) shell_exec('find ' . escapeshellarg("$dir/store") . ' -type f'))));
                truthy($marks !== [], 'a mark');
                same(['0640'], array_values(array_unique(array_map('modeOf', $marks))), 'a mark holds an address: file-mode');
                same(['0750'], array_values(array_unique(array_map('modeOf', array_filter(explode("\n", (string) shell_exec('find ' . escapeshellarg("$dir/store") . ' -type d')))))), 'store-dir\'s folders');
            });
            if (!function_exists('exec')) {
                skip('no exec');
            }
            file_put_contents("$dir/site.rules", "set store-dir $dir/cli\nset file-mode 0660\nset dir-mode 02770\n");
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' deny ' . escapeshellarg("$dir/site.rules") . ' 203.0.113.7 --for=1d 2>&1', $out, $code);
            same(0, $code, implode("\n", $out));
            same(['02770', '0660'], [modeOf("$dir/cli/lists"), modeOf("$dir/cli/lists/deny.rules")], 'the command line takes the rule words');
        } finally {
            Files::modes(0600, 0700);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-02 check warns about modes: one everyone may read, and files in store-dir or the log everyone may read already' => function (): void {
        $dir = filesDir();
        try {
            mkdir("$dir/store", 0700);
            file_put_contents("$dir/store/learned.jsonl", '');
            chmod("$dir/store/learned.jsonl", 0600);
            $m = new ReflectionMethod(Cli::class, 'modeWarnings');
            $m->setAccessible(true);                                  // PHP 8.0
            $warn = static fn (Settings $s): string => implode("\n", $m->invoke(null, $s));
            same('', $warn(Settings::from(['storeDir' => "$dir/store"])), 'nothing to say');
            truthy(strpos($warn(Settings::from(['storeDir' => "$dir/store", 'fileMode' => 0644])), 'file-mode 0644 / dir-mode 0700: everyone on this machine may read') === 0, 'a mode for everyone');
            chmod("$dir/store/learned.jsonl", 0644);
            file_put_contents("$dir/shield.log", '');
            chmod("$dir/shield.log", 0604);
            $w = $warn(Settings::from(['storeDir' => "$dir/store", 'log' => ['file' => "$dir/shield.log"]]));
            truthy(strpos($w, "$dir/store/learned.jsonl (0644)") !== false && strpos($w, "$dir/shield.log (0604)") !== false && strpos($w, 'chmod o-rwx') !== false, $w);
            $w = $warn(Settings::from(['storeDir' => "$dir/store", 'fileMode' => 0644, 'log' => ['file' => "$dir/shield.log"]]));
            truthy(strpos($w, 'everyone on this machine may read what') !== false && strpos($w, 'made before') === false, "set so on purpose: no second warning about the files: $w");
        } finally {
            Files::modes(0600, 0700);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
