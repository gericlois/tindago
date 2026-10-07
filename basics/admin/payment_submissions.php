<?php
// Payment Submissions now lives on the Payments page as the "Member
// Submissions" tab (basics/admin/payments.php). Kept so old links,
// bookmarks and dashboard cards still land in the right place.
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

redirect('/basics/admin/payments.php?' . http_build_query(['tab' => 'submissions', 'status' => $_GET['status'] ?? 'pending']));
