<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    $id = (int) ($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '') ?: null;

    $stmt = $conn->prepare("SELECT * FROM basics_payment_submissions WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$submission) {
        $errors[] = 'Submission not found or already reviewed.';
    } else {
        $conn->begin_transaction();
        try {
            if ($submission['payment_for'] === 'grocery') {
                if (!$submission['order_id']) {
                    throw new Exception('This submission has no linked order to record the payment against.');
                }
                basics_record_payment($conn, $submission['order_id'], (float) $submission['amount'], $submission['paid_at'], basics_current_admin_id(), $notes);
            } elseif ($submission['payment_for'] === 'loan') {
                $member = basics_member_by_id($conn, $submission['member_id']);
                if ($member) {
                    basics_notify($conn, $member, "Hi {$member['full_name']}, we've received your payment of " . format_price($submission['amount']) . " for your Emergency Cash Loan. - JMC Foodies Basics", 'payment', 'Loan payment received', '/payments.php');
                }
            } else {
                basics_add_notification($conn, $submission['member_id'], 'payment', 'Payment confirmed',
                    'Your payment of ' . format_price($submission['amount']) . ' has been confirmed.', '/payments.php');
            }
            $stmt = $conn->prepare("UPDATE basics_payment_submissions SET status = 'confirmed', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $admin_id = basics_current_admin_id();
            $stmt->bind_param('sii', $notes, $admin_id, $id);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            log_activity($conn, 'confirm_payment_submission', 'Confirmed ' . $submission['payment_for'] . ' payment submission #' . $id . ' (' . format_price($submission['amount']) . ')');
            redirect('/basics/admin/payment_submissions.php?confirmed=1');
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = safe_error_message($e);
        }
    }
}

$ai_allowed = basics_ai_review_allowed();

