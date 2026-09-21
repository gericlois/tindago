<?php
// Module-data-driven: each page sets $module_name/$module_logo_url/
// $module_home_url/$module_nav_items/$module_guest_nav_items/$module_register_url
// before requiring this (see wellness/includes/module.php,
// basics/includes/module.php) — unset on neutral pages (hub, login), which
// fall back to the JMC Digital umbrella defaults below.
$module_name = $module_name ?? SITE_NAME;
$module_logo_url = $module_logo_url ?? BASE_URL . '/assets/img/wellness/logo.jpg';
$module_home_url = $module_home_url ?? BASE_URL . '/index.php';
$module_nav_items = $module_nav_items ?? [];
$module_guest_nav_items = $module_guest_nav_items ?? [];
$module_register_url = $module_register_url ?? null;
// Wellness and Basics have fully separate login sessions — this navbar is
// shared markup for both, so it checks whichever one applies to the current
// page instead of a single global is_logged_in().
// Needed below for basics_cart_item_count() — not every page that includes
// this shared navbar also happens to load basics/includes/functions.php
// (e.g. change_password.php), so this navbar can't assume it's available.
if ($module_name === 'JMC Foodies Basics') {
    require_once __DIR__ . '/../basics/includes/functions.php';
}
$module_is_logged_in = $module_name === 'JMC Foodies Basics' ? basics_is_logged_in() : is_logged_in();
$module_login_url = $module_name === 'JMC Foodies Basics' ? BASICS_URL . '/login.php' : BASE_URL . '/login.php';
$module_logout_url = $module_name === 'JMC Foodies Basics' ? BASICS_URL . '/logout.php' : BASE_URL . '/logout.php';
?>
<?php // The top strip used to carry the company email; now it only holds the
      // Wellness rebate tag, so other modules (Basics) don't render it at all. ?>
<?php if ($module_name === 'JMC Foodies Wellness'): ?>
<div id="topbar">
  <div class="container">
    <div class="d-flex justify-content-end align-items-center flex-wrap gap-2">
      <div class="d-flex align-items-center gap-3">
        <span class="ttag"><i class="fas fa-percent me-1"></i><?= (int) ((float) setting($conn, 'personal_rebate_rate', 0.20) * 100) ?>% Personal Rebate</span>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

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
            <li class="nav-item"><a class="nav-link" href="<?= sanitize($url) ?>"><?= sanitize($label) ?></a></li>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach ($module_guest_nav_items as $label => $url): ?>
            <li class="nav-item"><a class="nav-link" href="<?= sanitize($url) ?>"><?= sanitize($label) ?></a></li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-2 ms-3">
        <?php if ($module_is_logged_in): ?>
          <?php if ($module_name === 'JMC Foodies Basics'): ?>
            <?php $basics_cart_count = basics_cart_item_count($conn, basics_current_user_id()); ?>
            <a href="<?= BASICS_URL ?>/cart.php" class="nav-link nav-cta" id="basicsCartLink">
              <i class="fas fa-cart-shopping me-1"></i>Cart<?php if ($basics_cart_count > 0): ?><span class="nav-cart-badge" id="basicsCartBadge"><?= $basics_cart_count ?></span><?php endif; ?>
            </a>
          <?php endif; ?>
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
