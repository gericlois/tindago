<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_access($conn);

$member = basics_get_member($conn, basics_current_user_id());
$errors = [];
$type_labels = basics_benefit_type_labels();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_request') {
    $benefit_type = $_POST['benefit_type'] ?? '';
    // Amount due / due date are no longer member-reported — admin sets the
    // real payout amount at approval time based on the uploaded documents.
    $amount_due = 0.00;
    $due_date = null;
    $relationship_to_deceased = trim($_POST['relationship_to_deceased'] ?? '') ?: null;
    $deceased_address = trim($_POST['deceased_address'] ?? '') ?: null;

    if (!isset($type_labels[$benefit_type])) {
        $errors[] = 'Choose a benefit program.';
    }
    if ($benefit_type === 'burial_assistance') {
        if ($relationship_to_deceased === null) $errors[] = 'Relationship to the deceased is required.';
        if ($deceased_address === null) $errors[] = 'Deceased\'s address is required.';
    }

    $doc_requirements = basics_benefit_doc_requirements($benefit_type);
    foreach ($doc_requirements as $field => $label) {
        if (empty($_FILES[$field]['name'])) {
            $errors[] = $label . ' is required.';
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO basics_benefit_requests
                (member_id, benefit_type, amount_due, due_date, relationship_to_deceased, deceased_address)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isdsss', $member['id'], $benefit_type, $amount_due, $due_date, $relationship_to_deceased, $deceased_address);
            $stmt->execute();
            $request_id = $stmt->insert_id;
            $stmt->close();

            foreach ($doc_requirements as $field => $label) {
                [$filename, $upload_error] = handle_benefit_document_upload($field);
                if ($upload_error) {
                    throw new Exception($label . ': ' . $upload_error);
                }
                $stmt = $conn->prepare("INSERT INTO basics_benefit_documents (request_id, doc_type, file_path) VALUES (?, ?, ?)");
                $stmt->bind_param('iss', $request_id, $field, $filename);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            basics_notify($conn, $member, "Hi {$member['full_name']}, your " . $type_labels[$benefit_type] . " request is under review. - JMC Foodies Basics", 'benefit', 'Benefit request submitted', '/benefits.php');
            redirect('/basics/benefits.php?submitted=1');
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = safe_error_message($e);
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM basics_benefit_requests WHERE member_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$requests = $stmt->get_result();

$payout_stmt = $conn->prepare("SELECT * FROM basics_payout_accounts WHERE member_id = ?");
$payout_stmt->bind_param('i', $member['id']);
$payout_stmt->execute();
$payout_account = $payout_stmt->get_result()->fetch_assoc();

$page_title = 'Member Benefits';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Subject to Approval &amp; Fund Availability</span>
    <h1 class="stitle">Member <span>Benefits</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <?php if (isset($_GET['submitted'])): ?>
    <div class="sucmsg is-visible mb-4"><p>Request submitted. An admin will review it shortly.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if (!$payout_account): ?>
    <div class="errmsg mb-4">
      <p class="mb-0">You haven't enrolled a payout account yet — benefit payouts can't be sent to you until you do. <a href="<?= BASICS_URL ?>/payout_account.php">Enroll now</a>.</p>
    </div>
  <?php else: ?>
    <div class="sucmsg is-visible mb-4">
      <p class="mb-0">Payouts go to your enrolled <?= sanitize(ucfirst($payout_account['method'] === 'gcash' ? 'GCash' : ($payout_account['method'] === 'gotyme' ? 'GoTyme' : $payout_account['method']))) ?> account. <a href="<?= BASICS_URL ?>/payout_account.php">Manage</a>.</p>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <div class="panel-card mb-4">
        <h2 class="h6 mb-3">Request Assistance</h2>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="submit_request">

          <div class="mb-3">
            <label class="flbl">Benefit Program</label>
            <select name="benefit_type" id="benefitType" class="fctrl" required>
              <option value="">Select...</option>
              <?php foreach ($type_labels as $key => $label): ?>
                <option value="<?= $key ?>"><?= sanitize($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="benefit-fields" data-for="burial_assistance" style="display:none;">
            <div class="mb-3">
              <label class="flbl">Relationship to the Deceased</label>
              <input type="text" name="relationship_to_deceased" class="fctrl">
            </div>
            <div class="mb-3">
              <label class="flbl">Deceased's Address</label>
              <input type="text" name="deceased_address" class="fctrl">
            </div>
            <div class="mb-3">
              <label class="flbl">Death Certificate</label>
              <input type="file" name="death_certificate" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
          </div>

          <div class="benefit-fields" data-for="electric_subsidy" style="display:none;">
            <div class="mb-3">
              <label class="flbl">Electric Bill</label>
              <input type="file" name="electric_bill" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
          </div>

          <div class="benefit-fields" data-for="hospital_assistance" style="display:none;">
            <div class="mb-3">
              <label class="flbl">Medical Abstract / Certificate</label>
              <input type="file" name="medical_abstract" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="mb-3">
              <label class="flbl">Hospital Bill</label>
              <input type="file" name="hospital_bill" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="mb-3">
              <label class="flbl">Prescription(s)</label>
              <input type="file" name="prescription" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
          </div>

          <div class="benefit-fields" data-for="baon_eskwela" style="display:none;">
            <div class="mb-3">
              <label class="flbl">Current Enrollment Form</label>
              <input type="file" name="enrollment_form" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="mb-3">
              <label class="flbl">Child's ID</label>
              <input type="file" name="child_id" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="mb-3">
              <label class="flbl">Child's Birth Certificate</label>
              <input type="file" name="birth_certificate" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
          </div>

          <div class="form-text mb-3">JPG, PNG, WEBP, or PDF — max 5MB each.</div>
          <button type="submit" class="btn-red"><i class="fas fa-paper-plane"></i>Submit Request</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="panel-card">
        <h2 class="h6 mb-3">My Requests</h2>
        <div class="table-responsive">
          <table class="table-theme">
            <thead><tr><th>Program</th><th>Amount Due</th><th>Amount Paid</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php if ($requests->num_rows === 0): ?>
              <tr><td colspan="5" class="text-muted">No requests yet.</td></tr>
            <?php endif; ?>
            <?php $status_pill = ['pending' => 'pending', 'approved' => 'approved', 'denied' => 'rejected']; ?>
            <?php while ($r = $requests->fetch_assoc()): ?>
              <tr>
                <td><?= sanitize($type_labels[$r['benefit_type']] ?? $r['benefit_type']) ?></td>
                <td><?= format_price($r['amount_due']) ?></td>
                <td><?= $r['amount_paid'] !== null ? format_price($r['amount_paid']) : '—' ?></td>
                <td><span class="pill pill-<?= $status_pill[$r['status']] ?? 'pending' ?>"><?= ucfirst($r['status']) ?></span></td>
                <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
              </tr>
              <?php if ($r['status'] === 'denied' && $r['admin_notes']): ?>
                <tr><td colspan="5" class="small text-muted">Reason: <?= sanitize($r['admin_notes']) ?></td></tr>
              <?php endif; ?>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var benefitType = document.getElementById('benefitType');
  var fieldGroups = document.querySelectorAll('.benefit-fields');

  benefitType.addEventListener('change', function () {
    fieldGroups.forEach(function (group) {
      var show = group.dataset.for === benefitType.value;
      group.style.display = show ? '' : 'none';
      group.querySelectorAll('input[type="file"], input[type="text"]').forEach(function (input) {
        input.required = show;
      });
    });
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
