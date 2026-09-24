<?php
$admin_shell_open = true;

$current_path = BASE_URL !== '' && strpos($_SERVER['REQUEST_URI'], BASE_URL) === 0
    ? substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen(BASE_URL))
    : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Detail/edit sub-pages highlight their parent list page in the sidebar.
$sub_to_parent = [
    '/basics/admin/application_view.php' => '/basics/admin/applications.php',
    '/basics/admin/member_view.php'      => '/basics/admin/members.php',
    '/basics/admin/product_edit.php'     => '/basics/admin/products.php',
    '/basics/admin/order_view.php'       => '/basics/admin/orders.php',
    '/basics/admin/delivery_receipt.php' => '/basics/admin/orders.php',
];
// Registration staff have no Members page — their profile view hangs off Users.
if (basics_admin_role() === 'staff_registration') {
    $sub_to_parent['/basics/admin/member_view.php'] = '/basics/admin/users.php';
}
$current_path = $sub_to_parent[$current_path] ?? $current_path;

$pending_basics_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_members WHERE application_status = 'pending'")->fetch_assoc()['c'];
$pending_basics_payments_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_payment_submissions WHERE status = 'pending'")->fetch_assoc()['c'];
$pending_basics_credit_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_emergency_credit_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$pending_basics_benefits_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_benefit_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$pending_basics_checking_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_orders WHERE status = 'pending'")->fetch_assoc()['c'];

