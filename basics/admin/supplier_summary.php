<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $start_date)) {
    $start_date = date('Y-m-d');
}
if (!DateTime::createFromFormat('Y-m-d', $end_date)) {
    $end_date = date('Y-m-d');
}
if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

// Only orders still in "Checking" (pending) status — this summary is what
// gets ordered from the supplier, so it covers orders awaiting approval.
$stmt = $conn->prepare("SELECT p.id, p.sku, p.category, p.name, p.unit,
                                SUM(oi.quantity) AS total_qty,
                                SUM(oi.line_total) AS total_amount,
                                COUNT(DISTINCT oi.order_id) AS order_count
                         FROM basics_order_items oi
                         JOIN basics_orders o ON o.id = oi.order_id
                         JOIN basics_products p ON p.id = oi.product_id
                         WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status = 'pending'
                         GROUP BY p.id
                         ORDER BY p.category ASC, p.name ASC");
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(o.total_amount), 0) AS grand_total
                         FROM basics_orders o
                         WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status = 'pending'");
$stmt->bind_param('ss', $start_date, $end_date);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

$range_label = $start_date === $end_date
    ? date('M j, Y (l)', strtotime($start_date))
    : date('M j, Y', strtotime($start_date)) . ' &ndash; ' . date('M j, Y', strtotime($end_date));

$page_title = 'Supplier Order Summary';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Supplier Order Summary</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 no-print">
    <form method="get" class="d-flex align-items-end gap-2 flex-wrap">
      <div>
        <label class="flbl">From</label>
        <input type="date" name="start_date" class="fctrl" value="<?= sanitize($start_date) ?>">
      </div>
      <div>
        <label class="flbl">To</label>
        <input type="date" name="end_date" class="fctrl" value="<?= sanitize($end_date) ?>">
      </div>
      <button type="submit" class="btn-outline-theme">View</button>
    </form>
    <div class="d-flex gap-2">
      <button type="button" id="copyBtn" class="btn-outline-theme"><i class="fas fa-copy"></i> Copy for Supplier</button>
      <button type="button" class="btn-outline-theme" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
    </div>
  </div>

  <div class="panel-card mb-4">
    <div class="d-flex justify-content-between align-items-start mb-3">
      <div>
        <h2 class="h6 mb-1">TindaGo</h2>
        <p class="text-muted small mb-0">Supplier Procurement Summary</p>
      </div>
      <div class="text-end">
        <p class="mb-1"><strong>Order Date<?= $start_date === $end_date ? '' : ' Range' ?>:</strong> <?= $range_label ?></p>
        <p class="text-muted small mb-0"><?= (int) $totals['order_count'] ?> order(s) &mdash; <?= format_price($totals['grand_total']) ?> total</p>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table-theme no-datatable" id="summaryTable">
        <thead><tr><th>SKU</th><th>Category</th><th>Product</th><th>Total Qty</th><th>Unit</th><th># Orders</th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="6" class="text-muted">No orders in "Checking" status in this date range.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><code><?= sanitize($r['sku']) ?></code></td>
            <td><?= sanitize($r['category']) ?></td>
            <td><?= sanitize($r['name']) ?></td>
            <td class="fw-bold"><?= (int) $r['total_qty'] ?></td>
            <td><?= sanitize($r['unit']) ?></td>
            <td><?= (int) $r['order_count'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.getElementById('copyBtn').addEventListener('click', function () {
  var lines = [
    'TindaGo - Supplier Order Summary',
    'Order Date<?= $start_date === $end_date ? '' : ' Range' ?>: <?= addslashes(strip_tags(str_replace('&ndash;', '-', $range_label))) ?>',
    ''
  ];
  document.querySelectorAll('#summaryTable tbody tr').forEach(function (tr) {
    var cells = tr.querySelectorAll('td');
    if (cells.length < 6) return;
    var name = cells[2].innerText.trim();
    var qty = cells[3].innerText.trim();
    var unit = cells[4].innerText.trim();
    lines.push('- ' + name + ': ' + qty + ' ' + unit);
  });
  var text = lines.join('\n');
  navigator.clipboard.writeText(text).then(function () {
    var btn = document.getElementById('copyBtn');
    var original = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
    setTimeout(function () { btn.innerHTML = original; }, 2000);
  });
});
</script>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
