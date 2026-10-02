<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

$errors = [];
$ai_allowed = basics_ai_review_allowed();

if ($ai_allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'prescreen') {
    $id = (int) ($_POST['id'] ?? 0);
    $result = basics_prescreen_emergency_request($conn, $id);
    if ($result['success']) {
        // Reopen the same Review modal so the admin lands right on the result.
        redirect('/basics/admin/emergency_credit.php?' . http_build_query(['status' => $_GET['status'] ?? 'pending', 'open' => $id]));
    }
    $errors[] = $result['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $id = (int) ($_POST['id'] ?? 0);
    $amount_released = round((float) ($_POST['amount_released'] ?? 0), 2);
    $notes = trim($_POST['admin_notes'] ?? '') ?: null;

    $stmt = $conn->prepare("SELECT r.*, m.emergency_credit_limit FROM basics_emergency_credit_requests r
                             JOIN basics_members m ON m.id = r.member_id
                             WHERE r.id = ? AND r.status = 'pending'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        $errors[] = 'Request not found or already reviewed.';
    } elseif ($amount_released <= 0) {
        $errors[] = 'Enter a valid amount to release.';
    } else {
        $stmt = $conn->prepare("SELECT (m.emergency_credit_limit - COALESCE((
                                    SELECT SUM(amount_released) FROM basics_emergency_credit_requests
                                    WHERE member_id = m.id AND status = 'approved'
                                 ), 0)) AS remaining_limit
                                 FROM basics_members m WHERE m.id = ?");
        $stmt->bind_param('i', $request['member_id']);
        $stmt->execute();
        $remaining_limit = (float) $stmt->get_result()->fetch_assoc()['remaining_limit'];
        $stmt->close();

        if ($amount_released > $remaining_limit) {
            $errors[] = 'That exceeds the member\'s remaining Emergency Cash Loan limit (' . format_price($remaining_limit) . ' available).';
        } else {
            $stmt = $conn->prepare("UPDATE basics_emergency_credit_requests
                SET status = 'approved', amount_released = ?, released_at = NOW(), admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
                WHERE id = ?");
            $admin_id = basics_current_admin_id();
            $stmt->bind_param('dsii', $amount_released, $notes, $admin_id, $id);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'approve_emergency_credit', 'Approved Emergency Cash Loan request #' . $id . ', released ' . format_price($amount_released));
            $member = basics_member_by_id($conn, $request['member_id']);
            if ($member) {
                basics_notify($conn, $member, "Hi {$member['full_name']}, your Emergency Cash Loan of " . format_price($amount_released) . " has been released. - JMC Foodies Basics");
            }
            redirect('/basics/admin/emergency_credit.php?approved=1');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deny') {
    $id = (int) ($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '');

    if ($notes === '') {
        $errors[] = 'A reason is required to deny a request.';
    } else {
        $admin_id = basics_current_admin_id();
        $stmt = $conn->prepare("UPDATE basics_emergency_credit_requests SET status = 'denied', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('sii', $notes, $admin_id, $id);
        $stmt->execute();
        $denied = $stmt->affected_rows > 0;
        $stmt->close();
        if ($denied) {
            log_activity($conn, 'deny_emergency_credit', 'Denied Emergency Cash Loan request #' . $id . ': ' . $notes);
            redirect('/basics/admin/emergency_credit.php?denied=1');
        }
        $errors[] = 'Request not found or already reviewed.';
    }
}

$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending', 'approved', 'denied', 'all'], true)) {
    $status_filter = 'pending';
}

$sql = "SELECT r.*, u.full_name, u.username, m.emergency_credit_limit,
               (m.emergency_credit_limit - COALESCE((
                   SELECT SUM(amount_released) FROM basics_emergency_credit_requests
                   WHERE member_id = m.id AND status = 'approved'
               ), 0)) AS remaining_limit
        FROM basics_emergency_credit_requests r
        JOIN basics_members m ON m.id = r.member_id
        JOIN basics_users u ON u.id = m.user_id";
if ($status_filter !== 'all') {
    $sql .= " WHERE r.status = '" . $conn->real_escape_string($status_filter) . "'";
}
$sql .= " ORDER BY r.created_at DESC";
$requests = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$pending_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_emergency_credit_requests WHERE status = 'pending'")->fetch_assoc()['c'];

$page_title = 'Emergency Cash Loan';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Emergency Cash Loan</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['approved'])): ?>
    <div class="sucmsg is-visible"><p>Request approved.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['denied'])): ?>
    <div class="sucmsg is-visible"><p>Request denied.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
      <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'denied' => 'Denied'] as $key => $label): ?>
        <a href="?status=<?= $key ?>" class="filter-pill <?= $status_filter === $key ? 'active' : '' ?>">
          <?= $label ?><?= $key === 'pending' && $pending_count > 0 ? ' (' . $pending_count . ')' : '' ?>
        </a>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Member</th><th>Requested</th><th>Reason</th><th>Remaining Limit</th><th>Released</th><th>Status</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (empty($requests)): ?>
        <tr><td colspan="7" class="text-muted">No requests.</td></tr>
      <?php endif; ?>
      <?php $status_pill = ['pending' => 'pending', 'approved' => 'approved', 'denied' => 'rejected']; ?>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $r['member_id'] ?>"><?= sanitize($r['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($r['username']) ?>)</span></td>
          <td><?= format_price($r['amount_requested']) ?></td>
          <td class="small"><?= $r['reason'] ? sanitize($r['reason']) : '—' ?></td>
          <td><?= format_price($r['remaining_limit']) ?> / <?= format_price($r['emergency_credit_limit']) ?></td>
          <td><?= $r['amount_released'] !== null ? format_price($r['amount_released']) : '—' ?></td>
          <td><span class="pill pill-<?= $status_pill[$r['status']] ?? 'pending' ?>"><?= ucfirst($r['status']) ?></span></td>
          <td class="no-print">
            <?php if ($r['status'] === 'pending'): ?>
              <button type="button" class="btn-chip btn-chip-success" data-bs-toggle="modal" data-bs-target="#reviewModal-<?= (int) $r['id'] ?>">Review</button>
            <?php elseif ($r['admin_notes']): ?>
              <span class="small text-muted"><?= sanitize($r['admin_notes']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($requests as $r): ?>
  <?php if ($r['status'] !== 'pending') continue; ?>
  <div class="modal fade" id="reviewModal-<?= (int) $r['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Review — <?= sanitize($r['full_name']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body d-flex flex-column gap-3">
          <?php if ($ai_allowed): ?>
            <?php $prescreen = $r['ai_analyzed_at'] ? json_decode((string) $r['ai_result'], true) : null; ?>
            <?php if (is_array($prescreen)): ?>
              <div class="errmsg" style="background:#f5f0ff; color:#3d2a66; border-color:#d9c8f5;">
                <p class="mb-2"><strong><i class="fas fa-wand-magic-sparkles"></i> AI Pre-screen</strong> <span class="small">(a summary of the records, not a decision &mdash; as of <?= date('M j, g:i A', strtotime($r['ai_analyzed_at'])) ?>)</span></p>
                <?php if ($prescreen['summary']): ?><p class="mb-2"><?= sanitize($prescreen['summary']) ?></p><?php endif; ?>
                <?php foreach ($prescreen['positives'] as $point): ?>
                  <div class="small text-success"><i class="fas fa-circle-check"></i> <?= sanitize($point) ?></div>
                <?php endforeach; ?>
                <?php foreach ($prescreen['risks'] as $point): ?>
                  <div class="small text-danger"><i class="fas fa-triangle-exclamation"></i> <?= sanitize($point) ?></div>
                <?php endforeach; ?>
                <details class="small mt-2">
                  <summary>Records used</summary>
                  <?php foreach ($prescreen['facts'] as $label => $value): ?>
                    <div><?= sanitize($label) ?>: <?= sanitize($value) ?></div>
                  <?php endforeach; ?>
                </details>
              </div>
            <?php else: ?>
              <form method="post">
                <input type="hidden" name="action" value="prescreen">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-outline"><i class="fas fa-wand-magic-sparkles"></i> AI Pre-screen Payment History</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <div>
              <label class="flbl">Amount to Release</label>
              <input type="number" step="0.01" min="0.01" max="<?= sanitize($r['remaining_limit']) ?>" name="amount_released" class="fctrl" value="<?= sanitize(min($r['amount_requested'], $r['remaining_limit'])) ?>" style="width:140px;">
            </div>
            <div>
              <label class="flbl">Notes (optional)</label>
              <input type="text" name="admin_notes" class="fctrl">
            </div>
            <button type="submit" class="btn-red" onclick="return confirm('Approve and release this amount? Make sure it has actually been handed to the member.');"><i class="fas fa-check"></i>Approve &amp; Release</button>
          </form>
          <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
            <input type="hidden" name="action" value="deny">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <div>
              <label class="flbl">Reason (required)</label>
              <input type="text" name="admin_notes" class="fctrl" required>
            </div>
            <button type="submit" class="btn-outline-theme" onclick="return confirm('Deny this request?');"><i class="fas fa-xmark"></i>Deny</button>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if (isset($_GET['open'])): ?>
<script>
window.addEventListener('load', function () {
  var modal = document.getElementById('reviewModal-<?= (int) $_GET['open'] ?>');
  if (modal && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
