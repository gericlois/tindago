<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_registration']);

$errors = [];
$text_fields = ['first_name', 'middle_name', 'last_name', 'address_line', 'barangay', 'city', 'province',
                'birthdate', 'contact_number', 'email', 'username', 'employer_name', 'employer_contact', 'position'];
$old = array_fill_keys($text_fields, '');

$doc_fields = [
    'valid_id_1' => 'Valid ID #1',
    'valid_id_2' => 'Valid ID #2',
    'barangay_clearance' => 'Barangay Clearance',
    'membership_application_form' => 'Membership Application Form (signed) - Front Page',
    'membership_application_form_back' => 'Membership Application Form (signed) - Back Page',
    'certificate_of_employment' => 'Certificate of Employment / Company Work Clearance',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($text_fields as $field) {
        $old[$field] = trim($_POST[$field] ?? '');
    }
    extract($old);

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name === '') $errors[] = 'Last name is required.';
    if ($address_line === '') $errors[] = 'House #/Street is required.';
    if ($barangay === '') $errors[] = 'Barangay is required.';
    if ($city === '') $errors[] = 'City/Municipality is required.';
    if ($province === '') $errors[] = 'Province is required.';
    if ($birthdate === '' || !DateTime::createFromFormat('Y-m-d', $birthdate)) $errors[] = 'A valid birthdate is required.';
    if ($contact_number === '') $errors[] = 'Contact number is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address doesn\'t look valid.';
    if ($username === '') $errors[] = 'Username is required.';
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

    foreach ($doc_fields as $field => $label) {
        if (empty($_FILES[$field]['name'])) {
            $errors[] = $label . ' is required.';
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

            $stmt = $conn->prepare("INSERT INTO basics_members (user_id, employer_name, employer_contact, position) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isss', $user_id, $employer_name, $employer_contact, $position);
            $stmt->execute();
            $member_id = $stmt->insert_id;
            $stmt->close();

            foreach ($doc_fields as $field => $label) {
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

            log_activity($conn, 'register_basics_member', 'Registered Basics member "' . $full_name . '" (' . $username . ') — application #' . $member_id . ' pending approval');
            send_sms($contact_number, "Hi $full_name, we've received your JMC Foodies Basics membership application. It's now under review for processing and approval. - JMC Foodies Basics");

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

$page_title = 'Register Member';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Register Member</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if ($registered): ?>
    <div class="sucmsg is-visible mb-4">
      <p class="mb-1"><strong><?= sanitize($registered['full_name']) ?></strong> was registered. Their application is now pending approval.</p>
      <p class="mb-1">Username: <strong><?= sanitize($registered['username']) ?></strong></p>
      <p class="mb-1">Temporary password: <span style="font-size:1.2rem;font-family:monospace;"><?= sanitize($registered['password']) ?></span></p>
      <p class="mb-2 small">Copy this now and give it to the member — it will not be shown again. They'll be asked to choose their own password at first login.</p>
      <a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $registered['member_id'] ?>" class="btn-chip btn-chip-outline">View profile</a>
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
        <p class="text-muted small">Registers a member on their behalf. The application goes to an admin for approval — credit limit and activation are set there, not here.</p>
        <form method="post" enctype="multipart/form-data">
          <h2 class="h6 mb-3">Member Details</h2>
          <div class="row">
            <div class="col-sm-4 mb-3">
              <label class="flbl">First Name</label>
              <input type="text" name="first_name" class="fctrl" value="<?= sanitize($old['first_name']) ?>" required>
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Middle Name</label>
              <input type="text" name="middle_name" class="fctrl" value="<?= sanitize($old['middle_name']) ?>">
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Surname</label>
              <input type="text" name="last_name" class="fctrl" value="<?= sanitize($old['last_name']) ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">House #/Street</label>
            <input type="text" name="address_line" class="fctrl" value="<?= sanitize($old['address_line']) ?>" required>
          </div>
          <div class="row">
            <div class="col-sm-4 mb-3">
              <label class="flbl">Barangay</label>
              <input type="text" name="barangay" class="fctrl" value="<?= sanitize($old['barangay']) ?>" required>
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">City/Municipality</label>
              <input type="text" name="city" class="fctrl" value="<?= sanitize($old['city']) ?>" required>
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Province</label>
              <input type="text" name="province" class="fctrl" value="<?= sanitize($old['province']) ?>" required>
            </div>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Birthdate</label>
              <input type="date" name="birthdate" class="fctrl" value="<?= sanitize($old['birthdate']) ?>" required>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Contact Number</label>
              <input type="text" name="contact_number" class="fctrl" value="<?= sanitize($old['contact_number']) ?>" required>
            </div>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Email Address (optional)</label>
              <input type="email" name="email" class="fctrl" value="<?= sanitize($old['email']) ?>">
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Username</label>
              <input type="text" name="username" class="fctrl" value="<?= sanitize($old['username']) ?>" required>
              <div class="form-text">A temporary password is generated after you submit.</div>
            </div>
          </div>

          <h2 class="h6 mb-3 mt-2">Employer Information</h2>
          <div class="mb-3">
            <label class="flbl">Employer / Company Name</label>
            <input type="text" name="employer_name" class="fctrl" value="<?= sanitize($old['employer_name']) ?>" required>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Employer Contact</label>
              <input type="text" name="employer_contact" class="fctrl" value="<?= sanitize($old['employer_contact']) ?>" required>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Position</label>
              <input type="text" name="position" class="fctrl" value="<?= sanitize($old['position']) ?>" required>
            </div>
          </div>

          <h2 class="h6 mb-3 mt-2">Required Documents</h2>
          <div class="form-text mb-3">JPG, PNG, WEBP, or PDF — max 5MB each.</div>
          <?php foreach ($doc_fields as $field => $label): ?>
            <div class="mb-3">
              <label class="flbl"><?= sanitize($label) ?></label>
              <input type="file" name="<?= $field ?>" class="fctrl" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
            </div>
          <?php endforeach; ?>

          <button type="submit" class="btn-red"><i class="fas fa-user-plus"></i>Register Member</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
