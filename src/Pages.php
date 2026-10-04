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
 * A capability on a Plugin (0031 B.10): it draws the pages the shield answers
 * with itself -- the refusal page (error), the browser check (challenge) and
 * the dashboard's login form (access-login) -- in the site's own look. Asked
 * only when the shield answers itself; a passing request pays nothing. The
 * headers stay the core's (status, no-store, noindex, the cookies); the
 * plugin returns the whole document and escapes what it embeds. null means
 * "the core's page"; a plugin that throws is left out and noted once a
 * minute, the core's page goes out.
 *
 * Recorded into the compiled settings (`$s->hooks['pages']`) when the rules
 * are compiled. Proposal 0030 (the quiet error page) is its first consumer.
 */
interface Pages
{
    /** The refusal page: ctx status, decision, reason, retryAfter, texts (the visitor's language), lang, home, request. */
    public const ERROR = 'error';

    /** The browser check: ctx challenge (the task), field (the solution cookie's name), secure, texts, lang, resend, home, logo, about (the check in plain words, for visitors: a URL, or null with set docs-url off). */
    public const CHALLENGE = 'challenge';

    /** The dashboard's login form: ctx status, message, action, lang, texts, home, homeLabel, title. */
    public const ACCESS_LOGIN = 'access-login';

    /**
     * @param string $kind one of the constants
     * @param array<string, mixed> $ctx what the core's page is made of (see the constants)
     * @return ?string the whole HTML document, or null for the core's page
     */
    public function page(string $kind, array $ctx): ?string;
}
