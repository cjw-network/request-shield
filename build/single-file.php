<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php build/single-file.php [--edition=mini|stats|api|cache|waf] [--out=<file>] [--build=<id>]
 *
 * Builds one edition as one PHP file (proposal 0002, 0031 E.2): the classes
 * of the edition's directories, each in its own `namespace X { … }` block,
 * parents and interfaces before the classes that need them; the shipped rule
 * sets embedded in Rules\Shipped; the challenge page's and the widget's
 * scripts without indentation and comment lines; and, for the mini edition,
 * the bootstrap at the end -- the file protects a site from
 * `auto_prepend_file` and is the command line when run directly.
 *
 * Deterministic: the same sources and --build give the same bytes (no time,
 * no machine-specific path). --build names the build in Shield::BUILD
 * (default: "single", plus the commit when git knows it). Exit 0: written;
 * 1: refused (the message says why) -- never a partial file.
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/..';

/**
 * The editions. A file's classes come from its directories; "core" is the
 * edition the others load on top of. An edition that cannot be built yet says
 * why ("later").
 *
 * @var array<string, array{file?: string, dirs?: list<string>, core?: bool, about?: string, minify?: list<string>, later?: string, load?: string}>
 */
const EDITIONS = [
    'mini' => [
        'file' => 'request-shield.php',
        'dirs' => ['src'],
        'core' => true,
        'about' => 'the mini edition: the checks, the answers, the browser check, the log, the rule compiler, the command line and the shipped rule sets',
        'minify' => ['src/Challenge/ChallengePage.php', 'src/Challenge/Widget.php'],
    ],
    'stats' => [
        'file' => 'request-shield-stats.php',
        'dirs' => ['plugins/stats/src'],
        'core' => false,
        'about' => 'the statistics plugin, loaded after request-shield.php',
        'load' => 'the statistics are switched on in the rules (set stats on)',
    ],
    'waf' => ['later' => 'the dashboard\'s pages (src/Report, Access) are still part of the mini edition; 0031 step G.3 moves them into plugins/waf, then this edition is built from there'],
    'api' => [
        'file' => 'request-shield-api.php',
        'dirs' => ['plugins/api/src'],
        'core' => false,
        'about' => 'the API plugin, loaded after request-shield.php',
        'load' => 'the API is on below <dashboard-path>/api/v1, guarded like the dashboard (set api off, set api-write on)',
    ],
    'cache' => [
        'file' => 'request-shield-cache.php',
        'dirs' => ['plugins/cache/src'],
        'core' => false,
        'about' => 'the HTTP cache plugin, loaded after request-shield.php',
        'load' => 'the cache is switched on in the rules (set http-cache on)',
    ],
];

/** The header comment's install guide: what an agent that has only the file needs to know. */
const GUIDE = <<<'TXT'
 * Install (PHP 8.0 or newer, nothing else):
 *
 *   1. Put this file OUTSIDE the document root, e.g. /var/www/request-shield.php.
 *   2. Next to it, the rules: request-shield.rules (one line is enough:
 *      "host www.example.org"); check them with
 *        php /var/www/request-shield.php check /var/www/request-shield.rules
 *   3. Switch it on, for every PHP request of the site:
 *        auto_prepend_file=/var/www/request-shield.php   (php.ini, .user.ini or the pool)
 *
 * The rules are found in this order: the constant or environment variable
 * REQUEST_SHIELD_CONFIG (naming it saves the search), request-shield.rules
 * next to this file, config/request-shield.rules, config/request-shield.php.
 * The store (counters, the secret) goes to .request-shield/ next to the rules
 * unless "set store-dir" says otherwise -- keep it out of the document root too.
 *
 * Run directly, this file is the command line: php request-shield.php
 * (check, show, test, trace, crawlers, feeds, lists, version …).
 * Documentation: https://github.com/cjw-network/request-shield
TXT;

/** @return never */
function refuse(string $why): void
{
    fwrite(STDERR, "build: $why\n");
    exit(1);
}

/**
 * The PHP files below the directories, by path (byte order).
 *
 * @param list<string> $dirs
 * @return list<string> paths relative to ROOT
 */
