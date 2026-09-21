<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

require_admin_login();

$user_count = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
$pending_users = $conn->query("SELECT COUNT(*) AS c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];
$pending_orders = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status IN ('pending', 'processing')")->fetch_assoc()['c'];
$pending_cashouts = $conn->query("SELECT COUNT(*) AS c FROM cashouts WHERE status = 'pending'")->fetch_assoc()['c'];
$total_rebates = (float) $conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM wallet_transactions WHERE type = 'personal_rebate'")->fetch_assoc()['s'];
$total_overrides = (float) $conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM wallet_transactions WHERE type = 'referral_override'")->fetch_assoc()['s'];
$total_cashed_out = (float) $conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM cashouts WHERE status = 'approved'")->fetch_assoc()['s'];

$recent_orders = $conn->query("SELECT o.*, u.full_name FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.created_at DESC LIMIT 5");
$recent_cashouts = $conn->query("SELECT c.*, u.full_name FROM cashouts c JOIN users u ON u.id = c.user_id ORDER BY c.created_at DESC LIMIT 5");

$page_title = 'Dashboard';
require __DIR__ . '/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">Overview</span>
    <h1 class="stitle" style="font-size:2rem;">Admin <span>Dashboard</span></h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-2">
      <div class="stat-tile"><div class="stat-num"><?= (int) $user_count ?></div><div class="stat-lbl">Total Users</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="stat-tile"><div class="stat-num<?= $pending_users > 0 ? ' accent' : '' ?>"><?= (int) $pending_users ?></div><div class="stat-lbl">Users Awaiting Approval</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="stat-tile"><div class="stat-num"><?= (int) $pending_orders ?></div><div class="stat-lbl">Orders Needing Action</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="stat-tile"><div class="stat-num"><?= (int) $pending_cashouts ?></div><div class="stat-lbl">Pending Cashouts</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="stat-tile"><div class="stat-num" style="font-size:1.3rem;"><?= format_price($total_rebates) ?></div><div class="stat-lbl">Rebates Paid</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="stat-tile"><div class="stat-num" style="font-size:1.3rem;"><?= format_price($total_overrides) ?></div><div class="stat-lbl">Overrides Paid</div></div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="stat-tile"><div class="stat-num accent" style="font-size:1.3rem;"><?= format_price($total_cashed_out) ?></div><div class="stat-lbl">Cashed Out</div></div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <h2 class="h5 mb-3">Recent Orders</h2>
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Buyer</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if ($recent_orders->num_rows === 0): ?>
            <tr><td colspan="5" class="text-muted">No orders yet.</td></tr>
          <?php endif; ?>
          <?php while ($o = $recent_orders->fetch_assoc()): ?>
            <tr>
              <td><?= sanitize($o['full_name']) ?></td>
              <td><?= format_price($o['total_amount']) ?></td>
              <td><?= sanitize(payment_method_label($o['payment_method'])) ?></td>
              <td><span class="pill pill-<?= $o['status'] ?>"><?= sanitize($o['status']) ?></span></td>
              <td><a href="<?= BASE_URL ?>/admin/order_view.php?id=<?= (int) $o['id'] ?>" class="btn-chip btn-chip-outline">View</a></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <h2 class="h5 mb-3">Recent Cashout Requests</h2>
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>User</th><th>Amount</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if ($recent_cashouts->num_rows === 0): ?>
            <tr><td colspan="4" class="text-muted">No cashout requests yet.</td></tr>
          <?php endif; ?>
          <?php while ($c = $recent_cashouts->fetch_assoc()): ?>
            <tr>
              <td><?= sanitize($c['full_name']) ?></td>
              <td><?= format_price($c['amount']) ?></td>
              <td><span class="pill pill-<?= $c['status'] ?>"><?= sanitize($c['status']) ?></span></td>
              <td><a href="<?= BASE_URL ?>/admin/cashouts.php" class="btn-chip btn-chip-outline">Manage</a></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
