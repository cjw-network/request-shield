<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Report\Inspector;
use CjwNetwork\RequestShield\Rules\CrawlerLists;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Shipped;

/**
 * The command line (bin/request-shield, and the single file run directly --
 * 0031 E.2): Cli::main($argv) is the whole tool. A command ends the process
 * with its exit code (exit()), as the script did; main() returns 0 when the
 * last command ("show") falls through.
 *
 *   php bin/request-shield check  site.rules [--source=<glob>]...
 *   php bin/request-shield show   site.rules [--source=<glob>]...
 *   php bin/request-shield reload site.rules [--source=<glob>]...
 *   php bin/request-shield trace  site.rules "GET https://www.example.org/wp-login.php" [--ip=<address>] [--ua=<User-Agent>]
 *   php bin/request-shield test   site.rules [--source=<glob>]... [--only=<ID>] [--as-written] [--junit=<file>]
 *   php bin/request-shield crawlers site.rules [update] [--force]
 *   php bin/request-shield access-token site.rules "<principal>"|'*'
 *   php bin/request-shield feeds site.rules [list|update|export] [--force] [--format=plain|nginx|nftables|ipset] [--write=<file>]
 *   php bin/request-shield <an extension's command> site.rules …   (stats: the statistics plugin's, see its usage)
 *   -- stats site.rules [--days=7 | --from=2026-01-01 --to=2026-09-30] [--by=day|week|month|year] [--crawler=<ID>] [--path=/news/] [--sort=views|blocked|refused|checked|throttled] [--site=<website>|other | --group=<group>] [--json]
 *   php bin/request-shield version [site.rules]
 *   php request-shield.php init --app=plain|wordpress|symfony|exponential [--docroot=<dir>] [--out=<file>] [--force]
 *   php request-shield.php verify request-shield.php [--sums=SHA256SUMS] [--sig=request-shield.php.minisig] [--key=<public key>]
 *   php request-shield.php self-update [--check] [--to=vX.Y.Z] [--major]     (the single file only)
 *
 * version: the library's version and build, PHP, whether APCu is there and
 *         the shipped rule sets' versions; with a rule file also the store in
 *         use, the mode and the versions of the site's own rule files.
 * check:  reads every file, reports the first error with file and line, and
 *         warns about rule files anyone else could change.
 * show:   the rules in effect after merging, each with the line it comes from.
 * reload: check, then mark the main file changed (touch), so every server
 *         reads the rules again on its next check -- for files added or changed
 *         where servers without APCu do not look by themselves (extensions).
 * trace:  what happens to a request, check by check, in plain words (nothing
 *         is counted); the visitor's address with --ip (default 198.51.100.7).
 * test:   decides every example next to the rules (expect lines), each on a
 *         fresh store, with the rules switched on (monitor as enforce; as they
 *         are with --as-written); lists the site's rules without an example.
 *         Exit 0: all pass, 1: one fails, 2: a mistake in the files.
 * replay: sends a recording of requests known to be good through the rules
 *         -- a session clicked through in a browser or by end-to-end tests
 *         (a HAR file), a web server's access log (its 2xx and 3xx), or a
 *         list of addresses -- each once, on a fresh store, nothing counted,
 *         the rules switched on as for test; says which the rules would
 *         refuse or check, and by which rule. Static files and other
 *         websites' addresses are left out (--all keeps the static files);
 *         every request comes from --ip (log: each line's own address).
 *         Exit 0: none refused, 1: one is, 2: a mistake in the files.
 * crawlers: the known crawlers, what the site does with each, and how old
 *         their address lists are. "update" fetches the operators' current
 *         lists into store-dir (cron, a deploy -- or where there is internet,
 *         copying store-dir/crawlers/ into a DMZ); the servers read them on their
 *         next check. A list that shrank to less than half is kept unless --force.
 * access-token: a new token for the dashboard (proposal 0023): printed once,
 *         with the dashboard-access line to paste -- only its hash goes into the
 *         rule file. "*" for everything, a group's name for its websites only.
 * feeds:  the public blocklists the rules name (feed …): how many entries, how
 *         old. "update" fetches those that are due (cron, e.g. hourly; each at
 *         most as often as its terms allow) into store-dir/feeds; a list that
 *         shrank to less than half is kept unless --force. "export" writes the
 *         addresses kept out (the deny list and the feeds named deny) for the
 *         level below PHP: a firewall (nftables, ipset), nginx, a plain list.
 *         Not .htaccess: Apache reads it on every request -- measured far too
 *         slow for lists (see docs/proposals/0025-blocklist-feeds.md).
 * stats:  what the counters (set stats on) say about the last days: requests
 *         let through, checked, refused, the rules behind them, the answers'
 *         status codes, pages not found and who links to them, what each known
 *         crawler did, other bots -- in words, or as JSON for a CMS.
 * examples: the "# demo:" groups of a rule file (one per feature): as Markdown
 *         tables for the docs (--markdown, --feature=RSF02-06 for one), as a
 *         page that needs no server with what test decided for each row
 *         (--html, --out=<file>), or how much is covered (--coverage).
 * vocabulary: every rule and set key, how it is written and the feature it
 *         belongs to (--json: with what each does) -- docs/reference's source.
 * init:   a commented starter rule file for an application, in monitor mode;
 *         never inside --docroot, never over a file without --force.
 * verify: is a downloaded file the released one: its checksum from SHA256SUMS
 *         and, with the release key and sodium, its minisign signature.
 * self-update: the single file replaces itself with a signed release, every
 *         file checked before any is replaced (--check: exit 10 when a newer
 *         one exists); the command line only, never by itself.
 */
