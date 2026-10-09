<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

use CjwNetwork\RequestShield\Extension;

/**
 * The words and set keys the extensions add to the rule file (ADR 0008).
 *
 * Static side: the registry. An extension is offered once per process --
 * the shipped ones named by the bootstrap (REQUEST_SHIELD_EXTENSIONS, loaded
 * and offered on the first lookup, so a passing request pays nothing for
 * them), a third-party one by its `plugin` line -- and the parser asks here
 * before it calls a word or a setting unknown.
 * Everything an extension registers writes into ext.<id> only; the core's
 * words and keys cannot be taken.
 *
 * Instance side: what Extension::vocabulary() gets to register with.
 */
final class Vocabulary
{
    /**
     * The feature each of the core's words and set keys belongs to (0031 F.2):
     * the id of its page in docs/features (RSF<gg>-<nn>). The feature
     * contract (tests/FeatureContractTest.php) holds every word and key to
     * one, and the generated reference names it. An extension's words belong
     * to its feature (StatsExtension: RSF06-03). Data only: never read on a
     * request.
     *
     * @var array<string, string>
     */
    public const FEATURES = [
        // the words
        'host' => 'RSF02-01', 'trust' => 'RSF01-01', 'exempt' => 'RSF01-02', 'method' => 'RSF02-01', 'allow' => 'RSF02-03', 'restrict' => 'RSF02-03',
        'block' => 'RSF02-02', 'unblock' => 'RSF02-02', 'query' => 'RSF02-05', 'cache-path' => 'RSF04-01', 'cache-query' => 'RSF04-01', 'cache-ignore' => 'RSF04-01',
        'challenge' => 'RSF03-02', 'challenge-exempt' => 'RSF03-02', 'dashboard-access' => 'RSF06-01', 'api-path' => 'RSF03-02', 'post-origin' => 'RSF02-04',
        'backend' => 'RSF06-03', 'limit' => 'RSF03-01', 'no-limit' => 'RSF03-01', 'crawler' => 'RSF01-04', 'crawlers' => 'RSF01-04', 'plugin' => 'RSF06-04',
        'site' => 'RSF05-01', 'deny' => 'RSF01-02', 'ban' => 'RSF01-02', 'feed' => 'RSF01-03', 'set' => 'RSF05-02', 'include' => 'RSF05-01', 'match' => 'RSF05-01',
        'monitor' => 'RSF05-03', 'expect' => 'RSF05-04', 'ids' => 'RSF05-05', 'version' => 'RSF05-01', 'replace' => 'RSF05-01',
        // the set keys
        'store' => 'RSF03-01', 'store-dir' => 'RSF03-01', 'ipv6-prefix' => 'RSF03-01', 'debug-header' => 'RSF05-05', 'app-challenge' => 'RSF03-04',
        'strip-untrusted-forwarded' => 'RSF01-01', 'max-uri' => 'RSF02-01', 'max-query-parameters' => 'RSF02-01', 'max-header-bytes' => 'RSF02-01',
        'secret' => 'RSF03-02', 'pass-ttl' => 'RSF03-02', 'solution-ttl' => 'RSF03-02', 'difficulty-min' => 'RSF03-02', 'difficulty-max' => 'RSF03-02',
        'cookie' => 'RSF03-02', 'solution-cookie' => 'RSF03-02', 'bind-user-agent' => 'RSF03-02', 'search-engines' => 'RSF01-04', 'dns-lookups' => 'RSF01-04',
        'recheck' => 'RSF05-01', 'site-from' => 'RSF05-01', 'lists-dir' => 'RSF01-02', 'ban-growth' => 'RSF01-02', 'ban-max' => 'RSF01-02',
        'language' => 'RSF03-02', 'home' => 'RSF03-02', 'widget-path' => 'RSF03-03', 'widget-difficulty' => 'RSF03-03', 'challenge-logo' => 'RSF03-02',
        'mode' => 'RSF05-03', 'crawler-verify' => 'RSF01-04', 'log' => 'RSF05-05', 'dashboard-path' => 'RSF06-01', 'docs-url' => 'RSF06-01', 'error-page' => 'RSF05-06', 'dashboard-session' => 'RSF06-01',
        'log-level' => 'RSF05-05', 'live' => 'RSF06-02', 'live-keep' => 'RSF06-02', 'ban-keep' => 'RSF01-02', 'feeds-max-age' => 'RSF01-03',
        'log-ip' => 'RSF05-05', 'log-max-size' => 'RSF05-05', 'file-mode' => 'RSF05-02', 'dir-mode' => 'RSF05-02', 'cache-unknown-query' => 'RSF04-01',
    ];

