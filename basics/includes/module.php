<?php
// Sets the current module's identity for the shared header/navbar/footer.
// Required by every page under basics/ before requiring the shared
// includes/header.php. See wellness/includes/module.php for the parallel.
$module_name = 'JMC Foodies Basics';
$module_logo_url = is_file(__DIR__ . '/../../assets/img/basics/logo.jpg')
    ? BASE_URL . '/assets/img/basics/logo.jpg'
    : BASE_URL . '/assets/img/wellness/logo.jpg'; // falls back to the site logo until a Basics logo file is provided
$module_home_url = BASICS_URL . '/index.php';
$module_register_url = BASICS_URL . '/apply.php';
$module_footer_desc = 'A weekly grocery credit line for employees of partner companies. Basic needs, everyday, for every family.';
// Orange + green — re-themes every shared button/badge/card component for
// Basics pages via the CSS variable override in includes/header.php.
$module_primary_color = '#e8720c';
$module_secondary_color = '#2e7d32';
// Header (topbar + nav) uses the same dark green as the footer instead of
// theme.css's default white nav.
$module_header_dark = true;
// Bold sans-serif headings + squared, left-accented components instead of
// Wellness's elegant-serif, fully-rounded look — see includes/header.php.
$module_squared_ui = true;

$module_nav_items = [
    'Dashboard'         => BASICS_URL . '/dashboard.php',
    'Catalog'           => BASICS_URL . '/catalog.php',
    'My Orders'         => BASICS_URL . '/orders.php',
    'Payments'          => BASICS_URL . '/payments.php',
    'Emergency Credit'  => BASICS_URL . '/emergency_credit.php',
    'Benefits'          => BASICS_URL . '/benefits.php',
    'My Account'        => BASICS_URL . '/account.php',
];
$module_guest_nav_items = [
    'Home' => BASICS_URL . '/index.php',
];

// Maintenance mode toggle (basics/admin/maintenance.php). This file is
// required by every Basics member-facing page but no basics/admin/*.php
// page, so gating here blocks members site-wide while leaving the admin
// panel (including the toggle itself) and all of Wellness untouched.
if (isset($conn) && $conn instanceof mysqli && setting($conn, 'basics_maintenance_enabled', '0') === '1') {
    http_response_code(503);
    $page_title = 'Under Maintenance';
    require __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container py-5 text-center" style="min-height:50vh;display:flex;flex-direction:column;justify-content:center;align-items:center;">
      <i class="fas fa-screwdriver-wrench mb-3" style="font-size:3rem;color:var(--primary);"></i>
      <h1 class="stitle">We'll be right back</h1>
      <p class="sdesc mb-3">JMC Foodies Basics is temporarily down for maintenance. Please check back soon.</p>
      <p class="mb-0 small">For questions, email <a href="mailto:jmcdigital2026@gmail.com">jmcdigital2026@gmail.com</a></p>
    </div>
    </body>
    </html>
    <?php
    exit;
}
