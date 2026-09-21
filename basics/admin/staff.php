<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

$errors = [];
$staff_role_info = basics_staff_roles();
$staff_roles = array_keys($staff_role_info);

// Every action below re-checks that the TARGET account is itself a staff
// account — an admin must never be able to edit, reset or delete another
// admin or a super admin through this page (those live in admins.php).
function staff_find_target($conn, $id, array $staff_roles) {
    $stmt = $conn->prepare("SELECT * FROM basics_admins WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($target && in_array($target['role'], $staff_roles, true)) ? $target : null;
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

if ($action === 'create') {
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? '';

    if ($username === '' || $name === '' || !in_array($role, $staff_roles, true)) {
        $errors[] = 'Fill in a username, name, and choose a staff type.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM basics_admins WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'That username is already taken.';
        }
        $stmt->close();

        if (empty($errors)) {
            try {
                $password = generate_temp_password(14);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO basics_admins (username, password_hash, name, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $username, $hash, $name, $role);
                $stmt->execute();
                $stmt->close();
                log_activity($conn, 'create_basics_staff', 'Created Basics staff "' . $username . '" (' . basics_admin_role_label($role) . ')');
                $_SESSION['flash_admin_password'] = $password;
                $_SESSION['flash_admin_username'] = $username;
                redirect('/basics/admin/staff.php?created=1');
            } catch (mysqli_sql_exception $e) {
                $errors[] = 'Could not create the account. If this is a Staff (Registration) account, the database migration database/live_add_basics_staff_registration_role.sql must be run first.';
            }
        }
    }
}

if ($action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? '';
    $target = staff_find_target($conn, $id, $staff_roles);

    if (!$target) {
        $errors[] = 'Staff account not found.';
    } elseif ($name === '' || !in_array($role, $staff_roles, true)) {
        $errors[] = 'Enter a name and choose a staff type.';
    } else {
        $stmt = $conn->prepare("UPDATE basics_admins SET name = ?, role = ? WHERE id = ?");
        $stmt->bind_param('ssi', $name, $role, $id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'update_basics_staff', 'Updated Basics staff "' . $target['username'] . '" (' . basics_admin_role_label($role) . ')');
        redirect('/basics/admin/staff.php?updated=1');
    }
}

if ($action === 'reset_password') {
    $target = staff_find_target($conn, (int) ($_POST['id'] ?? 0), $staff_roles);
    if (!$target) {
        $errors[] = 'Staff account not found.';
    } else {
        $password = generate_temp_password(14);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE basics_admins SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $hash, $target['id']);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'reset_basics_staff_password', 'Reset password for Basics staff "' . $target['username'] . '"');
        $_SESSION['flash_admin_password'] = $password;
        $_SESSION['flash_admin_username'] = $target['username'];
        redirect('/basics/admin/staff.php?reset=1');
    }
}

if ($action === 'delete') {
    $target = staff_find_target($conn, (int) ($_POST['id'] ?? 0), $staff_roles);
    if (!$target) {
        $errors[] = 'Staff account not found.';
    } else {
        $stmt = $conn->prepare("DELETE FROM basics_admins WHERE id = ?");
        $stmt->bind_param('i', $target['id']);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'delete_basics_staff', 'Deleted Basics staff "' . $target['username'] . '"');
        redirect('/basics/admin/staff.php?deleted=1');
    }
}

$flash_password = null;
$flash_username = null;
if (isset($_SESSION['flash_admin_password'])) {
    $flash_password = $_SESSION['flash_admin_password'];
    $flash_username = $_SESSION['flash_admin_username'];
    unset($_SESSION['flash_admin_password'], $_SESSION['flash_admin_username']);
}

$in_list = "'" . implode("','", $staff_roles) . "'";
$staff = $conn->query("SELECT * FROM basics_admins WHERE role IN ($in_list) ORDER BY FIELD(role, $in_list), username ASC")->fetch_all(MYSQLI_ASSOC);

$page_title = 'Staff Management';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">JMC Foodies Basics</span>
    <h1 class="stitle" style="font-size:2rem;">Staff Management</h1>
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
    <div class="sucmsg is-visible mb-4"><p class="mb-0">Staff account updated.</p></div>
  <?php elseif (isset($_GET['deleted'])): ?>
    <div class="sucmsg is-visible mb-4"><p class="mb-0">Staff account deleted.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="panel-card mb-4">
        <h2 class="h6">Add Staff</h2>
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
            <label class="flbl">Staff Type</label>
            <select name="role" class="fctrl" required>
              <?php foreach ($staff_roles as $role): ?>
                <option value="<?= $role ?>"><?= basics_admin_role_label($role) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn-chip btn-chip-success"><i class="fas fa-user-plus"></i> Create Staff</button>
        </form>
      </div>

      <div class="panel-card">
        <h2 class="h6">What each staff type can do</h2>
        <?php foreach ($staff_role_info as $role => $description): ?>
          <p class="mb-2 small"><strong><?= basics_admin_role_label($role) ?></strong><br><?= sanitize($description) ?></p>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Username</th><th>Name</th><th>Staff Type</th><th>Created</th><th class="no-print"></th></tr></thead>
          <tbody>
          <?php if (empty($staff)): ?>
            <tr><td colspan="5" class="text-muted">No staff accounts yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($staff as $a): ?>
            <tr>
              <td><?= sanitize($a['username']) ?></td>
              <td><?= sanitize($a['name']) ?></td>
              <td><span class="pill pill-pending"><?= basics_admin_role_label($a['role']) ?></span></td>
              <td><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
              <td class="no-print">
                <details class="d-inline staff-edit">
                  <summary class="btn-chip btn-chip-outline">Edit</summary>
                  <form method="post" class="mt-2">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <input type="text" name="name" class="fctrl mb-2" value="<?= sanitize($a['name']) ?>" required>
                    <select name="role" class="fctrl mb-2" required>
                      <?php foreach ($staff_roles as $role): ?>
                        <option value="<?= $role ?>" <?= $a['role'] === $role ? 'selected' : '' ?>><?= basics_admin_role_label($role) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-chip btn-chip-success"><i class="fas fa-floppy-disk"></i> Save</button>
                  </form>
                </details>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm(<?= js_str('Reset the password for ' . $a['username'] . '? A new password will be generated.') ?>);">Reset Password</button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm(<?= js_str('Delete staff ' . $a['username'] . '? This cannot be undone.') ?>);">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
