<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders', 'staff_payments', 'staff_registration']);

// Texting a member is an admin action (member profiles are view-only for
// staff — see member_view.php), so the Send SMS column is too.
$can_send_sms = in_array(basics_admin_role(), ['super_admin', 'admin'], true);
$sms_errors = [];
$sms_on = sms_enabled();

if ($can_send_sms && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_sms') {
    $member = basics_member_by_id($conn, (int) ($_POST['member_id'] ?? 0));
    $sms_message = trim($_POST['sms_message'] ?? '');

    if (!$sms_on) {
        $sms_errors[] = sanitize(sms_disabled_notice());
    } elseif (!$member) {
        $sms_errors[] = 'Member not found.';
    } elseif (empty($member['contact_number'])) {
        $sms_errors[] = sanitize($member['full_name']) . ' has no contact number on file.';
    }
    if ($sms_message === '') {
        $sms_errors[] = 'Enter a message.';
    } elseif (strlen($sms_message) > 480) {
        $sms_errors[] = 'Message is too long (max 480 characters, about 3 SMS segments).';
    }

    if (empty($sms_errors)) {
        // Same as the Send SMS box on the member's profile: text, log, and
        // a copy in the member's notification feed.
        if (send_sms($member['contact_number'], $sms_message)) {
            log_activity($conn, 'send_basics_member_sms', 'Texted member "' . $member['full_name'] . '"');
            basics_add_notification($conn, $member['id'], 'message', 'Message from TindaGo', basics_notification_text($sms_message));
            redirect('/basics/admin/members.php?' . http_build_query(['status' => $_GET['status'] ?? 'active', 'barangay' => $_GET['barangay'] ?? '', 'sms_sent' => $member['full_name']]));
        }
        $sms_errors[] = 'Failed to send — check the Semaphore SMS configuration (config/sms.php) and your SMS credits.';
    }
}

$valid_statuses = ['active', 'suspended', 'dormant', 'terminated'];
// No status in the URL = Active; the All pill passes an empty status.
$status_filter = $_GET['status'] ?? 'active';
$barangay_filter = trim($_GET['barangay'] ?? '');