function sources(array $dirs): array
{
    $out = [];
    foreach ($dirs as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . "/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                $out[] = $dir . substr($f->getPathname(), strlen(ROOT . "/$dir"));
            }
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

/** A name as a class reference resolves: \A\B as it is, an imported first part replaced, else below the namespace. */
function resolve(string $name, string $ns, array $uses): string
{
    if ($name[0] === '\\') {
        return ltrim($name, '\\');
    }
    $first = strtolower((string) strtok($name, '\\'));
    $rest = strpos($name, '\\') === false ? '' : substr($name, strpos($name, '\\'));
    return isset($uses[$first]) ? $uses[$first] . $rest : ($ns === '' ? $name : "$ns\\$name");
}

/**
 * One source file, taken apart: its namespace, its imports (alias => class,
 * and the statements as written), the code after them, the class it declares
 * and the classes it extends or implements. Anything at the top level but
 * declarations refuses the build: in one file it would run on every include.
 *
 * @return array{ns: string, uses: array<string, string>, useLines: list<string>, body: string, declares: list<string>, needs: list<string>}
 */
function parse(string $path, bool $minify): array
{
    $tokens = token_get_all((string) file_get_contents(ROOT . "/$path"));
    $ns = '';
    $uses = [];
    $useLines = [];
    $body = '';
    $declares = [];
    $needs = [];
    $depth = 0;
    $licence = false;
    $n = count($tokens);
    $heredoc = null;
    $allowed = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT, T_CLASS, T_INTERFACE, T_TRAIT, T_STRING, T_EXTENDS, T_IMPLEMENTS, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ATTRIBUTE];
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        $id = is_array($t) ? $t[0] : null;
        $text = is_array($t) ? $t[1] : $t;
        if ($id === T_OPEN_TAG) {
            continue;
        }
        if ($depth === 0) {
            if ($id === T_DOC_COMMENT && !$licence && strpos($text, 'This file is part of') !== false) {
                $licence = true;
                continue;
            }
            if ($id === T_DECLARE) {
                while ($i < $n && $tokens[$i] !== ';') {
                    $i++;
                }
                continue;
            }
            if ($id === T_NAMESPACE || $id === T_USE) {
                $stmt = '';
                for ($i++; $i < $n && $tokens[$i] !== ';'; $i++) {
                    $stmt .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                }
                $stmt = trim($stmt);
                if ($id === T_NAMESPACE) {
                    $ns = $stmt;
                    continue;
                }
                if (preg_match('/^([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?$/', $stmt, $m) !== 1) {
                    refuse("$path: an import the build does not read: use $stmt");
                }
                $class = ltrim($m[1], '\\');
                $alias = $m[2] ?? substr((string) strrchr('\\' . $class, '\\'), 1);
                $uses[strtolower($alias)] = $class;
                // An import of a class of the same namespace under its own name says
                // nothing -- and in one file it would clash with that class's declaration.
                if (!(isset($m[2]) === false && substr($class, 0, (int) strrpos($class, '\\')) === $ns)) {
                    $useLines[] = "use $class" . (isset($m[2]) ? " as {$m[2]}" : '') . ';';
                }
                continue;
            }
            if ($id !== null && !in_array($id, $allowed, true) || ($id === null && !in_array($text, ['{', '}', ','], true))) {
                refuse("$path:" . (is_array($t) ? $t[2] : '?') . ": top-level code (" . ($id !== null ? token_name($id) : $text) . ') -- only declarations go into the single file');
            }
            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                // The name, then extends/implements up to the "{".
                $j = $i + 1;
                while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                $declares[] = ($ns === '' ? '' : "$ns\\") . $tokens[$j][1];
                for ($j++; $j < $n && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $needs[] = resolve($tokens[$j][1], $ns, $uses);
                    }
                }
            }
        }
        if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        } elseif ($text === '}') {
            $depth--;
        }
        // The visitors' scripts: nowdocs JS (and CSS) in the files named, minified.
        if ($minify && $id === T_START_HEREDOC && preg_match("/^<<<'(JS|CSS)'\\r?\\n$/", $text, $m) === 1) {
            $heredoc = $m[1];
        } elseif ($heredoc !== null && $id === T_ENCAPSED_AND_WHITESPACE) {
            $text = minify($text, $heredoc, $path);
        } elseif ($heredoc !== null && $id === T_END_HEREDOC) {
            $text = ltrim($text);              // the body has no indentation left, so the marker has none
            $heredoc = null;
        }
        $body .= $text;
    }
    if ($ns === '') {
        refuse("$path: no namespace");
    }
    return ['ns' => $ns, 'uses' => $uses, 'useLines' => $useLines, 'body' => trim($body), 'declares' => $declares, 'needs' => array_values(array_unique($needs))];
}

