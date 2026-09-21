<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_access($conn);

$user_id = basics_current_user_id();
$stmt = $conn->prepare("SELECT * FROM basics_users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $address_line = trim($_POST['address_line'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '') $errors[] = 'Last name is required.';
    if ($address_line === '') $errors[] = 'House #/Street is required.';
    if ($barangay === '') $errors[] = 'Barangay is required.';
    if ($city === '') $errors[] = 'City/Municipality is required.';
    if ($province === '') $errors[] = 'Province is required.';
    if ($contact_number === '') $errors[] = 'Contact number is required.';
    // Email is optional — only validated for format when provided.
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address doesn\'t look valid.';

    if (empty($errors) && $email !== '') {
        $stmt = $conn->prepare("SELECT id FROM basics_users WHERE email = ? AND id != ?");
        $stmt->bind_param('si', $email, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) $errors[] = 'That email address is already used by another account.';
        $stmt->close();
    }

    if (empty($errors)) {
        $full_name = basics_compose_full_name($first_name, $middle_name, $last_name);
        $address = basics_compose_address($address_line, $barangay, $city, $province);
        $email_to_store = $email !== '' ? $email : null;
        $stmt = $conn->prepare("UPDATE basics_users SET
            full_name = ?, first_name = ?, middle_name = ?, last_name = ?,
            address = ?, address_line = ?, barangay = ?, city = ?, province = ?,
            contact_number = ?, email = ?
            WHERE id = ?");
        $stmt->bind_param('sssssssssssi', $full_name, $first_name, $middle_name, $last_name,
            $address, $address_line, $barangay, $city, $province, $contact_number, $email_to_store, $user_id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, 'update_basics_account', 'Basics member updated their own account info');

        $stmt = $conn->prepare("SELECT * FROM basics_users WHERE id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $saved = true;
    }
}

$page_title = 'My Account';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Account Settings</span>
    <h1 class="stitle">My <span>Account</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-9 col-lg-8">
      <div class="panel-card mb-4">
        <?php if ($saved): ?>
          <div class="sucmsg is-visible mb-3"><p class="mb-0">Account info updated.</p></div>
        <?php endif; ?>
        <?php if ($errors): ?>
          <div class="errmsg">
            <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>

        <form method="post">
          <h2 class="h6 mb-3">Name</h2>
          <div class="row">
            <div class="col-sm-4 mb-3">
              <label class="flbl">First Name</label>
              <input type="text" name="first_name" class="fctrl" value="<?= sanitize($user['first_name'] ?? '') ?>" required>
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Middle Name</label>
              <input type="text" name="middle_name" class="fctrl" value="<?= sanitize($user['middle_name'] ?? '') ?>">
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Surname</label>
              <input type="text" name="last_name" class="fctrl" value="<?= sanitize($user['last_name'] ?? '') ?>" required>
            </div>
          </div>
          <?php if (empty($user['first_name']) && empty($user['last_name'])): ?>
            <p class="text-muted small mb-3">Your name was recorded as a single field when you joined — fill in the boxes above to split it out.</p>
          <?php endif; ?>

          <h2 class="h6 mb-3 mt-2">Address</h2>
          <div class="mb-3">
            <label class="flbl">House #/Street</label>
            <input type="text" name="address_line" class="fctrl" value="<?= sanitize($user['address_line'] ?? '') ?>" required>
          </div>
          <div class="row">
            <div class="col-sm-4 mb-3">
              <label class="flbl">Barangay</label>
              <input type="text" name="barangay" class="fctrl" value="<?= sanitize($user['barangay'] ?? '') ?>" required>
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">City/Municipality</label>
              <input type="text" name="city" class="fctrl" value="<?= sanitize($user['city'] ?? '') ?>" required>
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Province</label>
              <input type="text" name="province" class="fctrl" value="<?= sanitize($user['province'] ?? '') ?>" required>
            </div>
          </div>
          <?php if (empty($user['address_line'])): ?>
            <p class="text-muted small mb-3">Your address was recorded as a single field when you joined — fill in the boxes above to split it out. On file: <em><?= sanitize($user['address']) ?></em></p>
          <?php endif; ?>

          <h2 class="h6 mb-3 mt-2">Contact</h2>
          <div class="mb-3">
            <label class="flbl">Contact Number</label>
            <input type="text" name="contact_number" class="fctrl" value="<?= sanitize($user['contact_number']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Email Address (optional)</label>
            <input type="email" name="email" class="fctrl" value="<?= sanitize($user['email']) ?>">
          </div>

          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Username</label>
              <input type="text" class="fctrl" value="<?= sanitize($user['username']) ?>" disabled>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Birthdate</label>
              <input type="text" class="fctrl" value="<?= date('M j, Y', strtotime($user['birthdate'])) ?>" disabled>
            </div>
          </div>

          <button type="submit" class="btn-red"><i class="fas fa-floppy-disk"></i>Save Changes</button>
          <a href="<?= BASICS_URL ?>/change_password.php" class="btn-outline-theme"><i class="fas fa-key"></i>Change Password</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
