<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

// Online version of the TindaGo Store Partner Application Form
// (assets/img/form.jpg), section for section. Section 10 (For TindaGo Use
// Only) lives on the admin review page, basics/admin/application_view.php.
// Applying always creates a brand-new member account (basics_users). If this
// browser is already logged into one, just send them to their existing
// application.
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
$alt_contact_number = '';
$email = '';
$username = '';
$store_name = '';
$sp = tindago_store_profile_defaults();
$declaration = false;
// Optional referral/agent code. An invalid/unknown code is never a hard
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

$doc_types = tindago_kyc_doc_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $store_name = trim($_POST['store_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $alt_contact_number = trim($_POST['alt_contact_number'] ?? '');
    $address_line = trim($_POST['address_line'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $declaration = !empty($_POST['declaration']);

    if ($store_name === '') $errors[] = 'Store / business name is required.';
    if ($first_name === '') $errors[] = "Store owner's first name is required.";
    if ($last_name === '') $errors[] = "Store owner's surname is required.";
    if ($contact_number === '') $errors[] = 'Mobile number is required.';
    if ($address_line === '') $errors[] = 'Complete store address is required.';
    if ($barangay === '') $errors[] = 'Barangay is required.';
    if ($city === '') $errors[] = 'Municipality/City is required.';
    if ($province === '') $errors[] = 'Province is required.';

    [$sp, $sp_errors] = tindago_store_profile_from_post($_POST);
    $errors = array_merge($errors, $sp_errors);

    if ($birthdate === '' || !DateTime::createFromFormat('Y-m-d', $birthdate)) $errors[] = "A valid birthdate for the store owner is required.";
    // Email is optional — only validated for format when the applicant
    // actually provides one.
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address doesn\'t look valid.';
    if ($username === '') $errors[] = 'Username is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    foreach ($doc_types as $field => $doc) {
        if ($doc['required'] && empty($_FILES[$field]['name'])) {
            $errors[] = $doc['label'] . ' is required.';
        }
    }
    if (!$declaration) $errors[] = 'Please read and agree to the declaration.';

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

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $full_name = basics_compose_full_name($first_name, $middle_name, $last_name);
            // The store address is the member's address on file, so it's
            // also their default delivery address.
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

            // employer_name / employer_contact / position hold the store
            // name, alternative contact number, and the applicant's role
            // (column names kept from the codebase TindaGo was built from).
            $alt_contact_to_store = $alt_contact_number !== '' ? $alt_contact_number : null;
            $position = 'Owner / Proprietor';
            $referred_by = $referrer['id'] ?? null;
            $stmt = $conn->prepare("INSERT INTO basics_members (user_id, employer_name, employer_contact, position, referred_by, declaration_accepted_at)
                                     VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param('isssi', $user_id, $store_name, $alt_contact_to_store, $position, $referred_by);
            $stmt->execute();
            $member_id = $stmt->insert_id;
            $stmt->close();

            tindago_store_profile_save($conn, $member_id, $sp);

            foreach ($doc_types as $field => $doc) {
                // Skip entirely if an optional doc was left blank — no row,
                // no upload attempt, not just a suppressed error.
                if (!$doc['required'] && empty($_FILES[$field]['name'])) {
                    continue;
                }
                [$filename, $upload_error] = handle_kyc_document_upload($field);
                if ($upload_error) {
                    throw new Exception($doc['label'] . ': ' . $upload_error);
                }
                $stmt = $conn->prepare("INSERT INTO basics_kyc_documents (member_id, doc_type, file_path) VALUES (?, ?, ?)");
                $stmt->bind_param('iss', $member_id, $field, $filename);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();

            send_sms($contact_number, "Hi $full_name, we've received your TindaGo Store Partner application for $store_name. It's now under review for processing and approval. - TindaGo");

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

$page_title = 'Store Partner Application';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Mas Mura. Mas Madali. Mas Malaki ang Kita.</span>
    <h1 class="stitle">Store Partner <span>Application Form</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-10 col-xl-9">
      <div class="panel-card">
        <?php if ($errors): ?>
          <div class="errmsg">
            <ul class="mb-0">
              <?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="applyForm">
          <div class="form-section">
            <h2 class="form-section-title"><span>1</span>Store Information</h2>
            <div class="mb-3">
              <label class="flbl">Store / Business Name</label>
              <input type="text" name="store_name" class="fctrl" value="<?= sanitize($store_name) ?>" required>
            </div>
            <label class="flbl">Store Owner / Proprietor</label>
            <div class="row">
              <div class="col-sm-4 mb-3">
                <input type="text" name="first_name" class="fctrl" value="<?= sanitize($first_name) ?>" placeholder="First name" aria-label="First name" required>
              </div>
              <div class="col-sm-4 mb-3">
                <input type="text" name="middle_name" class="fctrl" value="<?= sanitize($middle_name) ?>" placeholder="Middle name" aria-label="Middle name">
              </div>
              <div class="col-sm-4 mb-3">
                <input type="text" name="last_name" class="fctrl" value="<?= sanitize($last_name) ?>" placeholder="Surname" aria-label="Surname" required>
              </div>
            </div>
            <div class="row">
              <div class="col-sm-6 mb-3">
                <label class="flbl">Mobile Number</label>
                <input type="tel" name="contact_number" class="fctrl" value="<?= sanitize($contact_number) ?>" required>
                <div class="form-text">Also your TindaGo account mobile number &mdash; order and payment updates are texted here.</div>
              </div>
              <div class="col-sm-6 mb-3">
                <label class="flbl">Alternative Contact Number (optional)</label>
                <input type="tel" name="alt_contact_number" class="fctrl" value="<?= sanitize($alt_contact_number) ?>">
              </div>
            </div>
            <div class="mb-3">
              <label class="flbl">Complete Store Address</label>
              <input type="text" name="address_line" class="fctrl" value="<?= sanitize($address_line) ?>" placeholder="House/lot no., street, landmark" required>
            </div>
            <div class="row">
              <div class="col-sm-4 mb-3">
                <label class="flbl">Barangay</label>
                <input type="text" name="barangay" class="fctrl" value="<?= sanitize($barangay) ?>" required>
              </div>
              <div class="col-sm-4 mb-3">
                <label class="flbl">Municipality/City</label>
                <input type="text" name="city" class="fctrl" value="<?= sanitize($city) ?>" required>
              </div>
              <div class="col-sm-4 mb-3">
                <label class="flbl">Province</label>
                <input type="text" name="province" class="fctrl" value="<?= sanitize($province) ?>" required>
              </div>
            </div>
          </div>

          <?php require __DIR__ . '/includes/store_profile_fields.php'; ?>

          <div class="form-section">
            <h2 class="form-section-title"><span>7</span>TindaGo Account Details</h2>
            <div class="row">
              <div class="col-sm-6 mb-3">
                <label class="flbl">Store Owner's Birthdate</label>
                <input type="date" name="birthdate" class="fctrl" value="<?= sanitize($birthdate) ?>" required>
              </div>
              <div class="col-sm-6 mb-3">
                <label class="flbl">Email Address (optional)</label>
                <input type="email" name="email" class="fctrl" value="<?= sanitize($email) ?>">
              </div>
            </div>
            <div class="mb-3">
              <label class="flbl">Username</label>
              <input type="text" name="username" class="fctrl" value="<?= sanitize($username) ?>" required>
            </div>
            <div class="row">
              <div class="col-sm-6 mb-3">
                <label class="flbl">Password</label>
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
            <div class="mb-0">
              <label class="flbl">Referral / Agent Code (optional)</label>
              <input type="text" name="ref_code" class="fctrl" value="<?= sanitize($ref_code) ?>">
              <?php if ($ref_code !== '' && $referrer): ?>
                <div class="alert alert-success py-2 px-3 mt-2 mb-0 small">Referred by <strong><?= sanitize($referrer['full_name']) ?></strong>.</div>
              <?php elseif ($ref_code !== ''): ?>
                <div class="form-text mt-1">That code wasn't recognized — you can still submit without it.</div>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-section">
            <h2 class="form-section-title"><span>8</span>Required Documents</h2>
            <div class="form-text mb-3">JPG, PNG, WEBP, or PDF — max 5MB each.</div>
            <div class="row">
              <?php foreach ($doc_types as $field => $doc): ?>
                <div class="col-md-6 mb-3">
                  <label class="flbl"><?= sanitize($doc['label']) ?><?= $doc['required'] ? '' : ' (optional)' ?></label>
                  <input type="file" name="<?= $field ?>" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf"<?= $doc['required'] ? ' required' : '' ?>>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-section">
            <h2 class="form-section-title"><span>9</span>Declaration</h2>
            <ul class="small mb-3">
              <li>I certify that the information provided in this application is true and correct to the best of my knowledge.</li>
              <li>I understand that registration as a TindaGo Store Partner does not automatically guarantee approval for credit, subsidies, assistance programs, or other benefits.</li>
              <li>I authorize TindaGo and its authorized representatives to verify the information provided in this application for purposes of store registration, account management, ordering, delivery, credit assessment, rewards, promotions, and applicable Store Partner programs.</li>
              <li>I agree to comply with the applicable TindaGo terms, policies, payment conditions, and program guidelines.</li>
            </ul>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="declaration" id="declaration" value="1"<?= $declaration ? ' checked' : '' ?> required>
              <label class="form-check-label fw-semibold" for="declaration">I have read and agree to the declaration above.</label>
            </div>
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
  var storageKey = 'tindagoApplyDraft';
  var fields = ['store_name', 'first_name', 'middle_name', 'last_name', 'contact_number', 'alt_contact_number',
                'address_line', 'barangay', 'city', 'province', 'store_type_other', 'store_hours_open', 'store_hours_close',
                'current_suppliers', 'ordering_method_other', 'products_interested_other', 'preferred_payment_other',
                'birthdate', 'email', 'username', 'ref_code'];

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
