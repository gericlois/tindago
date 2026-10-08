<?php
// Module-data-driven: each page sets $module_name/$module_logo_url/
// $module_home_url/$module_nav_items/$module_guest_nav_items/$module_register_url
// before requiring this (see basics/includes/module.php).
$module_name = $module_name ?? SITE_NAME;
$module_logo_url = $module_logo_url ?? BASE_URL . '/assets/img/basics/logo.jpg';
$module_home_url = $module_home_url ?? BASICS_URL . '/index.php';
$module_nav_items = $module_nav_items ?? [];
$module_guest_nav_items = $module_guest_nav_items ?? [];
$module_register_url = $module_register_url ?? null;
// Needed below for basics_cart_item_count() — not every page that includes
// this shared navbar also happens to load basics/includes/functions.php,
// so this navbar can't assume it's available.
require_once __DIR__ . '/../basics/includes/functions.php';
$module_is_logged_in = basics_is_logged_in();
$module_login_url = BASICS_URL . '/login.php';
$module_logout_url = BASICS_URL . '/logout.php';
?>

<nav class="navbar navbar-expand-lg" id="nav">
  <div class="container">
    <a class="navbar-brand" href="<?= sanitize($module_home_url) ?>">
      <img src="<?= sanitize($module_logo_url) ?>" alt="<?= sanitize($module_name) ?>" class="brand-logo">
    </a>
    <?php if ($module_is_logged_in && !empty($page_title)): ?>
      <span class="navbar-page-label d-lg-none"><?= sanitize($page_title) ?></span>
    <?php endif; ?>
    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navmenu">
      <i class="fas fa-bars" style="color:var(--primary);font-size:1.35rem;"></i>
    </button>
    <div class="collapse navbar-collapse" id="navmenu">
      <ul class="navbar-nav ms-auto">
        <?php if ($module_is_logged_in): ?>
          <?php foreach ($module_nav_items as $label => $url): ?>
            <?php if (is_array($url)): ?>
              <?php // No .dropdown-toggle: its ::after caret would collide with the theme's ::after hover underline on .nav-link. ?>
              <li class="nav-item dropdown">
                <a class="nav-link nav-dropdown-link" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= sanitize($label) ?> <i class="fas fa-chevron-down nav-dropdown-caret"></i></a>
                <ul class="dropdown-menu nav-dropdown-menu">
                  <?php foreach ($url as $sub_label => $sub_url): ?>
                    <li><a class="dropdown-item" href="<?= sanitize($sub_url) ?>"><?= sanitize($sub_label) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </li>
            <?php else: ?>
              <li class="nav-item"><a class="nav-link" href="<?= sanitize($url) ?>"><?= sanitize($label) ?></a></li>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach ($module_guest_nav_items as $label => $url): ?>
            <li class="nav-item"><a class="nav-link" href="<?= sanitize($url) ?>"><?= sanitize($label) ?></a></li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-2 ms-3">
        <?php if ($module_is_logged_in): ?>
          <?php $basics_cart_count = basics_cart_item_count($conn, basics_current_user_id()); ?>
          <a href="<?= BASICS_URL ?>/cart.php" class="nav-link nav-cta" id="basicsCartLink">
            <i class="fas fa-cart-shopping me-1"></i>Cart<?php if ($basics_cart_count > 0): ?><span class="nav-cart-badge" id="basicsCartBadge"><?= $basics_cart_count ?></span><?php endif; ?>
          </a>
          <?php $basics_unread_count = basics_unread_notification_count($conn, basics_current_user_id()); ?>
          <a href="<?= BASICS_URL ?>/notifications.php" class="nav-link nav-cta" title="Notifications" aria-label="Notifications<?= $basics_unread_count ? ' (' . $basics_unread_count . ' unread)' : '' ?>">
            <i class="fas fa-bell"></i><span class="d-lg-none ms-1">Notifications</span><?php if ($basics_unread_count > 0): ?><span class="nav-cart-badge"><?= $basics_unread_count > 99 ? '99+' : $basics_unread_count ?></span><?php endif; ?>
          </a>
          <a href="<?= $module_logout_url ?>" class="nav-link nav-cta"><i class="fas fa-right-from-bracket me-1"></i>Logout</a>
        <?php else: ?>
          <a href="<?= $module_login_url ?>" class="nav-link">Login</a>
          <?php if ($module_register_url): ?>
            <a href="<?= sanitize($module_register_url) ?>" class="nav-link nav-cta"><i class="fas fa-user-plus me-1"></i>Register</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
