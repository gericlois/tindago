<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_registration']);

// Staff entry of a paper Store Partner Application Form
// (assets/img/form.jpg) — same sections as basics/apply.php, except the
// account gets a generated temporary password.
$errors = [];
$text_fields = ['store_name', 'first_name', 'middle_name', 'last_name', 'contact_number', 'alt_contact_number',
                'address_line', 'barangay', 'city', 'province', 'birthdate', 'email', 'username'];
$old = array_fill_keys($text_fields, '');
$sp = tindago_store_profile_defaults();
$declaration = false;

$doc_types = tindago_kyc_doc_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($text_fields as $field) {
        $old[$field] = trim($_POST[$field] ?? '');
    }
    extract($old);
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
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address doesn\'t look valid.';
    if ($username === '') $errors[] = 'Username is required.';
    if (!$declaration) $errors[] = 'Confirm the applicant has signed the declaration on the paper form.';

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

    foreach ($doc_types as $field => $doc) {
        if ($doc['required'] && empty($_FILES[$field]['name'])) {
            $errors[] = $doc['label'] . ' is required.';
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // NULL, not '', so several members without an email don't collide
            // on the unique index (MySQL treats every NULL as distinct).
            $email_to_store = $email !== '' ? $email : null;
            $password = generate_temp_password(10);
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

            // employer_name / employer_contact / position hold the store
            // name, alternative contact number, and the applicant's role.
            $alt_contact_to_store = $alt_contact_number !== '' ? $alt_contact_number : null;
            $position = 'Owner / Proprietor';
            $stmt = $conn->prepare("INSERT INTO basics_members (user_id, employer_name, employer_contact, position, declaration_accepted_at)
                                     VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param('isss', $user_id, $store_name, $alt_contact_to_store, $position);
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

            log_activity($conn, 'register_basics_member', 'Registered Store Partner "' . $store_name . '" — ' . $full_name . ' (' . $username . '), application #' . $member_id . ' pending approval');
            send_sms($contact_number, "Hi $full_name, we've received your TindaGo Store Partner application for $store_name. It's now under review for processing and approval. - TindaGo");

            $_SESSION['flash_registered'] = [
                'member_id' => $member_id,
                'full_name' => $full_name,
                'username' => $username,
                'password' => $password,
            ];
            redirect('/basics/admin/register_member.php?registered=1');
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = safe_error_message($e);
        }
    }
}

$registered = null;
if (isset($_SESSION['flash_registered'])) {
    $registered = $_SESSION['flash_registered'];
    unset($_SESSION['flash_registered']);
}

$page_title = 'Register Store Partner';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Register Store Partner</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if ($registered): ?>
    <div class="sucmsg is-visible mb-4">
      <p class="mb-1"><strong><?= sanitize($registered['full_name']) ?></strong> was registered. Their application is now pending approval.</p>
      <p class="mb-1">Username: <strong><?= sanitize($registered['username']) ?></strong></p>
      <p class="mb-1">Temporary password: <span style="font-size:1.2rem;font-family:monospace;"><?= sanitize($registered['password']) ?></span></p>
      <p class="mb-2 small">Copy this now and give it to the member — it will not be shown again. They'll be asked to choose their own password at first login.</p>
      <a href="<?= BASE_URL ?>/basics/admin/application_view.php?id=<?= (int) $registered['member_id'] ?>" class="btn-chip btn-chip-outline">Review application</a>
    </div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row justify-content-center">
    <div class="col-12 col-xl-9">
      <div class="panel-card">
        <p class="text-muted small">Enter a paper Store Partner Application Form on the applicant's behalf. The application goes to an admin for approval — the credit line, agent and territory are set on the review page, not here.</p>
        <form method="post" enctype="multipart/form-data">
          <div class="form-section">
            <h2 class="form-section-title"><span>1</span>Store Information</h2>
            <div class="mb-3">
              <label class="flbl">Store / Business Name</label>
              <input type="text" name="store_name" class="fctrl" value="<?= sanitize($old['store_name']) ?>" required>
            </div>
            <label class="flbl">Store Owner / Proprietor</label>
            <div class="row">
              <div class="col-sm-4 mb-3">
                <input type="text" name="first_name" class="fctrl" value="<?= sanitize($old['first_name']) ?>" placeholder="First name" aria-label="First name" required>
              </div>
              <div class="col-sm-4 mb-3">
                <input type="text" name="middle_name" class="fctrl" value="<?= sanitize($old['middle_name']) ?>" placeholder="Middle name" aria-label="Middle name">
              </div>
              <div class="col-sm-4 mb-3">
                <input type="text" name="last_name" class="fctrl" value="<?= sanitize($old['last_name']) ?>" placeholder="Surname" aria-label="Surname" required>
              </div>
            </div>
            <div class="row">
              <div class="col-sm-6 mb-3">
                <label class="flbl">Mobile Number</label>
                <input type="tel" name="contact_number" class="fctrl" value="<?= sanitize($old['contact_number']) ?>" required>
              </div>
              <div class="col-sm-6 mb-3">
                <label class="flbl">Alternative Contact Number (optional)</label>
                <input type="tel" name="alt_contact_number" class="fctrl" value="<?= sanitize($old['alt_contact_number']) ?>">
              </div>
            </div>
            <div class="mb-3">
              <label class="flbl">Complete Store Address</label>
              <input type="text" name="address_line" class="fctrl" value="<?= sanitize($old['address_line']) ?>" required>
            </div>
            <div class="row">
              <div class="col-sm-4 mb-3">
                <label class="flbl">Barangay</label>
                <input type="text" name="barangay" class="fctrl" value="<?= sanitize($old['barangay']) ?>" required>
              </div>
              <div class="col-sm-4 mb-3">
                <label class="flbl">Municipality/City</label>
                <input type="text" name="city" class="fctrl" value="<?= sanitize($old['city']) ?>" required>
              </div>
              <div class="col-sm-4 mb-3">
                <label class="flbl">Province</label>
                <input type="text" name="province" class="fctrl" value="<?= sanitize($old['province']) ?>" required>
              </div>
            </div>
          </div>

          <?php require __DIR__ . '/../includes/store_profile_fields.php'; ?>

          <div class="form-section">
            <h2 class="form-section-title"><span>7</span>TindaGo Account Details</h2>
            <div class="row">
              <div class="col-sm-4 mb-3">
                <label class="flbl">Store Owner's Birthdate</label>
                <input type="date" name="birthdate" class="fctrl" value="<?= sanitize($old['birthdate']) ?>" required>
              </div>
              <div class="col-sm-4 mb-3">
                <label class="flbl">Email Address (optional)</label>
                <input type="email" name="email" class="fctrl" value="<?= sanitize($old['email']) ?>">
              </div>
              <div class="col-sm-4 mb-3">
                <label class="flbl">Username</label>
                <input type="text" name="username" class="fctrl" value="<?= sanitize($old['username']) ?>" required>
                <div class="form-text">A temporary password is generated after you submit.</div>
              </div>
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
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="declaration" id="declaration" value="1"<?= $declaration ? ' checked' : '' ?> required>
              <label class="form-check-label" for="declaration">The applicant has signed the declaration on the paper application form.</label>
            </div>
          </div>

          <button type="submit" class="btn-red"><i class="fas fa-user-plus"></i>Register Store Partner</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
