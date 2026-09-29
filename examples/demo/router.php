<?php
// For PHP's built-in server: every path goes to index.php, as a web server's
// rewrite rules would send it -- otherwise the built-in server would answer
// /.env and the like itself, and the shield would never see them.
//
//   php -S 127.0.0.1:8080 examples/demo/router.php
require __DIR__ . '/index.php';
