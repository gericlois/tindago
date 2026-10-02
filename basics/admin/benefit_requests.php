<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

$errors = [];
$type_labels = basics_benefit_type_labels();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $id = (int) ($_POST['id'] ?? 0);
    $amount_paid = round((float) ($_POST['amount_paid'] ?? 0), 2);
    $notes = trim($_POST['admin_notes'] ?? '') ?: null;

    if ($amount_paid <= 0) {
        $errors[] = 'Enter a valid amount to pay out.';
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
                    basics_notify($conn, $member, "Hi {$member['full_name']}, your " . $type_labels[$req_row['benefit_type']] . " of " . format_price($amount_paid) . " has been released to your registered account. - JMC Foodies Basics");
                }
            }
            redirect('/basics/admin/benefit_requests.php?approved=1');
        }
        $errors[] = 'Request not found or already reviewed.';
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

$sql = "SELECT r.*, u.full_name, u.username FROM basics_benefit_requests r
        JOIN basics_members bm ON bm.id = r.member_id
        JOIN basics_users u ON u.id = bm.user_id
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
            $doc_stmt = $conn->prepare("SELECT id, doc_type FROM basics_benefit_documents WHERE request_id = ?");
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
                <button type="button" class="btn btn-link p-0 d-block small" data-bs-toggle="modal" data-bs-target="#docViewerModal" data-doc-url="<?= BASE_URL ?>/basics/admin/benefit_document_view.php?id=<?= (int) $doc['id'] ?>" data-doc-title="<?= sanitize($doc_type_labels[$doc['doc_type']] ?? $doc['doc_type']) ?>"><?= sanitize($doc_type_labels[$doc['doc_type']] ?? $doc['doc_type']) ?></button>
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
          <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <div>
              <label class="flbl">Amount to Pay Out</label>
              <input type="number" step="0.01" min="0.01" name="amount_paid" class="fctrl" value="<?= sanitize($r['amount_due']) ?>" style="width:140px;">
            </div>
            <div>
              <label class="flbl">Notes (optional)</label>
              <input type="text" name="admin_notes" class="fctrl">
            </div>
            <button type="submit" class="btn-red" onclick="return confirm('Approve and mark this benefit as paid out?');"><i class="fas fa-check"></i>Approve</button>
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
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
