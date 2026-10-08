<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

// Applying always creates a brand-new member account (basics_users). If this
// browser is
// already logged into one, just send them to their existing application.
if (basics_is_logged_in()) {
    redirect('/basics/pending.php');
}

$errors = [];
$first_name = '';
$middle_name = '';
$last_name = '';
$address_line = '';
$barangay = '';
$city = '';
$province = '';
$birthdate = '';
$contact_number = '';
$email = '';
$username = '';
$employer_name = '';
$employer_contact = '';
$position = '';
$employer_address = '';
// Optional — membership is open to any employee of a partner
// company regardless of referral. An invalid/unknown code is never a hard
// error, just silently treated as "no referral" (see the lookup below).
$ref_code = trim($_GET['ref'] ?? $_POST['ref_code'] ?? '');
$referrer = null;
if ($ref_code !== '') {
    $stmt = $conn->prepare("SELECT bm.id, u.full_name FROM basics_members bm
                             JOIN basics_users u ON u.id = bm.user_id
                             WHERE bm.referral_code = ? AND bm.is_community_partner = 1");
    $stmt->bind_param('s', $ref_code);
    $stmt->execute();
    $referrer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $address_line = trim($_POST['address_line'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $employer_name = trim($_POST['employer_name'] ?? '');
    $employer_contact = trim($_POST['employer_contact'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $employer_address = trim($_POST['employer_address'] ?? '');

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '') $errors[] = 'Last name is required.';
    if ($address_line === '') $errors[] = 'House #/Street is required.';
    if ($barangay === '') $errors[] = 'Barangay is required.';
    if ($city === '') $errors[] = 'City/Municipality is required.';
    if ($province === '') $errors[] = 'Province is required.';
    if ($birthdate === '' || !DateTime::createFromFormat('Y-m-d', $birthdate)) $errors[] = 'A valid birthdate is required.';
    if ($contact_number === '') $errors[] = 'Contact number is required.';
    // Email is optional — only validated for format when the applicant
    // actually provides one.
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address doesn\'t look valid.';
    if ($username === '') $errors[] = 'Username is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if ($employer_name === '') $errors[] = 'Employer name is required.';
    if ($employer_contact === '') $errors[] = 'Employer contact is required.';
    if ($position === '') $errors[] = 'Position is required.';

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM basics_users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) $errors[] = 'That username is already taken.';
        $stmt->close();

        if ($email !== '') {
            $stmt = $conn->prepare("SELECT id FROM basics_users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) $errors[] = 'That email address is already registered.';
            $stmt->close();
        }
    }

    // Stored as NULL (not '') so the unique index on basics_users.email
    // doesn't collide between multiple applicants who skip it — MySQL treats
    // every NULL as distinct, but two empty strings would violate UNIQUE.
    $email_to_store = $email !== '' ? $email : null;

    $doc_fields = [
        'valid_id_1' => 'First valid ID',
        'valid_id_2' => 'Second valid ID',
        'barangay_clearance' => 'Barangay Clearance',
        'membership_application_form' => 'Membership Application Form (signed) - Front Page',
        'membership_application_form_back' => 'Membership Application Form (signed) - Back Page',
        'certificate_of_employment' => 'Certificate of Employment / Work Clearance',
    ];
    // Both valid IDs and Barangay Clearance are required up front — the
    // remaining paperwork (membership form, certificate of employment) can
    // still be collected separately before an admin approves the application.
    $optional_doc_fields = ['membership_application_form', 'membership_application_form_back', 'certificate_of_employment'];
    foreach ($doc_fields as $field => $label) {
        if (in_array($field, $optional_doc_fields, true)) {
            continue;
        }
        if (empty($_FILES[$field]['name'])) {
            $errors[] = $label . ' is required.';
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $full_name = basics_compose_full_name($first_name, $middle_name, $last_name);
            $address = basics_compose_address($address_line, $barangay, $city, $province);
            $stmt = $conn->prepare("INSERT INTO basics_users
                (full_name, first_name, middle_name, last_name, address, address_line, barangay, city, province,
                 birthdate, contact_number, email, username, password_hash, must_change_password, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'active')");
            $stmt->bind_param('ssssssssssssss', $full_name, $first_name, $middle_name, $last_name,
                $address, $address_line, $barangay, $city, $province,
                $birthdate, $contact_number, $email_to_store, $username, $hash);
            $stmt->execute();
            $user_id = $stmt->insert_id;
            $stmt->close();

            $employer_address_to_store = $employer_address !== '' ? $employer_address : null;
            $referred_by = $referrer['id'] ?? null;
            $stmt = $conn->prepare("INSERT INTO basics_members (user_id, employer_name, employer_contact, position, employer_address, referred_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('issssi', $user_id, $employer_name, $employer_contact, $position, $employer_address_to_store, $referred_by);
            $stmt->execute();
            $member_id = $stmt->insert_id;
            $stmt->close();

            foreach ($doc_fields as $field => $label) {
                // Skip entirely if an optional doc was left blank — no row,
                // no upload attempt, not just a suppressed error.
                if (in_array($field, $optional_doc_fields, true) && empty($_FILES[$field]['name'])) {
                    continue;
                }
                [$filename, $upload_error] = handle_kyc_document_upload($field);
                if ($upload_error) {
                    throw new Exception($label . ': ' . $upload_error);
                }
                $stmt = $conn->prepare("INSERT INTO basics_kyc_documents (member_id, doc_type, file_path) VALUES (?, ?, ?)");
                $stmt->bind_param('iss', $member_id, $field, $filename);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();

            send_sms($contact_number, "Hi $full_name, we've received your TindaGo membership application. It's now under review for processing and approval. - TindaGo");

            session_regenerate_id(true);
            $_SESSION['basics_user_id'] = $user_id;
            $_SESSION['basics_must_change_password'] = true;
            redirect('/basics/pending.php?submitted=1');
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = safe_error_message($e);
        }
    }
}

$page_title = 'Apply for Membership';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Join Us</span>
    <h1 class="stitle">Apply for <span>TindaGo Membership</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-9 col-lg-8">
      <div class="panel-card">
        <?php if ($errors): ?>
          <div class="errmsg">
            <ul class="mb-0">
              <?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="applyForm">
          <div class="mb-3">
            <label class="flbl">Referral Code (optional)</label>
            <input type="text" name="ref_code" class="fctrl" value="<?= sanitize($ref_code) ?>" placeholder="Have a Community Partner's code? Enter it here.">
            <?php if ($ref_code !== '' && $referrer): ?>
              <div class="alert alert-success py-2 px-3 mt-2 mb-0 small">Referred by <strong><?= sanitize($referrer['full_name']) ?></strong>.</div>
            <?php elseif ($ref_code !== ''): ?>
              <div class="form-text mt-1">That code wasn't recognized — you can still submit without it.</div>
            <?php endif; ?>
          </div>
          <h2 class="h6 mb-3">Your Account</h2>
          <div class="row">
            <div class="col-sm-4 mb-3">
              <label class="flbl">First Name</label>
              <input type="text" name="first_name" class="fctrl" value="<?= sanitize($first_name) ?>" required>
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Middle Name</label>
              <input type="text" name="middle_name" class="fctrl" value="<?= sanitize($middle_name) ?>">
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Surname</label>
              <input type="text" name="last_name" class="fctrl" value="<?= sanitize($last_name) ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">House #/Street</label>
            <input type="text" name="address_line" class="fctrl" value="<?= sanitize($address_line) ?>" required>
          </div>
          <div class="row">
            <div class="col-sm-4 mb-3">
              <label class="flbl">Barangay</label>
              <input type="text" name="barangay" class="fctrl" value="<?= sanitize($barangay) ?>" required>
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">City/Municipality</label>
              <input type="text" name="city" class="fctrl" value="<?= sanitize($city) ?>" required>
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Province</label>
              <input type="text" name="province" class="fctrl" value="<?= sanitize($province) ?>" required>
            </div>
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
            <label class="flbl">Email Address (optional)</label>
            <input type="email" name="email" class="fctrl" value="<?= sanitize($email) ?>">
          </div>
          <div class="mb-3">
            <label class="flbl">Username</label>
            <input type="text" name="username" class="fctrl" value="<?= sanitize($username) ?>" required>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Temporary Password</label>
              <div class="pwd-field">
                <input type="password" id="applyPassword" name="password" class="fctrl" required>
                <button type="button" class="pwd-toggle" data-pwd-target="applyPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
              </div>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Confirm Password</label>
              <div class="pwd-field">
                <input type="password" id="applyConfirmPassword" name="confirm_password" class="fctrl" required>
                <button type="button" class="pwd-toggle" data-pwd-target="applyConfirmPassword" tabindex="-1" aria-label="Show password"><i class="fas fa-eye"></i></button>
              </div>
            </div>
          </div>

          <h2 class="h6 mb-3 mt-2">Employer Information</h2>
          <div class="mb-3">
            <label class="flbl">Employer / Company Name</label>
            <input type="text" name="employer_name" class="fctrl" value="<?= sanitize($employer_name) ?>" required>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Employer Contact</label>
              <input type="text" name="employer_contact" class="fctrl" value="<?= sanitize($employer_contact) ?>" required>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Position</label>
              <input type="text" name="position" class="fctrl" value="<?= sanitize($position) ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">Employer / Office Address (optional)</label>
            <input type="text" name="employer_address" class="fctrl" value="<?= sanitize($employer_address) ?>" placeholder="Lets you choose company delivery at checkout later">
          </div>

          <h2 class="h6 mb-3 mt-2">Required Documents</h2>
          <div class="form-text mb-3">JPG, PNG, WEBP, or PDF — max 5MB each.</div>
          <div class="mb-3">
            <label class="flbl">Valid ID #1</label>
            <input type="file" name="valid_id_1" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Valid ID #2</label>
            <input type="file" name="valid_id_2" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Barangay Clearance</label>
            <input type="file" name="barangay_clearance" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Membership Application Form (signed) - Front Page (optional)</label>
            <input type="file" name="membership_application_form" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
          </div>
          <div class="mb-3">
            <label class="flbl">Membership Application Form (signed) - Back Page (optional)</label>
            <input type="file" name="membership_application_form_back" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
          </div>
          <div class="mb-3">
            <label class="flbl">Certificate of Employment / Company Work Clearance (optional)</label>
            <input type="file" name="certificate_of_employment" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf">
          </div>

          <button type="submit" class="btn-red w-100 justify-content-center"><i class="fas fa-paper-plane"></i>Submit Application</button>
        </form>
        <p class="text-center mt-3 small mb-0">Already have a TindaGo account? <a href="<?= BASICS_URL ?>/login.php">Login</a></p>
      </div>
    </div>
  </div>
</div>

<script>
// Restores text-field input if the browser is refreshed mid-form — never
// saves the password fields or file uploads (can't be restored anyway).
(function () {
  var form = document.getElementById('applyForm');
  if (!form) return;
  var storageKey = 'basicsApplyDraft';
  var fields = ['ref_code', 'first_name', 'middle_name', 'last_name', 'address_line', 'barangay', 'city', 'province',
                'birthdate', 'contact_number', 'email', 'username',
                'employer_name', 'employer_contact', 'position', 'employer_address'];

  var draft = {};
  try { draft = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (e) { draft = {}; }

  fields.forEach(function (name) {
    var el = form.elements[name];
    if (el && !el.value && draft[name]) {
      el.value = draft[name];
    }
    if (el) {
      el.addEventListener('input', function () {
        draft[name] = el.value;
        try { localStorage.setItem(storageKey, JSON.stringify(draft)); } catch (e) {}
      });
    }
  });

  form.addEventListener('submit', function () {
    try { localStorage.removeItem(storageKey); } catch (e) {}
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
