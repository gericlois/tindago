<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'flag_dormant') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE basics_members SET membership_status = 'dormant' WHERE id = ? AND membership_status = 'active'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $flagged = $stmt->affected_rows > 0;
    $stmt->close();
    if ($flagged) {
        log_activity($conn, 'flag_dormant_basics_member', 'Flagged member #' . $id . ' as dormant');
        basics_add_notification($conn, $id, 'account', 'We miss you!', 'Your account has been marked inactive because there were no orders for a while. Place an order anytime to stay active.', '/catalog.php');
    }
    redirect('/basics/admin/dormancy.php');
}

$dormancy_weeks = (int) setting($conn, 'basics_dormancy_weeks', 3);
$cutoff = date('Y-m-d H:i:s', strtotime('-' . $dormancy_weeks . ' weeks'));

$stmt = $conn->prepare("SELECT bm.*, u.full_name, u.username FROM basics_members bm
                         JOIN basics_users u ON u.id = bm.user_id
                         WHERE bm.membership_status = 'active' AND bm.application_status = 'approved'
                           AND (bm.last_activity_at IS NULL OR bm.last_activity_at < ?)
                           AND bm.applied_at < ?
                         ORDER BY bm.last_activity_at ASC");
$stmt->bind_param('ss', $cutoff, $cutoff);
$stmt->execute();
$dormant_candidates = $stmt->get_result();

$page_title = 'Dormancy Report';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Dormancy Report</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <p class="text-muted mb-0">Active members with no order or payment activity in the last <?= $dormancy_weeks ?> consecutive weeks. Review and flag as dormant if appropriate &mdash; this is a manual step, not automatic.</p>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Member</th><th>Last Activity</th><th>Weekly Limit</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($dormant_candidates->num_rows === 0): ?>
        <tr><td colspan="4" class="text-muted">No members currently flagged for review.</td></tr>
      <?php endif; ?>
      <?php while ($m = $dormant_candidates->fetch_assoc()): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $m['id'] ?>"><?= sanitize($m['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($m['username']) ?>)</span></td>
          <td><?= $m['last_activity_at'] ? date('M j, Y', strtotime($m['last_activity_at'])) : 'Never' ?></td>
          <td><?= format_price($m['weekly_credit_limit']) ?></td>
          <td class="no-print">
            <a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $m['id'] ?>" class="btn-chip btn-chip-outline">View</a>
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="flag_dormant">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Flag this member as dormant?');">Flag Dormant</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