/**
 * Whitespace and comment lines only, never a rename: each line without its
 * indentation, empty lines and whole-line // comments dropped, the line
 * breaks kept (so no statement runs into the next). A script with a template
 * literal (`) keeps its lines as they are.
 */
function minify(string $code, string $kind, string $path): string
{
    if (strpos($code, '`') !== false) {
        return $code;
    }
    if ($kind === 'CSS') {
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
    }
    $out = [];
    foreach (preg_split('/\r\n|\n|\r/', $code) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || ($kind === 'JS' && strncmp($line, '//', 2) === 0)) {
            continue;
        }
        if (preg_match('/^' . $kind . '\b/', $line) === 1) {
            refuse("$path: a line of the $kind nowdoc would end it once unindented: $line");
        }
        $out[] = $line;
    }
    return implode("\n", $out) . "\n";
}

/**
 * The files in an order PHP can declare them in: by path, each after the
 * classes of this build it extends or implements.
 *
 * @param array<string, array{ns: string, uses: array<string, string>, useLines: list<string>, body: string, declares: list<string>, needs: list<string>}> $parsed by path
 * @return list<string> paths
 */
function ordered(array $parsed): array
{
    $byClass = [];
    foreach ($parsed as $path => $p) {
        foreach ($p['declares'] as $c) {
            $byClass[strtolower($c)] = $path;
        }
    }
    $done = [];
    $out = [];
    $visit = static function (string $path, array $stack) use (&$visit, &$done, &$out, $parsed, $byClass): void {
        if (isset($done[$path])) {
            return;
        }
        if (in_array($path, $stack, true)) {
            refuse('a cycle of extends/implements: ' . implode(' → ', $stack) . " → $path");
        }
        $stack[] = $path;
        foreach ($parsed[$path]['needs'] as $need) {
            $dep = $byClass[strtolower($need)] ?? null;
            if ($dep !== null && $dep !== $path) {
                $visit($dep, $stack);
            }
        }
        $done[$path] = true;
        $out[] = $path;
    };
    foreach (array_keys($parsed) as $path) {
        $visit($path, []);
    }
    return $out;
}

/** A nowdoc for $text whose marker is in none of its lines; the value is exactly $text. */
function nowdoc(string $text): string
{
    $marker = 'RS_SHIPPED';
    foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
        if (preg_match('/^\s*' . $marker . '\b/', $line) === 1) {
            refuse("a shipped file has a line that starts with $marker");
        }
    }
    // The newline before the closing marker is not part of the value: one more keeps a trailing one.
    return "<<<'$marker'\n" . $text . "\n$marker";
}

/**
 * Shipped.php's body with the data in it -- the four constants filled,
 * dir() never reached. The five places must be as the class has them;
 * tests/ShippedTest.php replaces the same ones.
 */
