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
