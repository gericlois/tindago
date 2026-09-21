<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

$valid_statuses = ['pending', 'approved', 'denied'];
$status_filter = $_GET['status'] ?? 'pending';

$sql = "SELECT bm.*, u.full_name, u.username, u.email FROM basics_members bm JOIN basics_users u ON u.id = bm.user_id";
if (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " WHERE bm.application_status = '" . $conn->real_escape_string($status_filter) . "'";
}
$sql .= " ORDER BY bm.applied_at DESC";
$applications = $conn->query($sql);

$page_title = 'Basics Applications';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">JMC Foodies Basics</span>
    <h1 class="stitle" style="font-size:2rem;">Membership Applications</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/applications.php?status=" class="filter-pill <?= $status_filter === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($valid_statuses as $status): ?>
        <a href="<?= BASE_URL ?>/basics/admin/applications.php?status=<?= $status ?>"
           class="filter-pill text-capitalize <?= $status_filter === $status ? 'active' : '' ?>"><?= $status ?></a>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Applicant</th><th>Employer</th><th>Status</th><th>Applied</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($applications->num_rows === 0): ?>
        <tr><td colspan="5" class="text-muted">No applications found.</td></tr>
      <?php endif; ?>
      <?php while ($a = $applications->fetch_assoc()): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $a['id'] ?>"><?= sanitize($a['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($a['username']) ?>)</span></td>
          <td><?= sanitize($a['employer_name']) ?></td>
          <td><span class="pill pill-<?= $a['application_status'] === 'approved' ? 'approved' : ($a['application_status'] === 'denied' ? 'rejected' : 'pending') ?>"><?= sanitize($a['application_status']) ?></span></td>
          <td><?= date('M j, Y', strtotime($a['applied_at'])) ?></td>
          <td class="no-print"><a href="<?= BASE_URL ?>/basics/admin/application_view.php?id=<?= (int) $a['id'] ?>" class="btn-chip btn-chip-outline">Review</a></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
