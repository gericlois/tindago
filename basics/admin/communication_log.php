<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders', 'staff_payments', 'staff_registration']);

$channel_filter = $_GET['channel'] ?? '';
$valid_channels = ['sms', 'email'];

// Date range is optional — an empty bound means "no limit on that side",
// unlike supplier_summary.php's range filter which always defaults to today.
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

$sql = "SELECT * FROM communication_log WHERE module = 'basics'";
if (in_array($channel_filter, $valid_channels, true)) {
    $sql .= " AND channel = '" . $conn->real_escape_string($channel_filter) . "'";
}
if ($start_date !== '') {
    $sql .= " AND created_at >= '" . $conn->real_escape_string($start_date) . " 00:00:00'";
}
if ($end_date !== '') {
    $sql .= " AND created_at <= '" . $conn->real_escape_string($end_date) . " 23:59:59'";
}
$sql .= " ORDER BY created_at DESC LIMIT 300";
$logs = $conn->query($sql);

// Every filter link/form below needs to carry whichever filters are
// currently NOT the one it's changing, so switching one never silently
// drops the other.
$date_query = ($start_date !== '' ? '&start_date=' . urlencode($start_date) : '')
    . ($end_date !== '' ? '&end_date=' . urlencode($end_date) : '');
$channel_query = in_array($channel_filter, $valid_channels, true) ? '&channel=' . $channel_filter : '';

$page_title = 'Communication Log';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Communication Log</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/communication_log.php<?= $date_query !== '' ? '?' . ltrim($date_query, '&') : '' ?>" class="filter-pill <?= $channel_filter === '' ? 'active' : '' ?>">All</a>
      <a href="<?= BASE_URL ?>/basics/admin/communication_log.php?channel=sms<?= $date_query ?>" class="filter-pill <?= $channel_filter === 'sms' ? 'active' : '' ?>">SMS</a>
      <a href="<?= BASE_URL ?>/basics/admin/communication_log.php?channel=email<?= $date_query ?>" class="filter-pill <?= $channel_filter === 'email' ? 'active' : '' ?>">Email</a>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <form method="get" class="d-flex align-items-end flex-wrap gap-2 mb-4">
    <?php if (in_array($channel_filter, $valid_channels, true)): ?><input type="hidden" name="channel" value="<?= sanitize($channel_filter) ?>"><?php endif; ?>
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
      <a href="<?= BASE_URL ?>/basics/admin/communication_log.php<?= $channel_query !== '' ? '?' . ltrim($channel_query, '&') : '' ?>" class="btn-outline-theme"><i class="fas fa-xmark"></i> Clear Dates</a>
    <?php endif; ?>
  </form>

  <p class="text-muted small mb-3">Showing the most recent 300 SMS/email sends — automatic triggers and admin-initiated messages alike.</p>

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
            <td class="small" data-order="<?= strtotime($log['created_at']) ?>"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
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
