<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_access($conn);

$member = basics_get_member($conn, basics_current_user_id());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_birthday_gift') {
    basics_claim_birthday_gift($conn, $member);
    redirect('/basics/dashboard.php');
}

$birthday = basics_birthday_gift_status($conn, $member);
$outstanding = basics_outstanding_balance($conn, $member['id']);
$available = basics_credit_available($conn, $member);

$stmt = $conn->prepare("SELECT o.* FROM basics_orders o
                         WHERE o.member_id = ? ORDER BY o.created_at DESC LIMIT 5");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$recent_orders = $stmt->get_result();

$page_title = 'Dashboard';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Your Overview</span>
    <h1 class="stitle">Welcome, <span><?= sanitize($member['full_name']) ?></span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <?php if ($birthday): ?>
    <div class="panel-card mb-4 text-center" style="border-left:6px solid var(--secondary);">
      <i class="fas fa-cake-candles mb-2" style="font-size:2.4rem;color:var(--primary);"></i>
      <h2 class="h4 mb-2">Happy Birthday, <?= sanitize(basics_birthday_first_name($member)) ?>!</h2>
      <?php if ($birthday['claimed_at']): ?>
        <?php if ($birthday['order_status'] === 'delivered'): ?>
          <p class="mb-3">Your Birthday Grocery Gift was delivered on <?= date('M j, Y', strtotime($birthday['order_delivered_at'])) ?>. Enjoy!</p>
        <?php elseif ($birthday['order_status'] === 'cancelled'): ?>
          <p class="mb-3">Your Birthday Grocery Gift request was cancelled.</p>
        <?php else: ?>
          <p class="mb-3">Your Birthday Grocery Gift request was received on <?= date('M j, Y', strtotime($birthday['claimed_at'])) ?>. Our team will review it and get it ready for you.</p>
        <?php endif; ?>
        <?php if ($birthday['order_id']): ?>
          <a href="<?= BASICS_URL ?>/order_view.php?id=<?= (int) $birthday['order_id'] ?>" class="btn-red justify-content-center"><i class="fas fa-gift"></i>View Gift Order Status</a>
        <?php endif; ?>
      <?php else: ?>
        <p class="mb-3">
          <?= $birthday['is_today'] ? 'Everyone at JMC Foodies Basics is celebrating with you today.' : 'We hope you had a wonderful birthday.' ?>
          Your <strong>Birthday Grocery Gift</strong> is waiting for you<?= $birthday['is_today'] ? '' : ' &mdash; claim it by ' . date('M j, Y', strtotime($birthday['claim_by'])) ?>.
        </p>
        <form method="post">
          <input type="hidden" name="action" value="claim_birthday_gift">
          <button type="submit" class="btn-red justify-content-center" onclick="return confirm('Claim your Birthday Grocery Gift?');"><i class="fas fa-gift"></i>Claim My Birthday Gift</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($member['credit_limit_frozen']): ?>
    <div class="errmsg mb-4">
      <p class="mb-0"><i class="fas fa-triangle-exclamation me-1"></i>Your credit limit is currently frozen due to a late payment. It will unfreeze once your payment performance improves.</p>
    </div>
  <?php endif; ?>
  <?php if ($member['consecutive_on_time_payments'] >= 12): ?>
    <div class="sucmsg is-visible mb-4"><p><i class="fas fa-star me-1"></i>You've made 12+ consecutive on-time payments &mdash; you may be eligible for a higher credit limit. Contact support to ask about an increase.</p></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Sidebar: credit summary -->
    <div class="col-12 col-lg-4">
      <div class="row g-3 mb-3">
        <div class="col-6 col-lg-12">
          <div class="stat-tile">
            <div class="stat-num"><?= format_price($member['weekly_credit_limit']) ?></div>
            <div class="stat-lbl">Weekly Credit Limit</div>
          </div>
        </div>
        <div class="col-6 col-lg-12">
          <div class="stat-tile">
            <div class="stat-num accent"><?= format_price($available) ?></div>
            <div class="stat-lbl">Available Credit</div>
          </div>
        </div>
        <div class="col-6 col-lg-12">
          <div class="stat-tile">
            <div class="stat-num"><?= format_price($outstanding) ?></div>
            <div class="stat-lbl">Outstanding Balance</div>
          </div>
        </div>
        <div class="col-6 col-lg-12">
          <div class="stat-tile">
            <div class="stat-num"><?= (int) $member['consecutive_on_time_payments'] ?></div>
            <div class="stat-lbl">On-Time Payments</div>
          </div>
        </div>
      </div>

    </div>

    <!-- Main content: recent orders, benefits -->
    <div class="col-12 col-lg-8">
      <div class="panel-card mb-4">
        <h2 class="h6 mb-3">Recent Orders</h2>
        <div class="table-responsive">
          <table class="table-theme">
            <thead><tr><th>Order #</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php if ($recent_orders->num_rows === 0): ?>
              <tr><td colspan="4" class="text-muted">No orders yet. <a href="<?= BASICS_URL ?>/catalog.php">Browse the catalog</a>.</td></tr>
            <?php endif; ?>
            <?php while ($o = $recent_orders->fetch_assoc()): ?>
              <tr>
                <td>#<?= (int) $o['id'] ?></td>
                <td><?= format_price($o['total_amount']) ?></td>
                <td><span class="pill pill-<?= basics_order_status_pill($o['status']) ?>"><?= basics_order_status_label($o['status']) ?></span></td>
                <td><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel-card text-center">
        <h2 class="h6 mb-3">Member Benefits</h2>
        <img src="<?= BASE_URL ?>/assets/img/basics/JMCBasics_catalog.jpg" alt="JMC Foodies Basics membership benefits" class="highlight-poster mb-3" style="max-width:600px;">
        <div>
          <a href="<?= BASICS_URL ?>/benefits.php" class="btn-red justify-content-center"><i class="fas fa-hand-holding-heart"></i>Request Assistance</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
