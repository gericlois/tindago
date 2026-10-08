<?php
// TindaGo lives under /basics — the site root just forwards there.
require __DIR__ . '/config/constants.php';
header('Location: ' . BASICS_URL . '/index.php', true, 302);
exit;
