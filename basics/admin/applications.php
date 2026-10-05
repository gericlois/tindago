<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

$ai_allowed = basics_ai_review_allowed();

// "Check documents" — fetch() calls this once per KYC document (one at a
// time, so each request stays inside PHP's time limit and Gemini's
// per-minute cap), then reloads to show the summary.
if ($ai_allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_check_doc') {
    header('Content-Type: application/json');
    $result = basics_analyze_kyc_document($conn, (int) ($_POST['doc_id'] ?? 0));
    echo json_encode(['success' => $result['success'], 'busy' => !empty($result['busy']), 'error' => $result['error'] ?? null]);
    exit;
}

$valid_statuses = ['pending', 'approved', 'denied'];
$status_filter = $_GET['status'] ?? 'pending';

$sql = "SELECT bm.*, u.full_name, u.username, u.email FROM basics_members bm JOIN basics_users u ON u.id = bm.user_id";
if (in_array($status_filter, $valid_statuses, true)) {
    $sql .= " WHERE bm.application_status = '" . $conn->real_escape_string($status_filter) . "'";
}
$sql .= " ORDER BY bm.applied_at DESC";
$applications = $conn->query($sql);

// AI document-check summary per applicant, keyed by member id.
$kyc_docs = [];
if ($ai_allowed) {
    $result = $conn->query("SELECT d.id, d.member_id, d.ai_analyzed_at, d.ai_result FROM basics_kyc_documents d
                             JOIN basics_members bm ON bm.id = d.member_id" .
                            (in_array($status_filter, $valid_statuses, true) ? " WHERE bm.application_status = '" . $conn->real_escape_string($status_filter) . "'" : ''));
    while ($doc = $result->fetch_assoc()) {
        $kyc_docs[$doc['member_id']][] = $doc;
    }
}

$page_title = 'Basics Applications';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Membership Applications</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/applications.php?status=" class="filter-pill <?= $status_filter === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($valid_statuses as $status): ?>
        <a href="<?= BASE_URL ?>/basics/admin/applications.php?status=<?= $status ?>"
           class="filter-pill text-capitalize <?= $status_filter === $status ? 'active' : '' ?>"><?= $status ?></a>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Applicant</th><th>Employer</th><th>Status</th><th>Applied</th><?php if ($ai_allowed): ?><th class="no-print">AI Doc Check</th><?php endif; ?><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($applications->num_rows === 0): ?>
        <tr><td colspan="<?= $ai_allowed ? 6 : 5 ?>" class="text-muted">No applications found.</td></tr>
      <?php endif; ?>
      <?php while ($a = $applications->fetch_assoc()): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $a['id'] ?>"><?= sanitize($a['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($a['username']) ?>)</span></td>
          <td><?= sanitize($a['employer_name']) ?></td>
          <td><span class="pill pill-<?= $a['application_status'] === 'approved' ? 'approved' : ($a['application_status'] === 'denied' ? 'rejected' : 'pending') ?>"><?= sanitize($a['application_status']) ?></span>
            <?php // Approval is only the application; the membership may have since been suspended/terminated (what the profile shows). ?>
            <?php if ($a['application_status'] === 'approved' && $a['membership_status'] !== 'active'): ?>
              <span class="pill pill-<?= $a['membership_status'] === 'dormant' ? 'pending' : 'suspended' ?>" title="Current membership status"><?= sanitize($a['membership_status']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= date('M j, Y', strtotime($a['applied_at'])) ?></td>
          <?php if ($ai_allowed): ?>
            <td class="no-print small">
              <?php
                $docs = $kyc_docs[$a['id']] ?? [];
                $unchecked = array_values(array_map(fn($doc) => (int) $doc['id'], array_filter($docs, fn($doc) => !$doc['ai_analyzed_at'])));
                $concerns = [];
                foreach ($docs as $doc) {
                    $doc_result = $doc['ai_analyzed_at'] ? json_decode((string) $doc['ai_result'], true) : null;
                    foreach ($doc_result['concerns'] ?? [] as $concern) {
                        $concerns[] = $concern;
                    }
                }
                $flagged = count(array_filter($docs, fn($doc) => $doc['ai_analyzed_at'] && (json_decode((string) $doc['ai_result'], true)['verdict'] ?? '') === 'check'));
              ?>
              <?php if (!$docs): ?>
                <span class="text-muted">No documents</span>
              <?php else: ?>
                <?php if ($flagged): ?>
                  <span class="pill pill-rejected"><?= $flagged ?> to check</span>
                <?php elseif (!$unchecked): ?>
                  <span class="pill pill-approved">All OK</span>
                <?php elseif (count($unchecked) === count($docs)): ?>
                  <span class="pill pill-pending">Not checked</span>
                <?php else: ?>
                  <span class="pill pill-pending"><?= count($docs) - count($unchecked) ?> of <?= count($docs) ?> checked</span>
                <?php endif; ?>
                <?php foreach (array_slice(array_unique($concerns), 0, 2) as $concern): ?>
                  <div class="text-danger"><i class="fas fa-triangle-exclamation"></i> <?= sanitize($concern) ?></div>
                <?php endforeach; ?>
                <?php if ($unchecked): ?>
                  <div class="mt-1">
                    <button type="button" class="btn-chip btn-chip-outline" data-doc-ids="<?= sanitize(implode(',', $unchecked)) ?>" onclick="aiCheckDocuments(this);"><i class="fas fa-wand-magic-sparkles"></i> Check <?= count($unchecked) ?> document<?= count($unchecked) === 1 ? '' : 's' ?></button>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <td class="no-print"><a href="<?= BASE_URL ?>/basics/admin/application_view.php?id=<?= (int) $a['id'] ?>" class="btn-chip btn-chip-outline">Review</a></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($ai_allowed): ?>
<script>
// Checks one document per request, in order, then reloads to show the
// summary. The free AI tier only allows a few requests a minute, so a
// "busy" answer waits 20s and retries the same document (up to 3 times)
// instead of failing. Any other error stops — documents already checked
// are saved, so clicking again picks up where it left off.
function aiCheckDocuments(button) {
  var ids = button.getAttribute('data-doc-ids').split(',');
  var done = 0;
  var retries = 0;
  button.disabled = true;

  function next() {
    if (done === ids.length) {
      window.location.reload();
      return;
    }
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking ' + (done + 1) + ' of ' + ids.length + '…';
    var body = new FormData();
    body.append('action', 'ai_check_doc');
    body.append('doc_id', ids[done]);
    fetch(window.location.href, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (response) {
        return response.json().catch(function () { throw new Error('Could not reach the AI. Please try again.'); });
      })
      .then(function (data) {
        if (!data.success && data.busy && retries < 3) {
          retries++;
          button.innerHTML = '<i class="fas fa-hourglass-half"></i> AI busy — retrying ' + (done + 1) + ' of ' + ids.length + ' in 20s…';
          window.setTimeout(next, 20000);
          return;
        }
        if (!data.success) throw new Error(data.error || 'AI check failed.');
        done++;
        retries = 0;
        next();
      })
      .catch(function (error) {
        button.insertAdjacentHTML('afterend', '<div class="text-danger mt-1"></div>');
        button.nextElementSibling.textContent = error.message;
        if (done > 0) {
          window.setTimeout(function () { window.location.reload(); }, 2500);
        } else {
          button.disabled = false;
          button.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Try again';
        }
      });
  }
  next();
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
