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

// That is all: the rules count the searches themselves (EXP-SEARCHES, a
// budget inside the search's match block).

// Then whatever else config.php does, e.g. the HTTP cache's early exit:
// require __DIR__ . '/kernel/private/classes/httpcache/ezphttpcacheearlyexit.php';