    /**
     * Each feature's page and what it does in one sentence (0031 F.9): id =>
     * [the page's file name after the id, a short title, the sentence in
     * English, in German]. Help renders the pages' "?" links and sentences
     * from it; the feature contract holds it to docs/features. Data only:
     * never read on a request.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const TOPICS = [
        'RSF01-01' => ['trusted-proxies', 'Trusted proxies',
            'Forwarded addresses are believed only from your own proxies, so nobody can claim another visitor\'s address.',
            'Weitergeleitete Adressen gelten nur von den eigenen Proxys, damit sich niemand als ein anderer Besucher ausgeben kann.'],
        'RSF01-02' => ['ip-lists', 'IP lists and bans',
            'Addresses kept out or let in by hand, and temporary bans for whoever keeps knocking at closed doors.',
            'Adressen, die von Hand ausgesperrt oder hereingelassen werden, und befristete Sperren für alle, die immer wieder an verschlossene Türen klopfen.'],
        'RSF01-03' => ['blocklist-feeds', 'Public blocklists',
            'Public lists of known attack addresses, fetched on a schedule, keep those addresses out or check them.',
            'Öffentliche Listen bekannter Angriffsadressen werden regelmäßig geholt und halten diese Adressen fern oder prüfen sie.'],
        'RSF01-04' => ['known-crawlers', 'Known crawlers',
            'Search engines and AI crawlers are recognised by their published addresses and let in, checked or refused.',
            'Suchmaschinen und KI-Crawler werden an ihren veröffentlichten Adressen erkannt und hereingelassen, geprüft oder abgewiesen.'],
        'RSF02-01' => ['hard-rejects', 'Hard rejects',
            'Broken, oversized and disguised requests, and unknown website names, are refused before the site starts.',
            'Kaputte, übergroße und verschleierte Anfragen sowie unbekannte Website-Namen werden abgewiesen, bevor die Website startet.'],
        'RSF02-02' => ['blocked-paths', 'Blocked paths',
            'Paths only scanners ask for, such as /.env or backups, are answered with "not found".',
            'Pfade, nach denen nur Scanner fragen, etwa /.env oder Backups, werden mit "nicht gefunden" beantwortet.'],
        'RSF02-03' => ['access-rules', 'Access rules',
            'Some areas only for some addresses, and forms only where they belong.',
            'Manche Bereiche nur für manche Adressen, und Formulare nur dort, wo sie hingehören.'],
        'RSF02-04' => ['forms-from-the-website', 'Forms from the website itself',
            'A form is accepted only when it was sent from a page of this website.',
            'Ein Formular wird nur angenommen, wenn es von einer Seite dieser Website gesendet wurde.'],
        'RSF02-05' => ['known-parameters', 'Known query parameters',
            'Only the ?parameters the site uses, each of its type, may reach it.',
            'Nur die ?Parameter, die die Website nutzt, jeweils von ihrem Typ, erreichen sie.'],
        'RSF02-06' => ['attack-patterns', 'Attack patterns',
            'SQL injection, cross-site scripting and similar attacks in the address and the headers are refused.',
            'SQL-Injection, Cross-Site-Scripting und ähnliche Angriffe in der Adresse und den Headern werden abgewiesen.'],
        'RSF03-01' => ['budgets', 'Pace per visitor',
            'How many requests one visitor may make in a time; past it, the browser check or a pause.',
            'Wie viele Anfragen ein Besucher in einer Zeit stellen darf; darüber die Browserprüfung oder eine Pause.'],
        'RSF03-02' => ['browser-challenge', 'The browser check',
            'A small task a real browser solves by itself in a moment, so programs that pretend to be one are slowed down.',
            'Eine kleine Aufgabe, die ein echter Browser in einem Moment selbst löst, damit Programme, die nur so tun, ausgebremst werden.'],
        'RSF03-03' => ['browser-check-in-the-form', 'The check inside the form',
            'The browser check runs while the visitor types, so the form goes straight through.',
            'Die Browserprüfung läuft, während der Besucher tippt, damit das Formular direkt durchgeht.'],
        'RSF03-04' => ['app-challenges', 'The site asks for the check',
            'The site asks for the browser check where it needs one, such as before saving a comment.',
            'Die Website verlangt die Browserprüfung, wo sie sie braucht, etwa bevor ein Kommentar gespeichert wird.'],
        'RSF04-01' => ['cacheable-definition', 'What a cache may keep',
            'Made-up addresses and parameters are answered, but a cache does not keep them.',
            'Ausgedachte Adressen und Parameter werden beantwortet, aber ein Cache behält sie nicht.'],
        'RSF04-03' => ['http-cache', 'The HTTP cache',
            'Public pages are answered from a cache before the application starts; never a page that may be someone\'s own.',
            'Öffentliche Seiten kommen aus einem Cache, bevor die Anwendung startet; nie eine Seite, die jemandem gehören kann.'],
        'RSF05-01' => ['rule-files', 'Rule files',
            'The shield\'s rules, one per line, in a plain text file.',
            'Die Regeln der Shield, eine pro Zeile, in einer einfachen Textdatei.'],
        'RSF05-02' => ['settings', 'Settings',
            'The settings are checked once and kept compiled, so a request reads nothing.',
            'Die Einstellungen werden einmal geprüft und kompiliert gehalten, damit eine Anfrage nichts lesen muss.'],
        'RSF05-03' => ['modes', 'Modes',
            'Watch first, enforce as written, or tighten everything for a site under attack.',
            'Erst beobachten, dann wie geschrieben durchsetzen, oder alles verschärfen, wenn die Website angegriffen wird.'],
        'RSF05-04' => ['rule-examples', 'Examples next to the rules',
            'Lines next to a rule say what should happen to a request, and request-shield test checks them.',
            'Zeilen neben einer Regel sagen, was mit einer Anfrage geschehen soll, und request-shield test prüft sie.'],
        'RSF05-05' => ['log-and-rule-ids', 'Log and rule IDs',
            'Every refusal names the rule that decided, in the log and on the pages.',
            'Jede Abweisung nennt die Regel, die entschieden hat, im Log und auf den Seiten.'],
        'RSF05-06' => ['error-pages', 'Error pages',
            'A refused visitor gets a calm page in the own language, or the site\'s own page, never the reason.',
            'Wer abgewiesen wird, bekommt eine ruhige Seite in der eigenen Sprache oder die eigene Seite der Website, nie den Grund.'],
        'RSF05-07' => ['single-file', 'The single file',
            'The whole shield in one PHP file, for hosting without Composer.',
            'Die ganze Shield in einer PHP-Datei, für Hosting ohne Composer.'],
        'RSF06-01' => ['active-rules-page', 'Rules and setup',
            'Every rule in force, how often it decided, and a tester for any address.',
            'Jede geltende Regel, wie oft sie entschieden hat, und ein Tester für jede Adresse.'],
        'RSF06-02' => ['live-and-lists', 'Live view and lists',
            'What is stopped right now and why, and the addresses kept out or let in.',
            'Was gerade gestoppt wird und warum, und die ausgesperrten oder hereingelassenen Adressen.'],
        'RSF06-03' => ['statistics', 'Statistics',
            'Visitors, crawlers, bots and pages, and what the shield did, per hour, day, week or month.',
            'Besucher, Crawler, Bots und Seiten, und was die Shield getan hat, pro Stunde, Tag, Woche oder Monat.'],
        'RSF06-04' => ['plugins', 'Plugins',
            'Code that hears every decision and adds what the core does not do.',
            'Code, der jede Entscheidung erfährt und ergänzt, was der Kern nicht tut.'],
        'RSF06-05' => ['api', 'The API',
            'The shield\'s data as JSON for a CMS, a script or a language model, guarded like the dashboard.',
            'Die Daten des Schutzes als JSON für ein CMS, ein Skript oder ein Sprachmodell, geschützt wie das Dashboard.'],
    ];

    /** @var array<string, string> the feature an extension's words and keys belong to, by the extension's id */
    public const EXTENSION_FEATURES = ['stats' => 'RSF06-03', 'api' => 'RSF06-05', 'cache' => 'RSF04-03', 'waf' => 'RSF06-01'];

