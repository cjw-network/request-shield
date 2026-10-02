<?php
// For PHP's built-in server: every path goes to index.php, as Exponential's
// rewrite rules would send it -- otherwise the built-in server would answer
// /settings/site.ini and the like itself, and the shield would never see them.
//
//   php -S 127.0.0.1:8095 examples/exponential/router.php
require __DIR__ . '/index.php';
