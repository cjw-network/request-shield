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
 * What visitors read -- the browser check, a pause, "not found" -- in their
 * language: the one their browser asks for (Accept-Language) among those
 * there are texts for, else English. Built in: English and German; a site
 * adds or changes texts per language ("set text.de.title ...") or for all
 * languages at once ("set text.title ...").
 *
 * Only used for the answers the shield gives itself, never on a passing
 * request.
 */
final class Texts
{
    /** The keys a site can set. */
    public const KEYS = ['title', 'text', 'noscript', 'nocookies', 'failed', 'sending', 'send-again', 'resend-lost', 'back', 'home', 'widget-checking', 'widget-checked', 'widget-failed', 'spent', 'about',
        'bad-request', 'no-access', 'not-found', 'not-allowed', 'too-long', 'too-many', 'too-large', 'error',
        'bad-request-text', 'no-access-text', 'not-found-text', 'not-allowed-text', 'too-long-text', 'too-many-text', 'too-large-text', 'error-text', 'reference'];

    public const BUILT_IN = [
        'en' => [
            'title' => 'One moment, please',
            'text' => 'Your browser is being checked. This takes a moment and happens only once.',
            'noscript' => 'Please enable JavaScript to continue.',
            'nocookies' => 'Please allow cookies for this site to continue.',
            'failed' => 'The check did not succeed. Please reload the page.',
            'sending' => 'Your browser is being checked; then what you entered is sent. This takes a moment.',
            'send-again' => 'Send again',
            'resend-lost' => 'For your protection, your browser had to be checked first. Please go back and send the form again.',
            'back' => 'Back to the form',
            'home' => 'To the home page',
            'widget-checking' => 'Checking your browser …',
            'widget-checked' => 'Browser checked',
            'widget-failed' => 'Your browser will be checked when you send the form.',
            'about' => 'What is this check?',
            'spent' => 'You sent many requests in a short time. After a quick check of your browser you can go on.',
            'bad-request' => 'Bad request',
            'no-access' => 'No access',
            'not-found' => 'Not found',
            'not-allowed' => 'Not here',
            'too-long' => 'Address too long',
            'too-many' => 'Too many requests',
            'too-large' => 'Request too large',
            'error' => 'Error',
            // The error pages' sentences (0030): what to do, never why.
            'bad-request-text' => 'The address could not be read.',
            'no-access-text' => 'This address is not open to you.',
            'not-found-text' => 'This address does not exist here.',
            'not-allowed-text' => 'This kind of request is not taken at this address.',
            'too-long-text' => 'The address is longer than this site takes.',
            'too-many-text' => 'Please wait %s seconds, then try again.',
            'too-large-text' => 'The request carries more than this site takes.',
            'error-text' => 'This request could not be answered.',
            'reference' => 'Reference',
        ],
        'de' => [
            'title' => 'Einen Moment, bitte',
            'text' => 'Ihr Browser wird geprüft. Das dauert nur einen Moment und geschieht nur einmal.',
            'noscript' => 'Bitte aktivieren Sie JavaScript, um fortzufahren.',
            'nocookies' => 'Bitte erlauben Sie Cookies für diese Website, um fortzufahren.',
            'failed' => 'Die Prüfung ist nicht gelungen. Bitte laden Sie die Seite neu.',
            'sending' => 'Ihr Browser wird geprüft, danach wird gesendet, was Sie eingegeben haben. Das dauert nur einen Moment.',
            'send-again' => 'Erneut senden',
            'resend-lost' => 'Zu Ihrem Schutz musste Ihr Browser zuerst geprüft werden. Bitte gehen Sie zurück und senden Sie das Formular noch einmal.',
            'back' => 'Zurück zum Formular',
            'home' => 'Zur Startseite',
            'widget-checking' => 'Ihr Browser wird geprüft …',
            'widget-checked' => 'Browser geprüft',
            'widget-failed' => 'Ihr Browser wird beim Senden geprüft.',
            'about' => 'Was ist diese Prüfung?',
            'spent' => 'Sie haben in kurzer Zeit viele Anfragen gesendet. Nach einer kurzen Prüfung Ihres Browsers geht es weiter.',
            'bad-request' => 'Ungültige Anfrage',
            'no-access' => 'Kein Zugriff',
            'not-found' => 'Nicht gefunden',
            'not-allowed' => 'Hier nicht erlaubt',
            'too-long' => 'Adresse zu lang',
            'too-many' => 'Zu viele Anfragen',
            'too-large' => 'Anfrage zu groß',
            'error' => 'Fehler',
            'bad-request-text' => 'Die Adresse konnte nicht gelesen werden.',
            'no-access-text' => 'Diese Adresse ist für Sie nicht geöffnet.',
            'not-found-text' => 'Diese Adresse gibt es hier nicht.',
            'not-allowed-text' => 'Diese Art von Anfrage wird unter dieser Adresse nicht angenommen.',
            'too-long-text' => 'Die Adresse ist länger, als diese Website annimmt.',
            'too-many-text' => 'Bitte warten Sie %s Sekunden und versuchen Sie es dann noch einmal.',
            'too-large-text' => 'Die Anfrage ist größer, als diese Website annimmt.',
            'error-text' => 'Diese Anfrage konnte nicht beantwortet werden.',
            'reference' => 'Referenz',
        ],
    ];

