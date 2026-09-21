<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';

if (basics_is_admin_logged_in()) {
    redirect(basics_admin_landing_url());
}

$errors = [];
$username = '';
$maintenance_message = 'Basics is under maintenance. Only super admins can log in right now.';

if (isset($_GET['maintenance'])) {
    $errors[] = $maintenance_message;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $throttled = login_throttle_blocked($conn, 'basics_admin', $username);

    $stmt = $conn->prepare("SELECT * FROM basics_admins WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($throttled) {
        $errors[] = LOGIN_THROTTLE_MESSAGE;
    } elseif (!$admin || !password_verify($password, $admin['password_hash'])) {
        $errors[] = 'Invalid username or password.';
        login_throttle_fail($conn, 'basics_admin', $username);
    } elseif (basics_maintenance_blocks_role($admin['role'])) {
        $errors[] = $maintenance_message;
    } else {
        login_throttle_clear($conn, 'basics_admin', $username);
        session_regenerate_id(true);
        $_SESSION['basics_admin_last_activity'] = time();
        $_SESSION['basics_admin_id'] = $admin['id'];
        $_SESSION['basics_admin_name'] = $admin['name'];
        $_SESSION['basics_admin_role'] = $admin['role'];
        redirect(basics_admin_landing_url());
    }
}

$page_title = 'Basics Admin Login';
require __DIR__ . '/../../admin/includes/admin_header.php';
?>
<div style="background:var(--dark);min-height:100vh;display:flex;align-items:center;">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-12 col-sm-8 col-md-5 col-lg-4">
        <div class="text-center mb-4">
          <div class="hbi mx-auto mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-basket-shopping"></i></div>
          <h1 class="h4" style="color:#fff;font-family:'Playfair Display',serif;font-weight:900;">JMC Foodies Basics</h1>
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
                <input type="password" id="basicsAdminLoginPassword" name="password" class="fctrl" required>
                <button type="button" class="pwd-toggle" data-pwd-target="basicsAdminLoginPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
              </div>
            </div>
            <button type="submit" class="btn-red w-100 justify-content-center"><i class="fas fa-right-to-bracket"></i>Login</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
