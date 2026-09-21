<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

// Wellness admin's copy. A Basics admin who lands here (old bookmark/link) is
// sent to the copy inside the Basics admin instead of this Wellness layout.
if (!is_admin_logged_in() && basics_is_admin_logged_in()) {
    redirect('/basics/admin/db_backup.php');
}
require_admin_login();

$db_backup_url = '/admin/db_backup.php';
$db_backup_sidebar = __DIR__ . '/includes/admin_sidebar.php';
$db_backup_kicker = 'JMC Foodies Wellness';
require __DIR__ . '/includes/db_backup_page.php';