    private const STATUS = [400 => 'bad-request', 403 => 'no-access', 404 => 'not-found', 405 => 'not-allowed',
        414 => 'too-long', 429 => 'too-many', 431 => 'too-large'];

    /**
     * The language to answer in: $setting when it names one, else the best
     * of Accept-Language among the languages there are texts for, else "en".
     *
     * @param array<string, string> $own the site's texts ("title", "de.title", "fr.title")
     */
    public static function language(string $setting, ?string $accept, array $own = []): string
    {
        if ($setting !== 'auto') {
            return $setting;
        }
        $available = self::BUILT_IN;
        foreach (array_keys($own) as $key) {
            $dot = strrpos($key, '.');
            if ($dot !== false) {
                $available[substr($key, 0, $dot)] = [];
            }
        }
        $best = 'en';
        $bestQ = 0.0;
        foreach (explode(',', (string) $accept) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));
            $q = 1.0;
            if (isset($bits[1]) && preg_match('/q=([0-9.]+)/', $bits[1], $m)) {
                $q = (float) $m[1];
            }
            $q -= $i * 0.0001;          // equal weights: the first wins
            foreach ([$tag, explode('-', $tag)[0]] as $try) {
                if ($try !== '' && isset($available[$try]) && $q > $bestQ) {
                    [$best, $bestQ] = [$try, $q];
                    break;
                }
            }
        }
        return $best;
    }

    /**
     * Every text in one language: the site's own for that language, else its
     * own for all languages, else the built-in one, else English.
     *
     * @param array<string, string> $own
     * @return array<string, string> key => text, and "lang"
     */
    public static function all(string $lang, array $own = []): array
    {
        $base = explode('-', $lang)[0];
        $out = ['lang' => $lang];
        foreach (self::KEYS as $key) {
            $out[$key] = $own["$lang.$key"] ?? $own["$base.$key"] ?? $own[$key]
                ?? self::BUILT_IN[$lang][$key] ?? self::BUILT_IN[$base][$key] ?? self::BUILT_IN['en'][$key];
        }
        return $out;
    }

    /**
     * The heading of a status page (404: "Not Found", "Nicht gefunden").
     *
     * @param array<string, string> $texts
     */
    public static function status(int $status, array $texts): string
    {
        return $texts[self::key($status)] ?? 'Error';
    }

    /**
     * A status's sentence for the error page (0030): what to do, never why.
     *
     * @param array<string, string> $texts
     */
    public static function sentence(int $status, array $texts): string
    {
        return $texts[self::key($status) . '-text'] ?? $texts['error-text'] ?? '';
    }

    /** The key of a status's texts: not-found, too-many …; error for any other. */
    public static function key(int $status): string
    {
        return self::STATUS[$status] ?? 'error';
    }
}