final class Cli
{
    /**
     * @param list<string> $argv the script's arguments, its own name first
     */
    public static function main(array $argv): int
    {
        $args = array_slice($argv, 1);
        // Where the rule files named are: a mistake names its file by name only (mistake()).
        foreach ($args as $a) {
            $named = strncmp($a, '--source=', 9) === 0 ? substr($a, 9) : $a;
            if (substr($named, -6) === '.rules' || $named !== $a) {
                self::$dirs[] = dirname($named);
            }
        }
        $sources = [];
        $rest = [];
        $ip = '198.51.100.7';
        $force = false;
        $ua = null;
        $days = 7;
        $json = false;
        $period = [];
        $listFor = null;
        $listUntil = null;
        $listReason = '';
        $feedOpts = ['format' => 'plain', 'write' => null];
        // init, verify, self-update (0031 E.6): their options.
        $release = ['app' => null, 'docroot' => null, 'out' => null, 'sums' => null, 'sig' => null, 'key' => null, 'check' => false, 'to' => null, 'major' => false];
        // examples (0031 F.5): what to write.
        $show = ['markdown' => false, 'html' => false, 'coverage' => false, 'feature' => null];
        $testOpts = ['only' => null, 'asWritten' => false, 'junit' => null];
        $replayAll = false;
        $ipGiven = false;
        foreach ($args as $a) {
            if ($a === '--force') {
                $force = true;
            } elseif ($a === '--json') {
                $json = true;
            } elseif (strncmp($a, '--days=', 7) === 0) {
                $days = max(1, (int) substr($a, 7));
            } elseif (preg_match('/^--(from|to)=(\d{4})-(\d{2})-(\d{2})$/', $a, $m) === 1) {
                $period[$m[1]] = $m[2] . $m[3] . $m[4];
            } elseif (preg_match('/^--by=(day|week|month|year)$/', $a, $m) === 1) {
                $period['by'] = $m[1];
            } elseif (strncmp($a, '--site=', 7) === 0) {
                $period['site'] = strtolower(substr($a, 7));
            } elseif (strncmp($a, '--group=', 8) === 0) {
                $period['site'] = 'group:' . \CjwNetwork\RequestShield\Settings::principal(substr($a, 8));
            } elseif (strncmp($a, '--crawler=', 10) === 0) {
                $period['crawler'] = substr($a, 10);
            } elseif (strncmp($a, '--path=', 7) === 0) {
                $period['path'] = '/' . ltrim(substr($a, 7), '/');
            } elseif (preg_match('/^--sort=(views|blocked|refused|checked|throttled)$/', $a, $m) === 1) {
                $period['sort'] = $m[1];
            } elseif (strncmp($a, '--ua=', 5) === 0) {
                $ua = substr($a, 5);
            } elseif (strncmp($a, '--ip=', 5) === 0) {
                $ip = substr($a, 5);
                $ipGiven = true;
            } elseif (strncmp($a, '--source=', 9) === 0) {
                $sources[] = substr($a, 9);
            } elseif (preg_match('/^--for=(\d+)(s|m|h|d|w)$/', $a, $m) === 1) {
                $listFor = (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][$m[2]];
            } elseif (strncmp($a, '--until=', 8) === 0) {
                $listUntil = substr($a, 8);
            } elseif (strncmp($a, '--reason=', 9) === 0) {
                $listReason = substr($a, 9);
            } elseif (strncmp($a, '--format=', 9) === 0) {
                $feedOpts['format'] = substr($a, 9);
            } elseif (strncmp($a, '--write=', 8) === 0) {
                $feedOpts['write'] = substr($a, 8);
            } elseif (strncmp($a, '--only=', 7) === 0) {
                $testOpts['only'] = trim(substr($a, 7), '[]');
            } elseif ($a === '--all') {
                $replayAll = true;
            } elseif ($a === '--as-written') {
                $testOpts['asWritten'] = true;
            } elseif (strncmp($a, '--junit=', 8) === 0) {
                $testOpts['junit'] = substr($a, 8);
            } elseif (preg_match('/^--(app|docroot|out|sums|sig|key|to)=(.*)$/s', $a, $m) === 1) {
                $release[$m[1]] = $m[2];
            } elseif ($a === '--markdown' || $a === '--html' || $a === '--coverage') {
                $show[substr($a, 2)] = true;
            } elseif (strncmp($a, '--feature=', 10) === 0) {
                $show['feature'] = substr($a, 10);
            } elseif ($a === '--check' || $a === '--major') {
                $release[substr($a, 2)] = true;
            } else {
                $rest[] = $a;
            }
        }
        [$command, $file, $what] = $rest + [null, null, null];

        // The rule file's words and set keys, each with how it is written and its feature
        // (0031 F.5) -- from the same data as docs/reference.
        if ($command === 'vocabulary') {
            $features = [];
            foreach (array_merge(Rules\RuleFile::coreWords(), Rules\RuleFile::coreSettings(), Rules\Vocabulary::known()['words'], Rules\Vocabulary::known()['settings']) as $w) {
                $features[$w] = Rules\Vocabulary::featureOf($w);
            }
            if ($json) {
                echo json_encode(['rules' => Rules\Reference::RULES, 'settings' => Rules\Reference::SETTINGS, 'features' => $features], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
                exit(0);
            }
            foreach (Rules\Reference::RULES as $r) {
                echo str_pad(str_replace('`', '', $r['syntax']), 70) . ' ' . ($features[$r['words'][0]] ?? '') . "\n";
            }
            echo "\n";
            foreach (Rules\Reference::SETTINGS as $r) {
                echo str_pad('set ' . implode(' | ', $r['keys']), 70) . ' ' . ($features[$r['keys'][0]] ?? '') . "\n";
            }
            exit(0);
        }
        // Commands without a rule file (0031 E.6).
        if ($command === 'init') {
            exit(Rules\Starter::run($release['app'], $release['docroot'], $release['out'], $force, STDOUT, STDERR));
        }
        if ($command === 'verify') {
            if ($file === null) {
                fwrite(STDERR, "usage: request-shield verify <request-shield.php> [--sums=<SHA256SUMS>] [--sig=<file.minisig>] [--key=<public key>]\n");
                exit(2);
            }
            exit(Release\Verify::run($file, $release['sums'], $release['sig'], $release['key'] ?? Shipped::PUBKEY, STDOUT, STDERR));
        }
        if ($command === 'self-update') {
            if (!Shipped::embedded()) {
                fwrite(STDERR, "request-shield: self-update replaces the single file, and this is a source checkout or a Composer install -- update it with git or composer\n");
                exit(2);
            }
            $self = (string) (new \ReflectionClass(Shipped::class))->getFileName();
            exit((new Release\SelfUpdate($self, Shipped::PUBKEY, Shield::VERSION))->run($release['check'], $release['to'], $release['major'], STDOUT, STDERR));
        }

        if ($command === 'version' || $command === '--version') {
            // What is installed: for a bug report, a support call, or an agent that
            // checks what it downloaded. Never on a request path.
            $v = \CjwNetwork\RequestShield\Shield::VERSION;
            $apcu = \CjwNetwork\RequestShield\Store\ApcuStore::usable();
            echo 'request-shield ' . $v . ' (' . \CjwNetwork\RequestShield\Shield::BUILD . ")\n";
            echo 'PHP ' . PHP_VERSION . ', APCu ' . ($apcu ? 'available' : 'not available' . (PHP_SAPI === 'cli' ? ' here (apc.enable_cli=1 switches it on for the CLI)' : '')) . "\n";
            $shipped = [];
            foreach (Shipped::sets() as $set) {
                if (preg_match('/^version[ \t]+(\S+)/m', (string) Shipped::rules($set), $m) === 1) {
                    $shipped[] = '@' . $set . ' ' . $m[1];
                }
            }
            echo 'shipped rule sets: ' . ($shipped !== [] ? implode(' · ', $shipped) : 'none') . "\n";
            if ($file !== null) {
                if (substr($file, -6) !== '.rules') {
                    fwrite(STDERR, "request-shield: $file is not a rule file (.rules)\n");
                    exit(2);
                }
                try {
                    $read = RuleFile::read(array_merge($sources, [$file]));
                    $s = Settings::from($read['config']);
                } catch (\InvalidArgumentException $e) {
                    self::mistake($e->getMessage());
                    exit(1);
                }
                $store = \CjwNetwork\RequestShield\Shield::storeFor($s);
                $kind = ['ApcuStore' => 'apcu', 'FileStore' => 'file', 'MemoryStore' => 'memory'][substr(strrchr(get_class($store), '\\') ?: '', 1)] ?? get_class($store);
                echo "rules: $file, mode $s->mode" . ($s->sites !== [] ? ', ' . count(array_unique(array_values($s->sites))) . ' site block(s)' : '') . "\n";
                echo "store: $kind (set store $s->store)" . ($kind === 'file' ? ", $s->storeDir" : '') . "\n";
                $tier = \CjwNetwork\RequestShield\Tier::of($s, Settings::cacheDirFor($file));
                echo "tier: {$tier['tier']} -- " . implode('; ', $tier['why']) . "\n";
                foreach (array_merge($tier['chosen'], $tier['off']) as $off) {
                    echo "  not active: $off\n";
                }
                $own = [];
                foreach ($s->origins['versions'] ?? [] as $name => $version) {
                    $own[] = "$name $version";
                }
                echo 'versions in the rule files: ' . ($own !== [] ? implode(' · ', $own) : 'none (version <v> names one)') . "\n";
            }
            exit(0);
        }

        // The extensions' commands (Extension::commands(), 0031 D.1): name => the Command class, a dispatch table.
        $commands = [];
        foreach (\CjwNetwork\RequestShield\Rules\Vocabulary::extensions() as $extension) {
            /** @var array<mixed> $declared an extension's code: checked, not trusted */
            $declared = $extension::commands();
            foreach ($declared as $name => $class) {
                if (is_string($name) && is_string($class) && class_exists($class) && is_subclass_of($class, \CjwNetwork\RequestShield\Cli\Command::class)) {
                    $commands[$name] = $class;
                }
            }
        }
        $core = ['check', 'show', 'reload', 'trace', 'test', 'replay', 'examples', 'crawlers', 'feeds', 'access-token', 'deny', 'allow', 'unlist', 'lists'];
        if (!in_array($command, array_merge($core, array_keys($commands)), true) || $file === null
            || ($command === 'access-token' && $what === null)
            || ($command === 'feeds' && $what !== null && !in_array($what, ['list', 'update', 'export'], true))
            || (in_array($command, ['trace', 'deny', 'allow', 'unlist', 'replay'], true) && $what === null)
            || ($command === 'crawlers' && $what !== null && $what !== 'update')) {
            fwrite(STDERR, "usage: request-shield check|show|reload <main.rules> [--source=<glob>]...\n"
                . "       request-shield trace <main.rules> \"GET https://www.example.org/path\" [--ip=<address>] [--ua=<User-Agent>] [--source=<glob>]...\n"
                . "       request-shield test <main.rules> [--source=<glob>]... [--only=<ID>] [--as-written] [--junit=<file>]\n"
                . "       request-shield replay <main.rules> <session.har|access.log|urls.txt> [--ip=<address>|log] [--all] [--as-written] [--junit=<file>]\n"
                . "       request-shield crawlers <main.rules> [update] [--force]\n"
                . "       request-shield access-token <main.rules> \"<principal>\"|'*'\n"
                . "       request-shield feeds <main.rules> [list|update|export] [--force] [--format=plain|nginx|nftables|ipset] [--write=<file>]\n"
                . implode('', array_map(static fn (string $class): string => '       ' . $class::usage() . "\n", $commands))
                . "       request-shield deny|allow <main.rules> <address|range> [--for=7d | --until=2026-10-07[T15:30]] [--reason=\"…\"] [--force]\n"
                . "       request-shield unlist <main.rules> <address|range>\n"
                . "       request-shield lists <main.rules>\n"
                . "       request-shield version [<main.rules>]\n"
                . "       request-shield init --app=" . implode('|', Shipped::starters()) . " [--docroot=<dir>] [--out=<file>] [--force]\n"
                . "       request-shield verify <request-shield.php> [--sums=<SHA256SUMS>] [--sig=<file.minisig>] [--key=<public key>]\n"
                . "       request-shield self-update [--check] [--to=vX.Y.Z] [--major]\n"
                . "       request-shield examples <main.rules> --markdown [--feature=RSF02-06] | --html [--out=<file>] | --coverage\n"
                . "       request-shield vocabulary [--json]\n");
            exit(2);
        }
        if (substr($file, -6) !== '.rules') {
            fwrite(STDERR, "request-shield: $file is not a rule file (.rules)\n");
            exit(2);
        }

        $files = $sources;
        $files[] = $file;

        // The demo groups of a rule file (# demo:, 0031 F.4/F.5): as Markdown for the docs,
        // as a page that needs no server (recorded by test), or what is covered.
        if ($command === 'examples') {
            try {
                $groups = Report\DemoSite::groups($file);
                if ($show['feature'] !== null) {
                    // One feature's groups, in every form; an id with none is a mistake, never an empty table.
                    $groups = array_values(array_filter($groups, static fn (array $g): bool => $g['id'] === $show['feature']));
                    if ($groups === []) {
                        fwrite(STDERR, "request-shield: no \"# demo: {$show['feature']}\" group in $file\n");
                        exit(2);
                    }
                }
                $run = $show['html'] || $show['coverage'] ? Rules\Examples::run($files) : ['results' => [], 'without' => []];
            } catch (RuleFileException | \InvalidArgumentException $e) {
                self::mistake($e->getMessage());
                exit(2);
            }
            if ($show['markdown']) {
                echo Report\ExamplesPage::markdown($groups);
                exit(0);
            }
            if ($show['html']) {
                $results = [];
                foreach ($run['results'] as $r) {
                    $results[$r['example']['at']] = ['status' => $r['status'], 'got' => $r['got'], 'gotRule' => $r['gotRule'], 'http' => $r['http']];
                }
                $page = Report\ExamplesPage::html($groups, $results, 'request-shield demo -- ' . basename($file),
                    'Recorded by request-shield test, ' . Shield::VERSION . ' (' . Shield::BUILD . '): every row decided on a fresh store, as the rules say. The live demo answers the same: php -S 127.0.0.1:8080 examples/demo/router.php');
                if ($release['out'] !== null) {
                    if (@file_put_contents($release['out'], $page) === false) {
                        fwrite(STDERR, "request-shield: cannot write {$release['out']}\n");
                        exit(2);
                    }
                    echo "written: {$release['out']}\n";
                } else {
                    echo $page;
                }
                exit(0);
            }
            if ($show['coverage']) {
                // Per feature group: rows, decided, passing; then the rules without an example.
                foreach ($groups as $g) {
                    $expects = array_filter($g['rows'], static fn (array $r): bool => $r['kind'] === 'expect');
                    $ok = 0;
                    foreach ($run['results'] as $r) {
                        foreach ($expects as $x) {
                            $ok += $r['example']['at'] === $x['at'] && $r['status'] === 'pass' ? 1 : 0;
                        }
                    }
                    echo str_pad($g['id'], 10) . str_pad((string) count($expects), 4, ' ', STR_PAD_LEFT) . ' examples, ' . $ok . ' pass, ' . (count($g['rows']) - count($expects)) . ' to look at  ' . $g['title'] . "\n";
                }
                echo "\n" . count($run['without']) . ' rule(s) without an example' . ($run['without'] !== [] ? ': ' . implode(', ', $run['without']) : '') . "\n";
                exit(0);
            }
            fwrite(STDERR, "usage: request-shield examples <main.rules> --markdown [--feature=RSF02-06] | --html [--out=<file>] | --coverage\n");
            exit(2);
        }

        if ($command === 'replay') {
            // Requests known to be good, through the rules (proposal 0016, the replay).
            $source = (string) $what;
            $text = is_file($source) ? @file_get_contents($source) : false;
            if ($text === false) {
                fwrite(STDERR, "request-shield: cannot read the recording $source\n");
                exit(2);
            }
            try {
                $recording = \CjwNetwork\RequestShield\Rules\Replay::read($text);
                $config = $testOpts['asWritten'] ? RuleFile::read($files)['config'] : RuleFile::switchedOn($files);
                $base = Settings::from($config);
                $bySite = [];
                $settingsFor = static function (string $host) use ($base, $files, $testOpts, &$bySite): Settings {
                    $siteId = $host === '' || $base->sites === [] ? null : $base->siteFor(['SERVER_NAME' => $host, 'HTTP_HOST' => $host]);
                    if ($siteId === null) {
                        return $base;
                    }
                    return $bySite[$siteId] ??= Settings::from($testOpts['asWritten'] ? RuleFile::read($files, $siteId)['config'] : RuleFile::switchedOn($files, $siteId));
                };
                $run = \CjwNetwork\RequestShield\Rules\Replay::run($recording['requests'], $settingsFor, $ipGiven ? ($ip === 'log' ? null : $ip) : null, $replayAll);
            } catch (\InvalidArgumentException $e) {
                self::mistake($e->getMessage());
                exit(2);
            }
            $kinds = ['pass' => 0, 'check' => 0, 'refused' => 0];
            $left = [];
            foreach ($run['results'] as $r) {
                $kinds[$r['kind']]++;
                if ($r['kind'] !== 'pass') {
                    $left[] = $r;
                }
            }
            $out = [];
            foreach (['static' => 'static files (--all keeps them)', 'foreign' => 'of other websites'] as $k => $why) {
                if ($run[$k] > 0) {
                    $out[] = "{$run[$k]} $why";
                }
            }
            if ($recording['skipped'] > 0) {
                $out[] = $recording['skipped'] . ($recording['format'] === 'access log' ? ' the site answered with 4xx or 5xx' : ' not for a website');
            }
            echo "replay: " . basename($source) . " ({$recording['format']}): {$run['total']} requests, " . count($run['results']) . ' different'
                . ($out !== [] ? '; left out: ' . implode(', ', $out) : '') . "\n\n";
            usort($left, static fn (array $a, array $b): int => [$b['kind'] === 'refused', $b['count']] <=> [$a['kind'] === 'refused', $a['count']]);
            foreach ($left as $r) {
                $url = (string) preg_replace('#^https?://[^/]+#i', '', $r['url']);
                echo '  ' . ($r['kind'] === 'refused' ? '✕' : '!') . ' ' . str_pad($r['method'] . ' ' . (strlen($url) > 60 ? substr($url, 0, 59) . '…' : $url), 68)
                    . ' ' . $r['got'] . ($r['rule'] !== null ? ' by ' . $r['rule'] : '') . ($r['count'] > 1 ? "  ({$r['count']}×)" : '') . "\n";
            }
            echo ($left !== [] ? "\n" : '') . count($run['results']) . ' different requests: ' . $kinds['pass'] . ' pass'
                . ($kinds['check'] > 0 ? ', ' . $kinds['check'] . ' get the browser check (a browser passes it; an end-to-end test needs a pass)' : '')
                . ($kinds['refused'] > 0 ? ', ' . $kinds['refused'] . ' refused' : '') . ".\n";
            if ($kinds['refused'] > 0) {
                echo '  ' . Help::see('RSF05-04', 'the-replay-your-own-clicks-as-a-test') . "\n";
            }
            if ($testOpts['junit'] !== null) {
                $xml = new \DOMDocument('1.0', 'UTF-8');
                $xml->formatOutput = true;
                $suite = $xml->appendChild($xml->createElement('testsuite'));
                $suite->setAttribute('name', 'replay ' . basename($source));
                $suite->setAttribute('tests', (string) count($run['results']));
                $suite->setAttribute('failures', (string) $kinds['refused']);
                foreach ($run['results'] as $r) {
                    $case = $suite->appendChild($xml->createElement('testcase'));
                    $case->setAttribute('classname', $r['rule'] ?? 'replay');
                    $case->setAttribute('name', $r['method'] . ' ' . $r['url'] . ' -- ' . $r['got']);
                    if ($r['kind'] === 'refused') {
                        $case->appendChild($xml->createElement('failure'))->setAttribute('message', $r['got'] . ($r['rule'] !== null ? ' by ' . $r['rule'] : ''));
                    }
                }
                if (@file_put_contents($testOpts['junit'], (string) $xml->saveXML()) === false) {
                    fwrite(STDERR, "request-shield: cannot write {$testOpts['junit']}\n");
                    exit(2);
                }
            }
            exit($kinds['refused'] > 0 ? 1 : 0);
        }

        if ($command === 'test') {
            // The examples next to the rules (expect lines, proposal 0029), decided.
            try {
                $run = \CjwNetwork\RequestShield\Rules\Examples::run($files, $testOpts['asWritten'], $testOpts['only']);
            } catch (\InvalidArgumentException $e) {
                self::mistake($e->getMessage());
                exit(2);
            }
            $counts = ['pass' => 0, 'fail' => 0, 'skip' => 0];
            $last = null;
            foreach ($run['results'] as $r) {
                $counts[$r['status']]++;
                $x = $r['example'];
                $label = $r['about'] ?? '';
                $what = ($x['times'] > 1 ? $x['times'] . ' × ' : '') . $x['method'] . ' ' . $x['url'] . ($x['from'] !== RuleFile::EXAMPLE_FROM ? ' from ' . $x['from'] : '') . ($x['pass'] ? ' with pass' : '') . ($x['headers'] !== [] ? ' ' . implode(' ', array_map(static fn (string $k, string $v): string => "$k:$v", array_keys($x['headers']), $x['headers'])) : '');
                $mark = ['pass' => '✓', 'fail' => '✕', 'skip' => '–'][$r['status']];
                $said = $r['status'] === 'pass' ? $x['outcome'] . ($r['gotRule'] !== null && !in_array($x['outcome'], ['passes', 'uncached', 'answered'], true) ? ' by ' . $r['gotRule'] : '') : $r['why'];
                echo str_pad($label === $last ? '' : $label, 18) . "$mark " . str_pad($what, 52) . " $said\n";
                if ($r['status'] !== 'pass' && $x['text'] !== null) {
                    echo str_repeat(' ', 22) . $x['text'] . '   (' . $x['at'] . ")\n";
                } elseif ($r['status'] !== 'pass') {
                    echo str_repeat(' ', 22) . '(' . $x['at'] . ")\n";
                }
                $last = $label;
            }
            $n = count($run['results']);
            echo "\n$n example" . ($n === 1 ? '' : 's') . ': ' . $counts['pass'] . ' pass' . ($counts['fail'] > 0 ? ', ' . $counts['fail'] . ' fail' . ($counts['fail'] === 1 ? 's' : '') : '')
                . ($counts['skip'] > 0 ? ', ' . $counts['skip'] . ' skipped' : '') . '.'
                . ($run['without'] !== [] && $testOpts['only'] === null ? ' ' . count($run['without']) . ' rule' . (count($run['without']) === 1 ? '' : 's') . ' without an example: ' . implode(', ', $run['without']) : '') . "\n";
            if ($counts['fail'] > 0) {
                echo '  ' . Help::see('RSF05-04', 'request-shield-test') . "\n";   // how an example is read, and what a failure says
            }
            if ($testOpts['junit'] !== null) {
                $xml = new \DOMDocument('1.0', 'UTF-8');
                $xml->formatOutput = true;
                $suite = $xml->appendChild($xml->createElement('testsuite'));
                $suite->setAttribute('name', basename($file));
                $suite->setAttribute('tests', (string) $n);
                $suite->setAttribute('failures', (string) $counts['fail']);
                $suite->setAttribute('skipped', (string) $counts['skip']);
                foreach ($run['results'] as $r) {
                    $x = $r['example'];
                    $case = $suite->appendChild($xml->createElement('testcase'));
                    $case->setAttribute('classname', $r['about'] ?? basename($file));
                    $case->setAttribute('name', $x['method'] . ' ' . $x['url'] . ' ' . $x['outcome'] . ' (' . $x['at'] . ')');
                    if ($r['status'] === 'fail') {
                        $case->appendChild($xml->createElement('failure'))->setAttribute('message', $r['why']);
                    } elseif ($r['status'] === 'skip') {
                        $case->appendChild($xml->createElement('skipped'))->setAttribute('message', $r['why']);
                    }
                }
                if (@file_put_contents($testOpts['junit'], (string) $xml->saveXML()) === false) {
                    fwrite(STDERR, "request-shield: cannot write {$testOpts['junit']}\n");
                    exit(2);
                }
            }
            exit($counts['fail'] > 0 ? 1 : 0);
        }

        try {
            $read = RuleFile::read($files);
            $settings = Settings::from($read['config']);
            // Rules per website: every site block read and checked (base + block).
            $siteSettings = [];
            foreach (array_unique(array_values($settings->sites)) as $siteId) {
                $siteSettings[$siteId] = Settings::from(RuleFile::read($files, $siteId)['config']);
            }
        } catch (\InvalidArgumentException $e) {
            self::mistake($e->getMessage());
            exit(1);
        }

        // An extension's command: run from the table with what the script read and parsed.
        if (isset($commands[$command])) {
            exit($commands[$command]::run(new \CjwNetwork\RequestShield\Cli\Context($command, $settings, $file, $read, $sources, $what,
                ['days' => $days, 'json' => $json, 'force' => $force, 'ip' => $ip, 'ua' => $ua, 'period' => $period, 'feed' => $feedOpts, 'test' => $testOpts,
                    'list' => ['for' => $listFor, 'until' => $listUntil, 'reason' => $listReason]], $args)));
        }

        if ($command === 'access-token') {
            // A token for the dashboard: shown once; the rule file keeps its hash. The
            // principal is an opaque id to the shield ("Customer A" -> customer-a); the
            // statistics map it to a group's websites (stats-group).
            $who = (string) $what;
            $id = $who === '*' ? '*' : \CjwNetwork\RequestShield\Settings::principal($who);
            if ($id === '') {
                fwrite(STDERR, "request-shield: a principal is a name of letters and digits (\"Customer A\"), or '*' for everything\n");
                exit(2);
            }
            $groups = class_exists(\CjwNetwork\RequestShield\Stats\StatsExtension::class) ? \CjwNetwork\RequestShield\Stats\StatsExtension::of($settings)['groups'] : [];
            if ($id !== '*' && $groups !== [] && !isset($groups[$id])) {
                fwrite(STDERR, "warning: no stats-group named \"$who\" (" . implode(', ', array_column($groups, 'name')) . ") -- the token opens the dashboard, but no statistics\n");
            }
            [$token, $hash] = \CjwNetwork\RequestShield\Access::token();
            $name = $id === '*' ? '*' : (isset($groups[$id]) ? '"' . $groups[$id]['name'] . '"' : $id);
            echo "the token (give it to whoever reads; it is not kept anywhere):\n\n    $token\n\n";
            echo "the line for the rule file (only the hash):\n\n    dashboard-access $name sha256:$hash\n\n";
            echo "then: request-shield reload $file -- the token opens " . ($id === '*' ? 'everything' : "the pages of $name only") . "\n";
            exit(0);
        }

        if ($command === 'feeds') {
            // The public blocklists (feed …): list, update (cron), export.
            $named = array_merge($settings->feeds, $settings->monitor !== null ? $settings->monitor->feeds : []);
            $dir = $settings->storeDir . '/feeds';
            try {
                if ($what === 'update') {
                    if ($named === []) {
                        echo "no feed in the rules (feed <name> <action>)\n";
                        exit(0);
                    }
                    if (($offline = \CjwNetwork\RequestShield\Http::offline()) !== null) {
                        fwrite(STDERR, "request-shield: $offline\n");
                        exit(1);
                    }
                    $failed = false;
                    $changed = false;
                    foreach (\CjwNetwork\RequestShield\Rules\Feeds::update($named, $dir, null, $force) as $name => $happened) {
                        echo str_pad($name, 18) . $happened . "\n";
                        $failed = $failed || strncmp($happened, 'failed', 6) === 0 || strncmp($happened, 'refused', 7) === 0;
                        $changed = $changed || strncmp($happened, 'updated', 7) === 0;
                    }
                    if ($changed) {
                        touch($file);                               // every server reads them within its recheck
                    }
                    exit($failed ? 1 : 0);
                }
                if ($what === 'export') {
                    $x = \CjwNetwork\RequestShield\Rules\FeedExport::cidrs($settings);
                    if ($x['left'] !== []) {
                        fwrite(STDERR, count($x['left']) . ' range(s) left out: they touch a trusted proxy or an address let in (' . implode(', ', array_slice($x['left'], 0, 5)) . ")\n");
                    }
                    $text = \CjwNetwork\RequestShield\Rules\FeedExport::render($x['cidrs'], $feedOpts['format']);
                    if ($feedOpts['write'] === null) {
                        echo $text;
                        exit(0);
                    }
                    $target = $feedOpts['write'];
                    $tmp = $target . '.' . bin2hex(random_bytes(4));
                    if (@file_put_contents($tmp, $text) === false || !@rename($tmp, $target)) {
                        @unlink($tmp);
                        throw new \RuntimeException("cannot write $target");
                    }
                    echo 'wrote ' . count($x['cidrs']) . " range(s) to $target\n";
                    exit(0);
                }
                // list
                if ($named === []) {
                    echo "no feed in the rules -- the catalog (feed <name> deny|check|count|ban-signal <n>):\n";
                    foreach (\CjwNetwork\RequestShield\Rules\Feeds::catalog() as $name => $c) {
                        echo '  ' . str_pad($name, 16) . $c['title'] . ' -- ' . $c['about'] . ' (good for: ' . $c['suggested'] . ")\n" . str_repeat(' ', 18) . 'terms: ' . $c['terms'] . "\n";
                    }
                    exit(0);
                }
                foreach ($named as $f) {
                    $age = $f['fetched'] > 0 ? round((time() - $f['fetched']) / 3600, 1) . ' h ago' : 'never';
                    echo str_pad($f['name'], 16) . str_pad(($f['action'] === 'signal' ? 'ban-signal ' . $f['weight'] : $f['action']) . (in_array($f, $settings->feeds, true) ? '' : ' (count)'), 18)
                        . str_pad((string) $f['count'], 8, ' ', STR_PAD_LEFT) . ' entries  fetched ' . str_pad($age, 12) . str_pad($f['state'], 12) . $f['rule'] . "\n";
                    if ($f['terms'] !== '') {
                        echo str_repeat(' ', 16) . 'terms: ' . $f['terms'] . "\n";
                    }
                }
                exit(0);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                self::mistake('request-shield: ' . $e->getMessage());
                exit(1);
            }
        }

        if (in_array($command, ['deny', 'allow', 'unlist', 'lists'], true)) {
            // The list files (allow.rules, deny.rules in lists-dir): written whole, then
            // the main file touched, so every server reads them within its recheck.
            $dir = $settings->listsDir;
            if ($dir === null) {
                fwrite(STDERR, "no lists directory: set lists-dir (or store-dir) in the rule file\n");
                exit(1);
            }
            try {
                if ($command === 'lists') {
                    $now = time();
                    foreach (['deny' => 'kept out (deny)', 'exempt' => 'let in (allow)'] as $kind => $title) {
                        $entries = array_filter(\CjwNetwork\RequestShield\Rules\Lists::read($dir), static fn (array $e): bool => $e['kind'] === $kind);
                        echo "$title: " . count($entries) . "\n";
                        foreach ($entries as $e) {
                            $state = $e['until'] === null ? 'for good' : ($e['until'] > $now ? 'until ' . date('Y-m-d H:i', $e['until']) : 'ended ' . date('Y-m-d H:i', $e['until']));
                            echo '  ' . str_pad($e['id'], 10) . str_pad(implode(' ', $e['addresses']), 28) . str_pad($state, 24) . $e['note'] . "\n";
                        }
                    }
                    exit(0);
                }
                $address = (string) $what;
                \CjwNetwork\RequestShield\Rules\Lists::check($address);
                if ($command === 'unlist') {
                    $n = \CjwNetwork\RequestShield\Rules\Lists::remove($dir, $address);
                    touch($file);
                    echo "unlisted $address: $n entr" . ($n === 1 ? 'y' : 'ies') . " removed\n";
                    $bucket = strpos($address, '/') === false || strpos($address, ':') !== false ? \CjwNetwork\RequestShield\IpAddress::bucket((string) preg_replace('#/\d+$#', '', $address), $settings->ipv6Prefix) : null;
                    if ($bucket !== null && $settings->store === 'file') {
                        // The files are the servers' store too: a running ban is lifted at once.
                        if (\CjwNetwork\RequestShield\Shield::liftBan($settings, new \CjwNetwork\RequestShield\Store\FileStore($settings->storeDir), $bucket)) {
                            echo "its ban lifted\n";
                        }
                    } elseif ($bucket !== null && $settings->banKeep === 'file' && (new \CjwNetwork\RequestShield\Store\FileStore($settings->storeDir))->marked('ban:' . $bucket, time()) > 0) {
                        // Only the file can be reached from here; the servers' APCu keeps its copy.
                        (new \CjwNetwork\RequestShield\Store\FileStore($settings->storeDir))->mark('ban:' . $bucket, 0, time());
                        echo "its kept ban removed (ban-keep file); the servers' memory holds it until it ends -- lift it on the dashboard's lists page, or: request-shield allow $file $address --for=1h\n";
                    } elseif ($settings->bans !== []) {
                        echo "note: a running ban ends by itself (at most " . intdiv($settings->banMax, 3600) . " h) -- to lift it at once: the dashboard's lists page, or request-shield allow $file $address --for=1h\n";
                    }
                    exit(0);
                }
                $until = $listUntil !== null ? \CjwNetwork\RequestShield\Rules\Lists::time($listUntil) : ($listFor !== null ? time() + $listFor : null);
                if ($listUntil !== null && $until === null) {
                    throw new \InvalidArgumentException("--until takes a day (2026-10-07) or a moment (2026-10-07T15:30), not \"$listUntil\"");
                }
                if ($command === 'allow' && $until === null) {
                    throw new \InvalidArgumentException('allow takes --for or --until -- an address let in for good belongs in the rule file (exempt)');
                }
                if ($command === 'deny') {
                    // Never the proxies the site trusts (not even with --force); a wide range only on purpose.
                    $why = \CjwNetwork\RequestShield\Rules\Lists::refuse($address, $settings->trustedProxies, null, $force);
                    if ($why !== null) {
                        throw new \InvalidArgumentException(str_replace('confirm if that is meant', '--force if that is meant', $why));
                    }
                }
                $id = \CjwNetwork\RequestShield\Rules\Lists::add($dir, $command === 'deny' ? 'deny' : 'exempt', $address, $until, $listReason);
                touch($file);
                echo ($command === 'deny' ? 'kept out' : 'let in') . " $address" . ($until !== null ? ' until ' . date('Y-m-d H:i', $until) : ' for good') . " ($id); every server reads it within its recheck\n";
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                self::mistake('request-shield: ' . $e->getMessage());
                exit(1);
            }
            exit(0);
        }

        if ($command === 'trace') {
            $parts = preg_split('/\s+/', trim((string) $what), 2) ?: [];
            [$method, $url] = count($parts) === 2 ? $parts : ['GET', $parts[0] ?? '/'];
            // The website from the address: its site block's rules.
            $urlHost = (string) parse_url($url, PHP_URL_HOST);
            $siteId = $settings->siteFor(['SERVER_NAME' => $urlHost, 'HTTP_HOST' => $urlHost]);
            if ($siteId !== null) {
                $settings = $siteSettings[$siteId];
                echo "site: $siteId\n";
            }
            // Only looks at the counters (APCu is the CLI's own; files are the site's).
            $t = (new Inspector($settings))->trace(Inspector::request($method, $url, $ip, $ua !== null ? ['User-Agent' => $ua] : []));
            $mark = ['pass' => '✓', 'note' => '!', 'stop' => '✕', 'skip' => '–'];
            echo strtoupper($method) . " $url from $ip\n\n";
            foreach ($t['steps'] as $st) {
                echo '  ' . $mark[$st['state']] . ' ' . str_pad($st['check'], 34) . $st['text'] . ($st['rule'] !== null ? "  [{$st['rule']}]" : '') . "\n";
            }
            echo "\nThis visitor {$t['verdict']}." . ($t['rule'] !== null ? " Decided by {$t['rule']}." : '') . "\n";
            echo $t['watched'] !== null ? "Watched: it {$t['watched']}.\n" : '';
            exit($t['decision']->passes() || in_array($settings->mode, ['off', 'monitor'], true) ? 0 : 4);     // 4: refused (a script can test for it)
        }


        if ($command === 'crawlers') {
            $dir = $settings->storeDir . '/crawlers';
            if ($what === 'update') {
                if (($offline = \CjwNetwork\RequestShield\Http::offline()) !== null) {
                    fwrite(STDERR, "request-shield: $offline\n");
                    exit(1);
                }
                // The file each list is read from now: an earlier update, else the shipped one.
                $lists = [];
                foreach (RuleFile::crawlerListFiles($read['config']) as $name => $shipped) {
                    $lists[$name] = is_file("$dir/$name.json") ? "$dir/$name.json" : $shipped;
                }
                $failed = 0;
                foreach (CrawlerLists::update($lists, $dir, null, $force) as $name => $happened) {
                    echo str_pad($name, 26) . $happened . "\n";
                    $failed += strncmp($happened, 'updated', 7) === 0 || strncmp($happened, 'unchanged', 9) === 0 ? 0 : 1;
                }
                echo "into $dir -- the servers read them on their next check\n";
                exit($failed > 0 ? 1 : 0);
            }
            echo "crawler-verify $settings->crawlerVerify\n\n";
            foreach ($settings->crawlers as $id => $x) {
                $lists = [];
                foreach ($x['lists'] as $name => $about) {
                    $lists[] = $name . ' (' . substr((string) ($about['created'] ?? '?'), 0, 10) . ', ' . ($about['from'] ?? '?') . ')';
                }
                echo str_pad($id, 24) . str_pad($x['kind'], 13) . str_pad($x['policy'], 7)
                    . ($lists !== [] ? ' ranges: ' . implode(', ', $lists) : '') . ($x['dns'] !== [] ? ' dns: ' . implode(' ', $x['dns']) : '') . "\n";
            }
            foreach (self::crawlerListsAge($settings) as $note) {
                echo "\nnote: $note";
            }
            echo "\n";
            exit(0);
        }

        $versions = $settings->origins['versions'] ?? [];
        $sets = $versions === [] ? '' : 'rule sets ' . implode(', ', array_map(static fn (string $n, string $v): string => "$n $v", array_keys($versions), $versions));

        if ($command === 'check' || $command === 'reload') {
            $warnings = 0;
            foreach (self::warnings($settings, $read, $file) as $w) {
                fwrite(STDERR, "warning: $w\n");
                $warnings++;
            }
            $files = count(array_filter(array_keys($read['seen']), static fn (string $f): bool => substr($f, -6) === '.rules' && !Shipped::isShipped($f)));
            $attacks = 0;
            foreach ($settings->contentRules as $r) {
                $attacks += count($r['patterns']);
            }
            echo "ok: $files file(s) + built-in rules, " . count($settings->blockedPaths) . ' blocked patterns, ' . count($settings->budgets) . ' budget(s)'
                . ($attacks > 0 ? ", $attacks attack patterns" : '') . ($sets !== '' ? "; $sets" : '') . "\n";
            // What this installation can do (ADR 0013): the tier, and what is not active in it.
            $tier = \CjwNetwork\RequestShield\Tier::of($settings, Settings::cacheDirFor($file));
            echo "tier: {$tier['tier']} -- " . implode('; ', $tier['why']) . "\n";
            foreach ($tier['chosen'] as $note) {
                echo "note: $note\n";                      // the settings chose it: no warning
            }
            foreach ($tier['off'] as $off) {
                fwrite(STDERR, "warning: $off\n");         // the environment leaves it inactive
                $warnings++;
            }
            if ($settings->sites !== []) {
                // site shop.a.de (a.de) · *.b.de · default -- and which name picks one.
                $byBlock = [];
                foreach ($settings->sites as $name => $siteId) {
                    $byBlock[$siteId][] = $name;
                }
                echo 'sites: ' . implode(' · ', array_map(static fn (string $siteId, array $names): string => $siteId . (count($names) > 1 ? ' (' . implode(', ', array_slice($names, 1)) . ')' : ''),
                    array_keys($byBlock), $byBlock)) . "; picked by $settings->siteFrom\n";
            }
            foreach (array_merge(self::crawlerListsAge($settings), self::sectionsPastLimit($settings)) as $note) {
                echo "note: $note";          // a note, not a warning: the exit code stays
            }
            if ($command === 'reload') {
                touch($file);
                echo "reload: $file marked changed; every server reads the rules again on its next check\n";
            }
            exit($warnings > 0 ? 3 : 0);
        }

        // show: the effective rules, as rule lines (patterns as the regular
        // expressions they became), each with its origin.
        // Each rule as a rule line: [ID] in front when it has one of its own, and as
        // the comment its description and where it is written.
        $o = static fn (string $setting, string $what): string => $settings->origin($setting, $what) ?? \CjwNetwork\RequestShield\Config::setName($what) ?? 'default';
        $line = static function (string $text, string $id = '') use ($settings): string {
            if ($id === '') {
                return $text . "\n";
            }
            $where = $settings->origin('at', $id);
            $about = $settings->origin('text', $id) ?? '';
            $rev = $settings->origin('rev', $id);
            return str_pad(($where !== null ? '[' . $id . ($rev !== null ? "@$rev" : '') . '] ' : '') . $text, 64) . ' # ' . trim($about . '  (' . ($where ?? $id) . ')') . "\n";
        };
        $regex = static fn (string $p): string => trim(preg_replace('/^#|#i?$/', '', $p) ?? $p);

        echo "# Effective rules of $file" . ($sources !== [] ? ' with ' . implode(' ', $sources) : '') . "\n";
        echo $sets !== '' ? "# $sets\n" : '';
        foreach ($settings->origins['warnings'] ?? [] as $w) {
            echo "# warning: $w\n";
        }
        if ($settings->hosts !== []) {
            echo $line('host ' . implode(' ', $settings->hosts), $o('hosts', '*'));
        }
        if ($settings->trustedProxies !== []) {
            echo $line('trust ' . implode(' ', $settings->trustedProxies));
        }
        echo $line('method none ' . implode(' ', $settings->methods), $o('methods', '*'));
        foreach ($settings->methodPaths as $m => $patterns) {
            echo $line("allow $m regex " . implode(' ', array_map($regex, $patterns)), $o('methodPaths', $m));
        }
        foreach ($settings->restricted as $r) {
            echo $line('restrict regex ' . implode(' ', array_map($regex, $r['paths'])) . ' to ' . implode(' ', $r['ips']), $o('restricted', $r['paths'][0] ?? ''));
        }
        foreach ($settings->blockedPaths as $p) {
            echo $line('block regex ' . $regex($p), $o('blockedPaths', $p));
        }
        foreach ($settings->contentRules as $r) {
            $where = strncmp((string) $r['target'], 'header:', 7) === 0 ? 'header ' . ucwords(substr((string) $r['target'], 7), '-') : $r['target'];
            foreach ($r['patterns'] as $p) {
                echo $line("block $where regex " . $regex($p), $o('contentRules', $p));
            }
        }
        foreach ($settings->blockExceptions as $x) {
            echo $line('unblock ' . ($x['patterns'] === null ? '' : 'regex ' . implode(' ', array_map($regex, $x['patterns'])) . ' ') . 'at regex '
                . implode(' ', array_map($regex, $x['paths'])) . ($x['ips'] !== [] ? ' for ' . implode(' ', $x['ips']) : ''), $o('blockExceptions', $x['paths'][0] ?? ''));
        }
        echo $settings->cacheablePaths === null ? $line('cache-path any') : '';
        foreach ($settings->cacheablePaths ?? [] as $p) {
            echo $line('cache-path regex ' . $regex($p), $o('cacheable.paths', $p));
        }
        foreach ($settings->queryParams as $q) {
            $pairs = [];
            foreach ($q['exact'] + $q['globs'] as $name => $type) {
                $pairs[] = (strncmp((string) $name, '#^', 2) === 0 ? stripslashes(str_replace('.*', '*', substr((string) $name, 2, -2))) : $name) . ' ' . (strncmp($type, '#', 1) === 0 ? '/' . substr($type, 5, -3) . '/' : $type);
            }
            $first = (string) array_key_first($q['exact'] + $q['globs']);
            echo $line('query ' . implode('  ', $pairs) . ($q['paths'] !== null ? ' at regex ' . implode(' ', array_map($regex, $q['paths'])) : ''), $o('query', $first));
        }
        echo $settings->queryStrict ? $line('query strict', $o('query', 'strict')) : '';
        echo $line('cache-query ' . ($settings->cacheableQuery === null ? 'any' : ($settings->cacheableQuery === [] ? 'none' : implode(' ', $settings->cacheableQuery))), $o('cacheable.query', '*'));
        foreach ($settings->budgets as $b) {
            echo $line("limit $b->name $b->limit/{$b->window}s" . ($b->challengeAt !== null ? " challenge-at $b->challengeAt" : '') . ($b->onDemand ? ' on-demand' : '') . ($b->earnBack ? ' on-exceeded challenge' : ''), $o('budgets', $b->name));
        }
        foreach ($settings->challenge->alwaysPaths as $p) {
            $age = $settings->challenge->alwaysMaxAge[$p] ?? null;
            echo $line('challenge regex ' . $regex($p) . ($age !== null ? " max-age {$age}s" : ''), $o('challenge.alwaysPaths', $p));
        }
        foreach ($settings->challenge->exemptPaths as $p) {
            echo $line('challenge-exempt regex ' . $regex($p), $o('challenge.exemptPaths', $p));
        }
        foreach ($settings->origins['monitor'] ?? [] as $rid => $rule) {
            echo $line("monitor $rule", (string) $rid);
        }
        echo $line('exempt ' . ($settings->exemptIps === [] ? 'none' : implode(' ', $settings->exemptIps)));
        echo $line("set mode $settings->mode");
        echo $line('# ' . count($settings->crawlers) . ' known crawlers (bin/request-shield crawlers ' . basename($file) . ')');
        foreach ($settings->origins['crawlerPolicy'] ?? [] as $key => $rid) {
            $key = (string) $key;
            echo $line((isset($settings->crawlers[$key]) ? "crawler $key " : "crawlers $key ") . ($settings->crawlerPolicy[$key] ?? '?'), (string) $rid);
        }
        echo $line("set crawler-verify $settings->crawlerVerify");
        echo $line('set pass-ttl ' . $settings->challenge->passTtl . 's');
        echo $line('set store ' . $settings->store);
        echo $line('set store-dir ' . $settings->storeDir);
        echo $line('set secret ' . ($settings->challenge->secret === null ? '(generated in store-dir)' : '(set, not shown)'));
        echo $line('set log ' . ($settings->logFile ?? '(off)'));
        echo $line("set log-level $settings->logLevel");
        echo $line("set log-ip $settings->logIp");
        echo $line('set debug-header ' . ($settings->debugHeader ? 'on' : 'off'));
        echo $line("set recheck {$read['recheck']}s");
        return 0;
    }

    /**
     * Address lists older than 30 days (by the operator's date): a site whose
     * server cannot fetch them (a DMZ) should update them elsewhere and deploy.
     *
     * @return list<string>
     */
    private static function crawlerListsAge(Settings $settings): array
    {
        $old = [];
        foreach ($settings->crawlers as $x) {
            foreach ($x['lists'] as $name => $about) {
                $t = strtotime((string) ($about['fetched'] ?? $about['created'] ?? ''));
                if ($t !== false && $t < time() - 30 * 86400) {
                    $old[$name] = substr((string) ($about['fetched'] ?? $about['created']), 0, 10);
                }
            }
        }
        return $old === [] ? [] : ['address lists fetched more than 30 days ago: ' . implode(', ', array_map(static fn (string $n, string $d): string => "$n ($d)", array_keys($old), $old))
            . " -- php bin/request-shield crawlers <main.rules> update\n"];
    }

    /**
     * Section views that went to "(other)" in the last 7 days: some hour had more
     * sections on one folder level than the statistics keep (200 each). The levels
     * above stay exact (each has its own limit); a lower stats-depth drops the level
     * that overflows.
     *
     * @return list<string>
     */
    private static function sectionsPastLimit(Settings $settings): array
    {
        if (!class_exists(\CjwNetwork\RequestShield\Stats\StatsExtension::class) || !class_exists(\CjwNetwork\RequestShield\Stats\Stats::class)) {
            return [];
        }
        $so = \CjwNetwork\RequestShield\Stats\StatsExtension::of($settings);
        if (!$so['enabled'] || !in_array('pages', $so['parts'], true) || !is_dir($settings->storeDir . '/stats')) {
            return [];
        }
        $read = \CjwNetwork\RequestShield\Stats\Stats::of($settings)->read(gmdate('Ymd', time() - 6 * 86400), gmdate('Ymd'));
        $past = 0;
        foreach ($read['days'] as $counts) {          // a day holds its hours
            foreach ($counts as $k => $n) {
                $past += preg_match('/^pd:[a-z]+\|\(other\)$/', (string) $k) === 1 ? $n : 0;
            }
        }
        return $past === 0 ? [] : [number_format($past) . ' section views in the last 7 days were past the limit of ' . (\CjwNetwork\RequestShield\Stats\Stats::TOP * 2)
            . ' sections an hour on one folder level: the deepest levels are approximate there'
            . ($so['depth'] > 1 ? ' -- set stats-depth ' . ($so['depth'] - 1) . ' if the upper ones are enough' : '') . "\n"];
    }

    /** @var list<string> the folders of the rule files the command line names */
    private static array $dirs = [];

    /**
     * A mistake on stderr, and where it is explained (0031 F.9): the feature
     * of the rule file's line it names, or the settings page -- from the
     * repository's docs, the command line has no settings to ask yet.
     */
    private static function mistake(string $message): void
    {
        $see = Help::forError($message, Help::DOCS, array_values(array_unique(self::$dirs)));
        fwrite(STDERR, $message . "\n" . ($see !== null ? '  ' . $see . "\n" : ''));
    }


    /**
     * What `check` warns about the compiled rules (the API's POST /check says the
     * same): rule files anyone could change, opened blocked paths, plugins not
     * there, the dashboard unguarded, the extensions' own warnings, feeds not in force.
     *
     * @param array{config: array<string, mixed>, seen: array<string, mixed>} $read RuleFile::read()
     * @return list<string>
     */
    public static function warnings(Settings $settings, array $read, string $file): array
    {
        $out = [];
        foreach ($settings->origins['warnings'] ?? [] as $w) {
            $out[] = "$w";
        }
        foreach (array_keys($read['seen']) as $path) {
            $perms = @fileperms($path);
            if ($perms !== false && ($perms & 0002) !== 0) {
                $out[] = "$path can be changed by anyone on this machine";
            }
        }
        foreach ($settings->blockExceptions as $n => $x) {
            if ($x['ips'] === []) {
                $out[] = ($settings->origin('blockExceptions', $x['paths'][0] ?? '') ?? "blockExceptions[$n]")
                    . ": blocked paths are open there for everyone -- add \"for <addresses>\", or make sure only admins reach it";
            }
        }
        // plugin … from <file>: a file that is not there (the rules warned at compile; here with the fix).
        foreach ($settings->pluginFiles as $class => $pluginFile) {
            if (!is_file($pluginFile)) {
                $out[] = ($settings->origin('plugins', $class) ?? 'plugins') . ": plugin $class from $pluginFile -- the file is not there; the plugin is left out until it is";
            }
        }
        // Plugins: a class that is not there, or no Plugin, is left out when the shield runs.
        foreach ($settings->plugins as $class) {
            if (!class_exists($class) || !is_subclass_of($class, \CjwNetwork\RequestShield\Plugin::class)) {
                $out[] = ($settings->origin('plugins', $class) ?? 'plugins') . ": plugin $class is not there, or is no " . \CjwNetwork\RequestShield\Plugin::class . " -- it is left out";
            }
        }
        if ($settings->sites !== [] && $settings->siteFrom === 'host' && $settings->hosts === []) {
            $out[] = "site blocks picked by the Host header (set site-from host), and no host rule -- a visitor names the website; list the names (host …), or use set site-from server-name";
        }
        // The dashboard's pages nobody guards (0031 B.6): no restrict rule covers them, no login is set up.
        $open = \CjwNetwork\RequestShield\Dashboard::unguarded($settings);
        if ($open !== []) {
            $out[] = "the dashboard's pages are open to everyone (" . implode(', ', array_slice($open, 0, 3)) . (count($open) > 3 ? ', …' : '')
                . ") -- add a rule such as \"restrict {$settings->dashboardPath}/** to <your addresses>\", or a login (dashboard-access)";
        }
        // The extensions' own warnings about the compiled settings (Extension::check(), 0031 B.4).
        foreach (\CjwNetwork\RequestShield\Rules\Vocabulary::extensions() as $extension) {
            foreach ($extension::check($settings) as $warning) {
                $out[] = "$warning";
            }
        }
        foreach (array_merge($settings->feeds, $settings->monitor !== null ? $settings->monitor->feeds : []) as $f) {
            if ($f['state'] !== 'in force') {
                $out[] = "{$f['rule']}: the feed {$f['name']} is " . ($f['file'] !== null ? "a file that cannot be read: {$f['file']} -- not used until it can"
                    : ($f['state'] === 'too old' ? 'older than feeds-max-age and not used' : 'not fetched yet') . " -- request-shield feeds $file update (cron, e.g. hourly)");
            }
        }
        return $out;
    }

}
