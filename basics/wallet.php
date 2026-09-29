<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_access($conn);

$member = basics_get_member($conn, basics_current_user_id());

if (!$member['is_community_partner']) {
    redirect('/basics/dashboard.php');
}

$errors = [];
$success = '';
$min_cashout = (float) setting($conn, 'basics_min_cashout_amount', 500.00);
$fee_rate = (float) setting($conn, 'basics_cashout_processing_fee_rate', 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cashout') {
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');

    if ($amount <= 0) $errors[] = 'Enter a valid amount.';
    if ($amount > 0 && $amount < $min_cashout) $errors[] = 'Minimum cashout amount is ' . format_price($min_cashout) . '.';
    if ($bank_name === '') $errors[] = 'Name of bank is required.';
    if ($account_number === '') $errors[] = 'Account number is required.';
    if ($account_name === '') $errors[] = 'Account name is required.';

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Lock this member's row first so two simultaneous requests can't
            // both read the same balance and both pay out (double-spend).
            $conn->query("SELECT id FROM basics_members WHERE id = " . (int) $member['id'] . " FOR UPDATE");
            $balance = basics_wallet_balance($conn, $member['id']);
            if ($amount > $balance) {
                throw new Exception('You cannot cash out more than your current wallet balance (' . format_price($balance) . ').');
            }

            $fee_amount = round($amount * $fee_rate, 2);
            $net_amount = round($amount - $fee_amount, 2);

            $stmt = $conn->prepare("INSERT INTO basics_cashouts (member_id, amount, fee_amount, net_amount, bank_name, account_number, account_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('idddsss', $member['id'], $amount, $fee_amount, $net_amount, $bank_name, $account_number, $account_name);
            $stmt->execute();
            $cashout_id = $stmt->insert_id;
            $stmt->close();

            basics_wallet_credit($conn, $member['id'], 'cashout', -$amount, null, $cashout_id,
                'Cashout request #' . $cashout_id . ' to ' . $bank_name . ' account ' . $account_number
                . ' (' . format_price($net_amount) . ' net of ' . (int) ($fee_rate * 100) . '% processing fee)');

            $conn->commit();
            $success = 'Cashout request submitted. You will receive ' . format_price($net_amount) . ($fee_rate > 0 ? ' after the ' . (int) ($fee_rate * 100) . '% processing fee' : '') . '. It will be processed once an admin approves it.';
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = safe_error_message($e);
        }
    }
}

$balance = basics_wallet_balance($conn, $member['id']);

$stmt = $conn->prepare("SELECT * FROM basics_wallet_transactions WHERE member_id = ? ORDER BY created_at DESC, id DESC");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$transactions = $stmt->get_result();

