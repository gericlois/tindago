<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';

require_basics_admin_role(['super_admin', 'admin']);

$channel_filter = $_GET['channel'] ?? '';
$valid_channels = ['sms', 'email'];

$sql = "SELECT * FROM communication_log WHERE module = 'basics'";
if (in_array($channel_filter, $valid_channels, true)) {
    $sql .= " AND channel = '" . $conn->real_escape_string($channel_filter) . "'";
}
$sql .= " ORDER BY created_at DESC LIMIT 300";
$logs = $conn->query($sql);

$page_title = 'Communication Log';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">JMC Foodies Basics</span>
    <h1 class="stitle" style="font-size:2rem;">Communication Log</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/communication_log.php" class="filter-pill <?= $channel_filter === '' ? 'active' : '' ?>">All</a>
      <a href="<?= BASE_URL ?>/basics/admin/communication_log.php?channel=sms" class="filter-pill <?= $channel_filter === 'sms' ? 'active' : '' ?>">SMS</a>
      <a href="<?= BASE_URL ?>/basics/admin/communication_log.php?channel=email" class="filter-pill <?= $channel_filter === 'email' ? 'active' : '' ?>">Email</a>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <p class="text-muted small mb-3">Showing the most recent 300 Basics SMS/email sends — automatic triggers and admin-initiated messages alike.</p>

  <div class="panel-card">
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>Date</th><th>Channel</th><th>Recipient</th><th>Subject / Message</th><th>Status</th><th>Sent By</th></tr></thead>
        <tbody>
        <?php if ($logs->num_rows === 0): ?>
          <tr><td colspan="6" class="text-muted">No messages sent yet.</td></tr>
        <?php endif; ?>
        <?php while ($log = $logs->fetch_assoc()): ?>
          <tr>
            <td class="small"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
            <td><span class="pill pill-<?= $log['channel'] === 'sms' ? 'processing' : 'pending' ?>"><?= strtoupper($log['channel']) ?></span></td>
            <td class="small"><?= sanitize($log['recipient']) ?></td>
            <td class="small">
              <?php if ($log['subject']): ?><strong><?= sanitize($log['subject']) ?></strong><br><?php endif; ?>
              <?= sanitize(mb_strimwidth($log['message'], 0, 120, '…')) ?>
            </td>
            <td><span class="pill pill-<?= $log['status'] === 'sent' ? 'approved' : 'rejected' ?>"><?= sanitize($log['status']) ?></span></td>
            <td class="small"><?= $log['admin_name'] ? sanitize($log['admin_name']) : '<span class="text-muted">System</span>' ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
