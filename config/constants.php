<?php
date_default_timezone_set('Asia/Manila');

// Site-wide brand name (browser tab suffix, footer copyright, admin shell,
// emails/SMS). The member-facing module identity (logo, colors, nav) lives in
// basics/includes/module.php.
define('SITE_NAME', 'TindaGo');
// Leave blank to hide the Facebook link in the footer.
define('FACEBOOK_URL', '');

/*
 * BASE_URL CONFIGURATION
 * ======================
 * Auto-detects environment:
 *   - Local (localhost / 127.0.0.1) -> '/tindago' (app runs in a subfolder)
 *   - Live  (deployed at domain root) -> '' (empty string)
 */
$host_header = $_SERVER['HTTP_HOST'] ?? '';
$is_local = (strpos($host_header, 'localhost') !== false)
         || (strpos($host_header, '127.0.0.1') !== false);
define('BASE_URL', $is_local ? '/tindago' : '');

// The app's pages live under /basics (internal folder name kept from the
// codebase this was built from); the site root redirects there.
define('BASICS_URL', BASE_URL . '/basics');

// ---------------------------------------------------------------
// Security baseline, applied to every page (this file is the first thing
// each one loads).
//  - Never show PHP/MySQL errors to visitors on the live site: uncaught
//    exceptions print file paths, SQL and sometimes DB host details. They're
//    still written to the server error log.
//  - Anti-clickjacking / MIME-sniffing / referrer-leak headers. A full
//    Content-Security-Policy is intentionally not set: the pages rely on
//    inline scripts and several CDNs, so a strict one would break them.
//  - HSTS only when the request actually arrived over HTTPS.
// ---------------------------------------------------------------
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
error_reporting(E_ALL);
if (!$is_local) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if ($is_https) {
        header('Strict-Transport-Security: max-age=15552000');
    }
    header_remove('X-Powered-By');
}

define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('UPLOAD_URL', BASE_URL . '/uploads/');

// Personal rebate rate, referral override rate, minimum cashout amount,
// Basics penalty tiers, and company email are all editable at runtime — see
// the `settings` table and the setting() helper in includes/functions.php,
// managed via admin/settings.php. Not hardcoded here.
