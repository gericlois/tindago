<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

// Whole-database backup: super admins only (same as Admin Management
// and Maintenance Mode). Lives here, inside the admin panel, so the sidebar
// and logout stay the admin panel's; the page body is admin/includes/db_backup_page.php.
require_basics_admin_role(['super_admin']);

$db_backup_url = '/basics/admin/db_backup.php';
$db_backup_sidebar = __DIR__ . '/includes/admin_sidebar.php';
$db_backup_kicker = 'TindaGo';
require __DIR__ . '/../../admin/includes/db_backup_page.php';