$stmt = $conn->prepare("SELECT * FROM basics_cashouts WHERE member_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$cashouts = $stmt->get_result();

$stmt = $conn->prepare("SELECT u.full_name, bm.applied_at,
                                (SELECT COUNT(*) FROM basics_orders o WHERE o.member_id = bm.id AND o.status != 'draft') AS order_count,
                                (SELECT COALESCE(SUM(o.total_amount),0) FROM basics_orders o WHERE o.member_id = bm.id AND o.status != 'draft') AS order_total
                         FROM basics_members bm JOIN basics_users u ON u.id = bm.user_id
                         WHERE bm.referred_by = ? ORDER BY bm.applied_at DESC");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$referrals = $stmt->get_result();

$type_labels = [
    'referral_override' => 'Referral Override',
    'cashout' => 'Cashout Request',
    'cashout_reversal' => 'Cashout Reversed',
];

$page_title = 'My Earnings';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Your Earnings</span>
    <h1 class="stitle">My <span>Earnings</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row g-3 align-items-center justify-content-center mb-4">
    <div class="col-12 col-md-4">
      <div class="stat-tile">
        <div class="stat-lbl mb-1">Current Balance</div>
        <div class="stat-num accent" style="font-size:2rem;"><?= format_price($balance) ?></div>
      </div>
    </div>
    <div class="col-12 col-md-8">
      <div class="refcode-box d-flex align-items-center justify-content-between gap-2 mb-2">
        <span id="partnerRefCode"><?= sanitize($member['referral_code']) ?></span>
        <button type="button" class="btn-copy-icon" data-copy-target="partnerRefCode" aria-label="Copy referral code"><i class="fas fa-copy"></i></button>
      </div>
      <div class="d-flex gap-2">
        <input type="text" class="fctrl" id="partnerRefLink" value="<?= sanitize(basics_referral_link($member['referral_code'])) ?>" readonly>
        <button class="btn-outline-theme" data-copy-target="partnerRefLink">Copy</button>
      </div>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="sucmsg is-visible"><p><?= sanitize($success) ?></p></div>
  <?php endif; ?>

  <div class="row g-4 mb-4">
    <div class="col-12 col-lg-5">
      <div class="panel-card">
        <h2 class="h6">Request Earnings Cashout</h2>
        <form method="post">
          <input type="hidden" name="action" value="cashout">
          <div class="mb-2">
            <label class="flbl">Amount</label>
            <input type="number" step="0.01" min="<?= $min_cashout ?>" max="<?= $balance ?>" name="amount" id="cashoutAmount" class="fctrl" required>
            <div class="form-text">Minimum cashout: <?= format_price($min_cashout) ?>.<?= $fee_rate > 0 ? ' A ' . (int) ($fee_rate * 100) . '% processing fee applies.' : '' ?></div>
            <div class="form-text" id="cashoutFeePreview"></div>
          </div>
          <div class="mb-2">
            <label class="flbl">Name of Bank</label>
            <input type="text" name="bank_name" class="fctrl" required>
          </div>
          <div class="mb-2">
            <label class="flbl">Account Number</label>
            <input type="text" name="account_number" class="fctrl" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Account Name</label>
            <input type="text" name="account_name" class="fctrl" required>
          </div>
          <button type="submit" class="btn-red w-100 justify-content-center" <?= $balance < $min_cashout ? 'disabled' : '' ?>><i class="fas fa-money-bill-transfer"></i>Request Cashout</button>
        </form>
        <?php if ($balance < $min_cashout): ?>
          <p class="small text-muted mt-2 mb-0">You need at least <?= format_price($min_cashout) ?> in your earnings wallet to request a cashout.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <h2 class="h6">Your Cashout Requests</h2>
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Amount</th><th>Fee</th><th>You Receive</th><th>Bank</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php if ($cashouts->num_rows === 0): ?>
            <tr><td colspan="6" class="text-muted">No cashout requests yet.</td></tr>
          <?php endif; ?>
          <?php while ($c = $cashouts->fetch_assoc()): ?>
            <tr>
              <td><?= format_price($c['amount']) ?></td>
              <td class="text-muted"><?= format_price($c['fee_amount']) ?></td>
              <td class="fw-bold"><?= format_price($c['net_amount']) ?></td>
              <td><?= sanitize($c['bank_name']) ?></td>
              <td><span class="pill pill-<?= $c['status'] ?>"><?= sanitize($c['status']) ?></span></td>
              <td><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <h2 class="h5 mb-3">My Referrals</h2>
  <div class="table-responsive mb-4">
    <table class="table-theme">
      <thead><tr><th>Name</th><th>Joined</th><th>Orders</th><th>Total Spent</th></tr></thead>
      <tbody>
      <?php if ($referrals->num_rows === 0): ?>
        <tr><td colspan="4" class="text-muted">No one has joined using your referral code yet.</td></tr>
      <?php endif; ?>
      <?php while ($r = $referrals->fetch_assoc()): ?>
        <tr>
          <td><?= sanitize($r['full_name']) ?></td>
          <td><?= date('M j, Y', strtotime($r['applied_at'])) ?></td>
          <td><?= (int) $r['order_count'] ?></td>
          <td><?= format_price($r['order_total']) ?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <h2 class="h5 mb-3">Transaction History</h2>
  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php if ($transactions->num_rows === 0): ?>
        <tr><td colspan="4" class="text-muted">No wallet activity yet.</td></tr>
      <?php endif; ?>
      <?php while ($t = $transactions->fetch_assoc()): ?>
        <tr>
          <td><?= date('M j, Y g:i A', strtotime($t['created_at'])) ?></td>
          <td><?= sanitize($type_labels[$t['type']] ?? $t['type']) ?></td>
          <td><?= sanitize($t['description']) ?></td>
          <td class="text-end <?= $t['amount'] >= 0 ? 'amount-credit' : 'amount-debit' ?>">
            <?= $t['amount'] >= 0 ? '+' : '' ?><?= format_price($t['amount']) ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  var cashoutAmountInput = document.getElementById('cashoutAmount');
  var cashoutFeePreview = document.getElementById('cashoutFeePreview');
  if (cashoutAmountInput) {
    cashoutAmountInput.addEventListener('input', function () {
      var amount = parseFloat(cashoutAmountInput.value);
      if (!amount || amount <= 0) {
        cashoutFeePreview.textContent = '';
        return;
      }
      var fee = amount * <?= $fee_rate ?>;
      var net = amount - fee;
      cashoutFeePreview.textContent = fee > 0 ? ('Fee: ₱' + fee.toFixed(2) + ' — You\'ll receive: ₱' + net.toFixed(2)) : '';
    });
  }
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