$barangays = $conn->query("SELECT DISTINCT u.barangay FROM basics_members bm
    JOIN basics_users u ON u.id = bm.user_id
    WHERE bm.application_status = 'approved' AND u.barangay IS NOT NULL AND u.barangay != ''
    ORDER BY u.barangay ASC")->fetch_all(MYSQLI_ASSOC);

// recent_order_* = the member's latest placed order (carts still in draft
// don't count).
$sql = "SELECT bm.*, u.full_name, u.username, u.address, u.barangay, u.city, u.contact_number,
               ro.id AS recent_order_id, ro.status AS recent_order_status
        FROM basics_members bm
        JOIN basics_users u ON u.id = bm.user_id
        LEFT JOIN basics_orders ro ON ro.id = (
            SELECT o.id FROM basics_orders o
            WHERE o.member_id = bm.id AND o.status != 'draft'
            ORDER BY COALESCE(o.placed_at, o.created_at) DESC, o.id DESC LIMIT 1
        )
        WHERE bm.application_status = 'approved'";
if (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " AND bm.membership_status = '" . $conn->real_escape_string($status_filter) . "'";
}
if ($barangay_filter !== '') {
    $sql .= " AND u.barangay = '" . $conn->real_escape_string($barangay_filter) . "'";
}
$sql .= " ORDER BY u.full_name ASC";
$members = $conn->query($sql);

$can_open_orders = basics_admin_can_open('/basics/admin/order_view.php');

$page_title = 'Members';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Members</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['sms_sent'])): ?>
    <div class="sucmsg is-visible mb-4"><p class="mb-0">SMS sent to <?= sanitize($_GET['sms_sent']) ?>.</p></div>
  <?php endif; ?>
  <?php if ($can_send_sms && !$sms_on && !$sms_errors): ?>
    <div class="errmsg mb-4"><p class="mb-0"><i class="fas fa-ban"></i> <?= sanitize(sms_disabled_notice()) ?></p></div>
  <?php endif; ?>
  <?php if ($sms_errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($sms_errors as $error): ?><li><?= $error ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/members.php?status=" class="filter-pill <?= $status_filter === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($valid_statuses as $status): ?>
        <a href="<?= BASE_URL ?>/basics/admin/members.php?status=<?= $status ?>"
           class="filter-pill text-capitalize <?= $status_filter === $status ? 'active' : '' ?>"><?= $status ?></a>
      <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <?php if (!empty($barangays)): ?>
        <form method="get" class="d-inline">
          <input type="hidden" name="status" value="<?= sanitize($status_filter) ?>">
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
      <thead><tr><th>Member</th><th>Contact #</th><th>Address</th><th>On-Time Streak</th><th>Recent Order</th><th>Status</th><?php if ($can_send_sms): ?><th class="no-print"></th><?php endif; ?></tr></thead>
      <tbody>
      <?php if ($members->num_rows === 0): ?>
        <tr><td colspan="<?= $can_send_sms ? 7 : 6 ?>" class="text-muted">No members found.</td></tr>
      <?php endif; ?>
      <?php while ($m = $members->fetch_assoc()): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $m['id'] ?>"><?= sanitize($m['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($m['username']) ?>)</span></td>
          <?php // Flag numbers that aren't 11 digits (09XXXXXXXXX), ignoring spaces/dashes. ?>
          <?php $bad_number = $m['contact_number'] && strlen(preg_replace('/\D/', '', $m['contact_number'])) !== 11; ?>
          <td<?= $bad_number ? ' class="text-danger fw-semibold" title="Not an 11-digit number"' : '' ?>><?= sanitize($m['contact_number'] ?: '—') ?></td>
          <td><?= sanitize($m['address'] ?: '—') ?></td>
          <td><?= (int) $m['consecutive_on_time_payments'] ?></td>
          <td>
            <?php if ($m['recent_order_id']): ?>
              <?php $recent_pill = '<span class="pill pill-' . basics_order_status_pill($m['recent_order_status']) . '">' . basics_order_status_label($m['recent_order_status']) . '</span> <span class="small text-muted">#' . (int) $m['recent_order_id'] . '</span>'; ?>
              <?php if ($can_open_orders): ?>
                <a href="<?= BASE_URL ?>/basics/admin/order_view.php?id=<?= (int) $m['recent_order_id'] ?>" class="text-decoration-none"><?= $recent_pill ?></a>
              <?php else: ?>
                <?= $recent_pill ?>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted small">No orders yet</span>
            <?php endif; ?>
          </td>
          <td><span class="pill pill-<?= $m['membership_status'] === 'active' ? 'active' : ($m['membership_status'] === 'dormant' ? 'pending' : 'suspended') ?>"><?= sanitize($m['membership_status']) ?></span></td>
          <?php if ($can_send_sms): ?>
            <td class="no-print">
              <?php if (!$sms_on): ?>
                <span class="text-muted small" title="<?= sanitize(sms_disabled_notice()) ?>"><i class="fas fa-ban"></i> SMS off</span>
              <?php elseif ($m['contact_number']): ?>
                <button type="button" class="btn-chip btn-chip-outline" data-bs-toggle="modal" data-bs-target="#smsModal"
                        data-member-id="<?= (int) $m['id'] ?>" data-member-name="<?= sanitize($m['full_name']) ?>" data-member-number="<?= sanitize($m['contact_number']) ?>">
                  <i class="fas fa-comment-sms"></i> Send SMS
                </button>
              <?php else: ?>
                <span class="text-muted small">No number</span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($can_send_sms): ?>
  <?php // One shared modal, filled in from the clicked row's data-* attributes. ?>
  <div class="modal fade" id="smsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post" onsubmit="return confirm('Send this SMS to ' + document.getElementById('smsMemberNumber').textContent + '? This uses real SMS credits.');">
          <input type="hidden" name="action" value="send_sms">
          <input type="hidden" name="member_id" id="smsMemberId">
          <div class="modal-header">
            <h5 class="modal-title">Send SMS &mdash; <span id="smsMemberName"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="small text-muted mb-2">To <span id="smsMemberNumber"></span>. A copy also appears in the member's notifications.</p>
            <textarea name="sms_message" class="fctrl" rows="4" maxlength="480" required placeholder="Type your message"></textarea>
            <div class="form-text">Max 480 characters (~3 SMS segments).</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-chip btn-chip-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn-chip btn-chip-success"><i class="fas fa-paper-plane"></i> Send SMS</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <script>
  document.getElementById('smsModal').addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    document.getElementById('smsMemberId').value = button.getAttribute('data-member-id');
    document.getElementById('smsMemberName').textContent = button.getAttribute('data-member-name');
    document.getElementById('smsMemberNumber').textContent = button.getAttribute('data-member-number');
    this.querySelector('textarea').value = '';
  });
  </script>
<?php endif; ?>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
