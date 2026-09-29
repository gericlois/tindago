<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE basics_orders SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $confirmed = $stmt->affected_rows > 0;
    $stmt->close();
    if ($confirmed) {
        log_activity($conn, 'confirm_basics_order', 'Approved Basics order #' . $id);
        $member = basics_member_by_order_id($conn, $id);
        if ($member) {
            basics_notify($conn, $member, "Hi {$member['full_name']}, your order #{$id} has been approved and is being prepared. - JMC Foodies Basics");
        }
    }
    redirect('/basics/admin/orders.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'out_for_delivery') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE basics_orders SET status = 'out_for_delivery', out_for_delivery_at = NOW() WHERE id = ? AND status = 'confirmed'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $moved = $stmt->affected_rows > 0;
    $stmt->close();
    if ($moved) {
        log_activity($conn, 'basics_order_out_for_delivery', 'Marked Basics order #' . $id . ' as out for delivery');
        $member = basics_member_by_order_id($conn, $id);
        if ($member) {
            basics_notify($conn, $member, "Hi {$member['full_name']}, your order #{$id} is out for delivery! - JMC Foodies Basics");
        }
    }
    redirect('/basics/admin/orders.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deliver') {
    $id = (int) ($_POST['id'] ?? 0);
    // basics_deliver_order() (basics/includes/functions.php) is the single
    // place this transition happens — also called from order_view.php's own
    // deliver action — so the referral-override credit can't be missed.
    basics_deliver_order($conn, $id, basics_current_admin_id());
    redirect('/basics/admin/orders.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE basics_orders SET status = 'cancelled' WHERE id = ? AND status IN ('pending', 'confirmed')");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $cancelled = $stmt->affected_rows > 0;
    $stmt->close();
    if ($cancelled) {
        log_activity($conn, 'cancel_basics_order', 'Cancelled Basics order #' . $id);
        $member = basics_member_by_order_id($conn, $id);
        if ($member) {
            basics_notify($conn, $member, "Hi {$member['full_name']}, your order #{$id} has been cancelled. - JMC Foodies Basics");
        }
    }
    redirect('/basics/admin/orders.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['archive', 'unarchive'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'archive') {
        $stmt = $conn->prepare("UPDATE basics_orders SET archived_at = NOW() WHERE id = ? AND status IN ('delivered', 'cancelled')");
    } else {
        $stmt = $conn->prepare("UPDATE basics_orders SET archived_at = NULL WHERE id = ?");
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    redirect('/basics/admin/orders.php' . (($_GET['view'] ?? '') === 'archived' ? '?view=archived' : ''));
}

$valid_statuses = ['pending', 'confirmed', 'out_for_delivery', 'delivered', 'cancelled'];
$status_filter = $_GET['status'] ?? '';
$view = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';

$sql = "SELECT o.*, u.full_name, u.username,
               (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS amount_paid
        FROM basics_orders o
        JOIN basics_members bm ON bm.id = o.member_id
        JOIN basics_users u ON u.id = bm.user_id
        WHERE o.status != 'draft' AND o.archived_at IS " . ($view === 'archived' ? 'NOT NULL' : 'NULL');
if ($status_filter === 'paid') {
    // Not a real basics_orders.status value (see basics_order_is_paid()) —
    // filters on the computed amount_paid alias instead of o.status.
    $sql .= " HAVING o.total_amount > 0 AND amount_paid >= o.total_amount";
} elseif (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " AND o.status = '" . $conn->real_escape_string($status_filter) . "'";
}
$sql .= " ORDER BY o.created_at DESC";
$orders = $conn->query($sql);

$page_title = 'Basics Orders';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Orders</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/orders.php" class="filter-pill <?= $view === 'active' ? 'active' : '' ?>">Active</a>
      <a href="<?= BASE_URL ?>/basics/admin/orders.php?view=archived" class="filter-pill <?= $view === 'archived' ? 'active' : '' ?>">Archived</a>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="d-flex flex-wrap gap-2 mb-4">
    <a href="<?= BASE_URL ?>/basics/admin/orders.php<?= $view === 'archived' ? '?view=archived' : '' ?>" class="filter-pill <?= $status_filter === '' ? 'active' : '' ?>">All</a>
    <?php foreach ($valid_statuses as $status): ?>
      <a href="<?= BASE_URL ?>/basics/admin/orders.php?status=<?= $status ?><?= $view === 'archived' ? '&view=archived' : '' ?>"
         class="filter-pill <?= $status_filter === $status ? 'active' : '' ?>"><?= basics_order_status_label($status) ?></a>
    <?php endforeach; ?>
    <a href="<?= BASE_URL ?>/basics/admin/orders.php?status=paid<?= $view === 'archived' ? '&view=archived' : '' ?>"
       class="filter-pill <?= $status_filter === 'paid' ? 'active' : '' ?>">Paid</a>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Order #</th><th>Member</th><th>Total</th><th>Amount Paid</th><th>Payment</th><th>Status</th><th>Date</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($orders->num_rows === 0): ?>
        <tr><td colspan="8" class="text-muted">No <?= $view === 'archived' ? 'archived' : '' ?> orders found.</td></tr>
      <?php endif; ?>
      <?php while ($o = $orders->fetch_assoc()): ?>
        <tr>
          <td>#<?= (int) $o['id'] ?></td>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $o['member_id'] ?>"><?= sanitize($o['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($o['username']) ?>)</span></td>
          <td><?= format_price($o['total_amount']) ?><?php if ($o['is_gift']): ?> <span class="text-muted small">(Gift)</span><?php endif; ?></td>
          <td><?= format_price($o['amount_paid']) ?></td>
          <td><?php if ($o['is_gift']): ?><?= basics_gift_pill() ?><?php elseif (basics_order_is_paid($o['total_amount'], $o['amount_paid'])): ?><span class="pill pill-paid">Paid</span><?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td>
          <td><span class="pill pill-<?= basics_order_status_pill($o['status']) ?>"><?= basics_order_status_label($o['status']) ?></span></td>
          <td><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
          <td class="no-print">
            <a href="<?= BASE_URL ?>/basics/admin/order_view.php?id=<?= (int) $o['id'] ?>" class="btn-chip btn-chip-outline">View</a>
            <?php if ($o['status'] === 'pending'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Approve this order? Review the items on the order page first if anything is out of stock.');">Approve</button>
              </form>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Cancel this order? This cannot be undone.');">Cancel</button>
              </form>
            <?php elseif ($o['status'] === 'confirmed'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="out_for_delivery">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Mark this order as out for delivery?');">For Delivery</button>
              </form>
              <a href="<?= BASE_URL ?>/basics/admin/payments.php?order_id=<?= (int) $o['id'] ?>" class="btn-chip btn-chip-outline">Record Payment</a>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Cancel this order? This cannot be undone.');">Cancel</button>
              </form>
            <?php elseif ($o['status'] === 'out_for_delivery'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="deliver">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Mark this order as delivered?');">Delivered</button>
              </form>
              <?php if ($o['amount_paid'] < $o['total_amount']): ?>
                <a href="<?= BASE_URL ?>/basics/admin/payments.php?order_id=<?= (int) $o['id'] ?>" class="btn-chip btn-chip-outline">Record Payment</a>
              <?php endif; ?>
            <?php elseif ($o['status'] === 'delivered' && $o['amount_paid'] < $o['total_amount']): ?>
              <a href="<?= BASE_URL ?>/basics/admin/payments.php?order_id=<?= (int) $o['id'] ?>" class="btn-chip btn-chip-success">Record Payment</a>
            <?php endif; ?>
            <?php if ($o['archived_at']): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="unarchive">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-outline">Unarchive</button>
              </form>
            <?php elseif (in_array($o['status'], ['delivered', 'cancelled'], true)): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Archive this order? It will be hidden from the active list.');">Archive</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
