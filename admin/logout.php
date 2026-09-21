<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_last_activity']);
session_regenerate_id(true);
redirect('/admin/login.php');
