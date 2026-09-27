<?php
// PWA manifest for the JMC Foodies Basics module. See ../manifest.php (hub)
// for why this is a PHP script instead of a static .json file.
require __DIR__ . '/../config/constants.php';
header('Content-Type: application/manifest+json');
echo json_encode([
    'name' => 'JMC Foodies Basics',
    'short_name' => 'JMC Basics',
    'description' => 'A weekly grocery purchase line for employees of partner companies. Basic needs, everyday, for every family.',
    'start_url' => BASICS_URL . '/index.php',
    'scope' => BASE_URL . '/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#e8720c',
    'icons' => [
        ['src' => BASE_URL . '/assets/img/basics/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => BASE_URL . '/assets/img/basics/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => BASE_URL . '/assets/img/basics/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
