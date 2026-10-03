<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

$errors = [];
$type_labels = basics_benefit_type_labels();
$payout_method_labels = ['gcash' => 'GCash', 'gotyme' => 'GoTyme', 'bank' => 'Bank transfer'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $id = (int) ($_POST['id'] ?? 0);
    $amount_paid = round((float) ($_POST['amount_paid'] ?? 0), 2);
    $notes = trim($_POST['admin_notes'] ?? '') ?: null;

    // The payout is a manual transfer to the member's enrolled account, so
    // there must be one to send to before this can be marked paid out.
    $stmt = $conn->prepare("SELECT pa.id FROM basics_benefit_requests r
                             JOIN basics_payout_accounts pa ON pa.member_id = r.member_id
                             WHERE r.id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $has_payout_account = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($amount_paid <= 0) {
        $errors[] = 'Enter a valid amount to pay out.';
    } elseif (!$has_payout_account) {
        $errors[] = 'This member has no payout account enrolled yet, so there is nowhere to send the payout. Ask them to enroll one under Payout Account first.';
    } else {
        $admin_id = basics_current_admin_id();
        $stmt = $conn->prepare("UPDATE basics_benefit_requests
            SET status = 'approved', amount_paid = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('dsii', $amount_paid, $notes, $admin_id, $id);
        $stmt->execute();
        $approved = $stmt->affected_rows > 0;
        $stmt->close();
        if ($approved) {
            log_activity($conn, 'approve_benefit_request', 'Approved benefit request #' . $id . ', paid ' . format_price($amount_paid));
            $stmt = $conn->prepare("SELECT member_id, benefit_type FROM basics_benefit_requests WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $req_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($req_row) {
                $member = basics_member_by_id($conn, $req_row['member_id']);
                if ($member) {
                    basics_notify($conn, $member, "Hi {$member['full_name']}, your " . $type_labels[$req_row['benefit_type']] . " of " . format_price($amount_paid) . " has been released to your registered account. - JMC Foodies Basics", 'benefit', 'Benefit released', '/benefits.php');
                }
            }
            redirect('/basics/admin/benefit_requests.php?approved=1');
        }
        $errors[] = 'Request not found or already reviewed.';
    }
}

$ai_allowed = basics_ai_review_allowed();

if ($ai_allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze_doc') {
    $result = basics_analyze_benefit_document($conn, (int) ($_POST['doc_id'] ?? 0));
    if ($result['success']) {
        redirect('/basics/admin/benefit_requests.php?' . http_build_query(['status' => $_GET['status'] ?? 'pending', 'type' => $_GET['type'] ?? '']));
    }
    $errors[] = $result['error'];
}

// "Remind to add payout account" — shown in the Review modal when the
// member has no payout account, since approval is blocked until they do.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remind_payout') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT member_id, benefit_type FROM basics_benefit_requests WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $req_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $member = $req_row ? basics_member_by_id($conn, $req_row['member_id']) : null;

    if (!$member) {
        $errors[] = 'Request not found or already reviewed.';
    } else {
        $link = absolute_url(BASICS_URL . '/payout_account.php');
        $benefit = $type_labels[$req_row['benefit_type']] ?? 'benefit';
        $sms_sent = basics_notify($conn, $member, "Hi {$member['full_name']}, to receive your {$benefit} payout, please add your GCash, GoTyme or bank account here: {$link} - JMC Foodies Basics", 'benefit', 'Add your payout account', '/payout_account.php');
        $email_sent = !empty($member['email']) && send_email($member['email'], 'Add your payout account to receive your ' . $benefit,
            "Hi {$member['full_name']},\r\n\r\n"
            . "Your {$benefit} request is being reviewed, but we can't send the payout yet because you haven't added a payout account.\r\n\r\n"
            . "Please add your GCash, GoTyme or bank account here:\r\n{$link}\r\n\r\n"
            . "Once it's added, we can release your payout.\r\n\r\n"
            . '— JMC Foodies Basics Team');

        $channels = array_keys(array_filter(['SMS' => $sms_sent, 'email' => $email_sent]));
        if ($channels) {
            log_activity($conn, 'remind_payout_account', 'Reminded member #' . $req_row['member_id'] . ' to add a payout account (benefit request #' . $id . ') via ' . implode(' and ', $channels));
            redirect('/basics/admin/benefit_requests.php?' . http_build_query(['status' => $_GET['status'] ?? 'pending', 'type' => $_GET['type'] ?? '', 'reminded' => implode(' and ', $channels)]));
        }
        $errors[] = 'The reminder could not be sent — the member has no email on file and SMS failed or is turned off (Settings → SMS notifications).';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deny') {
    $id = (int) ($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '');

    if ($notes === '') {
        $errors[] = 'A reason is required to deny a request.';
    } else {
        $admin_id = basics_current_admin_id();
        $stmt = $conn->prepare("UPDATE basics_benefit_requests SET status = 'denied', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('sii', $notes, $admin_id, $id);
        $stmt->execute();
        $denied = $stmt->affected_rows > 0;
        $stmt->close();
        if ($denied) {
            log_activity($conn, 'deny_benefit_request', 'Denied benefit request #' . $id . ': ' . $notes);
            $row = $conn->query("SELECT member_id, benefit_type FROM basics_benefit_requests WHERE id = " . (int) $id)->fetch_assoc();
            basics_add_notification($conn, $row['member_id'], 'benefit', 'Benefit request not approved',
                'Your ' . ($type_labels[$row['benefit_type']] ?? 'benefit') . ' request was not approved. Reason: ' . $notes, '/benefits.php');
            redirect('/basics/admin/benefit_requests.php?denied=1');
        }
        $errors[] = 'Request not found or already reviewed.';
    }
}

$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending', 'approved', 'denied', 'all'], true)) {
    $status_filter = 'pending';
}
$type_filter = $_GET['type'] ?? '';
if (!isset($type_labels[$type_filter])) {
    $type_filter = '';
}

$sql = "SELECT r.*, u.full_name, u.username,
               pa.method AS payout_method, pa.bank_name AS payout_bank_name,
               pa.account_name AS payout_account_name, pa.account_number AS payout_account_number
        FROM basics_benefit_requests r
        JOIN basics_members bm ON bm.id = r.member_id
        JOIN basics_users u ON u.id = bm.user_id
        LEFT JOIN basics_payout_accounts pa ON pa.member_id = r.member_id
        WHERE 1=1";
if ($status_filter !== 'all') {
    $sql .= " AND r.status = '" . $conn->real_escape_string($status_filter) . "'";
}
if ($type_filter !== '') {
    $sql .= " AND r.benefit_type = '" . $conn->real_escape_string($type_filter) . "'";
}
$sql .= " ORDER BY r.created_at DESC";
$requests = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$pending_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_benefit_requests WHERE status = 'pending'")->fetch_assoc()['c'];

$page_title = 'Member Benefits';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Member Benefit Requests</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['approved'])): ?>
    <div class="sucmsg is-visible"><p>Request approved.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['denied'])): ?>
    <div class="sucmsg is-visible"><p>Request denied.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['reminded'])): ?>
    <div class="sucmsg is-visible"><p>Payout account reminder sent by <?= sanitize($_GET['reminded']) ?>.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
      <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'denied' => 'Denied'] as $key => $label): ?>
        <a href="?status=<?= $key ?>&type=<?= urlencode($type_filter) ?>" class="filter-pill <?= $status_filter === $key ? 'active' : '' ?>">
          <?= $label ?><?= $key === 'pending' && $pending_count > 0 ? ' (' . $pending_count . ')' : '' ?>
        </a>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="d-flex flex-wrap gap-2 mb-4">
    <a href="?status=<?= $status_filter ?>&type=" class="filter-pill <?= $type_filter === '' ? 'active' : '' ?>">All Programs</a>
    <?php foreach ($type_labels as $key => $label): ?>
      <a href="?status=<?= $status_filter ?>&type=<?= urlencode($key) ?>" class="filter-pill <?= $type_filter === $key ? 'active' : '' ?>"><?= sanitize($label) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="panel-card">
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>Member</th><th>Program</th><th>Amount Due</th><th>Amount Paid</th><th>Due Date</th><th class="no-print">Documents</th><th>Status</th><th class="no-print"></th></tr></thead>
        <tbody>
        <?php if (empty($requests)): ?>
          <tr><td colspan="8" class="text-muted">No requests.</td></tr>
        <?php endif; ?>
        <?php $status_pill = ['pending' => 'pending', 'approved' => 'approved', 'denied' => 'rejected']; ?>
        <?php foreach ($requests as $r): ?>
          <?php
            $doc_stmt = $conn->prepare("SELECT id, doc_type, ai_analyzed_at, ai_result FROM basics_benefit_documents WHERE request_id = ?");
            $doc_stmt->bind_param('i', $r['id']);
            $doc_stmt->execute();
            $docs = $doc_stmt->get_result();
            $doc_type_labels = basics_benefit_doc_requirements($r['benefit_type']);
          ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $r['member_id'] ?>"><?= sanitize($r['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($r['username']) ?>)</span></td>
            <td><?= sanitize($type_labels[$r['benefit_type']] ?? $r['benefit_type']) ?>
              <?php if ($r['benefit_type'] === 'burial_assistance'): ?>
                <div class="small text-muted"><?= sanitize($r['relationship_to_deceased']) ?> &mdash; <?= sanitize($r['deceased_address']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= format_price($r['amount_due']) ?></td>
            <td><?= $r['amount_paid'] !== null ? format_price($r['amount_paid']) : '—' ?></td>
            <td><?= $r['due_date'] ? date('M j, Y', strtotime($r['due_date'])) : '—' ?></td>
            <td class="no-print">
              <?php while ($doc = $docs->fetch_assoc()): ?>
                <div class="mb-2">
                  <button type="button" class="btn btn-link p-0 small" data-bs-toggle="modal" data-bs-target="#docViewerModal" data-doc-url="<?= BASE_URL ?>/basics/admin/benefit_document_view.php?id=<?= (int) $doc['id'] ?>" data-doc-title="<?= sanitize($doc_type_labels[$doc['doc_type']] ?? $doc['doc_type']) ?>"><?= sanitize($doc_type_labels[$doc['doc_type']] ?? $doc['doc_type']) ?></button>
                  <?php if (!$ai_allowed): ?>
                  <?php elseif ($doc['ai_analyzed_at']): ?>
                    <?= basics_ai_doc_result_html($doc['ai_result']) ?>
                  <?php else: ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="analyze_doc">
                      <input type="hidden" name="doc_id" value="<?= (int) $doc['id'] ?>">
                      <button type="submit" class="btn-chip btn-chip-outline"><i class="fas fa-wand-magic-sparkles"></i> Analyze</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endwhile; ?>
            </td>
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
          <?php if ($r['payout_method']): ?>
            <div class="panel-card mb-0" style="padding:12px 16px;">
              <p class="mb-1"><strong>Send the payout to</strong></p>
              <p class="mb-1"><?= sanitize($payout_method_labels[$r['payout_method']] ?? $r['payout_method']) ?><?= $r['payout_bank_name'] ? ' — ' . sanitize($r['payout_bank_name']) : '' ?></p>
              <p class="mb-1">Account name: <strong><?= sanitize($r['payout_account_name']) ?></strong></p>
              <p class="mb-0">Account number: <strong><?= sanitize($r['payout_account_number']) ?></strong></p>
            </div>
            <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <div>
                <label class="flbl">Amount to Pay Out</label>
                <input type="number" step="0.01" min="0.01" name="amount_paid" class="fctrl" value="<?= sanitize($r['amount_due']) ?>" style="width:140px;">
              </div>
              <div>
                <label class="flbl">Transfer ref # / Notes (optional)</label>
                <input type="text" name="admin_notes" class="fctrl">
              </div>
              <button type="submit" class="btn-red" onclick="return confirm('Have you already sent this amount to the member\'s <?= sanitize($payout_method_labels[$r['payout_method']] ?? $r['payout_method']) ?> account?\n\nApproving marks it as paid out and texts the member that the money has been released.');"><i class="fas fa-check"></i>Approve &amp; Mark Paid Out</button>
            </form>
            <p class="small text-muted mb-0">Send the money first, then approve &mdash; approving texts the member that it's been released.</p>
          <?php else: ?>
            <div class="errmsg mb-0">
              <p class="mb-2"><strong>No payout account enrolled.</strong> This member hasn't added a GCash, GoTyme or bank account yet, so there's nowhere to send the payout. Ask them to enroll one under <em>Payout Account</em> in their Basics account, then approve. You can still deny the request below.</p>
              <form method="post" class="mb-0">
                <input type="hidden" name="action" value="remind_payout">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn-outline-theme" onclick="return confirm('Text and email this member a link to add their payout account?');"><i class="fas fa-bell"></i>Remind to Add Payout Account</button>
              </form>
            </div>
          <?php endif; ?>
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
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
