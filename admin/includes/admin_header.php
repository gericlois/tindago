<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? sanitize($page_title) . ' - Admin - ' . SITE_NAME : 'Admin - ' . SITE_NAME ?></title>
<?php $is_basics_admin_page = strpos($_SERVER['REQUEST_URI'], '/basics/admin/') !== false; ?>
<?php $admin_icon_dir = BASE_URL . '/assets/img/basics/icons'; ?>
<link rel="icon" type="image/png" sizes="192x192" href="<?= $admin_icon_dir ?>/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<?php if ($is_basics_admin_page): ?>
<!-- DataTables (search/sort/print) for every table on the admin side -->
<link href="https://cdn.datatables.net/1.13.11/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.3/css/buttons.bootstrap5.min.css" rel="stylesheet">
<?php endif; ?>
<link href="<?= BASE_URL ?>/assets/css/theme.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/theme.css') ?>" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
