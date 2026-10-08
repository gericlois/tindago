<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin']);

$errors = [];
$valid_roles = ['super_admin', 'admin', 'staff_orders', 'staff_payments', 'staff_registration'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? '';

    if ($username === '' || $name === '' || !in_array($role, $valid_roles, true)) {
        $errors[] = 'Fill in a username, name, and a valid role.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM basics_admins WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'That username is already taken.';
        }
        $stmt->close();

        if (empty($errors)) {
            $password = generate_temp_password(14);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO basics_admins (username, password_hash, name, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $username, $hash, $name, $role);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'create_basics_admin', 'Created admin "' . $username . '" (role: ' . $role . ')');
            $_SESSION['flash_admin_password'] = $password;
            $_SESSION['flash_admin_username'] = $username;
            redirect('/basics/admin/admins.php?created=1');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM basics_admins WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        $errors[] = 'Admin not found.';
    } elseif ($name === '' || !in_array($role, $valid_roles, true)) {
        $errors[] = 'Enter a name and a valid role.';
    } elseif ($target['role'] === 'super_admin' && $role !== 'super_admin' && basics_super_admin_count($conn) <= 1) {
        $errors[] = 'Cannot remove the last super admin.';
    } elseif ($id === basics_current_admin_id() && $role !== 'super_admin') {
        $errors[] = "You can't remove your own super admin access.";
    } else {
        $stmt = $conn->prepare("UPDATE basics_admins SET name = ?, role = ? WHERE id = ?");
        $stmt->bind_param('ssi', $name, $role, $id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'update_basics_admin', 'Updated admin "' . $target['username'] . '" (role: ' . $role . ')');
        redirect('/basics/admin/admins.php?updated=1');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM basics_admins WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        $errors[] = 'Admin not found.';
    } else {
        $password = generate_temp_password(14);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE basics_admins SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'reset_basics_admin_password', 'Reset password for admin "' . $target['username'] . '"');
        $_SESSION['flash_admin_password'] = $password;
        $_SESSION['flash_admin_username'] = $target['username'];
        redirect('/basics/admin/admins.php?reset=1');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM basics_admins WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        $errors[] = 'Admin not found.';
    } elseif ($id === basics_current_admin_id()) {
        $errors[] = "You can't delete your own account.";
    } elseif ($target['role'] === 'super_admin' && basics_super_admin_count($conn) <= 1) {
        $errors[] = 'Cannot delete the last super admin.';
    } else {
        $stmt = $conn->prepare("DELETE FROM basics_admins WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'delete_basics_admin', 'Deleted admin "' . $target['username'] . '"');
        redirect('/basics/admin/admins.php?deleted=1');
    }
}

$flash_password = null;
$flash_username = null;
if (isset($_SESSION['flash_admin_password'])) {
    $flash_password = $_SESSION['flash_admin_password'];
    $flash_username = $_SESSION['flash_admin_username'];
    unset($_SESSION['flash_admin_password'], $_SESSION['flash_admin_username']);
}

$admins = $conn->query("SELECT * FROM basics_admins ORDER BY (role = 'super_admin') DESC, username ASC")->fetch_all(MYSQLI_ASSOC);

$page_title = 'Admin Management';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Admin Management</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if ($flash_password): ?>
    <div class="sucmsg is-visible mb-4">
      <p class="mb-1"><strong><?= sanitize($flash_username) ?></strong>'s password:</p>
      <p class="mb-1" style="font-size:1.2rem;font-family:monospace;"><?= sanitize($flash_password) ?></p>
      <p class="mb-0 small">Copy this now and share it securely — it will not be shown again.</p>
    </div>
  <?php elseif (isset($_GET['updated'])): ?>
    <div class="sucmsg is-visible mb-4"><p class="mb-0">Admin updated.</p></div>
  <?php elseif (isset($_GET['deleted'])): ?>
    <div class="sucmsg is-visible mb-4"><p class="mb-0">Admin deleted.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="panel-card">
        <h2 class="h6">Add Admin</h2>
        <form method="post">
          <input type="hidden" name="action" value="create">
          <div class="mb-2">
            <label class="flbl">Username</label>
            <input type="text" name="username" class="fctrl" required>
          </div>
          <div class="mb-2">
            <label class="flbl">Full Name</label>
            <input type="text" name="name" class="fctrl" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Role</label>
            <select name="role" class="fctrl" required>
              <?php foreach ($valid_roles as $role): ?>
                <option value="<?= $role ?>"><?= basics_admin_role_label($role) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn-chip btn-chip-success"><i class="fas fa-user-plus"></i> Create Admin</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Created</th><th class="no-print"></th></tr></thead>
          <tbody>
          <?php foreach ($admins as $a): ?>
            <tr>
              <td><?= sanitize($a['username']) ?></td>
              <td><?= sanitize($a['name']) ?></td>
              <?php $role_pill = ['super_admin' => 'approved', 'admin' => 'processing'][$a['role']] ?? 'pending'; ?>
              <td><span class="pill pill-<?= $role_pill ?>"><?= basics_admin_role_label($a['role']) ?></span></td>
              <td><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
              <td class="no-print">
                <button type="button" class="btn-chip btn-chip-outline" data-bs-toggle="modal" data-bs-target="#editModal-<?= (int) $a['id'] ?>">Edit</button>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm(<?= js_str('Reset the password for ' . $a['username'] . '? A new password will be generated.') ?>);">Reset Password</button>
                </form>
                <?php if ((int) $a['id'] !== basics_current_admin_id()): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm(<?= js_str('Delete admin ' . $a['username'] . '? This cannot be undone.') ?>);">Delete</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php foreach ($admins as $a): ?>
  <div class="modal fade" id="editModal-<?= (int) $a['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header">
            <h5 class="modal-title">Edit — <?= sanitize($a['username']) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <div class="mb-2">
              <label class="flbl">Full Name</label>
              <input type="text" name="name" class="fctrl" value="<?= sanitize($a['name']) ?>" required>
            </div>
            <div class="mb-0">
              <label class="flbl">Role</label>
              <select name="role" class="fctrl" required>
                <?php foreach ($valid_roles as $role): ?>
                  <option value="<?= $role ?>" <?= $a['role'] === $role ? 'selected' : '' ?>><?= basics_admin_role_label($role) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn-red"><i class="fas fa-floppy-disk"></i> Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
