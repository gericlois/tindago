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

// Date range is optional — an empty bound means "no limit on that side",
// same convention as basics/admin/communication_log.php's range filter.
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
if ($start_date !== '' && !DateTime::createFromFormat('Y-m-d', $start_date)) {
    $start_date = '';
}
if ($end_date !== '' && !DateTime::createFromFormat('Y-m-d', $end_date)) {
    $end_date = '';
}
if ($start_date !== '' && $end_date !== '' && $start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$sql = "SELECT * FROM activity_log WHERE admin_type = 'basics'";
if ($admin_filter > 0) {
    $sql .= " AND admin_id = " . $admin_filter;
}
if ($start_date !== '') {
    $sql .= " AND created_at >= '" . $conn->real_escape_string($start_date) . " 00:00:00'";
}
if ($end_date !== '') {
    $sql .= " AND created_at <= '" . $conn->real_escape_string($end_date) . " 23:59:59'";
}
$sql .= " ORDER BY created_at DESC LIMIT 300";
$logs = $conn->query($sql);

// Every filter link/form below needs to carry whichever filter it's not
// itself changing, so switching one never silently drops the other.
$date_query = ($start_date !== '' ? '&start_date=' . urlencode($start_date) : '')
    . ($end_date !== '' ? '&end_date=' . urlencode($end_date) : '');
$admin_query = $admin_filter > 0 ? '&admin_id=' . $admin_filter : '';

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
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
      <a href="?admin_id=0<?= $date_query ?>" class="filter-pill <?= $admin_filter === 0 ? 'active' : '' ?>">All Admins</a>
      <?php foreach ($admin_list as $a): ?>
        <a href="?admin_id=<?= (int) $a['id'] ?><?= $date_query ?>" class="filter-pill <?= $admin_filter === (int) $a['id'] ? 'active' : '' ?>"><?= sanitize($a['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <form method="get" class="d-flex align-items-end flex-wrap gap-2 mb-4">
    <?php if ($admin_filter > 0): ?><input type="hidden" name="admin_id" value="<?= $admin_filter ?>"><?php endif; ?>
    <div>
      <label class="flbl">From</label>
      <input type="date" name="start_date" class="fctrl" value="<?= sanitize($start_date) ?>">
    </div>
    <div>
      <label class="flbl">To</label>
      <input type="date" name="end_date" class="fctrl" value="<?= sanitize($end_date) ?>">
    </div>
    <button type="submit" class="btn-outline-theme"><i class="fas fa-filter"></i> Filter</button>
    <?php if ($start_date !== '' || $end_date !== ''): ?>
      <a href="<?= $admin_query !== '' ? '?' . ltrim($admin_query, '&') : '' ?>" class="btn-outline-theme"><i class="fas fa-xmark"></i> Clear Dates</a>
    <?php endif; ?>
  </form>

  <p class="text-muted small mb-3">Showing the most recent 300 admin entries.</p>

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
            <td class="small" data-order="<?= strtotime($log['created_at']) ?>"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
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
