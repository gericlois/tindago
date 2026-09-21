<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';

require_wellness_access($conn);

$user_id = current_user_id();
$product_id = (int) ($_POST['product_id'] ?? $_GET['product'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    redirect('/wellness/menu.php');
}

$rebate_rate = (float) setting($conn, 'personal_rebate_rate', 0.20);
$override_rate = (float) setting($conn, 'referral_override_rate', 0.10);

$balance = wallet_balance($conn, $user_id);

$errors = [];
$quantity = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
    $valid_methods = ['wallet', 'bank_transfer', 'cod'];
    $payment_method = in_array($_POST['payment_method'] ?? '', $valid_methods, true) ? $_POST['payment_method'] : 'bank_transfer';
    $payment_reference = trim($_POST['payment_reference'] ?? '');
    $total = round($product['srp'] * $quantity, 2);

    if ($payment_method === 'wallet' && $balance < $total) {
        $errors[] = 'Your wallet balance is not enough to cover this purchase.';
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            if ($payment_method === 'wallet') {
                // Serialize concurrent wallet payments per member (see wallet.php).
                $conn->query("SELECT id FROM users WHERE id = " . (int) $user_id . " FOR UPDATE");
                $fresh_balance = wallet_balance($conn, $user_id);
                if ($fresh_balance < $total) {
                    throw new Exception('Your wallet balance is not enough to cover this purchase.');
                }
            }

            $stmt = $conn->prepare("INSERT INTO orders (user_id, product_id, quantity, unit_price, total_amount, payment_method, payment_reference, status)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            // Wallet payment is already verified (real, previously-earned balance) and
            // COD payment happens at the delivery moment itself, so both skip straight
            // to "processing" — only bank transfer needs an admin to confirm proof of
            // payment first. Rewards still only credit once delivered either way.
            $status = in_array($payment_method, ['wallet', 'cod'], true) ? 'processing' : 'pending';
            $ref_or_null = $payment_reference !== '' ? $payment_reference : null;
            $stmt->bind_param('iiiddsss', $user_id, $product['id'], $quantity, $product['srp'], $total, $payment_method, $ref_or_null, $status);
            $stmt->execute();
            $order_id = $stmt->insert_id;
            $stmt->close();

            if ($payment_method === 'wallet') {
                wallet_credit($conn, $user_id, 'purchase_wallet_debit', -$total, $order_id, null,
                    'Paid for order #' . $order_id . ' using wallet balance');
            }

            $conn->commit();
            redirect('/wellness/orders.php?purchased=1&method=' . $payment_method);
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = safe_error_message($e);
        }
    }
}

$page_title = 'Buy ' . $product['name'];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Place an Order</span>
    <h1 class="stitle">Buy <span><?= sanitize($product['name']) ?></span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-7">
      <form method="post" novalidate>
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
        <div class="panel-card mb-3">
          <h2 class="h6">Order Details</h2>
          <div class="row g-3 align-items-center mb-2">
            <?php if ($product['image']): ?>
              <div class="col-4 col-sm-3">
                <img src="<?= UPLOAD_URL ?>products/<?= sanitize($product['image']) ?>" alt="<?= sanitize($product['name']) ?>" class="product-photo" style="height:100px;border-radius:10px;">
              </div>
            <?php endif; ?>
            <div class="col">
              <p class="mb-1 fw-bold"><?= sanitize($product['name']) ?></p>
              <p class="mb-0 small text-muted"><?= sanitize($product['description']) ?></p>
            </div>
          </div>
          <p class="mb-1">SRP: <span class="fw-bold"><?= format_price($product['srp']) ?></span> per unit</p>
          <div class="mb-0">
            <label class="flbl">Quantity</label>
            <input type="number" name="quantity" id="qty" class="fctrl" min="1" value="<?= (int) $quantity ?>" style="max-width: 8rem;">
          </div>
        </div>

        <div class="panel-card mb-3">
          <h2 class="h6">Payment Method</h2>
          <p class="small text-muted mb-2">Your current wallet balance: <strong><?= format_price($balance) ?></strong></p>

          <div class="form-check mb-2">
            <input class="form-check-input pm-option" type="radio" name="payment_method" id="pm_wallet" value="wallet" <?= $balance >= $product['srp'] ? '' : 'disabled' ?>>
            <label class="form-check-label" for="pm_wallet">
              Payment via <?= sanitize(WALLET_NAME) ?>
              <?php if ($balance < $product['srp']): ?><span class="text-muted">(insufficient balance)</span><?php endif; ?>
            </label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input pm-option" type="radio" name="payment_method" id="pm_bank" value="bank_transfer" checked>
            <label class="form-check-label" for="pm_bank">Payment via Bank Transfer &mdash; Eastwest QR</label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input pm-option" type="radio" name="payment_method" id="pm_cod" value="cod">
            <label class="form-check-label" for="pm_cod">Payment via Cash on Pick-up or Cash on Delivery</label>
          </div>

          <div id="pm_bank_details">
            <div class="text-center mb-3">
              <img src="<?= BASE_URL ?>/assets/img/wellness/qr_code.jpg" alt="Eastwest Bank QR code" style="max-width:220px;width:100%;border-radius:10px;border:1px solid #eee;">
              <p class="small text-muted mt-2 mb-0">Scan to pay via Eastwest, then enter your reference number below.</p>
            </div>
            <label class="flbl">Payment Reference Number (optional)</label>
            <input type="text" name="payment_reference" class="fctrl" placeholder="e.g. after you send payment">
            <div class="form-text">Bank transfer orders are reviewed by an admin before your rebate/override is credited.</div>
          </div>
        </div>

        <button type="submit" class="btn-red justify-content-center"><i class="fas fa-bag-shopping"></i>Place Order</button>
      </form>
    </div>

    <div class="col-12 col-lg-5">
      <div class="panel-card">
        <h2 class="h6">You'll Earn</h2>
        <p class="mb-1">Personal Rebate (<?= (int) ($rebate_rate * 100) ?>%): <span class="fw-bold amount-credit"><?= format_price($product['srp'] * $rebate_rate) ?></span> per unit</p>
        <p class="mb-0 small text-muted">If you were referred, your referrer earns a <?= (int) ($override_rate * 100) ?>% override on this purchase too.</p>
      </div>
    </div>
  </div>
</div>

<script>
  document.querySelectorAll('.pm-option').forEach(function (radio) {
    radio.addEventListener('change', function () {
      document.getElementById('pm_bank_details').style.display = document.getElementById('pm_bank').checked ? '' : 'none';
    });
  });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
