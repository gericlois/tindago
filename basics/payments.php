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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_payment') {
    $payment_for = $_POST['payment_for'] ?? '';
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $loan_request_id = (int) ($_POST['loan_request_id'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $bank_choice = $_POST['bank_choice'] ?? '';
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $reference_number = trim($_POST['reference_number'] ?? '');
    $paid_at = trim($_POST['paid_at'] ?? '');

    if (!in_array($payment_for, ['grocery', 'loan', 'other'], true)) {
        $errors[] = 'Choose what this payment is for.';
    }
    if (!in_array($payment_method, ['gcash', 'bank'], true)) {
        $errors[] = 'Choose a payment method.';
    }
    if ($payment_method === 'bank' && !in_array($bank_choice, ['pnb', 'eastwest'], true)) {
        $errors[] = 'Choose which bank you transferred to.';
    }
    if ($amount <= 0) {
        $errors[] = 'Enter a valid amount.';
    }
    if ($reference_number === '') {
        $errors[] = 'Reference number is required.';
    }
    if ($paid_at === '') {
        $errors[] = 'Date and time paid is required.';
    }

    if ($payment_for === 'grocery') {
        if ($order_id <= 0) {
            $errors[] = 'Choose which order this payment is for.';
        } else {
            $stmt = $conn->prepare("SELECT o.id, o.total_amount, o.delivered_at,
                                            (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS amount_paid
                                     FROM basics_orders o
                                     WHERE o.id = ? AND o.member_id = ? AND o.status IN ('confirmed', 'out_for_delivery', 'delivered')");
            $stmt->bind_param('ii', $order_id, $member['id']);
            $stmt->execute();
            $selected_order = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$selected_order) {
                $errors[] = 'That order was not found or is not awaiting payment.';
            } else {
                // Same "order total + overdue penalty, minus what's already paid"
                // figure shown to admins on the Record Payment page — a grocery
                // payment must cover it in full, no partial payments.
                $projection = basics_projected_penalty($conn, $selected_order, $member['offense_count']);
                $amount_due = round((float) $selected_order['total_amount'] + ($projection['amount'] ?? 0) - (float) $selected_order['amount_paid'], 2);
                if ($amount_due <= 0) {
                    $errors[] = 'That order is already fully paid.';
                } elseif ($amount > 0 && $amount < $amount_due) {
                    $errors[] = 'Your payment of ' . format_price($amount) . ' is less than the amount due (' . format_price($amount_due) . ') for order #' . $order_id . '. Partial payments are not accepted — please pay the full amount due.';
                }
            }
        }
    } else {
        $order_id = null;
    }

    if ($payment_for === 'loan') {
        if ($loan_request_id <= 0) {
            $errors[] = 'Choose which Emergency Cash Credit request this payment is for.';
        } else {
            $stmt = $conn->prepare("SELECT r.*, (r.amount_released - COALESCE((SELECT SUM(s.amount) FROM basics_payment_submissions s WHERE s.loan_request_id = r.id AND s.status = 'confirmed'), 0)) AS remaining
                                     FROM basics_emergency_credit_requests r
                                     WHERE r.id = ? AND r.member_id = ? AND r.status = 'approved'");
            $stmt->bind_param('ii', $loan_request_id, $member['id']);
            $stmt->execute();
            $loan = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$loan || $loan['remaining'] <= 0) {
                $errors[] = 'That Emergency Cash Credit request was not found or has no balance left.';
            }
        }
    } else {
        $loan_request_id = null;
    }

    if ($payment_method === 'gcash') {
        $destination_account = 'GCash — ' . setting($conn, 'basics_gcash_number', '(not yet configured)') . ' (' . setting($conn, 'basics_gcash_name', 'JMC Foodies Basics') . ')';
    } elseif ($payment_method === 'bank' && $bank_choice === 'pnb') {
        $destination_account = 'PNB — ' . setting($conn, 'basics_pnb_account_number', '(not yet configured)') . ' (' . setting($conn, 'basics_pnb_account_name', 'JMC Foodies Basics') . ')';
    } elseif ($payment_method === 'bank' && $bank_choice === 'eastwest') {
        $destination_account = 'EastWest — ' . setting($conn, 'basics_eastwest_account_number', '(not yet configured)') . ' (' . setting($conn, 'basics_eastwest_account_name', 'JMC Foodies Basics') . ')';
    } else {
        $destination_account = '';
    }

    $proof_filename = null;
    if (empty($errors)) {
        [$proof_filename, $upload_error] = handle_payment_proof_upload('proof_image');
        if ($upload_error) {
            $errors[] = $upload_error;
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO basics_payment_submissions
            (member_id, payment_for, order_id, loan_request_id, payment_method, destination_account, amount, reference_number, paid_at, proof_image)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isiissdsss', $member['id'], $payment_for, $order_id, $loan_request_id, $payment_method, $destination_account, $amount, $reference_number, $paid_at, $proof_filename);
        $stmt->execute();
        $stmt->close();
        redirect('/basics/payments.php?submitted=1');
    }
}

$stmt = $conn->prepare("SELECT p.*, o.id AS order_id FROM basics_payments p
                         JOIN basics_orders o ON o.id = p.order_id
                         WHERE p.member_id = ? ORDER BY p.created_at DESC");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$payments = $stmt->get_result();

$awaiting_orders = basics_member_awaiting_orders($conn, $member['id']);
$awaiting_list = [];
while ($row = $awaiting_orders->fetch_assoc()) {
    $awaiting_list[] = $row;
}

$outstanding_loans = basics_member_outstanding_loans($conn, $member['id']);
$outstanding_loans_list = [];
while ($row = $outstanding_loans->fetch_assoc()) {
    $outstanding_loans_list[] = $row;
}

$stmt = $conn->prepare("SELECT s.*, o.status AS order_status, o.total_amount AS order_total,
                                (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS order_amount_paid
                         FROM basics_payment_submissions s
                         LEFT JOIN basics_orders o ON o.id = s.order_id
                         WHERE s.member_id = ? ORDER BY s.created_at DESC");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$submissions = $stmt->get_result();

$gcash_number = setting($conn, 'basics_gcash_number', '');
$gcash_name = setting($conn, 'basics_gcash_name', 'JMC Foodies Basics');
$pnb_account_number = setting($conn, 'basics_pnb_account_number', '');
$pnb_account_name = setting($conn, 'basics_pnb_account_name', 'JMC Foodies Basics');
$eastwest_account_number = setting($conn, 'basics_eastwest_account_number', '');
$eastwest_account_name = setting($conn, 'basics_eastwest_account_name', 'JMC Foodies Basics');

$page_title = 'Payments';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Settlement History</span>
    <h1 class="stitle">My <span>Payments</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <?php if (isset($_GET['submitted'])): ?>
    <div class="sucmsg is-visible mb-4"><p>Payment submitted. An admin will review and confirm it shortly.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-4 align-items-stretch">
    <div class="col-6 col-md-3">
      <div class="stat-tile">
        <div class="stat-num"><?= (int) $member['offense_count'] ?></div>
        <div class="stat-lbl">Late Payment Offenses</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile">
        <div class="stat-num accent"><?= (int) $member['consecutive_on_time_payments'] ?></div>
        <div class="stat-lbl">Consecutive On-Time</div>
      </div>
    </div>
    <div class="col-12 col-md-6 d-flex align-items-center justify-content-md-end">
      <button type="button" class="btn-outline-theme" data-bs-toggle="collapse" data-bs-target="#policyInfo"><i class="fas fa-circle-info"></i>Payment &amp; Late Payment Policy</button>
    </div>
  </div>

  <div class="d-flex justify-content-end mb-4">
    <button type="button" class="btn-red" data-bs-toggle="collapse" data-bs-target="#payForm"><i class="fas fa-money-bill-wave"></i>Pay!</button>
  </div>

  <div class="collapse mb-4" id="policyInfo">
    <div class="panel-card">
      <h2 class="h6 mb-2">Payment Policy</h2>
      <p class="mb-4">All outstanding balances must be settled within <strong>7 days of your order being delivered</strong>. Failure to pay may result in penalties, suspension, reduction of purchase limit, or termination.</p>

      <h2 class="h6 mb-3">Late Payment Policy</h2>
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Offense</th><th>Penalty</th><th>Grace Period</th><th>Consequence</th></tr></thead>
          <tbody>
            <tr>
              <td>1st</td>
              <td>3%</td>
              <td>Up to 7 days</td>
              <td>—</td>
            </tr>
            <tr>
              <td>2nd</td>
              <td>5%</td>
              <td>Up to 7 days</td>
              <td>1-month account suspension</td>
            </tr>
            <tr>
              <td>3rd</td>
              <td>5%</td>
              <td>—</td>
              <td>Permanent account termination</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-muted small mb-0">Offense counts are cumulative and never reset. Penalties apply automatically when an admin confirms a late payment.</p>
    </div>
  </div>

  <div class="collapse mb-4<?= $errors ? ' show' : '' ?>" id="payForm">
    <div class="panel-card">
      <h2 class="h6 mb-3">Submit a Payment</h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="submit_payment">

        <div class="mb-3">
          <label class="flbl">What are you paying for?</label>
          <select name="payment_for" id="payment_for" class="fctrl" required>
            <option value="">Select...</option>
            <option value="grocery"<?= empty($awaiting_list) ? ' disabled' : '' ?>>Grocery Order<?= empty($awaiting_list) ? ' (no unpaid orders)' : '' ?></option>
            <option value="loan"<?= empty($outstanding_loans_list) ? ' disabled' : '' ?>>Loan / Emergency Credit<?= empty($outstanding_loans_list) ? ' (nothing outstanding)' : '' ?></option>
            <option value="other">Other</option>
          </select>
        </div>

        <div class="mb-3" id="order-field" style="display:none;">
          <label class="flbl">Which order?</label>
          <select name="order_id" class="fctrl">
            <option value="">Select an order...</option>
            <?php foreach ($awaiting_list as $o): ?>
              <?php
                $due_date = basics_payment_due_date($o);
                $due_label = $due_date ? ('Due ' . date('M j', strtotime($due_date))) : 'awaiting delivery';
                $projection = basics_projected_penalty($conn, $o, $member['offense_count']);
                $remaining = round($o['total_amount'] + ($projection['amount'] ?? 0) - $o['amount_paid'], 2);
                $penalty_note = $projection ? ' incl. ' . (int) round($projection['rate'] * 100) . '% overdue penalty' : '';
              ?>
              <option value="<?= (int) $o['id'] ?>" data-remaining="<?= sanitize($remaining) ?>">
                #<?= (int) $o['id'] ?> — <?= format_price($remaining) ?> due<?= $penalty_note ?> (<?= $due_label ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3" id="loan-field" style="display:none;">
          <label class="flbl">Which Emergency Cash Credit request?</label>
          <select name="loan_request_id" class="fctrl">
            <option value="">Select a request...</option>
            <?php foreach ($outstanding_loans_list as $l): ?>
              <option value="<?= (int) $l['id'] ?>" data-remaining="<?= sanitize($l['remaining']) ?>">
                #<?= (int) $l['id'] ?> — released <?= format_price($l['amount_released']) ?> — <?= format_price($l['remaining']) ?> remaining
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">No open request? <a href="<?= BASICS_URL ?>/emergency_credit.php">Request Emergency Cash Credit</a> first.</div>
        </div>

        <div class="mb-3">
          <label class="flbl">Payment Method</label>
          <select name="payment_method" id="payment_method" class="fctrl" required>
            <option value="">Select...</option>
            <option value="gcash">GCash</option>
            <option value="bank">Bank Transfer</option>
          </select>
        </div>

        <div class="mb-3 errmsg" id="gcash-info" style="display:none; background:#eef6ff; color:#1a3d5c; border-color:#bcdcf5;">
          <p class="mb-0">Send payment to GCash <strong><?= sanitize($gcash_number ?: 'not yet configured — contact support') ?></strong> (<?= sanitize($gcash_name) ?>).</p>
        </div>

        <div class="mb-3" id="bank-field" style="display:none;">
          <label class="flbl">Which bank did you transfer to?</label>
          <select name="bank_choice" id="bank_choice" class="fctrl">
            <option value="">Select...</option>
            <option value="pnb">PNB</option>
            <option value="eastwest">EastWest</option>
          </select>
        </div>

        <div class="mb-3 errmsg text-center" id="bank-info-pnb" style="display:none; background:#eef6ff; color:#1a3d5c; border-color:#bcdcf5;">
          <img src="<?= BASE_URL ?>/assets/img/basics/qr_pnb.jpg" alt="PNB QR code" style="max-width:220px;width:100%;border-radius:10px;border:1px solid #eee;">
          <p class="mb-0 mt-2">Send payment to PNB, account <strong><?= sanitize($pnb_account_number ?: 'not yet configured — contact support') ?></strong> (<?= sanitize($pnb_account_name) ?>).</p>
        </div>
        <div class="mb-3 errmsg text-center" id="bank-info-eastwest" style="display:none; background:#eef6ff; color:#1a3d5c; border-color:#bcdcf5;">
          <img src="<?= BASE_URL ?>/assets/img/basics/qr_eastwest.jpg" alt="EastWest QR code" style="max-width:220px;width:100%;border-radius:10px;border:1px solid #eee;">
          <p class="mb-0 mt-2">Send payment to EastWest, account <strong><?= sanitize($eastwest_account_number ?: 'not yet configured — contact support') ?></strong> (<?= sanitize($eastwest_account_name) ?>).</p>
        </div>

        <div class="row">
          <div class="col-sm-6 mb-3">
            <label class="flbl">Amount Paid</label>
            <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="fctrl" required>
          </div>
          <div class="col-sm-6 mb-3">
            <label class="flbl">Reference Number</label>
            <input type="text" name="reference_number" class="fctrl" required>
          </div>
        </div>

        <div class="mb-3">
          <label class="flbl">Date &amp; Time Paid</label>
          <input type="datetime-local" name="paid_at" class="fctrl" value="<?= date('Y-m-d\TH:i') ?>" required>
        </div>

        <div class="mb-3">
          <label class="flbl">Proof of Payment</label>
          <input type="file" name="proof_image" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          <div class="form-text">Screenshot of the GCash/bank receipt, JPG/PNG/WEBP/PDF, max 5MB.</div>
        </div>

        <button type="submit" class="btn-red"><i class="fas fa-paper-plane"></i>Submit Payment</button>
      </form>
    </div>
  </div>

  <div class="panel-card mb-4">
    <h2 class="h6 mb-3">My Submissions</h2>
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>For</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
        <?php if ($submissions->num_rows === 0): ?>
          <tr><td colspan="4" class="text-muted">No submissions yet.</td></tr>
        <?php endif; ?>
        <?php $for_labels = ['grocery' => 'Grocery', 'loan' => 'Loan', 'other' => 'Other']; ?>
        <?php $status_pill = ['pending' => 'pending', 'confirmed' => 'approved', 'rejected' => 'rejected']; ?>
        <?php while ($s = $submissions->fetch_assoc()): ?>
          <?php $is_order_paid = $s['order_id'] && $s['order_amount_paid'] >= $s['order_total']; ?>
          <tr>
            <td><?= $for_labels[$s['payment_for']] ?? sanitize($s['payment_for']) ?><?= $s['order_id'] ? ' #' . (int) $s['order_id'] : '' ?><?= $s['loan_request_id'] ? ' #' . (int) $s['loan_request_id'] : '' ?></td>
            <td><?= format_price($s['amount']) ?></td>
            <td><span class="pill pill-<?= $is_order_paid ? 'approved' : ($status_pill[$s['status']] ?? 'pending') ?>"><?= $is_order_paid ? 'Paid' : ucfirst($s['status']) ?></span></td>
            <td><?= date('M j, Y', strtotime($s['created_at'])) ?></td>
          </tr>
          <?php if ($s['status'] === 'rejected' && $s['admin_notes']): ?>
            <tr><td colspan="4" class="small text-muted">Reason: <?= sanitize($s['admin_notes']) ?></td></tr>
          <?php endif; ?>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel-card mb-4">
    <h2 class="h6 mb-3">Settlement History</h2>
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>Order #</th><th>Amount Due</th><th>Penalty</th><th>Paid</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
        <?php if ($payments->num_rows === 0): ?>
          <tr><td colspan="6" class="text-muted">No payments recorded yet.</td></tr>
        <?php endif; ?>
        <?php while ($p = $payments->fetch_assoc()): ?>
          <tr>
            <td>#<?= (int) $p['order_id'] ?></td>
            <td><?= format_price($p['amount_due']) ?></td>
            <td class="<?= $p['penalty_amount'] > 0 ? 'amount-debit' : '' ?>"><?= format_price($p['penalty_amount']) ?></td>
            <td><?= format_price($p['amount_paid']) ?></td>
            <td><span class="pill pill-<?= $p['is_late'] ? 'rejected' : 'approved' ?>"><?= $p['is_late'] ? 'Late' : 'On Time' ?></span></td>
            <td><?= date('M j, Y', strtotime($p['paid_at'])) ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var paymentFor = document.getElementById('payment_for');
  var orderField = document.getElementById('order-field');
  var loanField = document.getElementById('loan-field');
  var paymentMethod = document.getElementById('payment_method');
  var gcashInfo = document.getElementById('gcash-info');
  var bankField = document.getElementById('bank-field');
  var bankChoice = document.getElementById('bank_choice');
  var bankInfoPnb = document.getElementById('bank-info-pnb');
  var bankInfoEastwest = document.getElementById('bank-info-eastwest');
  var orderSelect = orderField.querySelector('select[name="order_id"]');
  var amountInput = document.getElementById('amount');

  paymentFor.addEventListener('change', function () {
    orderField.style.display = paymentFor.value === 'grocery' ? '' : 'none';
    loanField.style.display = paymentFor.value === 'loan' ? '' : 'none';
  });

  // Grocery payments must cover the full amount due (no partial payments) —
  // prefill it and set a floor so the field guides the member to the right
  // number instead of just rejecting a lower one after they submit.
  orderSelect.addEventListener('change', function () {
    var opt = orderSelect.options[orderSelect.selectedIndex];
    var due = opt ? opt.dataset.remaining : '';
    if (due) {
      amountInput.value = due;
      amountInput.min = due;
    } else {
      amountInput.removeAttribute('min');
    }
  });

  function updateBankInfo() {
    bankInfoPnb.style.display = bankChoice.value === 'pnb' ? '' : 'none';
    bankInfoEastwest.style.display = bankChoice.value === 'eastwest' ? '' : 'none';
  }

  paymentMethod.addEventListener('change', function () {
    gcashInfo.style.display = paymentMethod.value === 'gcash' ? '' : 'none';
    bankField.style.display = paymentMethod.value === 'bank' ? '' : 'none';
    if (paymentMethod.value !== 'bank') {
      bankChoice.value = '';
    }
    updateBankInfo();
  });

  bankChoice.addEventListener('change', updateBankInfo);
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
