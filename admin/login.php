<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

if (is_admin_logged_in()) {
    redirect('/admin/index.php');
}

$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $throttled = login_throttle_blocked($conn, 'wellness_admin', $username);

    $stmt = $conn->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($throttled) {
        $errors[] = LOGIN_THROTTLE_MESSAGE;
    } elseif (!$admin || !password_verify($password, $admin['password_hash'])) {
        $errors[] = 'Invalid username or password.';
        login_throttle_fail($conn, 'wellness_admin', $username);
    } else {
        login_throttle_clear($conn, 'wellness_admin', $username);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_last_activity'] = time();
        $_SESSION['admin_name'] = $admin['name'];
        redirect('/admin/index.php');
    }
}

$page_title = 'Admin Login';
require __DIR__ . '/includes/admin_header.php';
?>
<div style="background:var(--dark);min-height:100vh;display:flex;align-items:center;">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-12 col-sm-8 col-md-5 col-lg-4">
        <div class="text-center mb-4">
          <div class="hbi mx-auto mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-gift"></i></div>
          <h1 class="h4" style="color:#fff;font-family:'Playfair Display',serif;font-weight:900;"><?= sanitize(SITE_NAME) ?></h1>
          <p class="small" style="color:rgba(255,255,255,.5);">Admin Panel</p>
        </div>

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
                <input type="password" id="adminLoginPassword" name="password" class="fctrl" required>
                <button type="button" class="pwd-toggle" data-pwd-target="adminLoginPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
              </div>
            </div>
            <button type="submit" class="btn-red w-100 justify-content-center"><i class="fas fa-right-to-bracket"></i>Login</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
