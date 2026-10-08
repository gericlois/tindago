<?php
/*
 * DATABASE CONFIGURATION — EXAMPLE
 * =============================================
 * Copy this file to database.php (which is gitignored, since it holds real
 * credentials) and fill in your own values. Auto-detects environment:
 *   - Accessed via localhost / 127.0.0.1  -> local XAMPP database
 *   - Anywhere else (deployed)            -> live database
 */

$host_header = $_SERVER['HTTP_HOST'] ?? '';
$is_local = (strpos($host_header, 'localhost') !== false)
         || (strpos($host_header, '127.0.0.1') !== false);

if ($is_local) {
    // Local XAMPP
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'tindago';
} else {
    // Live (fill in with real host/credentials before deploying)
    $db_host = '';
    $db_user = '';
    $db_pass = '';
    $db_name = '';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Don't echo the exception (it contains the DB host and user).
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
} catch (mysqli_sql_exception $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('The site is temporarily unavailable. Please try again in a few minutes.');
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");
