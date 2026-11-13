<?php
// Apache's ErrorDocument for missing pages (see .htaccess) — the same plain
// "404 Not Found" the hidden admin panel answers with, so the two can't be
// told apart.
require __DIR__ . '/config/constants.php';
$status_code = 404;
require __DIR__ . '/basics/includes/404.php';