// Overdue follow-ups for Payment Reminders — same "delivered, unpaid, due
// date already passed" definition as basics_orders_overdue() in
// payment_reminders.php (that function lives on the page itself, not in a
// shared include, so it's re-derived here rather than called directly).
$overdue_orders_raw = $conn->query("SELECT o.id, o.total_amount, o.delivered_at,
        (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS amount_paid
    FROM basics_orders o WHERE o.status = 'delivered'
    HAVING amount_paid < o.total_amount")->fetch_all(MYSQLI_ASSOC);
$pending_basics_overdue_count = 0;
$today_ymd = date('Y-m-d');
foreach ($overdue_orders_raw as $overdue_order) {
    $due_date = basics_payment_due_date($overdue_order);
    if ($due_date !== null && $due_date < $today_ymd) {
        $pending_basics_overdue_count++;
    }
}

$nav_groups = [
    'Overview' => [
        '/basics/admin/index.php' => ['icon' => 'fa-gauge-high', 'label' => 'Dashboard'],
    ],
    'Basics' => [
        '__people' => [
            'icon' => 'fa-users', 'label' => 'People',
            'children' => [
                '/basics/admin/applications.php' => ['icon' => 'fa-file-signature', 'label' => 'Applications', 'badge' => $pending_basics_count],
                '/basics/admin/members.php'      => ['icon' => 'fa-address-card',   'label' => 'Members'],
                '/basics/admin/users.php'        => ['icon' => 'fa-address-book',   'label' => 'Users'],
                '/basics/admin/register_member.php' => ['icon' => 'fa-user-plus',   'label' => 'Register Member'],
            ],
        ],
        '/basics/admin/products.php'    => ['icon' => 'fa-box',            'label' => 'Manage Products'],
        '/basics/admin/orders.php'       => ['icon' => 'fa-receipt',        'label' => 'Manage Orders', 'badge' => $pending_basics_checking_count],
        '/basics/admin/supplier_summary.php' => ['icon' => 'fa-truck-ramp-box', 'label' => 'Supplier Summary'],
        '__payments' => [
            'icon' => 'fa-sack-dollar', 'label' => 'Payments',
            'children' => [
                '/basics/admin/payments.php'     => ['icon' => 'fa-money-bill-wave', 'label' => 'Payments'],
                '/basics/admin/payment_reminders.php' => ['icon' => 'fa-bell',       'label' => 'Payment Reminders', 'badge' => $pending_basics_overdue_count],
                '/basics/admin/payment_submissions.php' => ['icon' => 'fa-receipt', 'label' => 'Payment Submissions', 'badge' => $pending_basics_payments_count],
                '/basics/admin/dormancy.php'     => ['icon' => 'fa-user-clock',     'label' => 'Dormancy Report'],
            ],
        ],
        '__perks' => [
            // Emergency Cash Credit and Member Benefits are staff_payments-
            // accessible; Birthday Gifts is admin/super_admin-only (see
            // basics_admin_role() checks in each page) — the role filter
            // below naturally drops Birthday Gifts for staff_payments,
            // leaving them a 2-item dropdown instead of 3.
            'icon' => 'fa-gift', 'label' => 'Member Perks',
            'children' => [
                '/basics/admin/emergency_credit.php' => ['icon' => 'fa-hand-holding-dollar', 'label' => 'Emergency Cash Credit', 'badge' => $pending_basics_credit_count],
                '/basics/admin/benefit_requests.php' => ['icon' => 'fa-hand-holding-heart', 'label' => 'Member Benefits', 'badge' => $pending_basics_benefits_count],
                '/basics/admin/birthdays.php'    => ['icon' => 'fa-cake-candles',   'label' => 'Birthday Gifts'],
            ],
        ],
        '/basics/admin/broadcast.php'    => ['icon' => 'fa-comment-sms',    'label' => 'Announcement'],
    ],
    'System' => [
        '/basics/admin/settings.php' => ['icon' => 'fa-gear', 'label' => 'Settings'],
        '__logs' => [
            'icon' => 'fa-clock-rotate-left', 'label' => 'Logs',
            'children' => [
                '/basics/admin/activity_log.php' => ['icon' => 'fa-clock-rotate-left', 'label' => 'Activity Log'],
                '/basics/admin/communication_log.php' => ['icon' => 'fa-comments', 'label' => 'Communication Log'],
            ],
        ],
    ],
];

// Staff Management (orders / payments / registration staff) is open to admin
// and super_admin; the three items below it are super_admin-only.
if (in_array(basics_admin_role(), ['super_admin', 'admin'], true)) {
    $nav_groups['System']['/basics/admin/staff.php'] = ['icon' => 'fa-users-gear', 'label' => 'Staff Management'];
}

// Database Backup, Admin Management, and Maintenance Mode touch the whole
// database or the admin roster itself — kept super_admin-only, unlike
// everything else in "System" which the (lower-privilege) admin role can
// also see.
if (basics_admin_role() === 'super_admin') {
    $nav_groups['System']['__super_admin'] = [
        'icon' => 'fa-shield-halved', 'label' => 'Super Admin',
        'children' => [
            '/basics/admin/db_backup.php' => ['icon' => 'fa-database', 'label' => 'Database Backup'],
            '/basics/admin/admins.php' => ['icon' => 'fa-user-shield', 'label' => 'Admin Management'],
            '/basics/admin/maintenance.php' => ['icon' => 'fa-power-off', 'label' => 'Maintenance Mode'],
        ],
    ];
}

// staff_orders, staff_payments and staff_registration are restricted roles (see
// require_basics_admin_role() in includes/auth.php) — only show each the
// pages it can actually open, so the nav doesn't dangle links that just
// bounce back to its landing page.
$staff_role_paths = [
    'staff_orders' => [
        '/basics/admin/applications.php', '/basics/admin/products.php',
        '/basics/admin/orders.php', '/basics/admin/supplier_summary.php',
    ],
    'staff_payments' => [
        '/basics/admin/payments.php', '/basics/admin/payment_reminders.php',
        '/basics/admin/payment_submissions.php', '/basics/admin/emergency_credit.php',
        '/basics/admin/benefit_requests.php', '/basics/admin/dormancy.php',
    ],
    'staff_registration' => [
        '/basics/admin/users.php', '/basics/admin/register_member.php',
    ],
];
if (isset($staff_role_paths[basics_admin_role()])) {
    $allowed_paths = $staff_role_paths[basics_admin_role()];
    foreach ($nav_groups as $group_label => $group_items) {
        $filtered = [];
        foreach ($group_items as $path => $item) {
            if (isset($item['children'])) {
                // A dropdown group: keep only its allowed children. If just
                // one survives, drop down to a plain link instead of a
                // single-item dropdown.
                $children = array_intersect_key($item['children'], array_flip($allowed_paths));
                if (count($children) === 1) {
                    $filtered[array_key_first($children)] = reset($children);
                } elseif ($children) {
                    $item['children'] = $children;
                    $filtered[$path] = $item;
                }
            } elseif (in_array($path, $allowed_paths, true)) {
                $filtered[$path] = $item;
            }
        }
        if (empty($filtered)) {
            unset($nav_groups[$group_label]);
        } else {
            $nav_groups[$group_label] = $filtered;
        }
    }
}

$basics_dashboard_url = basics_admin_landing_url();
?>
<div class="admin-shell">
  <input type="checkbox" id="adminSidebarToggle" class="admin-sidebar-toggle-input">
  <label for="adminSidebarToggle" class="admin-sidebar-backdrop"></label>
  <div class="admin-sidebar" id="adminSidebar">
    <div class="offcanvas-header d-lg-none">
      <div class="d-flex align-items-center gap-2">
        <div class="brand-logo-box">
          <img src="<?= BASE_URL ?>/assets/img/basics/logo.jpg" alt="JMC Foodies Basics" class="brand-logo" style="height:30px;">
        </div>
        <span class="admin-sidebar-brand-name">JMC Foodies Basics</span>
      </div>
      <label for="adminSidebarToggle" class="btn-close btn-close-white" aria-label="Close"></label>
    </div>
    <div class="offcanvas-body admin-sidebar-body">
      <a href="<?= BASE_URL . $basics_dashboard_url ?>" class="admin-sidebar-brand d-none d-lg-flex">
        <div class="brand-logo-box">
          <img src="<?= BASE_URL ?>/assets/img/basics/logo.jpg" alt="JMC Foodies Basics" class="brand-logo" style="height:34px;">
        </div>
        <span class="admin-sidebar-brand-name">JMC Foodies Basics</span>
      </a>
      <nav class="admin-sidebar-nav">
        <?php foreach ($nav_groups as $group_label => $group_items): ?>
          <div class="admin-nav-group-label"><?= sanitize($group_label) ?></div>
          <?php foreach ($group_items as $path => $item): ?>
            <?php if (!empty($item['children'])): ?>
              <?php
                $child_active = array_key_exists($current_path, $item['children']);
                $badge_total = array_sum(array_column($item['children'], 'badge'));
              ?>
              <details class="admin-nav-dropdown" <?= $child_active ? 'open' : '' ?>>
                <summary class="admin-nav-link admin-nav-dropdown-toggle <?= $child_active ? 'active' : '' ?>">
                  <i class="fas <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
                  <?php if ($badge_total > 0): ?><span class="admin-nav-badge"><?= (int) $badge_total ?></span><?php endif; ?>
                  <i class="fas fa-chevron-down admin-nav-dropdown-caret"></i>
                </summary>
                <div class="admin-nav-submenu">
                  <?php foreach ($item['children'] as $child_path => $child): ?>
                    <a class="admin-nav-link admin-nav-sublink <?= $current_path === $child_path ? 'active' : '' ?>" href="<?= BASE_URL . $child_path ?>">
                      <i class="fas <?= $child['icon'] ?>"></i> <?= $child['label'] ?>
                      <?php if (!empty($child['badge'])): ?><span class="admin-nav-badge"><?= (int) $child['badge'] ?></span><?php endif; ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              </details>
            <?php else: ?>
              <a class="admin-nav-link <?= $current_path === $path ? 'active' : '' ?>" href="<?= BASE_URL . $path ?>">
                <i class="fas <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
                <?php if (!empty($item['badge'])): ?><span class="admin-nav-badge"><?= (int) $item['badge'] ?></span><?php endif; ?>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </nav>
      <div class="admin-sidebar-bottom">
        <a class="admin-nav-link" href="<?= BASE_URL ?>/basics/index.php" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i> View Site</a>
        <a class="admin-nav-link" href="<?= BASE_URL ?>/basics/admin/logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
  </div>

  <div class="admin-main">
    <div class="admin-topbar d-lg-none">
      <label for="adminSidebarToggle" class="admin-topbar-toggle" aria-controls="adminSidebar">
        <i class="fas fa-bars"></i>
      </label>
      <div class="brand-logo-box">
        <img src="<?= BASE_URL ?>/assets/img/basics/logo.jpg" alt="JMC Foodies Basics" class="brand-logo" style="height:28px;">
      </div>
    </div>
    <div class="admin-content">
