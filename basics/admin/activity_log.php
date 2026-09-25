<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

$admins = $conn->query("SELECT id, name FROM basics_admins ORDER BY name ASC");
$admin_list = [];
while ($a = $admins->fetch_assoc()) {
    $admin_list[] = $a;
}

$admin_filter = (int) ($_GET['admin_id'] ?? 0);

$sql = "SELECT * FROM activity_log WHERE admin_type = 'basics'";
if ($admin_filter > 0) {
    $sql .= " AND admin_id = " . $admin_filter;
}
$sql .= " ORDER BY created_at DESC LIMIT 300";
$logs = $conn->query($sql);

$page_title = 'Activity Log';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Activity Log</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2">
      <a href="?admin_id=0" class="filter-pill <?= $admin_filter === 0 ? 'active' : '' ?>">All Admins</a>
      <?php foreach ($admin_list as $a): ?>
        <a href="?admin_id=<?= (int) $a['id'] ?>" class="filter-pill <?= $admin_filter === (int) $a['id'] ? 'active' : '' ?>"><?= sanitize($a['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <p class="text-muted small mb-3">Showing the most recent 300 Basics admin entries.</p>

  <div class="panel-card">
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>Date</th><th>Admin</th><th>Action</th><th>Description</th><th class="no-print">IP</th></tr></thead>
        <tbody>
        <?php if ($logs->num_rows === 0): ?>
          <tr><td colspan="5" class="text-muted">No activity recorded yet.</td></tr>
        <?php endif; ?>
        <?php while ($log = $logs->fetch_assoc()): ?>
          <tr>
            <td class="small"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
            <td><?= $log['admin_name'] ? sanitize($log['admin_name']) : '<span class="text-muted">System</span>' ?></td>
            <td><code class="small"><?= sanitize($log['action']) ?></code></td>
            <td><?= sanitize($log['description']) ?></td>
            <td class="small text-muted no-print"><?= sanitize($log['ip_address'] ?? '—') ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
