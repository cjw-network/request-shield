<?php
// Exponential's config.php (next to index.php), read by autoload.php at the
// start of every request -- before the kernel, and before the HTTP cache's
// early exit, so cached pages are protected too.
//
// Copy the rule files to settings/request-shield/ (the web server never hands
// out settings/) and pick the main file for your admin:
//   exponential-admin-uri.rules   the admin is the siteaccess /admin
//   exponential-admin-host.rules  the admin has a host of its own

require_once __DIR__ . '/vendor/autoload.php';

use CjwNetwork\RequestShield\Shield;

Shield::protectFile(__DIR__ . '/settings/request-shield/exponential-admin-uri.rules');

// A search is counted against its own budget (EXP-SEARCHES: 10 a minute per
// visitor, then the browser check). Only a search with a text: the empty
// search form is a page like any other.
if (isset($_GET['SearchText']) && $_GET['SearchText'] !== ''
    && preg_match('#/content/(advanced)?search(/|$)#i', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH))) {
    Shield::active()?->consume('searches', answer: true);
}

// Then whatever else config.php does, e.g. the HTTP cache's early exit:
// require __DIR__ . '/kernel/private/classes/httpcache/ezphttpcacheearlyexit.php';
