<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

$pending_basics_applications = $conn->query("SELECT COUNT(*) AS c FROM basics_members WHERE application_status = 'pending'")->fetch_assoc()['c'];
$active_basics_members = $conn->query("SELECT COUNT(*) AS c FROM basics_members WHERE application_status = 'approved' AND membership_status = 'active'")->fetch_assoc()['c'];
$basics_orders_awaiting_approval = $conn->query("SELECT COUNT(*) AS c FROM basics_orders WHERE status = 'pending'")->fetch_assoc()['c'];
$basics_orders_awaiting_payment = $conn->query("SELECT COUNT(*) AS c FROM basics_orders o WHERE o.status IN ('confirmed', 'out_for_delivery', 'delivered')
    AND o.total_amount > (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id)")->fetch_assoc()['c'];
$basics_outstanding_total = (float) $conn->query("SELECT COALESCE(SUM(GREATEST(o.total_amount - IFNULL((SELECT SUM(amount_paid) FROM basics_payments p WHERE p.order_id = o.id), 0), 0)), 0) AS s
    FROM basics_orders o WHERE o.status IN ('confirmed', 'out_for_delivery', 'delivered')")->fetch_assoc()['s'];
$basics_revenue_this_month = (float) $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS s FROM basics_payments
    WHERE paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetch_assoc()['s'];
$pending_basics_benefit_requests = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_benefit_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$pending_basics_emergency_credit = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_emergency_credit_requests WHERE status = 'pending'")->fetch_assoc()['c'];

$recent_basics_applications = $conn->query("SELECT bm.*, u.full_name, u.username FROM basics_members bm
    JOIN basics_users u ON u.id = bm.user_id WHERE bm.application_status = 'pending' ORDER BY bm.applied_at DESC LIMIT 5");

// "Needs Attention" — things due right now, not just awaiting eventually
// (unlike the "Orders Awaiting Payment" stat tile above, which counts every
// unpaid order regardless of due date). Delivered is the only status with a
// due date at all (basics_payment_due_date() returns null otherwise), so
// only those need checking here.
$due_orders_raw = $conn->query("SELECT o.id, o.total_amount, o.delivered_at,
        (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS amount_paid
    FROM basics_orders o WHERE o.status = 'delivered'
    HAVING amount_paid < o.total_amount")->fetch_all(MYSQLI_ASSOC);
$due_payments_count = 0;
foreach ($due_orders_raw as $due_order) {
    $order_due_date = basics_payment_due_date($due_order);
    if ($order_due_date !== null && $order_due_date <= date('Y-m-d')) {
        $due_payments_count++;
    }
}

$birthdays_today_count = 0;
foreach (basics_birthday_entries($conn, 0, 0) as $birthday_entry) {
    if ($birthday_entry['days_away'] === 0) {
        $birthdays_today_count++;
    }
}

$pending_basics_payment_submissions = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_payment_submissions WHERE status = 'pending'")->fetch_assoc()['c'];

// Orders placed per day, last 14 days — bucketed by placed_at (when the
// order was actually submitted), not created_at (when its cart row first
// existed, which for a placed order can be well before it was placed).
$orders_per_day_days = 14;
$orders_per_day_raw = $conn->query("SELECT DATE(placed_at) AS d, COUNT(*) AS c
    FROM basics_orders
    WHERE status != 'draft' AND placed_at >= DATE_SUB(CURDATE(), INTERVAL " . ($orders_per_day_days - 1) . " DAY)
    GROUP BY DATE(placed_at)")->fetch_all(MYSQLI_ASSOC);
$orders_per_day_counts = array_column($orders_per_day_raw, 'c', 'd');
$orders_per_day_labels = [];
$orders_per_day_data = [];
for ($i = $orders_per_day_days - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $orders_per_day_labels[] = date('M j', strtotime($day));
    $orders_per_day_data[] = (int) ($orders_per_day_counts[$day] ?? 0);
}

$page_title = 'Dashboard';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Admin <span>Dashboard</span></h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if ($due_payments_count > 0 || $birthdays_today_count > 0 || $pending_basics_applications > 0 || $pending_basics_payment_submissions > 0): ?>
    <h2 class="h6 mb-3">Needs Attention</h2>
    <div class="row g-3 mb-4">
      <?php if ($pending_basics_applications > 0): ?>
        <div class="col-6 col-md-3">
          <a href="<?= BASE_URL ?>/basics/admin/applications.php" class="attention-card">
            <div class="attention-card-icon"><i class="fas fa-file-signature"></i></div>
            <div class="attention-card-num"><?= (int) $pending_basics_applications ?></div>
            <div class="attention-card-lbl">Pending Application<?= $pending_basics_applications === 1 ? '' : 's' ?></div>
          </a>
        </div>
      <?php endif; ?>
      <?php if ($due_payments_count > 0): ?>
        <div class="col-6 col-md-3">
          <a href="<?= BASE_URL ?>/basics/admin/payments.php" class="attention-card">
            <div class="attention-card-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="attention-card-num"><?= (int) $due_payments_count ?></div>
            <div class="attention-card-lbl">Payment<?= $due_payments_count === 1 ? '' : 's' ?> Due/Overdue</div>
          </a>
        </div>
      <?php endif; ?>
      <?php if ($pending_basics_payment_submissions > 0): ?>
        <div class="col-6 col-md-3">
          <a href="<?= BASE_URL ?>/basics/admin/payment_submissions.php" class="attention-card">
            <div class="attention-card-icon"><i class="fas fa-receipt"></i></div>
            <div class="attention-card-num"><?= (int) $pending_basics_payment_submissions ?></div>
            <div class="attention-card-lbl">Payment Submission<?= $pending_basics_payment_submissions === 1 ? '' : 's' ?></div>
          </a>
        </div>
      <?php endif; ?>
      <?php if ($birthdays_today_count > 0): ?>
        <div class="col-6 col-md-3">
          <a href="<?= BASE_URL ?>/basics/admin/birthdays.php" class="attention-card">
            <div class="attention-card-icon"><i class="fas fa-cake-candles"></i></div>
            <div class="attention-card-num"><?= (int) $birthdays_today_count ?></div>
            <div class="attention-card-lbl">Birthday<?= $birthdays_today_count === 1 ? '' : 's' ?> Today</div>
          </a>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/basics/admin/applications.php" class="stat-tile stat-tile-link"><div class="stat-num<?= $pending_basics_applications > 0 ? ' accent' : '' ?>"><?= (int) $pending_basics_applications ?></div><div class="stat-lbl">Pending Applications</div></a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/basics/admin/members.php?status=active" class="stat-tile stat-tile-link"><div class="stat-num"><?= (int) $active_basics_members ?></div><div class="stat-lbl">Active Members</div></a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/basics/admin/orders.php?status=pending" class="stat-tile stat-tile-link"><div class="stat-num<?= $basics_orders_awaiting_approval > 0 ? ' accent' : '' ?>"><?= (int) $basics_orders_awaiting_approval ?></div><div class="stat-lbl">Orders Awaiting Approval</div></a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/basics/admin/payments.php" class="stat-tile stat-tile-link"><div class="stat-num"><?= (int) $basics_orders_awaiting_payment ?></div><div class="stat-lbl">Orders Awaiting Payment</div></a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/basics/admin/payments.php" class="stat-tile stat-tile-link"><div class="stat-num accent" style="font-size:1.3rem;"><?= format_price($basics_outstanding_total) ?></div><div class="stat-lbl">Outstanding Balance</div></a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/basics/admin/payments.php" class="stat-tile stat-tile-link"><div class="stat-num accent" style="font-size:1.3rem;"><?= format_price($basics_revenue_this_month) ?></div><div class="stat-lbl">Revenue This Month</div></a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/basics/admin/benefit_requests.php" class="stat-tile stat-tile-link"><div class="stat-num<?= $pending_basics_benefit_requests > 0 ? ' accent' : '' ?>"><?= $pending_basics_benefit_requests ?></div><div class="stat-lbl">Pending Benefit Requests</div></a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/basics/admin/emergency_credit.php" class="stat-tile stat-tile-link"><div class="stat-num<?= $pending_basics_emergency_credit > 0 ? ' accent' : '' ?>"><?= $pending_basics_emergency_credit ?></div><div class="stat-lbl">Pending Emergency Credit</div></a>
    </div>
  </div>

  <?php if ($recent_basics_applications->num_rows > 0): ?>
    <h2 class="h6 mb-3">Recent Applications</h2>
    <div class="table-responsive mb-4">
      <table class="table-theme">
        <thead><tr><th>Applicant</th><th>Employer</th><th>Applied</th><th class="no-print"></th></tr></thead>
        <tbody>
        <?php while ($ba = $recent_basics_applications->fetch_assoc()): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $ba['id'] ?>"><?= sanitize($ba['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($ba['username']) ?>)</span></td>
            <td><?= sanitize($ba['employer_name']) ?></td>
            <td><?= date('M j, Y', strtotime($ba['applied_at'])) ?></td>
            <td><a href="<?= BASE_URL ?>/basics/admin/application_view.php?id=<?= (int) $ba['id'] ?>" class="btn-chip btn-chip-outline">Review</a></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <h2 class="h6 mb-3">Orders Placed, Last <?= $orders_per_day_days ?> Days</h2>
  <div class="panel-card">
    <canvas id="ordersPerDayChart" height="90"></canvas>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.1/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('ordersPerDayChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($orders_per_day_labels) ?>,
    datasets: [{
      label: 'Orders Placed',
      data: <?= json_encode($orders_per_day_data) ?>,
      backgroundColor: '#34a853',
      borderRadius: 4,
      maxBarThickness: 36
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 } }
    }
  }
});
</script>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
