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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $method = $_POST['method'] ?? '';
    $bank_name = trim($_POST['bank_name'] ?? '') ?: null;
    $account_name = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');

    if (!in_array($method, ['gotyme', 'gcash', 'bank'], true)) {
        $errors[] = 'Choose an account method.';
    }
    if ($method === 'bank' && $bank_name === null) {
        $errors[] = 'Bank name is required for a bank account.';
    }
    if ($account_name === '') {
        $errors[] = 'Account name is required.';
    }
    if ($account_number === '') {
        $errors[] = 'Account number is required.';
    }
    if ($method !== 'bank') {
        $bank_name = null;
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO basics_payout_accounts (member_id, method, bank_name, account_name, account_number)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE method = VALUES(method), bank_name = VALUES(bank_name),
                account_name = VALUES(account_name), account_number = VALUES(account_number)");
        $stmt->bind_param('issss', $member['id'], $method, $bank_name, $account_name, $account_number);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'update_basics_payout_account', 'Member #' . $member['id'] . ' updated their payout account (' . $method . ')');
        redirect('/basics/payout_account.php?saved=1');
    }
}

$stmt = $conn->prepare("SELECT * FROM basics_payout_accounts WHERE member_id = ?");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc() ?: ['method' => 'gcash', 'bank_name' => '', 'account_name' => '', 'account_number' => ''];

$page_title = 'Payout Account';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Where Your Benefits Get Sent</span>
    <h1 class="stitle">Payout <span>Account</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
      <?php if (isset($_GET['saved'])): ?>
        <div class="sucmsg is-visible mb-4"><p>Payout account saved.</p></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="errmsg mb-4">
          <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <div class="panel-card">
        <p class="text-muted small mb-3">This is where TindaGo sends approved benefit payouts (Electric Subsidy, Hospital/Burial Assistance, Baon Eskwela).</p>
        <form method="post">
          <input type="hidden" name="action" value="save">

          <div class="mb-3">
            <label class="flbl">Account Method</label>
            <select name="method" id="payoutMethod" class="fctrl" required>
              <option value="gcash" <?= $account['method'] === 'gcash' ? 'selected' : '' ?>>GCash</option>
              <option value="gotyme" <?= $account['method'] === 'gotyme' ? 'selected' : '' ?>>GoTyme</option>
              <option value="bank" <?= $account['method'] === 'bank' ? 'selected' : '' ?>>Bank Account</option>
            </select>
          </div>

          <div class="mb-3" id="bankNameField" style="<?= $account['method'] === 'bank' ? '' : 'display:none;' ?>">
            <label class="flbl">Bank Name</label>
            <input type="text" name="bank_name" class="fctrl" value="<?= sanitize($account['bank_name'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label class="flbl">Account Name</label>
            <input type="text" name="account_name" class="fctrl" value="<?= sanitize($account['account_name']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Account / Mobile Number</label>
            <input type="text" name="account_number" class="fctrl" value="<?= sanitize($account['account_number']) ?>" required>
          </div>

          <button type="submit" class="btn-red w-100 justify-content-center"><i class="fas fa-floppy-disk"></i>Save Payout Account</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var method = document.getElementById('payoutMethod');
  var bankField = document.getElementById('bankNameField');
  method.addEventListener('change', function () {
    bankField.style.display = method.value === 'bank' ? '' : 'none';
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
