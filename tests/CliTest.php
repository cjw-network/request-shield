<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Shield;

/** @return array{0: list<string>, 1: int} the CLI's output lines and exit code */
function cli(string $args): array
{
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/request-shield') . ' ' . $args . ' 2>&1', $out, $code);
    return [$out, $code];
}

return [
    'RSF5.2 the version is one literal, in the shape of a release (or the next one, -dev)' => function (): void {
        truthy(preg_match('/^\d+\.\d+\.\d+(-dev)?$/', Shield::VERSION) === 1, 'Shield::VERSION is a version: ' . Shield::VERSION);
        same('source', Shield::BUILD, 'the repository is the source build');
        // Ahead of the last release in the changelog: the next version, not an old one.
        $log = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        truthy(preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $log, $m) === 1, 'the changelog names the last release');
        truthy(version_compare((string) strtok(Shield::VERSION, '-'), $m[1], '>'), 'VERSION ' . Shield::VERSION . ' is ahead of the last release ' . $m[1]);
    },
    'RSF5.2 request-shield version: what is installed, without a rule file' => function (): void {
        foreach (['version', '--version'] as $word) {
            [$out, $code] = cli($word);
            same(0, $code, "$word exits 0: " . implode(' | ', $out));
            same('request-shield ' . Shield::VERSION . ' (source)', $out[0] ?? '', 'the first line');
            truthy(strpos($out[1] ?? '', 'PHP ' . PHP_VERSION) === 0, 'PHP and APCu on the second line: ' . ($out[1] ?? ''));
            truthy(preg_match('/^shipped rule sets: @attacks \d{4}\.\d{2}\.\d+ · @crawlers /', $out[2] ?? '') === 1, 'the shipped sets with their versions: ' . ($out[2] ?? ''));
            same(3, count($out), 'nothing more without a rule file');
        }
    },
    'RSF5.2 request-shield version site.rules: the store in use, the mode, the versions of the site\'s files' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-cli-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/site.rules", "version 2026.10.3\nset store file\nset store-dir $dir/store\nset mode monitor\ninclude @scanners\n");
            [$out, $code] = cli('version ' . escapeshellarg("$dir/site.rules"));
            same(0, $code, 'exits 0: ' . implode(' | ', $out));
            $text = implode("\n", $out);
            truthy(strpos($text, "rules: $dir/site.rules, mode monitor") !== false, 'the file and the mode: ' . $text);
            truthy(strpos($text, "store: file (set store file), $dir/store") !== false, 'the store in use: ' . $text);
            truthy(strpos($text, '2026.10.3') !== false, 'the site file\'s own version: ' . $text);
            [$out, $code] = cli('version ' . escapeshellarg("$dir/site.php"));
            same(2, $code, 'not a rule file: exit 2');
            file_put_contents("$dir/bad.rules", "set mode sideways\n");
            [$out, $code] = cli('version ' . escapeshellarg("$dir/bad.rules"));
            same(1, $code, 'a wrong rule file: exit 1 with the error, ' . implode(' | ', $out));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
