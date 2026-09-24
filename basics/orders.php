<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_access($conn);

$member = basics_get_member($conn, basics_current_user_id());

$stmt = $conn->prepare("SELECT o.* FROM basics_orders o
                         WHERE o.member_id = ? AND o.status != 'draft'
                         ORDER BY o.created_at DESC");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$orders = $stmt->get_result();

$page_title = 'My Orders';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Order History</span>
    <h1 class="stitle">My <span>Orders</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <?php if (isset($_GET['placed'])): ?>
    <div class="sucmsg is-visible"><p>Order placed! We'll notify you once it's confirmed, and your balance will be due 7 days after it's delivered.</p></div>
  <?php endif; ?>

  <div class="panel-card">
    <h2 class="h6 mb-3">Order History</h2>
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>Order #</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php if ($orders->num_rows === 0): ?>
          <tr><td colspan="5" class="text-muted">No orders yet. <a href="<?= BASICS_URL ?>/catalog.php">Browse the catalog</a>.</td></tr>
        <?php endif; ?>
        <?php while ($order = $orders->fetch_assoc()): ?>
          <tr>
            <td>#<?= (int) $order['id'] ?></td>
            <td><?= format_price($order['total_amount']) ?><?php if ($order['is_gift']): ?> <span class="text-muted small">(Gift)</span><?php endif; ?></td>
            <td><span class="pill pill-<?= basics_order_status_pill($order['status']) ?>"><?= basics_order_status_label($order['status']) ?></span></td>
            <td><?= date('M j, Y', strtotime($order['created_at'])) ?></td>
            <td><a href="<?= BASICS_URL ?>/order_view.php?id=<?= (int) $order['id'] ?>" class="btn-chip btn-chip-outline">View</a></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
