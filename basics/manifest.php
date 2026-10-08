<?php
// PWA manifest for TindaGo. A PHP script instead of a static .json file
// because BASE_URL is only known at request time.
require __DIR__ . '/../config/constants.php';
header('Content-Type: application/manifest+json');
echo json_encode([
    'name' => 'TindaGo',
    'short_name' => 'TindaGo',
    'description' => 'Digital wholesale marketplace para sa mga tindahan. Mas Mura. Mas Madali. Mas Malaki ang Kita.',
    'start_url' => BASICS_URL . '/index.php',
    'scope' => BASE_URL . '/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#003fab',
    'icons' => [
        ['src' => BASE_URL . '/assets/img/basics/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => BASE_URL . '/assets/img/basics/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => BASE_URL . '/assets/img/basics/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
