<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['archive', 'unarchive'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'archive') {
        $stmt = $conn->prepare("UPDATE orders SET archived_at = NOW() WHERE id = ? AND status IN ('completed', 'cancelled')");
    } else {
        $stmt = $conn->prepare("UPDATE orders SET archived_at = NULL WHERE id = ?");
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    redirect('/admin/orders.php' . (($_GET['view'] ?? '') === 'archived' ? '?view=archived' : ''));
}

$valid_statuses = ['pending', 'processing', 'completed', 'cancelled'];
$status_filter = $_GET['status'] ?? '';
$view = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';

$sql = "SELECT o.*, u.full_name, u.username FROM orders o JOIN users u ON u.id = o.user_id
        WHERE o.archived_at IS " . ($view === 'archived' ? 'NOT NULL' : 'NULL');
if (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " AND o.status = '" . $conn->real_escape_string($status_filter) . "'";
}
$sql .= " ORDER BY o.created_at DESC";
$orders = $conn->query($sql);

$page_title = 'Orders';
require __DIR__ . '/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">Manage</span>
    <h1 class="stitle" style="font-size:2rem;">Orders</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/admin/orders.php" class="filter-pill <?= $view === 'active' ? 'active' : '' ?>">Active</a>
      <a href="<?= BASE_URL ?>/admin/orders.php?view=archived" class="filter-pill <?= $view === 'archived' ? 'active' : '' ?>">Archived</a>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="d-flex flex-wrap gap-2 mb-4">
    <a href="<?= BASE_URL ?>/admin/orders.php<?= $view === 'archived' ? '?view=archived' : '' ?>" class="filter-pill <?= $status_filter === '' ? 'active' : '' ?>">All</a>
    <?php foreach ($valid_statuses as $status): ?>
      <a href="<?= BASE_URL ?>/admin/orders.php?status=<?= $status ?><?= $view === 'archived' ? '&view=archived' : '' ?>"
         class="filter-pill text-capitalize <?= $status_filter === $status ? 'active' : '' ?>"><?= $status ?></a>
    <?php endforeach; ?>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Order #</th><th>Buyer</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($orders->num_rows === 0): ?>
        <tr><td colspan="7" class="text-muted">No <?= $view === 'archived' ? 'archived' : '' ?> orders found.</td></tr>
      <?php endif; ?>
      <?php while ($o = $orders->fetch_assoc()): ?>
        <tr>
          <td>#<?= (int) $o['id'] ?></td>
          <td><?= sanitize($o['full_name']) ?> <span class="text-muted small">(<?= sanitize($o['username']) ?>)</span></td>
          <td><?= format_price($o['total_amount']) ?></td>
          <td><?= sanitize(payment_method_label($o['payment_method'])) ?></td>
          <td><span class="pill pill-<?= $o['status'] ?>"><?= sanitize($o['status']) ?></span></td>
          <td><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
          <td class="no-print">
            <a href="<?= BASE_URL ?>/admin/order_view.php?id=<?= (int) $o['id'] ?>" class="btn-chip btn-chip-outline">View</a>
            <?php if ($o['archived_at']): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="unarchive">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-outline">Unarchive</button>
              </form>
            <?php elseif (in_array($o['status'], ['completed', 'cancelled'], true)): ?>
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
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
