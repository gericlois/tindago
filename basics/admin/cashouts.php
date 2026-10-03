<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $conn->prepare("SELECT c.*, u.full_name FROM basics_cashouts c
                             JOIN basics_members bm ON bm.id = c.member_id
                             JOIN basics_users u ON u.id = bm.user_id
                             WHERE c.id = ? AND c.status = 'pending'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $cashout = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($cashout) {
        $admin_id = basics_current_admin_id();
        if ($action === 'approve') {
            $stmt = $conn->prepare("UPDATE basics_cashouts SET status = 'approved', processed_by = ?, processed_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $admin_id, $id);
            $stmt->execute();
            $stmt->close();

            log_activity($conn, 'approve_basics_cashout', 'Approved Basics cashout #' . $id . ' (' . format_price($cashout['net_amount']) . ') for ' . $cashout['full_name']);
            basics_add_notification($conn, $cashout['member_id'], 'earnings', 'Cash-out approved',
                'Your cash-out of ' . format_price($cashout['net_amount']) . ' has been approved and is being sent to you.', '/wallet.php');
        } elseif ($action === 'reject') {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE basics_cashouts SET status = 'rejected', processed_by = ?, processed_at = NOW() WHERE id = ?");
                $stmt->bind_param('ii', $admin_id, $id);
                $stmt->execute();
                $stmt->close();

                basics_wallet_credit($conn, $cashout['member_id'], 'cashout_reversal', $cashout['amount'], null, $id,
                    'Cashout request #' . $id . ' rejected — funds returned');

                $conn->commit();
                log_activity($conn, 'reject_basics_cashout', 'Rejected Basics cashout #' . $id . ' (' . format_price($cashout['amount']) . ') for ' . $cashout['full_name']);
                basics_add_notification($conn, $cashout['member_id'], 'earnings', 'Cash-out not approved',
                    'Your cash-out request of ' . format_price($cashout['amount']) . ' was not approved. The amount has been returned to your earnings balance.', '/wallet.php');
            } catch (Exception $e) {
                $conn->rollback();
            }
        }
    }
    redirect('/basics/admin/cashouts.php');
}

$valid_statuses = ['pending', 'approved', 'rejected'];
$status_filter = $_GET['status'] ?? 'pending';

$sql = "SELECT c.*, u.full_name, u.username FROM basics_cashouts c
        JOIN basics_members bm ON bm.id = c.member_id
        JOIN basics_users u ON u.id = bm.user_id";
if (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " WHERE c.status = '" . $conn->real_escape_string($status_filter) . "'";
}
$sql .= " ORDER BY c.created_at DESC";
$cashouts = $conn->query($sql);

$page_title = 'Cashout Requests';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">Manage</span>
    <h1 class="stitle" style="font-size:2rem;">Cashout Requests</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/cashouts.php?status=" class="filter-pill <?= $status_filter === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($valid_statuses as $status): ?>
        <a href="<?= BASE_URL ?>/basics/admin/cashouts.php?status=<?= $status ?>"
           class="filter-pill text-capitalize <?= $status_filter === $status ? 'active' : '' ?>"><?= $status ?></a>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Member</th><th>Requested</th><th>Fee</th><th>Pay Out</th><th>Bank</th><th>Account #</th><th>Account Name</th><th>Status</th><th>Date</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($cashouts->num_rows === 0): ?>
        <tr><td colspan="10" class="text-muted">No cashout requests found.</td></tr>
      <?php endif; ?>
      <?php while ($c = $cashouts->fetch_assoc()): ?>
        <tr>
          <td><?= sanitize($c['full_name']) ?> <span class="text-muted small">(<?= sanitize($c['username']) ?>)</span></td>
          <td><?= format_price($c['amount']) ?></td>
          <td class="text-muted"><?= format_price($c['fee_amount']) ?></td>
          <td class="fw-bold"><?= format_price($c['net_amount']) ?></td>
          <td><?= sanitize($c['bank_name']) ?></td>
          <td><?= sanitize($c['account_number']) ?></td>
          <td><?= sanitize($c['account_name']) ?></td>
          <td><span class="pill pill-<?= $c['status'] ?>"><?= sanitize($c['status']) ?></span></td>
          <td><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
          <td class="no-print">
            <?php if ($c['status'] === 'pending'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Confirm you have paid out <?= format_price($c['net_amount']) ?> (net of <?= format_price($c['fee_amount']) ?> fee) to this member?');">Approve</button>
              </form>
              <form method="post" class="d-inline">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Reject and return funds to the member\'s wallet?');">Reject</button>
              </form>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
