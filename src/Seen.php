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
 * What the shield knows about a request beyond its parts, for plugins: the
 * website, the known crawler it names and whether its address proves it, the
 * family of a bot that says it is one. Each is worked out on first use and
 * kept: a plugin that does not ask pays nothing.
 */
final class Seen
{
    private ?string $host = null;

    private ?string $crawler = null;

    private bool $crawlerKnown = false;

    private ?bool $verified = null;

    private ?string $family = null;

    private bool $familyKnown = false;

    public function __construct(private Request $request, private Shield $shield)
    {
    }

    /** The website: the host the request names, lower case, without its port and a trailing dot. */
    public function host(): string
    {
        return $this->host ??= rtrim(strtolower((string) preg_replace('/:\d+$/', '', $this->request->host)), '.');
    }

    /** The known crawler the User-Agent names (CRAWL-…), or null; proven or not. */
    public function crawler(): ?string
    {
        if (!$this->crawlerKnown) {
            $this->crawlerKnown = true;
            $this->crawler = $this->shield->settings->crawlers === [] ? null : $this->shield->crawlers()->claims((string) $this->request->header('user-agent'));
        }
        return $this->crawler;
    }

    /** The kind of the named crawler (search, ai-search, ai-user, ai-training), or null. */
    public function crawlerKind(): ?string
    {
        $id = $this->crawler();
        return $id === null ? null : $this->shield->crawlers()->kind($id);
    }

    /** Whether the named crawler's address proves it (false: none named, or only borrowed). */
    public function verified(): bool
    {
        $id = $this->crawler();
        return $this->verified ??= $id !== null && $this->shield->crawlers()->verified($this->request->clientIp, $id);
    }

    /** The family of a bot that says it is one (curl, python, headless …), or null: a browser, or a known crawler. */
    public function botFamily(): ?string
    {
        if (!$this->familyKnown) {
            $this->familyKnown = true;
            $this->family = $this->crawler() === null ? self::family((string) $this->request->header('user-agent')) : null;
        }
        return $this->family;
    }

    /** Who came: crawlers (verified), bots (one that says so, or only borrows a crawler's name), or people. */
    public function who(): string
    {
        if ($this->crawler() !== null) {
            return $this->verified() ? 'crawlers' : 'bots';
        }
        return $this->botFamily() !== null ? 'bots' : 'people';
    }

    /**
     * A client that says it is a tool, not a browser, by family (python, curl,
     * wget, go, java, node, php, perl, headless, scrapy, other), "empty" for
     * no User-Agent -- or null.
     */
    public static function family(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return 'empty';
        }
        // One expression for all families; the first alternative that matches names it.
        $m = [];
        if (preg_match('/(?:HeadlessChrome|PhantomJS|Puppeteer|Playwright|Selenium)(*MARK:headless)|(?:python-requests|python-urllib|aiohttp|httpx|Python\/)(*MARK:python)'
            . '|(?:^curl\/)(*MARK:curl)|(?:^Wget\/)(*MARK:wget)|(?:Go-http-client)(*MARK:go)|(?:^Java\/|okhttp|Apache-HttpClient)(*MARK:java)'
            . '|(?:node-fetch|axios|undici|^got |Node\.js)(*MARK:node)|(?:GuzzleHttp|^PHP\/|Symfony HttpClient)(*MARK:php)|(?:libwww-perl|^LWP)(*MARK:perl)'
            . '|(?:Scrapy)(*MARK:scrapy)|(?:bot\b|crawler|spider|scraper|fetcher)(*MARK:other)/i', $userAgent, $m) !== 1) {
            return null;
        }
        return isset($m['MARK']) ? (string) $m['MARK'] : 'other';
    }
}
