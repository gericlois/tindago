<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

require_admin_login();

$id = (int) ($_GET['id'] ?? 0);
$email_errors = [];
$sms_errors = [];
$reset_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    update_user_status($conn, $id, $_POST['new_status'] ?? '');
    redirect('/admin/user_view.php?id=' . $id);
}

$stmt = $conn->prepare("SELECT u.*, ref.full_name AS referrer_name FROM users u
                         LEFT JOIN users ref ON ref.id = u.referred_by WHERE u.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    redirect('/admin/users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_email') {
    $email_subject = trim($_POST['email_subject'] ?? '');
    $email_message = trim($_POST['email_message'] ?? '');

    if (empty($user['email'])) {
        $email_errors[] = 'This member has no email address on file.';
    }
    if ($email_subject === '') {
        $email_errors[] = 'Enter a subject.';
    }
    if ($email_message === '') {
        $email_errors[] = 'Enter a message.';
    }

    if (empty($email_errors)) {
        if (send_email($user['email'], $email_subject, "Hi {$user['full_name']},\r\n\r\n{$email_message}\r\n\r\n— JMC Foodies Wellness Team")) {
            log_activity($conn, 'send_wellness_user_email', 'Emailed Wellness user "' . $user['full_name'] . '" (subject: ' . $email_subject . ')');
            redirect('/admin/user_view.php?id=' . $id . '&email_sent=1');
        } else {
            $email_errors[] = 'Failed to send — check the Gmail SMTP configuration (config/email.php).';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_sms') {
    $sms_message = trim($_POST['sms_message'] ?? '');

    if (empty($user['contact_number'])) {
        $sms_errors[] = 'This member has no contact number on file.';
    }
    if ($sms_message === '') {
        $sms_errors[] = 'Enter a message.';
    } elseif (strlen($sms_message) > 480) {
        $sms_errors[] = 'Message is too long (max 480 characters, about 3 SMS segments).';
    }

    if (empty($sms_errors)) {
        if (send_sms($user['contact_number'], $sms_message)) {
            log_activity($conn, 'send_wellness_user_sms', 'Texted Wellness user "' . $user['full_name'] . '"');
            redirect('/admin/user_view.php?id=' . $id . '&sms_sent=1');
        } else {
            $sms_errors[] = 'Failed to send — check the Semaphore SMS configuration (config/sms.php).';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (empty($user['email']) && empty($user['contact_number'])) {
        $reset_errors[] = 'This member has no email address or contact number on file.';
    }

    if (empty($reset_errors)) {
        $sent = reset_member_password($conn, 'users', $id, 'JMC Foodies Wellness', $user['full_name'], (string) $user['email'], (string) $user['contact_number'], false);
        if ($sent['email'] || $sent['sms']) {
            $via = $sent['email'] && $sent['sms'] ? 'email and SMS' : ($sent['email'] ? 'email' : 'SMS');
            log_activity($conn, 'reset_wellness_user_password', 'Reset password for Wellness user "' . $user['full_name'] . '" and sent the new temporary password by ' . $via);
            redirect('/admin/user_view.php?id=' . $id . '&password_reset=' . ($sent['email'] && $sent['sms'] ? 'both' : ($sent['email'] ? 'email' : 'sms')));
        } else {
            $reset_errors[] = 'Nothing was sent, so the password was left unchanged — check the Gmail SMTP (config/email.php) and Semaphore SMS (config/sms.php) configuration.';
        }
    }
}

$total_rebates = wallet_sum_by_type($conn, $id, 'personal_rebate');
$total_overrides = wallet_sum_by_type($conn, $id, 'referral_override');
$balance = wallet_balance($conn, $id);

$stmt = $conn->prepare("SELECT * FROM users WHERE referred_by = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $id);
$stmt->execute();
$referrals = $stmt->get_result();

$stmt = $conn->prepare("SELECT o.*, p.name AS product_name FROM orders o JOIN products p ON p.id = o.product_id WHERE o.user_id = ? ORDER BY o.created_at DESC");
$stmt->bind_param('i', $id);
$stmt->execute();
$orders = $stmt->get_result();

$stmt = $conn->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC");
$stmt->bind_param('i', $id);
$stmt->execute();
$transactions = $stmt->get_result();

$page_title = $user['full_name'];
require __DIR__ . '/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <a href="<?= BASE_URL ?>/admin/users.php" class="small">&larr; Back to Users</a>
    <h1 class="stitle" style="font-size:2rem;"><?= sanitize($user['full_name']) ?></h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num" style="font-size:1.3rem;"><?= format_price($total_rebates) ?></div><div class="stat-lbl">Rebates Earned</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num" style="font-size:1.3rem;"><?= format_price($total_overrides) ?></div><div class="stat-lbl">Overrides Earned</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num accent" style="font-size:1.3rem;"><?= format_price($balance) ?></div><div class="stat-lbl">JMC Wallet Balance</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num"><?= (int) $referrals->num_rows ?></div><div class="stat-lbl">Direct Referrals</div></div>
    </div>
  </div>

  <?php if (isset($_GET['email_sent'])): ?>
    <div class="sucmsg is-visible mb-4"><p>Email sent to <?= sanitize($user['email']) ?>.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['sms_sent'])): ?>
    <div class="sucmsg is-visible mb-4"><p>Text sent to <?= sanitize($user['contact_number']) ?>.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['password_reset'])): ?>
    <?php $pr = $_GET['password_reset']; ?>
    <div class="sucmsg is-visible mb-4"><p>Password reset. New temporary password sent
      <?= $pr === 'both' ? 'by email to ' . sanitize($user['email']) . ' and by SMS to ' . sanitize($user['contact_number']) : ($pr === 'sms' ? 'by SMS to ' . sanitize($user['contact_number']) : 'by email to ' . sanitize($user['email'])) ?>.</p></div>
  <?php endif; ?>
  <?php if ($email_errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($email_errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($sms_errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($sms_errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($reset_errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($reset_errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row g-4 mb-4">
    <div class="col-12 col-md-6">
      <div class="panel-card">
        <h2 class="h6">Profile</h2>
        <p class="mb-1">Username: <?= sanitize($user['username']) ?></p>
        <p class="mb-1">Address: <?= sanitize($user['address']) ?></p>
        <p class="mb-1">Birthdate: <?= date('M j, Y', strtotime($user['birthdate'])) ?></p>
        <p class="mb-1">Contact #: <?= sanitize($user['contact_number']) ?></p>
        <p class="mb-1">Email: <?= $user['email'] ? sanitize($user['email']) : '—' ?></p>
        <p class="mb-1">Referral Code: <code><?= sanitize($user['referral_code']) ?></code></p>
        <p class="mb-3">Referred By: <?= $user['referrer_name'] ? sanitize($user['referrer_name']) : '— (root account)' ?></p>
        <p class="mb-3">Status: <span class="pill pill-<?= $user['status'] ?>"><?= sanitize($user['status']) ?></span></p>
        <?php if ($user['status'] === 'pending'): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="new_status" value="active">
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Approve this user? They will be able to log in.');">Approve</button>
          </form>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="new_status" value="suspended">
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Reject this registration? They will not be able to log in.');">Reject</button>
          </form>
        <?php elseif ($user['status'] === 'active'): ?>
          <form method="post">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="new_status" value="suspended">
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Suspend this user? They will not be able to log in.');">Suspend Account</button>
          </form>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="new_status" value="active">
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Reinstate this user?');">Reinstate Account</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Send Email</h2>
        <?php if (empty($user['email'])): ?>
          <p class="text-muted small mb-0">This member has no email address on file.</p>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="send_email">
            <div class="mb-2">
              <label class="flbl">Subject</label>
              <input type="text" name="email_subject" class="fctrl" required>
            </div>
            <div class="mb-2">
              <label class="flbl">Message</label>
              <textarea name="email_message" class="fctrl" rows="4" required></textarea>
            </div>
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm(<?= js_str('Send this email to ' . $user['email'] . '?') ?>);"><i class="fas fa-paper-plane"></i> Send Email</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Send SMS</h2>
        <?php if (empty($user['contact_number'])): ?>
          <p class="text-muted small mb-0">This member has no contact number on file.</p>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="send_sms">
            <div class="mb-2">
              <label class="flbl">Message</label>
              <textarea name="sms_message" class="fctrl" rows="3" maxlength="480" required></textarea>
              <div class="form-text">Max 480 characters (~3 SMS segments).</div>
            </div>
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm(<?= js_str('Send this SMS to ' . $user['contact_number'] . '? This will use real SMS credits.') ?>);"><i class="fas fa-comment-sms"></i> Send SMS</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Reset Password</h2>
        <p class="text-muted small">Passwords are stored as one-way hashes and can't be recovered — this generates a new temporary password, sends it to the member by email and SMS, and requires them to change it on next login.</p>
        <?php if (empty($user['email']) && empty($user['contact_number'])): ?>
          <p class="text-muted small mb-0">This member has no email address or contact number on file.</p>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="reset_password">
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm(<?= js_str('Reset the password for ' . $user['full_name'] . ' and send them a new temporary password by email and SMS?') ?>);"><i class="fas fa-key"></i> Reset &amp; Send New Password</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12 col-md-6">
      <h2 class="h6">Direct Referrals</h2>
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Name</th><th>Username</th><th>Joined</th></tr></thead>
          <tbody>
          <?php if ($referrals->num_rows === 0): ?>
            <tr><td colspan="3" class="text-muted">None yet.</td></tr>
          <?php endif; ?>
          <?php while ($r = $referrals->fetch_assoc()): ?>
            <tr>
              <td><?= sanitize($r['full_name']) ?></td>
              <td><?= sanitize($r['username']) ?></td>
              <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-md-6">
      <h2 class="h6">Orders</h2>
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Product</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
          <?php if ($orders->num_rows === 0): ?>
            <tr><td colspan="4" class="text-muted">No orders yet.</td></tr>
          <?php endif; ?>
          <?php while ($o = $orders->fetch_assoc()): ?>
            <tr>
              <td><?= sanitize($o['product_name']) ?></td>
              <td><?= format_price($o['total_amount']) ?></td>
              <td><span class="pill pill-<?= $o['status'] ?>"><?= sanitize($o['status']) ?></span></td>
              <td><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="col-12 col-md-6">
      <h2 class="h6">Wallet Ledger</h2>
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Type</th><th>Amount</th><th>Date</th></tr></thead>
          <tbody>
          <?php if ($transactions->num_rows === 0): ?>
            <tr><td colspan="3" class="text-muted">No wallet activity yet.</td></tr>
          <?php endif; ?>
          <?php while ($t = $transactions->fetch_assoc()): ?>
            <tr>
              <td class="text-capitalize"><?= sanitize(str_replace('_', ' ', $t['type'])) ?></td>
              <td class="<?= $t['amount'] >= 0 ? 'amount-credit' : 'amount-debit' ?>"><?= $t['amount'] >= 0 ? '+' : '' ?><?= format_price($t['amount']) ?></td>
              <td><?= date('M j, Y', strtotime($t['created_at'])) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
