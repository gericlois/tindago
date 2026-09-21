<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';

if (basics_is_logged_in()) {
    redirect(!empty($_SESSION['basics_must_change_password']) ? '/basics/change_password.php' : '/basics/dashboard.php');
}

$errors = [];
$username = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');

    if ($username === '') {
        $errors[] = 'Enter your username.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM basics_users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Same confirmation message whether or not the account/email/phone
        // exists, so this form can't be used to enumerate registered usernames.
        if ($user && (!empty($user['email']) || !empty($user['contact_number']))) {
            $module_name = 'JMC Foodies Basics';
            $subject = "Your {$module_name} password has been reset";
            $sms_prefix = password_reset_sms_prefix($module_name) . '%';

            // Rate limit: skip actually resetting again if a reset email or
            // SMS already went to this member in the last 5 minutes, so
            // repeated submissions can't be used to spam/lock the account.
            $email = (string) $user['email'];
            $phone = (string) $user['contact_number'];
            $stmt = $conn->prepare("SELECT id FROM communication_log WHERE status = 'sent' AND created_at > (NOW() - INTERVAL 5 MINUTE)
                                     AND ((channel = 'email' AND recipient = ? AND subject = ?) OR (channel = 'sms' AND recipient = ? AND message LIKE ?)) LIMIT 1");
            $stmt->bind_param('ssss', $email, $subject, $phone, $sms_prefix);
            $stmt->execute();
            $recent = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$recent) {
                reset_member_password($conn, 'basics_users', $user['id'], $module_name, $user['full_name'], $email, $phone, true);
            }
        }

        $submitted = true;
    }
}

$page_title = 'Forgot Password';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Account Recovery</span>
    <h1 class="stitle">Forgot Password</h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
      <div class="panel-card">
        <?php if ($submitted): ?>
          <div class="sucmsg is-visible mb-3">
            <p class="mb-0">If that username exists, we've sent a new temporary password by email and SMS to the contact details on file.</p>
          </div>
          <p class="text-center small mb-0"><a href="<?= BASICS_URL ?>/login.php">Back to Login</a></p>
        <?php else: ?>
          <?php if ($errors): ?>
            <div class="errmsg">
              <ul class="mb-0">
                <?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <p class="text-muted small">Enter your username and we'll send you a new temporary password by email and SMS.</p>
          <form method="post" novalidate>
            <div class="mb-3">
              <label class="flbl">Username</label>
              <input type="text" name="username" class="fctrl" value="<?= sanitize($username) ?>" required autofocus>
            </div>
            <button type="submit" class="btn-red w-100 justify-content-center"><i class="fas fa-key"></i> Send New Password</button>
          </form>
          <p class="text-center mt-3 small mb-0"><a href="<?= BASICS_URL ?>/login.php">Back to Login</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
