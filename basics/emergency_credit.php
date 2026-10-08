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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_credit') {
    $amount_requested = round((float) ($_POST['amount_requested'] ?? 0), 2);
    $reason = trim($_POST['reason'] ?? '') ?: null;
    $available = basics_emergency_credit_available($conn, $member);

    if ($amount_requested <= 0) {
        $errors[] = 'Enter a valid amount.';
    } elseif ($amount_requested > $available) {
        $errors[] = 'You can request up to ' . format_price($available) . ' based on your remaining Emergency Cash Loan limit.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO basics_emergency_credit_requests (member_id, amount_requested, reason) VALUES (?, ?, ?)");
        $stmt->bind_param('ids', $member['id'], $amount_requested, $reason);
        $stmt->execute();
        $stmt->close();
        basics_notify($conn, $member, "Hi {$member['full_name']}, your Emergency Cash Loan request of " . format_price($amount_requested) . " is under review. - TindaGo", 'loan', 'Loan request submitted', '/emergency_credit.php');
        redirect('/basics/emergency_credit.php?requested=1');
    }
}

$outstanding = basics_emergency_credit_outstanding($conn, $member['id']);
$available = basics_emergency_credit_available($conn, $member);

$stmt = $conn->prepare("SELECT * FROM basics_emergency_credit_requests WHERE member_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$requests = $stmt->get_result();

$page_title = 'Emergency Cash Loan';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">0% Interest</span>
    <h1 class="stitle">Emergency <span>Cash Loan</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <?php if (isset($_GET['requested'])): ?>
    <div class="sucmsg is-visible mb-4"><p>Request submitted. An admin will review it shortly.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-6">
      <button type="button" class="btn-outline-theme w-100 justify-content-center" data-bs-toggle="collapse" data-bs-target="#ecInfo"><i class="fas fa-circle-info"></i>About Emergency Cash Loan</button>
    </div>
    <div class="col-6">
      <button type="button" class="btn-outline-theme w-100 justify-content-center" data-bs-toggle="collapse" data-bs-target="#ecIncrease"><i class="fas fa-arrow-trend-up"></i>How to Increase Your Limit</button>
    </div>
  </div>

  <div class="collapse mb-4" id="ecInfo">
    <div class="panel-card">
      <h2 class="h6 mb-3">Terms &amp; Conditions</h2>
      <ul class="mb-0" style="padding-left:1.1rem;">
        <li class="mb-2">Up to <strong>₱1,000</strong>, at <strong>0% interest</strong> — no interest is ever added to what you owe.</li>
        <li class="mb-2">Every request is <strong>subject to approval</strong> and your <strong>payment performance</strong> — approval isn't automatic, and admins may release less than requested.</li>
        <li class="mb-2">This loan facility is a <strong>privilege</strong>, not a guarantee — it may be adjusted, suspended, or revoked at any time.</li>
        <li class="mb-2">Two or more late payments (on order or emergency loan obligations) may result in your loan limit being reduced, suspended, or revoked.</li>
        <li class="mb-0">Repay promptly and on time to stay in good standing for future requests and higher limits.</li>
      </ul>
    </div>
  </div>

  <div class="collapse mb-4" id="ecIncrease">
    <div class="panel-card">
      <h2 class="h6 mb-3">How to Increase Your Limit</h2>
      <ul class="mb-0" style="padding-left:1.1rem;">
        <li class="mb-2">Make <strong>12 consecutive on-time order payments</strong> to become eligible for a higher limit review. Track your streak on the <a href="<?= BASICS_URL ?>/payments.php">Payments</a> page.</li>
        <li class="mb-2">One late payment doesn't lower your limit — it just freezes it in place until your payment record improves.</li>
        <li class="mb-2">Two or more late payments move things the other way: your loan limit may be reduced, suspended, or revoked instead.</li>
        <li class="mb-0">Reaching 12 on-time payments makes you <strong>eligible</strong>, not automatically approved — an admin still reviews and decides whether to raise your limit.</li>
      </ul>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
      <div class="stat-tile">
        <div class="stat-num"><?= format_price($member['emergency_credit_limit']) ?></div>
        <div class="stat-lbl">Loan Limit</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="stat-tile">
        <div class="stat-num"><?= format_price($outstanding) ?></div>
        <div class="stat-lbl">Outstanding</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="stat-tile">
        <div class="stat-num accent"><?= format_price($available) ?></div>
        <div class="stat-lbl">Available to Request</div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <div class="panel-card mb-4">
        <h2 class="h6 mb-3">Request Emergency Cash Loan</h2>
        <?php if ((float) $member['emergency_credit_limit'] <= 0): ?>
          <p class="text-muted mb-0">You don't have an Emergency Cash Loan limit set up yet. Contact support if you need one.</p>
        <?php elseif ($available <= 0): ?>
          <p class="text-muted mb-0">You have no available Emergency Cash Loan right now — you're at your limit. Repay an outstanding balance from the <a href="<?= BASICS_URL ?>/payments.php">Payments</a> page to free up room.</p>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="request_credit">
            <div class="mb-3">
              <label class="flbl">Amount (up to <?= format_price($available) ?>)</label>
              <input type="number" step="0.01" min="0.01" max="<?= sanitize($available) ?>" name="amount_requested" class="fctrl" required>
            </div>
            <div class="mb-3">
              <label class="flbl">Reason (optional)</label>
              <input type="text" name="reason" class="fctrl" placeholder="e.g. Medical expense">
            </div>
            <button type="submit" class="btn-red"><i class="fas fa-hand-holding-dollar"></i>Submit Request</button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($outstanding > 0): ?>
        <div class="panel-card">
          <h2 class="h6 mb-2">Repaying?</h2>
          <p class="mb-0">Settle an outstanding Emergency Cash Loan balance from the <a href="<?= BASICS_URL ?>/payments.php">Payments</a> page — choose "Emergency Cash Loan" under Pay!.</p>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-12 col-lg-6">
      <div class="panel-card">
        <h2 class="h6 mb-3">My Requests</h2>
        <div class="table-responsive">
          <table class="table-theme">
            <thead><tr><th>Requested</th><th>Released</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php if ($requests->num_rows === 0): ?>
              <tr><td colspan="4" class="text-muted">No requests yet.</td></tr>
            <?php endif; ?>
            <?php $status_pill = ['pending' => 'pending', 'approved' => 'approved', 'denied' => 'rejected']; ?>
            <?php while ($r = $requests->fetch_assoc()): ?>
              <tr>
                <td><?= format_price($r['amount_requested']) ?></td>
                <td><?= $r['amount_released'] !== null ? format_price($r['amount_released']) : '—' ?></td>
                <td><span class="pill pill-<?= $status_pill[$r['status']] ?? 'pending' ?>"><?= ucfirst($r['status']) ?></span></td>
                <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
              </tr>
              <?php if ($r['status'] === 'denied' && $r['admin_notes']): ?>
                <tr><td colspan="4" class="small text-muted">Reason: <?= sanitize($r['admin_notes']) ?></td></tr>
              <?php endif; ?>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