    /** The feature a word or set key belongs to: the core's (FEATURES), else its extension's; null when none is known. */
    public static function featureOf(string $word): ?string
    {
        if (isset(self::FEATURES[$word])) {
            return self::FEATURES[$word];
        }
        $ext = self::wordFor($word)['id'] ?? self::settingFor($word)['id'] ?? null;
        return is_string($ext) ? self::EXTENSION_FEATURES[$ext] ?? null : null;
    }

    /** The types a set key may have; the parser checks the value like the core's own (path: relative to the rule file). */
    public const TYPES = ['bool', 'int', 'seconds', 'string', 'words', 'path'];

    /** @var array<string, class-string<Extension>> id => class */
    private static array $offered = [];

    /** The shipped extensions (REQUEST_SHIELD_EXTENSIONS) are offered: on the first lookup, or forgotten with the rest. */
    private static bool $shipped = false;

    /** @var array<string, array{id: string, parse: callable, help: string, paths: bool, serverWide: bool}>|null keyword => its owner; null: indexed on the next lookup */
    private static ?array $words = null;

    /** @var array<string, array{id: string, name: string, type: string, check: ?callable, help: string, serverWide: bool, many: bool}>|null set key => its owner */
    private static ?array $settings = null;

    /** @var array<string, array{id: string, parse: callable, help: string, paths: bool, serverWide: bool}> */
    private array $myWords = [];

