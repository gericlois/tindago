<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';

unset($_SESSION['basics_admin_id'], $_SESSION['basics_admin_name'], $_SESSION['basics_admin_role'], $_SESSION['basics_admin_last_activity']);
session_regenerate_id(true);
redirect('/basics/admin/login.php');