if ($ai_allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze_proof') {
    $id = (int) ($_POST['id'] ?? 0);
    $result = basics_analyze_payment_proof($conn, $id);
    if ($result['success']) {
        redirect('/basics/admin/payment_submissions.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    }
    $errors[] = $result['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    $id = (int) ($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '');

    if ($notes === '') {
        $errors[] = 'A reason is required to reject a submission.';
    } else {
        $admin_id = basics_current_admin_id();
        $stmt = $conn->prepare("UPDATE basics_payment_submissions SET status = 'rejected', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('sii', $notes, $admin_id, $id);
        $stmt->execute();
        $rejected = $stmt->affected_rows > 0;
        $stmt->close();
        if ($rejected) {
            log_activity($conn, 'reject_payment_submission', 'Rejected payment submission #' . $id . ': ' . $notes);
            $row = $conn->query("SELECT member_id, amount FROM basics_payment_submissions WHERE id = " . (int) $id)->fetch_assoc();
            basics_add_notification($conn, $row['member_id'], 'payment', 'Payment not accepted',
                'Your payment submission of ' . format_price($row['amount']) . ' could not be confirmed. Reason: ' . rtrim($notes, '.') . '. Please check it and submit again.', '/payments.php');
            redirect('/basics/admin/payment_submissions.php?rejected=1');
        }
        $errors[] = 'Submission not found or already reviewed.';
    }
}

$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending', 'confirmed', 'rejected', 'all'], true)) {
    $status_filter = 'pending';
}

$sql = "SELECT s.*, u.full_name, u.username FROM basics_payment_submissions s
        JOIN basics_members bm ON bm.id = s.member_id
        JOIN basics_users u ON u.id = bm.user_id";
if ($status_filter !== 'all') {
    $sql .= " WHERE s.status = '" . $conn->real_escape_string($status_filter) . "'";
}
$sql .= " ORDER BY s.created_at DESC";
$submissions = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$pending_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_payment_submissions WHERE status = 'pending'")->fetch_assoc()['c'];

$page_title = 'Payment Submissions';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Payment Submissions</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['confirmed'])): ?>
    <div class="sucmsg is-visible"><p>Submission confirmed.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['rejected'])): ?>
    <div class="sucmsg is-visible"><p>Submission rejected.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
      <?php foreach (['all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected'] as $key => $label): ?>
        <a href="?status=<?= $key ?>" class="filter-pill <?= $status_filter === $key ? 'active' : '' ?>">
          <?= $label ?><?= $key === 'pending' && $pending_count > 0 ? ' (' . $pending_count . ')' : '' ?>
        </a>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Member</th><th>For</th><th>Method</th><th>Sent To</th><th>Amount</th><th>Reference #</th><th>Paid At</th><th class="no-print">Proof</th><?php if ($ai_allowed): ?><th class="no-print">AI Check</th><?php endif; ?><th>Status</th><th>Reason</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (empty($submissions)): ?>
        <tr><td colspan="<?= $ai_allowed ? 12 : 11 ?>" class="text-muted">No submissions.</td></tr>
      <?php endif; ?>
      <?php $for_labels = ['grocery' => 'Grocery', 'loan' => 'Loan', 'other' => 'Other']; ?>
      <?php $status_pill = ['pending' => 'pending', 'confirmed' => 'approved', 'rejected' => 'rejected']; ?>
      <?php foreach ($submissions as $s): ?>
        <?php
          $amount_matches = $s['ai_analyzed_at'] && $s['ai_extracted_amount'] !== null && abs((float) $s['ai_extracted_amount'] - (float) $s['amount']) <= 0.01;
          $reference_matches = $s['ai_analyzed_at'] && $s['ai_extracted_reference'] !== null
              && strcasecmp(trim((string) $s['ai_extracted_reference']), trim((string) $s['reference_number'])) === 0;
        ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $s['member_id'] ?>"><?= sanitize($s['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($s['username']) ?>)</span></td>
          <td><?= $for_labels[$s['payment_for']] ?? sanitize($s['payment_for']) ?><?= $s['order_id'] ? ' #' . (int) $s['order_id'] : '' ?><?= $s['loan_request_id'] ? ' #' . (int) $s['loan_request_id'] : '' ?></td>
          <td><?= sanitize($s['payment_method']) ?></td>
          <td class="small"><?= sanitize($s['destination_account']) ?></td>
          <td><?= format_price($s['amount']) ?></td>
          <td><?= sanitize($s['reference_number']) ?></td>
          <td><?= date('M j, Y g:i A', strtotime($s['paid_at'])) ?></td>
          <td class="no-print"><?php if ($s['proof_image']): ?><button type="button" class="btn btn-link p-0" data-bs-toggle="modal" data-bs-target="#docViewerModal" data-doc-url="<?= BASE_URL ?>/basics/admin/payment_proof_view.php?id=<?= (int) $s['id'] ?>" data-doc-title="Payment Proof — <?= sanitize($s['full_name']) ?>">View</button><?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td>
          <?php if ($ai_allowed): ?>
          <td class="no-print small">
            <?php if (!$s['proof_image']): ?>
              <span class="text-muted">&mdash;</span>
            <?php elseif (!$s['ai_analyzed_at']): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="analyze_proof">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-outline"><i class="fas fa-wand-magic-sparkles"></i> Analyze</button>
              </form>
            <?php else: ?>
              <div>Amount: <?= $s['ai_extracted_amount'] !== null ? format_price($s['ai_extracted_amount']) : '<span class="text-muted">not read</span>' ?>
                <?php if ($s['ai_extracted_amount'] !== null): ?><span class="pill pill-<?= $amount_matches ? 'approved' : 'rejected' ?>"><?= $amount_matches ? 'Match' : 'Mismatch' ?></span><?php endif; ?>
              </div>
              <div>Ref: <?= $s['ai_extracted_reference'] !== null ? sanitize($s['ai_extracted_reference']) : '<span class="text-muted">not read</span>' ?>
                <?php if ($s['ai_extracted_reference'] !== null): ?><span class="pill pill-<?= $reference_matches ? 'approved' : 'rejected' ?>"><?= $reference_matches ? 'Match' : 'Mismatch' ?></span><?php endif; ?>
              </div>
              <?php if ($s['ai_notes']): ?><div class="text-muted"><?= sanitize($s['ai_notes']) ?></div><?php endif; ?>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td><span class="pill pill-<?= $status_pill[$s['status']] ?? 'pending' ?>"><?= ucfirst($s['status']) ?></span></td>
          <td class="small"><?= $s['admin_notes'] ? sanitize($s['admin_notes']) : '<span class="text-muted">—</span>' ?></td>
          <td class="no-print">
            <?php if ($s['status'] === 'pending'): ?>
              <button type="button" class="btn-chip btn-chip-success" data-bs-toggle="modal" data-bs-target="#reviewModal-<?= (int) $s['id'] ?>">Review</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($submissions as $s): ?>
  <?php if ($s['status'] !== 'pending') continue; ?>
  <div class="modal fade" id="reviewModal-<?= (int) $s['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Review — <?= sanitize($s['full_name']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body d-flex flex-column gap-3">
          <?php if ($ai_allowed && $s['ai_analyzed_at']): ?>
            <?php
              $amount_matches = $s['ai_extracted_amount'] !== null && abs((float) $s['ai_extracted_amount'] - (float) $s['amount']) <= 0.01;
              $reference_matches = $s['ai_extracted_reference'] !== null
                  && strcasecmp(trim((string) $s['ai_extracted_reference']), trim((string) $s['reference_number'])) === 0;
            ?>
            <div class="errmsg" style="background:#f5f0ff; color:#3d2a66; border-color:#d9c8f5;">
              <p class="mb-1"><strong>AI Review</strong> (not a decision — just a cross-check against what the member typed)</p>
              <p class="mb-1">Amount read: <?= $s['ai_extracted_amount'] !== null ? format_price($s['ai_extracted_amount']) : 'not legible' ?>
                <?php if ($s['ai_extracted_amount'] !== null): ?> — <?= $amount_matches ? 'matches' : 'does NOT match' ?> the submitted amount (<?= format_price($s['amount']) ?>)<?php endif; ?></p>
              <p class="mb-1">Reference read: <?= $s['ai_extracted_reference'] !== null ? sanitize($s['ai_extracted_reference']) : 'not legible' ?>
                <?php if ($s['ai_extracted_reference'] !== null): ?> — <?= $reference_matches ? 'matches' : 'does NOT match' ?> the submitted reference (<?= sanitize($s['reference_number']) ?>)<?php endif; ?></p>
              <?php if ($s['ai_notes']): ?><p class="mb-0 small text-muted">Note: <?= sanitize($s['ai_notes']) ?></p><?php endif; ?>
            </div>
          <?php endif; ?>
          <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <div>
              <label class="flbl">Notes (optional)</label>
              <input type="text" name="admin_notes" class="fctrl">
            </div>
            <button type="submit" class="btn-red" onclick="return confirm('Confirm this payment?<?= $s['payment_for'] === 'grocery' ? ' This will apply the late-payment penalty tier automatically if applicable.' : '' ?>');"><i class="fas fa-check"></i>Confirm</button>
          </form>
          <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <div>
              <label class="flbl">Reason (required)</label>
              <input type="text" name="admin_notes" class="fctrl" required>
            </div>
            <button type="submit" class="btn-outline-theme" onclick="return confirm('Reject this submission?');"><i class="fas fa-xmark"></i>Reject</button>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
