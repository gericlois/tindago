<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

$valid_statuses = ['active', 'suspended', 'dormant', 'terminated'];
$status_filter = $_GET['status'] ?? '';
$barangay_filter = trim($_GET['barangay'] ?? '');

$barangays = $conn->query("SELECT DISTINCT u.barangay FROM basics_members bm
    JOIN basics_users u ON u.id = bm.user_id
    WHERE bm.application_status = 'approved' AND u.barangay IS NOT NULL AND u.barangay != ''
    ORDER BY u.barangay ASC")->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT bm.*, u.full_name, u.username, u.address, u.barangay, u.city FROM basics_members bm
        JOIN basics_users u ON u.id = bm.user_id WHERE bm.application_status = 'approved'";
if (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " AND bm.membership_status = '" . $conn->real_escape_string($status_filter) . "'";
}
if ($barangay_filter !== '') {
    $sql .= " AND u.barangay = '" . $conn->real_escape_string($barangay_filter) . "'";
}
$sql .= " ORDER BY u.full_name ASC";
$members = $conn->query($sql);

$page_title = 'Basics Members';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">JMC Foodies Basics</span>
    <h1 class="stitle" style="font-size:2rem;">Members</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/members.php" class="filter-pill <?= $status_filter === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($valid_statuses as $status): ?>
        <a href="<?= BASE_URL ?>/basics/admin/members.php?status=<?= $status ?>"
           class="filter-pill text-capitalize <?= $status_filter === $status ? 'active' : '' ?>"><?= $status ?></a>
      <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <?php if (!empty($barangays)): ?>
        <form method="get" class="d-inline">
          <?php if (in_array($status_filter, $valid_statuses, true)): ?><input type="hidden" name="status" value="<?= sanitize($status_filter) ?>"><?php endif; ?>
          <select name="barangay" class="fctrl" onchange="this.form.submit()">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
              <option value="<?= sanitize($b['barangay']) ?>" <?= $barangay_filter === $b['barangay'] ? 'selected' : '' ?>><?= sanitize($b['barangay']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
      <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Member</th><th>Address</th><th>Weekly Limit</th><th>Offenses</th><th>On-Time Streak</th><th>Status</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($members->num_rows === 0): ?>
        <tr><td colspan="7" class="text-muted">No members found.</td></tr>
      <?php endif; ?>
      <?php while ($m = $members->fetch_assoc()): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $m['id'] ?>"><?= sanitize($m['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($m['username']) ?>)</span></td>
          <td><?= sanitize($m['address'] ?: '—') ?></td>
          <td><?= format_price($m['weekly_credit_limit']) ?><?= $m['credit_limit_frozen'] ? ' <span class="text-muted small">(frozen)</span>' : '' ?></td>
          <td><?= (int) $m['offense_count'] ?></td>
          <td><?= (int) $m['consecutive_on_time_payments'] ?></td>
          <td><span class="pill pill-<?= $m['membership_status'] === 'active' ? 'active' : ($m['membership_status'] === 'dormant' ? 'pending' : 'suspended') ?>"><?= sanitize($m['membership_status']) ?></span></td>
          <td class="no-print"><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $m['id'] ?>" class="btn-chip btn-chip-outline">View</a></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
