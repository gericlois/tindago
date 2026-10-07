<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_access($conn);

$member = basics_get_member($conn, basics_current_user_id());
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    // Confirm this order actually belongs to the member before cancelling —
    // basics_cancel_order() itself has no member_id check (it's also called
    // from the admin side), so that ownership check stays here.
    $stmt = $conn->prepare("SELECT id FROM basics_orders WHERE id = ? AND member_id = ? AND status = 'pending'");
    $stmt->bind_param('ii', $id, $member['id']);
    $stmt->execute();
    $owns_order = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($owns_order && basics_cancel_order($conn, $id, $member['full_name'] . ' (member)')) {
        redirect('/basics/order_view.php?id=' . $id . '&cancelled=1');
    }
    redirect('/basics/order_view.php?id=' . $id);
}

$stmt = $conn->prepare("SELECT o.* FROM basics_orders o
                         WHERE o.id = ? AND o.member_id = ? AND o.status != 'draft'");
$stmt->bind_param('ii', $id, $member['id']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    redirect('/basics/orders.php');
}

$payment_due_date = basics_payment_due_date($order);

$stmt = $conn->prepare("SELECT oi.*, p.name, p.sku, p.unit FROM basics_order_items oi
                         JOIN basics_products p ON p.id = oi.product_id
                         WHERE oi.order_id = ? ORDER BY oi.id ASC");
$stmt->bind_param('i', $id);
$stmt->execute();
$items = $stmt->get_result();

$stmt = $conn->prepare("SELECT * FROM basics_payments WHERE order_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $id);
$stmt->execute();
$payments = $stmt->get_result();

$page_title = 'Order #' . $order['id'];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <a href="<?= BASICS_URL ?>/orders.php" class="small">&larr; Back to Orders</a>
    <h1 class="stitle" style="font-size:2rem;">Order #<?= (int) $order['id'] ?><?php if ($order['is_gift']): ?> <?= basics_gift_pill() ?><?php endif; ?></h1>
  </div>
</div>

<div class="container py-5">
  <?php if (isset($_GET['cancelled'])): ?>
    <div class="sucmsg is-visible mb-4"><p class="mb-0">Order cancelled.</p></div>
  <?php endif; ?>
  <div class="row g-4">
    <div class="col-12 col-md-7">
      <div class="panel-card mb-4">
        <h2 class="h6">Order Details</h2>
        <p class="mb-1">Deliver To (<?= $order['delivery_location'] === 'company' ? 'Company' : 'Home' ?>): <?= sanitize($order['delivery_address']) ?></p>
        <?php if ($order['delivered_at']): ?>
          <p class="mb-1">Delivered: <?= date('M j, Y', strtotime($order['delivered_at'])) ?></p>
        <?php endif; ?>
        <?php if ($order['is_gift']): ?>
          <p class="mb-1 text-muted">This is your Birthday Grocery Gift &mdash; no payment is required.</p>
        <?php elseif ($order['delivered_at']): ?>
          <p class="mb-1">Payment Due: <?= date('M j, Y', strtotime($payment_due_date)) ?></p>
        <?php else: ?>
          <p class="mb-1 text-muted">Payment Due: 7 days after delivery</p>
        <?php endif; ?>
        <p class="mb-2">Status: <span class="pill pill-<?= basics_order_status_pill($order['status']) ?>"><?= basics_order_status_label($order['status']) ?></span></p>
        <?php if ($order['status'] === 'cancelled' && $order['cancel_reason']): ?>
          <p class="mb-2 text-muted">Reason: <?= sanitize($order['cancel_reason']) ?></p>
        <?php endif; ?>
        <?php if ($order['status'] === 'pending'): ?>
          <form method="post">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Cancel this order? This cannot be undone.');"><i class="fas fa-xmark"></i> Cancel Order</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead>
          <tbody>
          <?php while ($item = $items->fetch_assoc()): ?>
            <tr>
              <td><?= sanitize($item['name']) ?></td>
              <td><?= (int) $item['quantity'] ?> <?= sanitize($item['unit']) ?></td>
              <td><?= format_price($item['unit_price']) ?></td>
              <td><?= format_price($item['line_total']) ?></td>
            </tr>
          <?php endwhile; ?>
          <tr><td colspan="3" class="text-end fw-bold">Total</td><td class="fw-bold"><?= format_price($order['total_amount']) ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="col-12 col-md-5">
      <div class="panel-card">
        <h2 class="h6">Payment History</h2>
        <?php if ($payments->num_rows === 0): ?>
          <p class="text-muted mb-0">No payment recorded yet.</p>
        <?php endif; ?>
        <?php while ($p = $payments->fetch_assoc()): ?>
          <div class="mb-3 pb-3" style="border-bottom:1px solid #f1f1f1;">
            <p class="mb-1">Paid: <span class="fw-bold"><?= format_price($p['amount_paid']) ?></span></p>
            <?php if (!empty($p['payment_method'])): ?>
              <p class="mb-1 small">Method: <?= sanitize($p['payment_method']) ?></p>
            <?php endif; ?>
            <?php if ($p['is_late']): ?>
              <p class="mb-1 small" style="color:var(--primary);">Late payment &mdash; <?= format_price($p['penalty_amount']) ?> penalty (<?= (int) ($p['penalty_rate'] * 100) ?>%)</p>
            <?php else: ?>
              <p class="mb-1 small" style="color:var(--green);">On time, no penalty</p>
            <?php endif; ?>
            <p class="mb-0 small text-muted"><?= date('M j, Y', strtotime($p['paid_at'])) ?></p>
          </div>
        <?php endwhile; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
