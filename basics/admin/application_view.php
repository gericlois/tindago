<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

$id = (int) ($_GET['id'] ?? 0);
$errors = [];
$ai_allowed = basics_ai_review_allowed();

if ($ai_allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze_doc') {
    $result = basics_analyze_kyc_document($conn, (int) ($_POST['doc_id'] ?? 0));
    if ($result['success']) {
        redirect('/basics/admin/application_view.php?id=' . $id);
    }
    $errors[] = $result['error'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $admin_id = basics_current_admin_id();

    if ($action === 'approve') {
        $weekly_limit = round((float) ($_POST['weekly_credit_limit'] ?? 0), 2);
        $emergency_limit = round((float) ($_POST['emergency_credit_limit'] ?? 0), 2);
        $stmt = $conn->prepare("UPDATE basics_members SET application_status = 'approved', membership_status = 'active',
                                 weekly_credit_limit = ?, emergency_credit_limit = ?, reviewed_by = ?, reviewed_at = NOW()
                                 WHERE id = ? AND application_status = 'pending'");
        $stmt->bind_param('ddii', $weekly_limit, $emergency_limit, $admin_id, $id);
        $stmt->execute();
        $approved = $stmt->affected_rows > 0;
        $stmt->close();

        if ($approved) {
            log_activity($conn, 'approve_basics_application', 'Approved Basics application for member #' . $id . ' (weekly limit ' . format_price($weekly_limit) . ')');
            $member = basics_get_member($conn, $conn->query("SELECT user_id FROM basics_members WHERE id = $id")->fetch_assoc()['user_id']);
            if ($member) {
                // No password reset on approval — the applicant already
                // chose their own at signup, so they log in with that.
                basics_notify($conn, $member, "Hi {$member['full_name']}, your JMC Foodies Basics membership has been APPROVED! Weekly purchase limit: " . format_price($weekly_limit) . ". Log in with the username and password you set at signup. - JMC Foodies Basics");
                send_basics_account_approved_email($member['email'], $member['full_name'], $member['username'], $weekly_limit);
            }
        }
    } elseif ($action === 'deny') {
        $notes = trim($_POST['admin_notes'] ?? '');
        $stmt = $conn->prepare("UPDATE basics_members SET application_status = 'denied', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
                                 WHERE id = ? AND application_status = 'pending'");
        $stmt->bind_param('sii', $notes, $admin_id, $id);
        $stmt->execute();
        $denied = $stmt->affected_rows > 0;
        $stmt->close();

        if ($denied) {
            log_activity($conn, 'deny_basics_application', 'Denied Basics application for member #' . $id);
            $member = basics_get_member($conn, $conn->query("SELECT user_id FROM basics_members WHERE id = $id")->fetch_assoc()['user_id']);
            if ($member) {
                basics_notify($conn, $member, "Hi {$member['full_name']}, thank you for choosing to apply for the JMC Foodies Basics Program. Unfortunately, we are unable to approve your application at this time, based on your available purchase capacity and financial information. However, we would like you to consider applying after 30 days. For questions and other concerns please call +63 917 323 8153. - JMC Foodies Basics");
                send_basics_account_denied_email($member['email'], $member['full_name']);
            }
        }
    }
    redirect('/basics/admin/application_view.php?id=' . $id);
}

$stmt = $conn->prepare("SELECT bm.*, u.full_name, u.username, u.email, u.contact_number, u.address, u.birthdate
                         FROM basics_members bm JOIN basics_users u ON u.id = bm.user_id WHERE bm.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$application = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$application) {
    redirect('/basics/admin/applications.php');
}

$stmt = $conn->prepare("SELECT * FROM basics_kyc_documents WHERE member_id = ? ORDER BY doc_type ASC");
$stmt->bind_param('i', $id);
$stmt->execute();
$documents = $stmt->get_result();

$doc_labels = [
    'valid_id_1' => 'Valid ID #1',
    'valid_id_2' => 'Valid ID #2',
    'barangay_clearance' => 'Barangay Clearance',
    'membership_application_form' => 'Membership Application Form (signed) - Front Page',
    'membership_application_form_back' => 'Membership Application Form (signed) - Back Page',
    'certificate_of_employment' => 'Certificate of Employment / Work Clearance',
];

$page_title = 'Review Application';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <a href="<?= BASE_URL ?>/basics/admin/applications.php" class="small">&larr; Back to Applications</a>
    <h1 class="stitle" style="font-size:2rem;"><?= sanitize($application['full_name']) ?></h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <div class="row g-4">
    <div class="col-12 col-md-6">
      <div class="panel-card mb-4">
        <h2 class="h6">Applicant</h2>
        <p class="mb-1">Username: <?= sanitize($application['username']) ?></p>
        <p class="mb-1">Email: <?= sanitize($application['email']) ?></p>
        <p class="mb-1">Contact #: <?= sanitize($application['contact_number']) ?></p>
        <p class="mb-1">Address: <?= sanitize($application['address']) ?></p>
        <p class="mb-0">Birthdate: <?= date('M j, Y', strtotime($application['birthdate'])) ?></p>
      </div>
      <div class="panel-card mb-4">
        <h2 class="h6">Employer</h2>
        <p class="mb-1">Employer: <?= sanitize($application['employer_name']) ?></p>
        <p class="mb-1">Contact: <?= $application['employer_contact'] ? sanitize($application['employer_contact']) : '—' ?></p>
        <p class="mb-0">Position: <?= $application['position'] ? sanitize($application['position']) : '—' ?></p>
      </div>
      <div class="panel-card">
        <h2 class="h6">Submitted Documents</h2>
        <?php if ($documents->num_rows === 0): ?>
          <p class="text-muted mb-0">No documents on file.</p>
        <?php endif; ?>
        <?php while ($doc = $documents->fetch_assoc()): ?>
          <div class="mb-3">
            <button type="button" class="btn-chip btn-chip-outline" data-bs-toggle="modal" data-bs-target="#docViewerModal" data-doc-url="<?= BASE_URL ?>/basics/admin/kyc_view.php?doc_id=<?= (int) $doc['id'] ?>" data-doc-title="<?= sanitize($doc_labels[$doc['doc_type']] ?? $doc['doc_type']) ?>">
              <i class="fas fa-file-arrow-down"></i> <?= sanitize($doc_labels[$doc['doc_type']] ?? $doc['doc_type']) ?>
            </button>
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
        <?php if ($ai_allowed): ?>
          <p class="small text-muted mb-0 mt-2">AI checks are a cross-check against the application, not a decision.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12 col-md-6">
      <div class="panel-card">
        <h2 class="h6">Application Status</h2>
        <p class="mb-3">Status: <span class="pill pill-<?= $application['application_status'] === 'approved' ? 'approved' : ($application['application_status'] === 'denied' ? 'rejected' : 'pending') ?>"><?= sanitize($application['application_status']) ?></span></p>

        <?php if ($application['application_status'] === 'pending'): ?>
          <form method="post" class="mb-4">
            <input type="hidden" name="action" value="approve">
            <h3 class="h6 mb-2">Approve &amp; Set Purchase Line</h3>
            <div class="mb-2">
              <label class="flbl">Weekly Grocery Purchase Limit (₱3,000&ndash;4,000)</label>
              <input type="number" step="0.01" min="0" name="weekly_credit_limit" class="fctrl" value="3000" required>
            </div>
            <div class="mb-3">
              <label class="flbl">Emergency Cash Loan Limit (up to ₱1,000)</label>
              <input type="number" step="0.01" min="0" max="1000" name="emergency_credit_limit" class="fctrl" value="0">
            </div>
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Approve this application?');"><i class="fas fa-check"></i> Approve Membership</button>
          </form>
          <form method="post">
            <input type="hidden" name="action" value="deny">
            <h3 class="h6 mb-2">Deny</h3>
            <div class="mb-3">
              <label class="flbl">Reason (shown to applicant)</label>
              <textarea name="admin_notes" class="fctrl" rows="2"></textarea>
            </div>
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Deny this application?');"><i class="fas fa-xmark"></i> Deny Application</button>
          </form>
        <?php else: ?>
          <p class="mb-1">Weekly Purchase Limit: <?= format_price($application['weekly_credit_limit']) ?></p>
          <p class="mb-1">Emergency Loan Limit: <?= format_price($application['emergency_credit_limit']) ?></p>
          <?php if ($application['admin_notes']): ?>
            <p class="mb-0 text-muted">Notes: <?= sanitize($application['admin_notes']) ?></p>
          <?php endif; ?>
          <p class="mt-3"><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $application['id'] ?>" class="btn-chip btn-chip-outline">View Member</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
