<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php docs/tools/gen-reference.php [--check]
 *
 * Writes the reference from the code (0031 F.5), never by hand:
 *
 * - the two tables in docs/features/RSF05-01-rule-files.md (the rules, the
 *   set keys), between their "<!-- reference … -->" markers;
 * - docs/reference/rule-files.md and docs/reference/settings.md, the same
 *   rows with the feature each belongs to (Rules\Reference,
 *   Rules\Vocabulary::FEATURES);
 * - docs/reference/cli.md, the command line's usage and its commands (Cli).
 *
 * --check writes nothing and exits 1 when a file is not what it would write
 * (the tests run it). Exit 0: written or current.
 */

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use CjwNetwork\RequestShield\Rules\Reference;
use CjwNetwork\RequestShield\Rules\Vocabulary;

$root = dirname(__DIR__, 2);
$check = in_array('--check', $argv, true);
$note = '<!-- Written by docs/tools/gen-reference.php from src/Rules/Reference.php -- do not edit; run the tool. -->';

/** A Markdown table cell: a pipe escaped (also inside code), one line. */
$cell = static fn (string $s): string => str_replace(["\n", '|'], [' ', '\\|'], str_replace('\\|', '|', $s));

$pages = [];
foreach (glob("$root/docs/features/RSF*.md") ?: [] as $f) {
    $pages[substr(basename($f), 0, 8)] = basename($f);
}
// The feature of a word or key: the core's, else its extension's (Vocabulary::featureOf()).
$feature = static function (string $word, string $base) use ($pages): string {
    $id = Vocabulary::featureOf($word);
    return $id === null ? '' : (isset($pages[$id]) ? "[$id]($base{$pages[$id]})" : $id);
};
// Links in the rows are relative to the rule files page; from docs/reference they go through ../features/.
$relink = static fn (string $s): string => (string) preg_replace(['/\]\(#/', '/\]\((RSF\d{2}-\d{2}-)/'], ['](../features/RSF05-01-rule-files.md#', '](../features/$1'], $s);

$rulesTable = "| Rule | Setting | |\n|---|---|---|\n";
$rulesRef = "| Rule | Setting | Feature | What it does |\n|---|---|---|---|\n";
foreach (Reference::RULES as $r) {
    $rulesTable .= '| ' . $cell($r['syntax']) . ' | ' . $cell($r['setting']) . ' | ' . $cell($r['about']) . " |\n";
    $rulesRef .= '| ' . $cell($r['syntax']) . ' | ' . $cell($r['setting']) . ' | ' . $feature($r['words'][0], '../features/') . ' | ' . $cell($relink($r['about'])) . " |\n";
}
$setsTable = "| Key | Value |\n|---|---|\n";
$setsRef = "| Key | Value | Feature |\n|---|---|---|\n";
foreach (Reference::SETTINGS as $r) {
    $setsTable .= '| ' . $cell($r['label']) . ' | ' . $cell($r['value']) . " |\n";
    $setsRef .= '| ' . $cell($r['label']) . ' | ' . $cell($relink($r['value'])) . ' | ' . $feature($r['keys'][0], '../features/') . " |\n";
}

// The command line: its usage (as it prints it) and the commands as the class explains them.
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/bin/request-shield") . ' 2>&1', $usage);
$doc = (new ReflectionClass(\CjwNetwork\RequestShield\Cli::class))->getDocComment();
$explained = '';
foreach (preg_split('/\n/', (string) $doc) ?: [] as $line) {
    $line = (string) preg_replace('#^\s*/?\*+/?\s?#', '', $line);
    // Prose outside code: "<file>" must not become an HTML tag.
    if (preg_match('/^([a-z][a-z-]*):\s+(.*)$/', $line, $m) === 1) {
        $explained .= "\n- **`{$m[1]}`** " . htmlspecialchars($m[2], ENT_NOQUOTES, 'UTF-8');
    } elseif ($explained !== '' && preg_match('/^\s{2,}(\S.*)$/', $line, $m) === 1) {
        $explained .= ' ' . htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8');
    }
}

$files = [
    "$root/docs/reference/rule-files.md" => "# Reference: the rule file\n\n$note\n\nEvery rule a rule file may hold, what it sets, and the feature it belongs to. "
        . "How the file works -- blocks, sites, IDs, includes -- is in [rule files](../features/RSF05-01-rule-files.md); `request-shield vocabulary` prints the same.\n\n$rulesRef",
    "$root/docs/reference/settings.md" => "# Reference: the set keys\n\n$note\n\nEvery `set` key, its values, and the feature it belongs to. `\${NAME}` in a value is an environment "
        . "variable ([rule files](../features/RSF05-01-rule-files.md#set)).\n\n$setsRef",
    "$root/docs/reference/cli.md" => "# Reference: the command line\n\n$note\n\n`bin/request-shield` in the repository and a Composer install, `php request-shield.php` "
        . "for the single file. What it prints without a command:\n\n```text\n" . implode("\n", $usage) . "\n```\n\n## The commands\n$explained\n",
];
// The tables in the rule files page, between their markers.
$page = "$root/docs/features/RSF05-01-rule-files.md";
$text = (string) file_get_contents($page);
foreach (['rules' => $rulesTable, 'settings' => $setsTable] as $what => $table) {
    $open = "<!-- reference $what: docs/tools/gen-reference.php -->";
    $re = '/' . preg_quote($open, '/') . '\n.*?<!-- \/reference -->/s';
    if (preg_match($re, $text) !== 1) {
        fwrite(STDERR, "gen-reference: $page has no \"$open\" … \"<!-- /reference -->\"\n");
        exit(1);
    }
    $text = (string) preg_replace_callback($re, static fn (): string => "$open\n$table<!-- /reference -->", $text);
}
$files[$page] = $text;

$stale = [];
foreach ($files as $file => $content) {
    if ((string) @file_get_contents($file) === $content) {
        continue;
    }
    $stale[] = substr($file, strlen($root) + 1);
    if (!$check) {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $content);
    }
}
if ($check && $stale !== []) {
    fwrite(STDERR, 'gen-reference: not current -- php docs/tools/gen-reference.php: ' . implode(', ', $stale) . "\n");
    exit(1);
}
echo $stale === [] ? "gen-reference: current\n" : 'gen-reference: written ' . implode(', ', $stale) . "\n";
