<?php
// Sets TindaGo's identity (name, logo, colors, nav) for the shared
// header/navbar/footer. Required by every page under basics/ before
// requiring the shared includes/header.php.
$module_name = 'TindaGo';
$module_logo_url = BASE_URL . '/assets/img/basics/logo.jpg';
$module_home_url = BASICS_URL . '/index.php';
$module_register_url = BASICS_URL . '/apply.php';
$module_footer_desc = 'A weekly grocery purchase line for employees of partner companies. Basic needs, everyday, for every family.';
// TindaGo blue + orange (from the logo) — re-themes every shared button/badge/card component for
// every page via the CSS variable override in includes/header.php.
$module_primary_color = '#003fab';
$module_secondary_color = '#fb7009';
// Header (topbar + nav) uses the same deep blue as the footer instead of
// theme.css's default white nav.
$module_header_dark = true;
// Bold sans-serif headings + squared, left-accented components instead of
// the base theme's elegant-serif, fully-rounded look — see includes/header.php.
$module_squared_ui = true;

// An array value renders as a dropdown of those links (includes/navbar.php).
$module_nav_items = [
    'Catalog'           => BASICS_URL . '/catalog.php',
    'Dashboard'         => BASICS_URL . '/dashboard.php',
    'My Orders'         => BASICS_URL . '/orders.php',
    'Finance'           => [
        'Payments'       => BASICS_URL . '/payments.php',
        'Emergency Loan' => BASICS_URL . '/emergency_credit.php',
        'Benefits'       => BASICS_URL . '/benefits.php',
    ],
    'Account'           => [
        'My Account'     => BASICS_URL . '/account.php',
        'Payout Account' => BASICS_URL . '/payout_account.php',
    ],
];
$module_guest_nav_items = [
    'Home' => BASICS_URL . '/index.php',
];

// Only Community Partners get "My Earnings" — queried directly against $conn
// (rather than basics_get_member(), which isn't reliably loaded this early on
// every page) since this file runs before member data is otherwise fetched.
if (isset($conn) && $conn instanceof mysqli && function_exists('basics_is_logged_in') && basics_is_logged_in()) {
    $stmt = $conn->prepare("SELECT bm.is_community_partner FROM basics_members bm
                             JOIN basics_users u ON u.id = bm.user_id
                             WHERE u.id = ?");
    $basics_nav_user_id = basics_current_user_id();
    $stmt->bind_param('i', $basics_nav_user_id);
    $stmt->execute();
    $partner_check = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($partner_check && $partner_check['is_community_partner']) {
        $module_nav_items['Account']['My Earnings'] = BASICS_URL . '/wallet.php';
    }
}

// Maintenance mode toggle (basics/admin/maintenance.php). This file is
// required by every member-facing page but no basics/admin/*.php
// page, so gating here blocks members site-wide while leaving the admin
// panel (including the toggle itself) untouched.
if (isset($conn) && $conn instanceof mysqli && setting($conn, 'basics_maintenance_enabled', '0') === '1') {
    http_response_code(503);
    $page_title = 'Under Maintenance';
    require __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container py-5 text-center" style="min-height:50vh;display:flex;flex-direction:column;justify-content:center;align-items:center;">
      <i class="fas fa-screwdriver-wrench mb-3" style="font-size:3rem;color:var(--primary);"></i>
      <h1 class="stitle">We'll be right back</h1>
      <p class="sdesc mb-3">TindaGo is temporarily down for maintenance. Please check back soon.</p>
      <?php $maintenance_email = setting($conn, 'company_email', ''); ?>
      <?php if ($maintenance_email !== ''): ?>
        <p class="mb-0 small">For questions, email <a href="mailto:<?= sanitize($maintenance_email) ?>"><?= sanitize($maintenance_email) ?></a></p>
      <?php endif; ?>
    </div>
    </body>
    </html>
    <?php
    exit;
}
