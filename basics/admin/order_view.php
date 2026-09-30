<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    // basics_confirm_order() (basics/includes/functions.php) is the single
    // place this transition happens — also called from orders.php's own
    // confirm action — so the empty-gift safeguard and status trail can't be
    // missed from either entry point.
    $result = basics_confirm_order($conn, $id, basics_current_admin_name());
    if ($result === 'empty_gift') {
        redirect('/basics/admin/order_view.php?id=' . $id . '&error=empty_gift');
    }
    redirect('/basics/admin/order_view.php?id=' . $id);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'out_for_delivery') {
    basics_send_order_out_for_delivery($conn, $id, basics_current_admin_name());
    redirect('/basics/admin/order_view.php?id=' . $id);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_item') {
    // Only while Checking — admin adjusts quantities/removes out-of-stock
    // items before approving, matching the member's own cart editing.
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $new_qty = max(0, (int) ($_POST['quantity'] ?? 0));
    $stmt = $conn->prepare("SELECT oi.*, o.is_gift FROM basics_order_items oi JOIN basics_orders o ON o.id = oi.order_id
                             WHERE oi.id = ? AND oi.order_id = ? AND o.status = 'pending'");
    $stmt->bind_param('ii', $item_id, $id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($item) {
        if ($new_qty <= 0) {
            $stmt = $conn->prepare("DELETE FROM basics_order_items WHERE id = ?");
            $stmt->bind_param('i', $item_id);
        } else {
            $line_total = round($item['unit_price'] * $new_qty, 2);
            $stmt = $conn->prepare("UPDATE basics_order_items SET quantity = ?, line_total = ? WHERE id = ?");
            $stmt->bind_param('idi', $new_qty, $line_total, $item_id);
        }
        $stmt->execute();
        $stmt->close();
        // Gift orders stay pinned at total_amount=0 — see basics_gift_pill().
        if (!$item['is_gift']) {
            $stmt = $conn->prepare("UPDATE basics_orders SET total_amount = (SELECT COALESCE(SUM(line_total),0) FROM basics_order_items WHERE order_id = ?) WHERE id = ?");
            $stmt->bind_param('ii', $id, $id);
            $stmt->execute();
            $stmt->close();
        }
        log_activity($conn, 'update_basics_order_item', 'Adjusted item on Basics order #' . $id . ' (checking stage)');
    }
    redirect('/basics/admin/order_view.php?id=' . $id);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_item') {
    // Gift orders only — regular orders are built by the member's own cart;
    // there is no "add item" action for those, by design. The exact package
    // contents aren't fixed, so the admin picks products while still
    // Checking, same product/price source as basics/catalog.php's cart add.
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

    $stmt = $conn->prepare("SELECT id FROM basics_orders WHERE id = ? AND status = 'pending' AND is_gift = 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $is_open_gift_order = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($is_open_gift_order) {
        $stmt = $conn->prepare("SELECT * FROM basics_products WHERE id = ? AND status = 'active'");
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($product) {
            $line_total = round($product['srp'] * $quantity, 2);
            $stmt = $conn->prepare("INSERT INTO basics_order_items (order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('iiidd', $id, $product_id, $quantity, $product['srp'], $line_total);
            $stmt->execute();
            $stmt->close();
            // Deliberately NOT recomputing total_amount — gift orders stay at 0.
            log_activity($conn, 'add_basics_gift_order_item', 'Added ' . $quantity . ' x product #' . $product_id . ' to gift order #' . $id);
        }
    }
    redirect('/basics/admin/order_view.php?id=' . $id);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deliver') {
    // basics_deliver_order() (basics/includes/functions.php) is the single
    // place this transition happens — also called from orders.php's own
    // deliver action — so the referral-override credit can't be missed.
    basics_deliver_order($conn, $id, basics_current_admin_id());
    redirect('/basics/admin/order_view.php?id=' . $id);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $cancel_reason = trim($_POST['cancel_reason'] ?? '');
    if ($cancel_reason === '') {
        redirect('/basics/admin/order_view.php?id=' . $id . '&error=missing_cancel_reason');
    }

    basics_cancel_order($conn, $id, basics_current_admin_name(), $cancel_reason);
    redirect('/basics/admin/order_view.php?id=' . $id);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_status_note') {
    // Corrects/annotates a trail entry after the fact (e.g. fixing a typo in
    // a cancellation reason) — the status, actor, and date stay untouched;
    // only the note is editable, so the trail's actual history can't be
    // rewritten, just clarified.
    $history_id = (int) ($_POST['history_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $note_to_store = $note !== '' ? $note : null;
    $stmt = $conn->prepare("UPDATE basics_order_status_history SET note = ? WHERE id = ? AND order_id = ?");
    $stmt->bind_param('sii', $note_to_store, $history_id, $id);
    $stmt->execute();
    $edited = $stmt->affected_rows > 0;
    $stmt->close();
    if ($edited) {
        log_activity($conn, 'edit_basics_order_status_note', 'Edited a status trail note on Basics order #' . $id);
    }
    redirect('/basics/admin/order_view.php?id=' . $id);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    $stmt = $conn->prepare("UPDATE basics_orders SET archived_at = NOW() WHERE id = ? AND status IN ('delivered', 'cancelled')");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    redirect('/basics/admin/order_view.php?id=' . $id);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unarchive') {
    $stmt = $conn->prepare("UPDATE basics_orders SET archived_at = NULL WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    redirect('/basics/admin/order_view.php?id=' . $id);
}

$stmt = $conn->prepare("SELECT o.*, u.full_name, u.username, u.address
                         FROM basics_orders o
                         JOIN basics_members bm ON bm.id = o.member_id
                         JOIN basics_users u ON u.id = bm.user_id
                         WHERE o.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    redirect('/basics/admin/orders.php');
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

$amount_paid = (float) $conn->query("SELECT COALESCE(SUM(amount_paid),0) AS s FROM basics_payments WHERE order_id = $id")->fetch_assoc()['s'];

$stmt = $conn->prepare("SELECT * FROM basics_order_status_history WHERE order_id = ? ORDER BY created_at ASC, id ASC");
$stmt->bind_param('i', $id);
$stmt->execute();
$status_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = 'Order #' . $order['id'];
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <a href="<?= BASE_URL ?>/basics/admin/orders.php" class="small">&larr; Back to Orders</a>
    <h1 class="stitle" style="font-size:2rem;">Order #<?= (int) $order['id'] ?><?php if ($order['is_gift']): ?> <?= basics_gift_pill() ?><?php endif; ?></h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (($_GET['error'] ?? '') === 'empty_gift'): ?>
    <div class="errmsg mb-4"><p class="mb-0">Add at least one item before approving a gift order.</p></div>
  <?php elseif (($_GET['error'] ?? '') === 'missing_cancel_reason'): ?>
    <div class="errmsg mb-4"><p class="mb-0">Enter a reason before cancelling this order.</p></div>
  <?php endif; ?>
  <div class="row g-4">
    <div class="col-12 col-md-7">
      <div class="panel-card mb-4">
        <h2 class="h6">Order Details</h2>
        <p class="mb-1">Member: <a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $order['member_id'] ?>"><?= sanitize($order['full_name']) ?></a> (<?= sanitize($order['username']) ?>)</p>
        <?php $delivery_addr = $order['delivery_address'] !== '' ? $order['delivery_address'] : $order['address']; ?>
        <p class="mb-1">Delivery Address (<?= $order['delivery_location'] === 'company' ? 'Company' : 'Home' ?>): <?= $delivery_addr ? sanitize($delivery_addr) : '—' ?></p>
        <p class="mb-1">Order Date: <?= $order['placed_at'] ? date('M j, Y', strtotime($order['placed_at'])) : '—' ?></p>
        <p class="mb-1">Delivery Date: <?= $order['delivered_at'] ? date('M j, Y', strtotime($order['delivered_at'])) : 'Not yet delivered' ?></p>
        <?php if ($order['status'] === 'cancelled' && $order['cancel_reason']): ?>
          <p class="mb-1">Cancellation Reason: <?= sanitize($order['cancel_reason']) ?></p>
        <?php endif; ?>
        <?php if ($order['is_gift']): ?>
          <p class="mb-1 text-muted">No payment required &mdash; Birthday Grocery Gift.</p>
        <?php elseif ($order['delivered_at']): ?>
          <p class="mb-1">Payment Due: <?= date('M j, Y', strtotime($payment_due_date)) ?></p>
        <?php else: ?>
          <p class="mb-1 text-muted">Payment Due: 7 days after delivery</p>
        <?php endif; ?>
        <p class="mb-2">Status: <span class="pill pill-<?= basics_order_status_pill($order['status']) ?>"><?= basics_order_status_label($order['status']) ?></span>
          <?php if (!$order['is_gift'] && basics_order_is_paid($order['total_amount'], $amount_paid)): ?><span class="pill pill-paid">Paid</span><?php endif; ?>
        </p>
        <?php if (in_array($order['status'], ['confirmed', 'out_for_delivery', 'delivered'], true)): ?>
          <a href="<?= BASE_URL ?>/basics/admin/delivery_receipt.php?id=<?= (int) $order['id'] ?>" class="btn-chip btn-chip-outline"><i class="fas fa-receipt"></i> Delivery Receipt</a>
        <?php endif; ?>
        <?php if (in_array($order['status'], ['delivered', 'cancelled'], true)): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="<?= $order['archived_at'] ? 'unarchive' : 'archive' ?>">
            <button type="submit" class="btn-chip btn-chip-outline" <?= $order['archived_at'] ? '' : 'onclick="return confirm(\'Archive this order? It will be hidden from the active list.\');"' ?>>
              <i class="fas fa-box-archive"></i> <?= $order['archived_at'] ? 'Unarchive' : 'Archive' ?>
            </button>
          </form>
        <?php endif; ?>
      </div>

      <?php $is_editable = $order['status'] === 'pending'; ?>
      <?php if ($is_editable): ?>
        <p class="small text-muted mb-2">Still Checking &mdash; adjust quantities or remove out-of-stock items before approving.</p>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table-theme no-datatable">
          <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Line Total</th><?php if ($is_editable): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php while ($item = $items->fetch_assoc()): ?>
            <tr>
              <td><?= sanitize($item['name']) ?> <span class="text-muted small">(<?= sanitize($item['sku']) ?>)</span></td>
              <td>
                <?php if ($is_editable): ?>
                  <form method="post" class="d-inline-flex align-items-center gap-1">
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <input type="number" name="quantity" value="<?= (int) $item['quantity'] ?>" min="0" class="fctrl" style="width:70px;display:inline-block;">
                    <button type="submit" class="btn-chip btn-chip-outline">Update</button>
                  </form>
                <?php else: ?>
                  <?= (int) $item['quantity'] ?>
                <?php endif; ?>
                <?= sanitize($item['unit']) ?>
              </td>
              <td><?= format_price($item['unit_price']) ?></td>
              <td><?= format_price($item['line_total']) ?></td>
              <?php if ($is_editable): ?>
                <td>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <input type="hidden" name="quantity" value="0">
                    <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Remove this item (out of stock)?');"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endwhile; ?>
          <tr><td colspan="3" class="text-end fw-bold">Total</td><td class="fw-bold"><?= format_price($order['total_amount']) ?></td><?php if ($is_editable): ?><td></td><?php endif; ?></tr>
          </tbody>
        </table>
      </div>
      <?php if ($is_editable && $order['is_gift']): ?>
        <div class="panel-card mt-3">
          <h2 class="h6">Add Item to Gift Package</h2>
          <p class="small text-muted mb-2">Pick whatever products make up this member's gift &mdash; there's no fixed list.</p>
          <form method="post" class="d-flex flex-wrap gap-2">
            <input type="hidden" name="action" value="add_item">
            <select name="product_id" class="fctrl" style="flex:1 1 240px;" required>
              <?php $gift_products = $conn->query("SELECT id, name, sku, unit FROM basics_products WHERE status = 'active' ORDER BY name ASC"); ?>
              <?php while ($gp = $gift_products->fetch_assoc()): ?>
                <option value="<?= (int) $gp['id'] ?>"><?= sanitize($gp['name']) ?> (<?= sanitize($gp['sku']) ?>)</option>
              <?php endwhile; ?>
            </select>
            <input type="number" name="quantity" value="1" min="1" class="fctrl" style="width:80px;">
            <button type="submit" class="btn-chip btn-chip-success">Add Item</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-12 col-md-5">
      <div class="panel-card mb-4">
        <h2 class="h6">Payment</h2>
        <?php if ($order['is_gift']): ?>
          <p class="mb-3"><?= basics_gift_pill() ?> This is a Birthday Grocery Gift &mdash; no payment required.</p>
        <?php else: ?>
          <p class="mb-1">Amount Due: <?= format_price($order['total_amount']) ?></p>
          <p class="mb-3">Amount Paid: <span class="fw-bold"><?= format_price($amount_paid) ?></span></p>
        <?php endif; ?>
        <?php if ($order['status'] === 'pending'): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Approve this order? Review the items above first if anything is out of stock.');">Approve Order</button>
          </form>
          <button type="button" class="btn-chip btn-chip-outline" data-bs-toggle="modal" data-bs-target="#cancelOrderModal">Cancel Order</button>
        <?php elseif ($order['status'] === 'confirmed'): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="out_for_delivery">
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Mark this order as out for delivery?');">Mark For Delivery</button>
          </form>
          <?php if (!$order['is_gift']): ?>
            <a href="<?= BASE_URL ?>/basics/admin/payments.php?order_id=<?= (int) $order['id'] ?>" class="btn-chip btn-chip-outline">Record Payment</a>
          <?php endif; ?>
          <button type="button" class="btn-chip btn-chip-outline" data-bs-toggle="modal" data-bs-target="#cancelOrderModal">Cancel Order</button>
        <?php elseif ($order['status'] === 'out_for_delivery'): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="deliver">
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Mark this order as delivered?');">Mark Delivered</button>
          </form>
          <?php if (!$order['is_gift'] && $amount_paid < $order['total_amount']): ?>
            <a href="<?= BASE_URL ?>/basics/admin/payments.php?order_id=<?= (int) $order['id'] ?>" class="btn-chip btn-chip-outline">Record Payment</a>
          <?php endif; ?>
        <?php elseif ($order['status'] === 'delivered' && !$order['is_gift'] && $amount_paid < $order['total_amount']): ?>
          <a href="<?= BASE_URL ?>/basics/admin/payments.php?order_id=<?= (int) $order['id'] ?>" class="btn-chip btn-chip-success">Record Payment</a>
        <?php endif; ?>
      </div>

      <div class="panel-card">
        <h2 class="h6">Payment History</h2>
        <?php if ($payments->num_rows === 0): ?>
          <p class="text-muted mb-0">No payments recorded yet.</p>
        <?php endif; ?>
        <?php while ($p = $payments->fetch_assoc()): ?>
          <div class="mb-3 pb-3" style="border-bottom:1px solid #f1f1f1;">
            <p class="mb-1">Paid: <span class="fw-bold"><?= format_price($p['amount_paid']) ?></span></p>
            <?php if ($p['is_late']): ?>
              <p class="mb-1 small" style="color:var(--primary);">Late &mdash; offense #<?= (int) $p['offense_number'] ?>, <?= format_price($p['penalty_amount']) ?> penalty</p>
            <?php else: ?>
              <p class="mb-1 small" style="color:var(--green);">On time</p>
            <?php endif; ?>
            <p class="mb-0 small text-muted"><?= date('M j, Y', strtotime($p['paid_at'])) ?></p>
          </div>
        <?php endwhile; ?>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Order Trail</h2>
        <div class="table-responsive">
          <table class="table-theme no-datatable">
            <thead><tr><th>Status</th><th>Date</th><th>Action By</th><th class="no-print"></th></tr></thead>
            <tbody>
            <?php if (empty($status_history)): ?>
              <tr><td colspan="4" class="text-muted">No status history recorded for this order.</td></tr>
            <?php endif; ?>
            <?php foreach ($status_history as $h): ?>
              <tr>
                <td>
                  <span class="pill pill-<?= basics_order_status_pill($h['status']) ?>"><?= basics_order_status_label($h['status']) ?></span>
                  <?php if ($h['note']): ?><div class="text-muted small mt-1"><?= sanitize($h['note']) ?></div><?php endif; ?>
                </td>
                <td><?= date('M j, Y g:i A', strtotime($h['created_at'])) ?></td>
                <td><?= sanitize($h['actor_label']) ?></td>
                <td class="no-print">
                  <button type="button" class="btn-chip btn-chip-outline" data-bs-toggle="modal" data-bs-target="#editNoteModal-<?= (int) $h['id'] ?>"><i class="fas fa-pen"></i></button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php foreach ($status_history as $h): ?>
      <div class="modal fade" id="editNoteModal-<?= (int) $h['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post">
              <input type="hidden" name="action" value="edit_status_note">
              <input type="hidden" name="history_id" value="<?= (int) $h['id'] ?>">
              <div class="modal-header">
                <h5 class="modal-title">Edit Reason — <?= basics_order_status_label($h['status']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <label class="flbl">Reason / Note</label>
                <textarea name="note" class="fctrl" rows="3"><?= sanitize($h['note'] ?? '') ?></textarea>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn-chip btn-chip-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn-chip btn-chip-success">Save</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if (in_array($order['status'], ['pending', 'confirmed'], true)): ?>
<div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="cancel">
        <div class="modal-header">
          <h5 class="modal-title">Cancel Order #<?= (int) $order['id'] ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">The member will be notified by SMS/email with this reason. This cannot be undone.</p>
          <label class="flbl">Reason for Cancellation (required)</label>
          <textarea name="cancel_reason" class="fctrl" rows="3" required></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-outline-theme" data-bs-dismiss="modal">Never Mind</button>
          <button type="submit" class="btn-red" onclick="return confirm('Cancel this order? This cannot be undone.');">Cancel Order</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
