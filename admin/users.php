<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    update_user_status($conn, (int) $_POST['id'], $_POST['new_status'] ?? '');
    redirect('/admin/users.php');
}

$users = $conn->query("SELECT u.*, ref.full_name AS referrer_name,
                               (SELECT COUNT(*) FROM users d WHERE d.referred_by = u.id) AS referral_count
                        FROM users u
                        LEFT JOIN users ref ON ref.id = u.referred_by
                        ORDER BY u.created_at DESC");

$page_title = 'Users';
require __DIR__ . '/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">Manage</span>
    <h1 class="stitle" style="font-size:2rem;">Users</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Name</th><th>Username</th><th>Referral Code</th><th>Referred By</th><th>Referrals</th><th>JMC Wallet Balance</th><th>Status</th><th>Joined</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($users->num_rows === 0): ?>
        <tr><td colspan="9" class="text-muted">No users yet.</td></tr>
      <?php endif; ?>
      <?php while ($u = $users->fetch_assoc()): ?>
        <tr>
          <td><?= sanitize($u['full_name']) ?></td>
          <td><?= sanitize($u['username']) ?></td>
          <td><code><?= sanitize($u['referral_code']) ?></code></td>
          <td><?= $u['referrer_name'] ? sanitize($u['referrer_name']) : '—' ?></td>
          <td><?= (int) $u['referral_count'] ?></td>
          <td><?= format_price(wallet_balance($conn, $u['id'])) ?></td>
          <td><span class="pill pill-<?= $u['status'] ?>"><?= sanitize($u['status']) ?></span></td>
          <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
          <td class="no-print">
            <a href="<?= BASE_URL ?>/admin/user_view.php?id=<?= (int) $u['id'] ?>" class="btn-chip btn-chip-outline">View</a>
            <?php if ($u['status'] === 'pending'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="new_status" value="active">
                <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Approve this user? They will be able to log in.');">Approve</button>
              </form>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="new_status" value="suspended">
                <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Reject this registration? They will not be able to log in.');">Reject</button>
              </form>
            <?php elseif ($u['status'] === 'active'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="new_status" value="suspended">
                <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Suspend this user? They will not be able to log in.');">Suspend</button>
              </form>
            <?php else: ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="new_status" value="active">
                <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Reinstate this user?');">Reinstate</button>
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