function shipped(string $body): string
{
    $rules = [];
    foreach (glob(ROOT . '/rules/*.rules') ?: [] as $f) {
        $rules[basename($f, '.rules')] = (string) file_get_contents($f);
    }
    ksort($rules, SORT_STRING);
    $lists = [];
    foreach (glob(ROOT . '/rules/crawlers/*.json') ?: [] as $f) {
        $lists[basename($f, '.json')] = (string) file_get_contents($f);
    }
    ksort($lists, SORT_STRING);
    $starters = [];
    foreach (glob(ROOT . '/rules/starter/*.rules') ?: [] as $f) {
        $starters[basename($f, '.rules')] = (string) file_get_contents($f);
    }
    ksort($starters, SORT_STRING);
    $map = static function (array $data): string {
        $out = "[\n";
        foreach ($data as $name => $text) {
            $out .= '        ' . var_export((string) $name, true) . ' => ' . nowdoc($text) . ",\n";
        }
        return $out . '    ]';
    };
    $replace = [
        'public const RULES = [];' => 'public const RULES = ' . $map($rules) . ';',
        "public const FEEDS = '';" => 'public const FEEDS = ' . nowdoc((string) file_get_contents(ROOT . '/rules/feeds.json')) . ';',
        'public const CRAWLER_LISTS = [];' => 'public const CRAWLER_LISTS = ' . $map($lists) . ';',
        'public const STARTERS = [];' => 'public const STARTERS = ' . $map($starters) . ';',
        "return dirname(__DIR__, 2) . '/rules';" => "return __DIR__ . '/rules';         // never reached: the data is embedded",
    ];
    foreach ($replace as $from => $to) {
        if (substr_count($body, $from) !== 1) {
            refuse("src/Rules/Shipped.php no longer has \"$from\" once -- the build fills it");
        }
        $body = str_replace($from, $to, $body);
    }
    return $body;
}

/** The bootstrap at the end of the mini edition: bootstrap.php's search order, and the command line when run directly. */
function tail(): string
{
    return <<<'PHP'
namespace {
    // The shipped extensions, by name (as bootstrap.php): a statistics file loaded
    // before the rules are compiled is offered; without it, nothing is loaded.
    if (!defined('REQUEST_SHIELD_EXTENSIONS')) {
        define('REQUEST_SHIELD_EXTENSIONS', ['CjwNetwork\\RequestShield\\Stats\\StatsExtension', 'CjwNetwork\\RequestShield\\Api\\ApiExtension', 'CjwNetwork\\RequestShield\\Cache\\CacheExtension']);
    }
    (static function (): void {
        if (defined('REQUEST_SHIELD_DONE')) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            // Run directly: the command line. Required by a script or a test runner: no effect.
            $argv = $_SERVER['argv'] ?? null;
            if (is_array($argv) && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
                define('REQUEST_SHIELD_DONE', true);
                exit(\CjwNetwork\RequestShield\Cli::main(array_values(array_map('strval', $argv))));
            }
            return;
        }
        define('REQUEST_SHIELD_DONE', true);
        $named = defined('REQUEST_SHIELD_CONFIG') ? constant('REQUEST_SHIELD_CONFIG') : getenv('REQUEST_SHIELD_CONFIG');
        if (is_string($named) && $named !== '') {
            if (is_file($named)) {
                \CjwNetwork\RequestShield\Shield::protectFile($named);
            }
            return;             // named, and not there: nothing -- not another file by surprise
        }
        foreach ([__DIR__ . '/request-shield.rules', __DIR__ . '/config/request-shield.rules', __DIR__ . '/config/request-shield.php'] as $file) {
            if (is_file($file)) {
                \CjwNetwork\RequestShield\Shield::protectFile($file);
                return;
            }
        }
    })();
}

PHP;
}

// --- the command line ---
$edition = 'mini';
$out = null;
$build = null;
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--edition=', 10) === 0) {
        $edition = substr($a, 10);
    } elseif (strncmp($a, '--out=', 6) === 0) {
        $out = substr($a, 6);
    } elseif (strncmp($a, '--build=', 8) === 0) {
        $build = substr($a, 8);
    } else {
        refuse('usage: php build/single-file.php [--edition=' . implode('|', array_keys(EDITIONS)) . '] [--out=<file>] [--build=<id>]');
    }
}
$e = EDITIONS[$edition] ?? refuse("no edition \"$edition\" (" . implode(', ', array_keys(EDITIONS)) . ')');
if (isset($e['later'])) {
    refuse("the $edition edition is not built yet: {$e['later']}");
}
if ($build === null) {
    $commit = function_exists('exec') ? trim((string) @exec('git -C ' . escapeshellarg(ROOT) . ' rev-parse --short HEAD 2>/dev/null')) : '';
    $build = 'single' . ($commit !== '' ? " $commit" : '');
}
if (preg_match('/^[A-Za-z0-9 ._+-]{1,64}$/', $build) !== 1) {
    refuse('--build takes letters, digits, space, ".", "_", "+" and "-"');
}
$out ??= ROOT . '/build/out/' . $e['file'];