    /** @var array<string, array{id: string, name: string, type: string, check: ?callable, help: string, serverWide: bool, many: bool}> */
    private array $mySettings = [];

    private function __construct(private string $id)
    {
    }

    // ── The registry ──────────────────────────────────────────────────────

    /**
     * Makes an extension's words known. Offering the same class twice is
     * nothing; a second class with the same id is a mistake that names both.
     *
     * @param class-string $class
     */
    public static function offer(string $class): void
    {
        self::shipped();
        if (!is_subclass_of($class, Extension::class)) {
            throw new \InvalidArgumentException("request-shield: $class is not an extension (it does not implement " . Extension::class . ')');
        }
        $id = $class::id();
        if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $id)) {
            throw new \InvalidArgumentException("request-shield: $class: an extension id is letters, digits and \"-\" (stats, rs-test), not \"$id\"");
        }
        if (isset(self::$offered[$id])) {
            if (self::$offered[$id] !== $class) {
                throw new \InvalidArgumentException("request-shield: two extensions with the id $id: " . self::$offered[$id] . " and $class");
            }
            return;
        }
        self::$offered[$id] = $class;
        self::$words = null;
        self::$settings = null;
    }

    /** Forgets every extension, the shipped ones too (tests re-offer what they need). */
    public static function forget(): void
    {
        self::$offered = [];
        self::$shipped = true;
        self::$words = null;
        self::$settings = null;
    }

    /** Back to the shipped extensions, offered again on the next lookup (tests, after forget()). */
    public static function reset(): void
    {
        self::forget();
        self::$shipped = false;
    }

    /** @return class-string<Extension>|null */
    public static function extension(string $id): ?string
    {
        self::shipped();
        return self::$offered[$id] ?? null;
    }

    /** @return array<string, class-string<Extension>> id => class */
    public static function extensions(): array
    {
        self::shipped();
        return self::$offered;
    }

    /**
     * The shipped extensions, offered on the first lookup: the bootstrap only
     * names them (REQUEST_SHIELD_EXTENSIONS, a list of class names) so that a
     * passing request, which never consults the registry, loads none of this.
     * A name whose class is not there (a build without plugins/stats) is skipped.
     */
    private static function shipped(): void
    {
        if (self::$shipped) {
            return;
        }
        self::$shipped = true;
        $named = defined('REQUEST_SHIELD_EXTENSIONS') ? (array) constant('REQUEST_SHIELD_EXTENSIONS') : [];
        foreach ($named as $class) {
            $class = (string) $class;
            if (class_exists($class)) {
                self::offer($class);
            }
        }
    }

    /**
     * Who owns a rule keyword the core does not have.
     *
     * @return array{id: string, parse: callable, help: string, paths: bool, serverWide: bool}|null
     */
    public static function wordFor(string $keyword): ?array
    {
        self::index();
        return self::$words[$keyword] ?? null;
    }

    /**
     * Who owns a set key the core does not have.
     *
     * @return array{id: string, name: string, type: string, check: ?callable, help: string, serverWide: bool, many: bool}|null
     */
    public static function settingFor(string $key): ?array
    {
        self::index();
        return self::$settings[$key] ?? null;
    }

    /**
     * Every word and set key the extensions add (for suggestions and the reference).
     *
     * @return array{words: list<string>, settings: list<string>}
     */
    public static function known(): array
    {
        self::index();
        return ['words' => array_keys(self::$words ?? []), 'settings' => array_keys(self::$settings ?? [])];
    }

    private static function index(): void
    {
        self::shipped();
        if (self::$words !== null) {
            return;
        }
        $words = [];
        $settings = [];
        foreach (self::$offered as $id => $class) {
            $v = new self($id);
            $class::vocabulary($v);
            foreach ($v->myWords as $keyword => $def) {
                if (isset($words[$keyword])) {
                    throw new \InvalidArgumentException("request-shield: the word $keyword is claimed by two extensions, {$words[$keyword]['id']} and $id");
                }
                $words[$keyword] = $def;
            }
            foreach ($v->mySettings as $key => $def) {
                if (isset($settings[$key])) {
                    throw new \InvalidArgumentException("request-shield: the setting $key is claimed by two extensions, {$settings[$key]['id']} and $id");
                }
                $settings[$key] = $def;
            }
        }
        self::$words = $words;
        self::$settings = $settings;
    }

    // ── What an extension registers ───────────────────────────────────────

    /**
     * A rule of its own: `<keyword> <args…>`. The parser calls $parse with the
     * line's words (environment variables resolved), the extension's values
     * so far (ext.<id>), the line's place ("site.rules:12") and the rule's id;
     * it returns the new values. A wrong line is a RuleFileException that
     * starts with the place.
     *
     * $paths: the word takes paths, like the core's challenge-exempt: the
     * parser compiles them (globs, `regex <expression>`, `none` first) to
     * patterns before $parse sees them, and inside a match block the word
     * takes no paths and gets the block's. Without it a word does not go
     * inside a match block.
     *
     * @param callable(list<string>, array<string, mixed>, string, string): array<string, mixed> $parse
     */
    public function word(string $keyword, callable $parse, string $help = '', bool $paths = false, bool $serverWide = false): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,31}$/', $keyword)) {
            throw new \InvalidArgumentException("request-shield: extension {$this->id}: a word is letters, digits and \"-\", not \"$keyword\"");
        }
        if (in_array($keyword, RuleFile::coreWords(), true)) {
            throw new \InvalidArgumentException("request-shield: extension {$this->id}: \"$keyword\" is a word of the core");
        }
        if (isset($this->myWords[$keyword])) {
            throw new \InvalidArgumentException("request-shield: extension {$this->id}: the word $keyword is registered twice");
        }
        $this->myWords[$keyword] = ['id' => $this->id, 'parse' => $parse, 'help' => $help, 'paths' => $paths, 'serverWide' => $serverWide];
    }

    /**
     * A `set <key> <value>` of its own, typed like the core's: bool (on/off),
     * int, seconds (30s, 5m, 2h), string, words (split at spaces), path
     * (relative to the rule file). The value lands in ext.<id>.<name>; $name
     * defaults to the key in camelCase without the extension's own prefix
     * (stats-depth => depth, fail-at => failAt); a dotted name goes below
     * (crawlerLog.dir). $check sees the typed value and the line's place,
     * returns the value to keep or throws a RuleFileException.
     *
     * $serverWide: about the server, not a website -- refused inside a site
     * block, as the core's store or secret. $many: $check returns several
     * values at once, name => value, each put below ext.<id> (set stats
     * on|off|<parts> writes enabled and parts).
     *
     * @param callable(mixed, string): mixed|null $check
     */
    public function set(string $key, string $type, string $help = '', ?callable $check = null, ?string $name = null, bool $serverWide = false, bool $many = false): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,31}$/', $key)) {
            throw new \InvalidArgumentException("request-shield: extension {$this->id}: a set key is letters, digits and \"-\", not \"$key\"");
        }
        if (in_array($key, RuleFile::coreSettings(), true) || strncmp($key, 'text.', 5) === 0) {
            throw new \InvalidArgumentException("request-shield: extension {$this->id}: \"set $key\" is a setting of the core");
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("request-shield: extension {$this->id}: set $key: the type is one of " . implode(', ', self::TYPES) . ", not \"$type\"");
        }
        if (isset($this->mySettings[$key])) {
            throw new \InvalidArgumentException("request-shield: extension {$this->id}: the setting $key is registered twice");
        }
        if ($many && $check === null) {
            throw new \InvalidArgumentException("request-shield: extension {$this->id}: set $key: many needs a check that returns the values");
        }
        $name ??= self::camel(strncmp($key, $this->id . '-', strlen($this->id) + 1) === 0 ? substr($key, strlen($this->id) + 1) : $key);
        $this->mySettings[$key] = ['id' => $this->id, 'name' => $name, 'type' => $type, 'check' => $check, 'help' => $help, 'serverWide' => $serverWide, 'many' => $many];
    }

    /** fail-at => failAt */
    private static function camel(string $key): string
    {
        return lcfirst(str_replace('-', '', ucwords($key, '-')));
    }
}
