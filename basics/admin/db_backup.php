<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

// Whole-database backup: Basics super admins only (same as Admin Management
// and Maintenance Mode). Lives here, inside the Basics admin, so the sidebar
// and logout stay Basics'; the page body is shared with the Wellness admin.
require_basics_admin_role(['super_admin']);

$db_backup_url = '/basics/admin/db_backup.php';
$db_backup_sidebar = __DIR__ . '/includes/admin_sidebar.php';
$db_backup_kicker = 'JMC Foodies Basics';
require __DIR__ . '/../../admin/includes/db_backup_page.php';