$parsed = [];
foreach (sources($e['dirs']) as $path) {
    $parsed[$path] = parse($path, in_array($path, $e['minify'] ?? [], true));
}
$version = null;
$blocks = [];
foreach (ordered($parsed) as $path) {
    $p = $parsed[$path];
    $body = $p['body'];
    if ($path === 'src/Rules/Shipped.php') {
        $body = shipped($body);
    }
    if ($path === 'src/Shield.php') {
        if (preg_match("/public const VERSION = '([^']+)';/", $body, $m) !== 1 || substr_count($body, "public const BUILD = 'source';") !== 1) {
            refuse('src/Shield.php: VERSION and BUILD (\'source\') are not where the build looks');
        }
        $version = $m[1];
        $body = str_replace("public const BUILD = 'source';", 'public const BUILD = ' . var_export($build, true) . ';', $body);
    }
    // "if (true)": a conditional declaration. At the top level PHP (and OPcache, when it
    // loads the file) declares a class before any code runs -- the guard below would
    // find it there already, and a second include would be fatal. Inside a block the
    // classes are declared when the code reaches them, after the guard.
    $blocks[] = "// $path\nnamespace {$p['ns']} {\n" . ($p['useLines'] !== [] ? implode("\n", $p['useLines']) . "\n\n" : '') . "if (true) {\n" . $body . "\n}\n}\n";
}
if ($e['core'] ?? false) {
    if ($version === null) {
        refuse('the core edition without src/Shield.php');
    }
    $guard = "namespace {\n    // Loaded already (a second include, or a Composer install of the same library): nothing more.\n"
        . "    if (class_exists('CjwNetwork\\\\RequestShield\\\\Shield', false)) {\n        return;\n    }\n}\n";
} else {
    $version = null;
    if (preg_match("/public const VERSION = '([^']+)';/", (string) file_get_contents(ROOT . '/src/Shield.php'), $m) === 1) {
        $version = $m[1];
    }
    $first = $parsed[array_key_first($parsed)]['declares'][0] ?? refuse('an edition without classes');
    $guard = "namespace {\n    // Loaded already: nothing more. Without request-shield.php first, its classes could not be declared.\n"
        . '    if (class_exists(' . var_export($first, true) . ", false)) {\n        return;\n    }\n"
        . "    if (!class_exists('CjwNetwork\\\\RequestShield\\\\Shield', false)) {\n"
        . "        error_log('request-shield: ' . basename(__FILE__) . ' needs request-shield.php loaded first -- nothing loaded');\n        return;\n    }\n}\n";
}
$head = "<?php\n/**\n * cjw-network/request-shield $version -- $edition: {$e['about']}.\n"
    . " * One file, built by build/single-file.php (build: $build) from the repository's sources; do not edit.\n *\n"
    . " * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network\n * @license MIT, see https://github.com/cjw-network/request-shield/blob/main/LICENSE\n *\n"
    . (($e['core'] ?? false) ? GUIDE . "\n" : " * Load it after request-shield.php; " . ($e['load'] ?? '') . ".\n")
    . " */\n\ndeclare(strict_types=1);\n\n";
$file = $head . $guard . "\n" . implode("\n", $blocks) . (($e['core'] ?? false) ? "\n" . tail() : '');

// Written whole or not at all.
$dir = dirname($out);
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    refuse("cannot create $dir");
}
$tmp = $out . '.' . bin2hex(random_bytes(4));
if (@file_put_contents($tmp, $file) === false || !@rename($tmp, $out)) {
    @unlink($tmp);
    refuse("cannot write $out");
}
echo "$out: $edition, " . count($blocks) . ' files, ' . strlen($file) . " bytes (build: $build)\n";
