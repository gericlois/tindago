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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $throttled = login_throttle_blocked($conn, 'basics', $username);

    $stmt = $conn->prepare("SELECT * FROM basics_users WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($throttled) {
        $errors[] = LOGIN_THROTTLE_MESSAGE;
    } elseif (!$user || !password_verify($password, $user['password_hash'])) {
        $errors[] = 'Invalid username or password.';
        login_throttle_fail($conn, 'basics', $username);
    } elseif ($user['status'] === 'suspended') {
        $errors[] = 'Your account has been suspended. Please contact support.';
    } else {
        login_throttle_clear($conn, 'basics', $username);
        session_regenerate_id(true);
        $_SESSION['basics_user_id'] = $user['id'];
        $_SESSION['basics_must_change_password'] = (bool) $user['must_change_password'];
        redirect($user['must_change_password'] ? '/basics/change_password.php' : '/basics/dashboard.php');
    }
}

$page_title = 'Login';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Welcome Back</span>
    <h1 class="stitle">Login</h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
      <div class="panel-card">
        <?php if ($errors): ?>
          <div class="errmsg">
            <ul class="mb-0">
              <?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" novalidate>
          <div class="mb-3">
            <label class="flbl">Username</label>
            <input type="text" name="username" class="fctrl" value="<?= sanitize($username) ?>" required autofocus>
          </div>
          <div class="mb-3">
            <label class="flbl">Password</label>
            <div class="pwd-field">
              <input type="password" id="loginPassword" name="password" class="fctrl" required>
              <button type="button" class="pwd-toggle" data-pwd-target="loginPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
            </div>
            <div class="text-end mt-1"><a href="<?= BASICS_URL ?>/forgot_password.php" class="small">Forgot password?</a></div>
          </div>
          <button type="submit" class="btn-red w-100 justify-content-center"><i class="fas fa-right-to-bracket"></i>Login</button>
        </form>
        <p class="text-center mt-3 small mb-0">No account yet? <a href="<?= BASICS_URL ?>/apply.php">Apply for Membership</a></p>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
