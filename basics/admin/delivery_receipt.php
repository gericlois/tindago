<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

$id = (int) ($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT o.*, u.full_name, u.username, u.contact_number, u.address
                         FROM basics_orders o
                         JOIN basics_members bm ON bm.id = o.member_id
                         JOIN basics_users u ON u.id = bm.user_id
                         WHERE o.id = ? AND o.status IN ('confirmed', 'out_for_delivery', 'delivered')");
$stmt->bind_param('i', $id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    redirect('/basics/admin/orders.php');
}

$stmt = $conn->prepare("SELECT oi.*, p.name, p.sku, p.unit FROM basics_order_items oi
                         JOIN basics_products p ON p.id = oi.product_id
                         WHERE oi.order_id = ? ORDER BY oi.id ASC");
$stmt->bind_param('i', $id);
$stmt->execute();
$items = $stmt->get_result();

$amount_paid = (float) $conn->query("SELECT COALESCE(SUM(amount_paid),0) AS s FROM basics_payments WHERE order_id = " . (int) $id)->fetch_assoc()['s'];

$page_title = 'Delivery Receipt #' . $order['id'];
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <a href="<?= BASE_URL ?>/basics/admin/order_view.php?id=<?= (int) $order['id'] ?>" class="small">&larr; Back to Order</a>
    <h1 class="stitle" style="font-size:2rem;">Delivery Receipt</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-end mb-3 no-print">
    <button type="button" class="btn-outline-theme" onclick="window.print()"><i class="fas fa-print"></i> Print Receipt</button>
  </div>

  <div class="panel-card">
    <div class="d-flex justify-content-between align-items-start mb-4">
      <div>
        <h2 class="h6 mb-1">JMC Foodies Basics</h2>
        <p class="text-muted small mb-0">Weekly Grocery Credit Line</p>
      </div>
      <div class="text-end">
        <p class="mb-1"><strong>Order #<?= (int) $order['id'] ?></strong></p>
        <p class="text-muted small mb-0">Delivery Date: <?= $order['delivered_at'] ? date('M j, Y', strtotime($order['delivered_at'])) : 'Pending' ?></p>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-12 col-md-6">
        <p class="mb-1"><strong>Member:</strong> <a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $order['member_id'] ?>"><?= sanitize($order['full_name']) ?></a> (<?= sanitize($order['username']) ?>)</p>
        <p class="mb-1"><strong>Contact #:</strong> <?= sanitize($order['contact_number']) ?></p>
        <p class="mb-0"><strong>Address:</strong> <?= sanitize($order['address']) ?></p>
      </div>
      <div class="col-12 col-md-6 text-md-end">
        <p class="mb-1"><strong>Order Status:</strong> <?= basics_order_status_label($order['status']) ?></p>
        <p class="mb-0"><strong>Payment Status:</strong> <?= $amount_paid >= $order['total_amount'] ? 'Paid in full' : 'Balance due: ' . format_price($order['total_amount'] - $amount_paid) ?></p>
      </div>
    </div>

    <div class="table-responsive mb-4">
      <table class="table-theme no-datatable">
        <thead><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead>
        <tbody>
        <?php while ($item = $items->fetch_assoc()): ?>
          <tr>
            <td><?= sanitize($item['name']) ?></td>
            <td><?= sanitize($item['sku']) ?></td>
            <td><?= (int) $item['quantity'] ?> <?= sanitize($item['unit']) ?></td>
            <td><?= format_price($item['unit_price']) ?></td>
            <td><?= format_price($item['line_total']) ?></td>
          </tr>
        <?php endwhile; ?>
        <tr><td colspan="4" class="text-end fw-bold">Total</td><td class="fw-bold"><?= format_price($order['total_amount']) ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="row g-4 mt-5">
      <div class="col-6 text-center">
        <div style="border-top:1px solid #333;margin-top:40px;padding-top:6px;">Received By (Signature over Printed Name)</div>
      </div>
      <div class="col-6 text-center">
        <div style="border-top:1px solid #333;margin-top:40px;padding-top:6px;">Date</div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
