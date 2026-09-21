<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';

require_basics_login_only($conn);

$stmt = $conn->prepare("SELECT * FROM basics_users WHERE id = ?");
$stmt->bind_param('i', $_SESSION['basics_user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Reachable two ways: forced (must_change_password=1, e.g. right after
// signup or an admin-triggered reset) or voluntary (member chooses to
// update their password from the dashboard). $forced only changes the
// copy shown below — both paths use the same form/logic.
$forced = (bool) $user['must_change_password'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    }
    if (strlen($new_password) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new_password !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }
    if ($new_password === $current) {
        $errors[] = 'New password must be different from your current password.';
    }

    if (empty($errors)) {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE basics_users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
        $stmt->bind_param('si', $hash, $_SESSION['basics_user_id']);
        $stmt->execute();
        $stmt->close();

        $_SESSION['basics_must_change_password'] = false;
        redirect('/basics/dashboard.php');
    }
}

$page_title = 'Change Password';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Security Step</span>
    <h1 class="stitle">Change Your <span>Password</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
      <div class="panel-card">
        <?php if ($forced): ?>
          <div class="errmsg mb-3">
            <p><i class="fas fa-shield-halved me-1"></i>For your security, you must set a new password before you can use your account.</p>
          </div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="errmsg">
            <ul class="mb-0">
              <?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" novalidate>
          <div class="mb-3">
            <label class="flbl"><?= $forced ? 'Current (Temporary) Password' : 'Current Password' ?></label>
            <div class="pwd-field">
              <input type="password" id="curPassword" name="current_password" class="fctrl" required autofocus>
              <button type="button" class="pwd-toggle" data-pwd-target="curPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">New Password</label>
            <div class="pwd-field">
              <input type="password" id="newPassword" name="new_password" class="fctrl" required>
              <button type="button" class="pwd-toggle" data-pwd-target="newPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">Confirm New Password</label>
            <div class="pwd-field">
              <input type="password" id="confirmNewPassword" name="confirm_password" class="fctrl" required>
              <button type="button" class="pwd-toggle" data-pwd-target="confirmNewPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
            </div>
          </div>
          <button type="submit" class="btn-red w-100 justify-content-center"><i class="fas fa-key"></i>Set New Password</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
