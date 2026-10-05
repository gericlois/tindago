<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_registration']);

$valid_statuses = ['pending', 'approved', 'denied'];
$status_filter = $_GET['status'] ?? '';

// Every registered person regardless of application status (unlike
// members.php, which is approved members only) — registration staff need to
// see who they've just registered while it's still pending approval. Kept to
// identity/contact fields only; credit, orders and payments stay out of this
// view on purpose.
$sql = "SELECT bm.id, bm.application_status, bm.membership_status, bm.applied_at,
               u.full_name, u.username, u.contact_number, u.address
        FROM basics_members bm JOIN basics_users u ON u.id = bm.user_id WHERE 1=1";
$types = '';
$params = [];
if (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " AND bm.application_status = ?";
    $types .= 's';
    $params[] = $status_filter;
}
$sql .= " ORDER BY bm.applied_at DESC, u.full_name ASC";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result();

$app_pill = ['pending' => 'pending', 'approved' => 'approved', 'denied' => 'rejected'];

$page_title = 'Users';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Users</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/users.php" class="filter-pill <?= $status_filter === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($valid_statuses as $status): ?>
        <a href="<?= BASE_URL ?>/basics/admin/users.php?status=<?= $status ?>"
           class="filter-pill text-capitalize <?= $status_filter === $status ? 'active' : '' ?>"><?= $status ?></a>
      <?php endforeach; ?>
    </div>
    <a href="<?= BASE_URL ?>/basics/admin/register_member.php" class="btn-chip btn-chip-success"><i class="fas fa-user-plus"></i> Register Member</a>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>User</th><th>Contact #</th><th>Address</th><th>Application</th><th>Registered</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($users->num_rows === 0): ?>
        <tr><td colspan="6" class="text-muted">No users found.</td></tr>
      <?php endif; ?>
      <?php while ($u = $users->fetch_assoc()): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $u['id'] ?>"><?= sanitize($u['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($u['username']) ?>)</span></td>
          <td><?= sanitize($u['contact_number']) ?></td>
          <td><?= sanitize($u['address'] ?: '—') ?></td>
          <td><span class="pill pill-<?= $app_pill[$u['application_status']] ?? 'pending' ?>"><?= sanitize($u['application_status']) ?></span></td>
          <td><?= date('M j, Y', strtotime($u['applied_at'])) ?></td>
          <td class="no-print"><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $u['id'] ?>" class="btn-chip btn-chip-outline">View</a></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
