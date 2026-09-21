<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';

if (is_logged_in()) {
    redirect(route_after_login($conn, current_user_id()));
}

$errors = [];
$full_name = '';
$address = '';
$birthdate = '';
$contact_number = '';
$email = '';
$username = '';
$ref_code = trim($_GET['ref'] ?? $_POST['ref_code'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $ref_code = trim($_POST['ref_code'] ?? '');

    if ($full_name === '') $errors[] = 'Full name is required.';
    if ($address === '') $errors[] = 'Address is required.';
    if ($birthdate === '' || !DateTime::createFromFormat('Y-m-d', $birthdate)) $errors[] = 'A valid birthdate is required.';
    if ($contact_number === '') $errors[] = 'Contact number is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if ($username === '') $errors[] = 'Username is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    $referrer = null;
    if ($ref_code === '') {
        $errors[] = 'A referral code is required to register.';
    } else {
        $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE referral_code = ?");
        $stmt->bind_param('s', $ref_code);
        $stmt->execute();
        $referrer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$referrer) {
            $errors[] = 'That referral code is not valid.';
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'That username is already taken.';
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'That email address is already registered.';
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $new_code = generate_referral_code($conn);
        $referred_by = $referrer['id'];

        $stmt = $conn->prepare("INSERT INTO users
            (referral_code, referred_by, full_name, address, birthdate, contact_number, email, username, password_hash, must_change_password, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending')");
        $stmt->bind_param('sisssssss', $new_code, $referred_by, $full_name, $address, $birthdate, $contact_number, $email, $username, $hash);
        $stmt->execute();
        $stmt->close();

        redirect('/wellness/register.php?submitted=1');
    }
}

$page_title = 'Register';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Join Us</span>
    <h1 class="stitle">Create Your <span>Account</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-7">
      <div class="panel-card">
        <?php if (isset($_GET['submitted'])): ?>
          <div class="sucmsg is-visible">
            <p>Registration submitted! Your account is pending admin approval — you'll be able to log in once it's confirmed.</p>
          </div>
          <p class="text-center mt-3 small mb-0"><a href="<?= BASE_URL ?>/login.php">Back to Login</a></p>
        <?php else: ?>
        <?php if ($errors): ?>
          <div class="errmsg">
            <ul class="mb-0">
              <?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" novalidate>
          <div class="mb-3">
            <label class="flbl">Referral Code</label>
            <input type="text" name="ref_code" class="fctrl text-uppercase" value="<?= sanitize($ref_code) ?>" required>
            <div class="form-text">You need a referral code from an existing member to register.</div>
          </div>
          <div class="mb-3">
            <label class="flbl">Full Name</label>
            <input type="text" name="full_name" class="fctrl" value="<?= sanitize($full_name) ?>" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Address</label>
            <textarea name="address" class="fctrl" rows="2" required><?= sanitize($address) ?></textarea>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Birthdate</label>
              <input type="date" name="birthdate" class="fctrl" value="<?= sanitize($birthdate) ?>" required>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Contact Number</label>
              <input type="text" name="contact_number" class="fctrl" value="<?= sanitize($contact_number) ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">Email Address</label>
            <input type="email" name="email" class="fctrl" value="<?= sanitize($email) ?>" required>
            <div class="form-text">We'll email you once your account is confirmed.</div>
          </div>
          <div class="mb-3">
            <label class="flbl">Username</label>
            <input type="text" name="username" class="fctrl" value="<?= sanitize($username) ?>" required>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Temporary Password</label>
              <div class="pwd-field">
                <input type="password" id="regPassword" name="password" class="fctrl" required>
                <button type="button" class="pwd-toggle" data-pwd-target="regPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
              </div>
              <div class="form-text">You'll change this on first login.</div>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Confirm Password</label>
              <div class="pwd-field">
                <input type="password" id="regConfirmPassword" name="confirm_password" class="fctrl" required>
                <button type="button" class="pwd-toggle" data-pwd-target="regConfirmPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
              </div>
            </div>
          </div>
          <button type="submit" class="btn-red w-100 justify-content-center"><i class="fas fa-user-plus"></i>Register</button>
        </form>
        <p class="text-center mt-3 small mb-0">Already have an account? <a href="<?= BASE_URL ?>/login.php">Login</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
