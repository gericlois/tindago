<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

require_admin_login();

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $conn->begin_transaction();
    try {
        if ($action === 'confirm') {
            confirm_order_payment($conn, $id);
        } elseif ($action === 'deliver') {
            mark_order_delivered($conn, $id, current_admin_id());
        } elseif ($action === 'cancel') {
            cancel_order($conn, $id);
        } elseif ($action === 'archive') {
            $stmt = $conn->prepare("UPDATE orders SET archived_at = NOW() WHERE id = ? AND status IN ('completed', 'cancelled')");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'unarchive') {
            $stmt = $conn->prepare("UPDATE orders SET archived_at = NULL WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }
    redirect('/admin/order_view.php?id=' . $id);
}

$stmt = $conn->prepare("SELECT o.*, u.full_name, u.username, u.referral_code, u.referred_by,
                                p.name AS product_name,
                                (SELECT full_name FROM users ref WHERE ref.id = u.referred_by) AS referrer_name
                         FROM orders o
                         JOIN users u ON u.id = o.user_id
                         JOIN products p ON p.id = o.product_id
                         WHERE o.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    redirect('/admin/orders.php');
}

$rebate_rate = (float) setting($conn, 'personal_rebate_rate', 0.20);
$override_rate = (float) setting($conn, 'referral_override_rate', 0.10);

$page_title = 'Order #' . $order['id'];
require __DIR__ . '/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <a href="<?= BASE_URL ?>/admin/orders.php" class="small">&larr; Back to Orders</a>
    <h1 class="stitle" style="font-size:2rem;">Order #<?= (int) $order['id'] ?></h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="row g-4">
    <div class="col-12 col-md-6">
      <div class="panel-card mb-4">
        <h2 class="h6">Buyer</h2>
        <p class="mb-1"><?= sanitize($order['full_name']) ?> (<?= sanitize($order['username']) ?>)</p>
        <p class="mb-1 small text-muted">Referral Code: <?= sanitize($order['referral_code']) ?></p>
        <p class="mb-0 small text-muted">
          Referred by: <?= $order['referrer_name'] ? sanitize($order['referrer_name']) : '— (no referrer)' ?>
        </p>
      </div>

      <div class="panel-card">
        <h2 class="h6">Order Details</h2>
        <p class="mb-1">Product: <?= sanitize($order['product_name']) ?></p>
        <p class="mb-1">Quantity: <?= (int) $order['quantity'] ?></p>
        <p class="mb-1">Unit Price: <?= format_price($order['unit_price']) ?></p>
        <p class="mb-1">Total: <span class="fw-bold"><?= format_price($order['total_amount']) ?></span></p>
        <p class="mb-1">Payment Method: <?= sanitize(payment_method_label($order['payment_method'])) ?></p>
        <?php if ($order['payment_reference']): ?>
          <p class="mb-1">Payment Reference: <?= sanitize($order['payment_reference']) ?></p>
        <?php endif; ?>
        <p class="mb-0">Status: <span class="pill pill-<?= $order['status'] ?>"><?= sanitize($order['status']) ?></span></p>
      </div>
    </div>

    <div class="col-12 col-md-6">
      <div class="panel-card">
        <h2 class="h6">Earnings Preview</h2>
        <p class="mb-1">Personal Rebate (<?= (int) ($rebate_rate * 100) ?>%) to buyer: <span class="fw-bold amount-credit"><?= format_price($order['total_amount'] * $rebate_rate) ?></span></p>
        <?php if ($order['referred_by']): ?>
          <p class="mb-3">Referral Override (<?= (int) ($override_rate * 100) ?>%) to <?= sanitize($order['referrer_name']) ?>: <span class="fw-bold amount-credit"><?= format_price($order['total_amount'] * $override_rate) ?></span></p>
        <?php else: ?>
          <p class="mb-3 text-muted">No referrer — no override applies.</p>
        <?php endif; ?>

        <?php if ($order['status'] === 'pending'): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Confirm payment was received for this order? It will move to processing — rewards are not credited until it is delivered.');">
              <i class="fas fa-check-circle"></i> Confirm Payment Received
            </button>
          </form>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Cancel this order?');">
              <i class="fas fa-times-circle"></i> Cancel Order
            </button>
          </form>
        <?php elseif ($order['status'] === 'processing'): ?>
          <p class="small text-muted mb-2">Payment verified. Rewards credit once this order is marked delivered.</p>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="deliver">
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Mark this order as delivered? This will credit the rebate/override.');">
              <i class="fas fa-truck"></i> Mark as Delivered
            </button>
          </form>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Cancel this order? Any wallet payment already made will be refunded.');">
              <i class="fas fa-times-circle"></i> Cancel Order
            </button>
          </form>
        <?php elseif ($order['status'] === 'completed'): ?>
          <p class="mb-0" style="color:var(--green);"><i class="fas fa-check-circle"></i> Delivered &amp; rewards credited on <?= date('M j, Y g:i A', strtotime($order['confirmed_at'])) ?></p>
        <?php else: ?>
          <p class="text-muted mb-0">This order was cancelled.</p>
        <?php endif; ?>

        <?php if (in_array($order['status'], ['completed', 'cancelled'], true)): ?>
          <form method="post" class="d-inline mt-2">
            <input type="hidden" name="action" value="<?= $order['archived_at'] ? 'unarchive' : 'archive' ?>">
            <button type="submit" class="btn-chip btn-chip-outline" <?= $order['archived_at'] ? '' : 'onclick="return confirm(\'Archive this order? It will be hidden from the active list.\');"' ?>>
              <i class="fas fa-box-archive"></i> <?= $order['archived_at'] ? 'Unarchive' : 'Archive' ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
