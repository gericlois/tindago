<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_registration']);

$id = (int) ($_GET['id'] ?? 0);
// Registration staff get a read-only profile + submitted documents: no credit,
// orders, payments, messaging or status changes — and no POST at all.
$is_view_only = basics_admin_role() === 'staff_registration';
if ($is_view_only && $_SERVER['REQUEST_METHOD'] === 'POST') {
    redirect('/basics/admin/member_view.php?id=' . $id);
}
$email_errors = [];
$sms_errors = [];
$reset_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['action'] ?? '', ['send_email', 'send_sms', 'reset_password', 'update_profile'], true)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_credit') {
        $weekly_limit = round((float) ($_POST['weekly_credit_limit'] ?? 0), 2);
        $emergency_limit = round((float) ($_POST['emergency_credit_limit'] ?? 0), 2);
        $unfreeze = isset($_POST['unfreeze']) ? 0 : null;
        if ($unfreeze === null) {
            $stmt = $conn->prepare("UPDATE basics_members SET weekly_credit_limit = ?, emergency_credit_limit = ? WHERE id = ?");
            $stmt->bind_param('ddi', $weekly_limit, $emergency_limit, $id);
        } else {
            $stmt = $conn->prepare("UPDATE basics_members SET weekly_credit_limit = ?, emergency_credit_limit = ?, credit_limit_frozen = 0 WHERE id = ?");
            $stmt->bind_param('ddi', $weekly_limit, $emergency_limit, $id);
        }
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'update_basics_credit', 'Updated purchase limit for Basics member #' . $id . ' (weekly ' . format_price($weekly_limit) . ', emergency ' . format_price($emergency_limit) . ')');
    } elseif ($action === 'suspend') {
        $stmt = $conn->prepare("UPDATE basics_members SET membership_status = 'suspended' WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'suspend_basics_member', 'Suspended Basics member #' . $id);
    } elseif ($action === 'reinstate') {
        $stmt = $conn->prepare("UPDATE basics_members SET membership_status = 'active', suspended_until = NULL WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'reinstate_basics_member', 'Reinstated Basics member #' . $id);
    } elseif ($action === 'terminate') {
        $stmt = $conn->prepare("UPDATE basics_members SET membership_status = 'terminated' WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'terminate_basics_member', 'Terminated Basics member #' . $id);
    } elseif ($action === 'toggle_partner') {
        $stmt = $conn->prepare("SELECT is_community_partner, referral_code FROM basics_members WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($current) {
            $activating = !$current['is_community_partner'];
            // Generate the code once, on first-ever activation — re-toggling
            // off and back on must not hand out a second code.
            if ($activating && !$current['referral_code']) {
                $new_code = basics_generate_referral_code($conn);
                $stmt = $conn->prepare("UPDATE basics_members SET is_community_partner = 1, referral_code = ? WHERE id = ?");
                $stmt->bind_param('si', $new_code, $id);
            } else {
                $stmt = $conn->prepare("UPDATE basics_members SET is_community_partner = ? WHERE id = ?");
                $flag = $activating ? 1 : 0;
                $stmt->bind_param('ii', $flag, $id);
            }
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'toggle_basics_partner', ($activating ? 'Designated' : 'Revoked') . ' Community Partner status for Basics member #' . $id);
        }
    }
    redirect('/basics/admin/member_view.php?id=' . $id);
}

$stmt = $conn->prepare("SELECT bm.*, u.full_name, u.username, u.email, u.contact_number, u.address,
                                u.first_name, u.middle_name, u.last_name,
                                u.address_line, u.barangay, u.city, u.province, u.birthdate
                         FROM basics_members bm JOIN basics_users u ON u.id = bm.user_id WHERE bm.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    redirect('/basics/admin/members.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_email') {
    $email_subject = trim($_POST['email_subject'] ?? '');
    $email_message = trim($_POST['email_message'] ?? '');

    if (empty($member['email'])) {
        $email_errors[] = 'This member has no email address on file.';
    }
    if ($email_subject === '') {
        $email_errors[] = 'Enter a subject.';
    }
    if ($email_message === '') {
        $email_errors[] = 'Enter a message.';
    }

    if (empty($email_errors)) {
        if (send_email($member['email'], $email_subject, "Hi {$member['full_name']},\r\n\r\n{$email_message}\r\n\r\n— JMC Foodies Basics Team")) {
            log_activity($conn, 'send_basics_member_email', 'Emailed Basics member "' . $member['full_name'] . '" (subject: ' . $email_subject . ')');
            redirect('/basics/admin/member_view.php?id=' . $id . '&email_sent=1');
        } else {
            $email_errors[] = 'Failed to send — check the Gmail SMTP configuration (config/email.php).';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_sms') {
    $sms_message = trim($_POST['sms_message'] ?? '');

    if (empty($member['contact_number'])) {
        $sms_errors[] = 'This member has no contact number on file.';
    }
    if ($sms_message === '') {
        $sms_errors[] = 'Enter a message.';
    } elseif (strlen($sms_message) > 480) {
        $sms_errors[] = 'Message is too long (max 480 characters, about 3 SMS segments).';
    }

    if (empty($sms_errors)) {
        if (send_sms($member['contact_number'], $sms_message)) {
            log_activity($conn, 'send_basics_member_sms', 'Texted Basics member "' . $member['full_name'] . '"');
            redirect('/basics/admin/member_view.php?id=' . $id . '&sms_sent=1');
        } else {
            $sms_errors[] = 'Failed to send — check the Semaphore SMS configuration (config/sms.php).';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (empty($member['email']) && empty($member['contact_number'])) {
        $reset_errors[] = 'This member has no email address or contact number on file.';
    }

    if (empty($reset_errors)) {
        $sent = reset_member_password($conn, 'basics_users', $member['user_id'], 'JMC Foodies Basics', $member['full_name'], (string) $member['email'], (string) $member['contact_number'], false);
        if ($sent['email'] || $sent['sms']) {
            $via = $sent['email'] && $sent['sms'] ? 'email and SMS' : ($sent['email'] ? 'email' : 'SMS');
            log_activity($conn, 'reset_basics_member_password', 'Reset password for Basics member "' . $member['full_name'] . '" and sent the new temporary password by ' . $via);
            redirect('/basics/admin/member_view.php?id=' . $id . '&password_reset=' . ($sent['email'] && $sent['sms'] ? 'both' : ($sent['email'] ? 'email' : 'sms')));
        } else {
            $reset_errors[] = 'Nothing was sent, so the password was left unchanged — check the Gmail SMTP (config/email.php) and Semaphore SMS (config/sms.php) configuration.';
        }
    }
}

$profile_errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $address_line = trim($_POST['address_line'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');

    if ($first_name === '') $profile_errors[] = 'First name is required.';
    if ($last_name === '') $profile_errors[] = 'Last name is required.';
    if ($address_line === '') $profile_errors[] = 'House #/Street is required.';
    if ($barangay === '') $profile_errors[] = 'Barangay is required.';
    if ($city === '') $profile_errors[] = 'City/Municipality is required.';
    if ($province === '') $profile_errors[] = 'Province is required.';
    if ($contact_number === '') $profile_errors[] = 'Contact number is required.';
    if ($birthdate === '' || !DateTime::createFromFormat('Y-m-d', $birthdate)) $profile_errors[] = 'A valid birthdate is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $profile_errors[] = 'That email address doesn\'t look valid.';

    if (empty($profile_errors) && $email !== '') {
        $stmt = $conn->prepare("SELECT id FROM basics_users WHERE email = ? AND id != ?");
        $stmt->bind_param('si', $email, $member['user_id']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) $profile_errors[] = 'That email address is already used by another account.';
        $stmt->close();
    }

    if (empty($profile_errors)) {
        $full_name = basics_compose_full_name($first_name, $middle_name, $last_name);
        $address = basics_compose_address($address_line, $barangay, $city, $province);
        $email_to_store = $email !== '' ? $email : null;
        $stmt = $conn->prepare("UPDATE basics_users SET
            full_name = ?, first_name = ?, middle_name = ?, last_name = ?,
            address = ?, address_line = ?, barangay = ?, city = ?, province = ?,
            contact_number = ?, email = ?, birthdate = ?
            WHERE id = ?");
        $stmt->bind_param('ssssssssssssi', $full_name, $first_name, $middle_name, $last_name,
            $address, $address_line, $barangay, $city, $province, $contact_number, $email_to_store, $birthdate, $member['user_id']);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, 'update_basics_member_profile', 'Updated profile details for Basics member "' . $full_name . '" (#' . $id . ')');
        redirect('/basics/admin/member_view.php?id=' . $id . '&profile_updated=1');
    }

    // Validation failed — keep the submitted values on screen instead of
    // silently reverting to what's still in the database.
    $member = array_merge($member, compact(
        'first_name', 'middle_name', 'last_name', 'address_line', 'barangay',
        'city', 'province', 'contact_number', 'email', 'birthdate'
    ));
    $member['full_name'] = basics_compose_full_name($first_name, $middle_name, $last_name);
}

$outstanding = basics_outstanding_balance($conn, $member['id']);

$stmt = $conn->prepare("SELECT o.*,
                                (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS amount_paid
                         FROM basics_orders o
                         WHERE o.member_id = ? AND o.status != 'draft' ORDER BY o.created_at DESC LIMIT 10");
$stmt->bind_param('i', $id);
$stmt->execute();
$orders = $stmt->get_result();

$stmt = $conn->prepare("SELECT * FROM basics_payments WHERE member_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->bind_param('i', $id);
$stmt->execute();
$payments = $stmt->get_result();

$stmt = $conn->prepare("SELECT * FROM basics_kyc_documents WHERE member_id = ? ORDER BY doc_type ASC");
$stmt->bind_param('i', $id);
$stmt->execute();
$documents = $stmt->get_result();

if ($member['is_community_partner']) {
    $stmt = $conn->prepare("SELECT bm.id, u.full_name, u.username, bm.applied_at,
                                    (SELECT COUNT(*) FROM basics_orders o WHERE o.member_id = bm.id AND o.status != 'draft') AS order_count,
                                    (SELECT COALESCE(SUM(o.total_amount),0) FROM basics_orders o WHERE o.member_id = bm.id AND o.status != 'draft') AS order_total
                             FROM basics_members bm JOIN basics_users u ON u.id = bm.user_id
                             WHERE bm.referred_by = ? ORDER BY bm.applied_at DESC");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $tagged_members = $stmt->get_result();

    $stmt = $conn->prepare("SELECT wt.amount, wt.created_at, wt.reference_order_id, o.total_amount AS order_total, u.full_name AS tagged_full_name
                             FROM basics_wallet_transactions wt
                             LEFT JOIN basics_orders o ON o.id = wt.reference_order_id
                             LEFT JOIN basics_members bm2 ON bm2.id = o.member_id
                             LEFT JOIN basics_users u ON u.id = bm2.user_id
                             WHERE wt.member_id = ? AND wt.type = 'referral_override'
                             ORDER BY wt.created_at DESC");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $override_earnings = $stmt->get_result();
}

$doc_labels = [
    'valid_id_1' => 'Valid ID #1',
    'valid_id_2' => 'Valid ID #2',
    'barangay_clearance' => 'Barangay Clearance',
    'membership_application_form' => 'Membership Application Form (signed) - Front Page',
    'membership_application_form_back' => 'Membership Application Form (signed) - Back Page',
    'certificate_of_employment' => 'Certificate of Employment / Work Clearance',
];

$page_title = $member['full_name'];
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <?php if ($is_view_only): ?>
      <a href="<?= BASE_URL ?>/basics/admin/users.php" class="small">&larr; Back to Users</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/basics/admin/members.php" class="small">&larr; Back to Members</a>
    <?php endif; ?>
    <h1 class="stitle" style="font-size:2rem;"><?= sanitize($member['full_name']) ?></h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['email_sent'])): ?>
    <div class="sucmsg is-visible mb-4"><p>Email sent to <?= sanitize($member['email']) ?>.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['sms_sent'])): ?>
    <div class="sucmsg is-visible mb-4"><p>Text sent to <?= sanitize($member['contact_number']) ?>.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['profile_updated'])): ?>
    <div class="sucmsg is-visible mb-4"><p class="mb-0">Details updated.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['password_reset'])): ?>
    <?php $pr = $_GET['password_reset']; ?>
    <div class="sucmsg is-visible mb-4"><p>Password reset. New temporary password sent
      <?= $pr === 'both' ? 'by email to ' . sanitize($member['email']) . ' and by SMS to ' . sanitize($member['contact_number']) : ($pr === 'sms' ? 'by SMS to ' . sanitize($member['contact_number']) : 'by email to ' . sanitize($member['email'])) ?>.</p></div>
  <?php endif; ?>
  <?php if ($email_errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($email_errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($sms_errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($sms_errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($reset_errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($reset_errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($profile_errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($profile_errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if (!$is_view_only): ?>
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num" style="font-size:1.3rem;"><?= format_price($member['weekly_credit_limit']) ?></div><div class="stat-lbl">Weekly Limit</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num accent" style="font-size:1.3rem;"><?= format_price($outstanding) ?></div><div class="stat-lbl">Outstanding</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num"><?= (int) $member['offense_count'] ?></div><div class="stat-lbl">Offenses</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num"><?= (int) $member['consecutive_on_time_payments'] ?></div><div class="stat-lbl">On-Time Streak</div></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-4 mb-4">
    <div class="col-12 col-md-6">
      <?php if ($is_view_only): ?>
      <div class="panel-card mb-4">
        <h2 class="h6">Profile</h2>
        <p class="mb-1">Username: <?= sanitize($member['username']) ?></p>
        <p class="mb-1">Email: <?= sanitize($member['email']) ?></p>
        <p class="mb-1">Contact #: <?= sanitize($member['contact_number']) ?></p>
        <p class="mb-1">Address: <?= $member['address'] ? sanitize($member['address']) : '—' ?></p>
        <p class="mb-1">Birthday: <?= $member['birthdate'] ? date('M j, Y', strtotime($member['birthdate'])) : '—' ?></p>
        <p class="mb-1">Employer: <?= sanitize($member['employer_name']) ?></p>
        <p class="mb-0">Application: <span class="pill pill-<?= ['approved' => 'approved', 'denied' => 'rejected'][$member['application_status']] ?? 'pending' ?>"><?= sanitize($member['application_status']) ?></span></p>
      </div>
      <?php endif; ?>

      <?php if (!$is_view_only): ?>
      <div class="panel-card">
        <h2 class="h6">Membership Status</h2>
        <p class="mb-3">Status: <span class="pill pill-<?= $member['membership_status'] === 'active' ? 'active' : ($member['membership_status'] === 'dormant' ? 'pending' : 'suspended') ?>"><?= sanitize($member['membership_status']) ?></span>
          <?php if ($member['credit_limit_frozen']): ?><span class="pill pill-rejected">Purchase Frozen</span><?php endif; ?>
        </p>
        <?php if ($member['consecutive_on_time_payments'] >= 12): ?>
          <div class="sucmsg is-visible mb-3"><p class="mb-0">Eligible for a higher purchase limit (12+ on-time payments).</p></div>
        <?php endif; ?>

        <?php if ($member['membership_status'] === 'active'): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="suspend">
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Suspend this member?');">Suspend</button>
          </form>
        <?php elseif (in_array($member['membership_status'], ['suspended', 'dormant'], true)): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="reinstate">
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Reinstate this member?');">Reinstate</button>
          </form>
        <?php endif; ?>
        <?php if ($member['membership_status'] !== 'terminated'): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="terminate">
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Permanently terminate this membership? This cannot be undone.');">Terminate</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Community Partner</h2>
        <p class="mb-3">Status: <span class="pill pill-<?= $member['is_community_partner'] ? 'active' : 'pending' ?>"><?= $member['is_community_partner'] ? 'Community Partner' : 'Not a Partner' ?></span></p>
        <?php if ($member['is_community_partner'] && $member['referral_code']): ?>
          <div class="row g-2 align-items-center mb-3">
            <div class="col-12 col-md-5">
              <div class="refcode-box d-flex align-items-center justify-content-between gap-2">
                <span id="partnerRefCode"><?= sanitize($member['referral_code']) ?></span>
                <button type="button" class="btn-copy-icon" data-copy-target="partnerRefCode" aria-label="Copy referral code"><i class="fas fa-copy"></i></button>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <input type="text" class="fctrl" id="partnerRefLink" value="<?= sanitize(basics_referral_link($member['referral_code'])) ?>" readonly>
            </div>
            <div class="col-12 col-md-1">
              <button class="btn-outline-theme w-100 justify-content-center" data-copy-target="partnerRefLink">Copy</button>
            </div>
          </div>
        <?php endif; ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="action" value="toggle_partner">
          <?php if ($member['is_community_partner']): ?>
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Revoke Community Partner status for this member?');">Revoke Partner Status</button>
          <?php else: ?>
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Designate this member as a Community Partner?');"><i class="fas fa-user-plus"></i> Add Community Partner</button>
          <?php endif; ?>
        </form>

        <?php if ($member['is_community_partner']): ?>
          <h3 class="h6 mt-4">Tagged Users</h3>
          <div class="table-responsive">
            <table class="table-theme">
              <thead><tr><th>Name</th><th>Joined</th><th>Orders</th><th>Total Spent</th></tr></thead>
              <tbody>
              <?php if ($tagged_members->num_rows === 0): ?>
                <tr><td colspan="4" class="text-muted">No one has joined using this partner's referral code yet.</td></tr>
              <?php endif; ?>
              <?php while ($t = $tagged_members->fetch_assoc()): ?>
                <tr>
                  <td><?= sanitize($t['full_name']) ?> <span class="text-muted small">(<?= sanitize($t['username']) ?>)</span></td>
                  <td><?= date('M j, Y', strtotime($t['applied_at'])) ?></td>
                  <td><?= (int) $t['order_count'] ?></td>
                  <td><?= format_price($t['order_total']) ?></td>
                </tr>
              <?php endwhile; ?>
              </tbody>
            </table>
          </div>

          <h3 class="h6 mt-4">Override Earnings</h3>
          <div class="table-responsive">
            <table class="table-theme">
              <thead><tr><th>Order</th><th>Tagged Member</th><th>Order Total</th><th>Override Earned</th><th>Date</th></tr></thead>
              <tbody>
              <?php if ($override_earnings->num_rows === 0): ?>
                <tr><td colspan="5" class="text-muted">No override earnings yet.</td></tr>
              <?php endif; ?>
              <?php while ($e = $override_earnings->fetch_assoc()): ?>
                <tr>
                  <td><?php if ($e['reference_order_id']): ?><a href="<?= BASE_URL ?>/basics/admin/order_view.php?id=<?= (int) $e['reference_order_id'] ?>">#<?= (int) $e['reference_order_id'] ?></a><?php else: ?>—<?php endif; ?></td>
                  <td><?= $e['tagged_full_name'] ? sanitize($e['tagged_full_name']) : '—' ?></td>
                  <td><?= $e['order_total'] !== null ? format_price($e['order_total']) : '—' ?></td>
                  <td class="fw-bold"><?= format_price($e['amount']) ?></td>
                  <td><?= date('M j, Y', strtotime($e['created_at'])) ?></td>
                </tr>
              <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Edit Details</h2>
        <form method="post">
          <input type="hidden" name="action" value="update_profile">
          <div class="row">
            <div class="col-sm-4 mb-2">
              <label class="flbl">First Name</label>
              <input type="text" name="first_name" class="fctrl" value="<?= sanitize($member['first_name'] ?? '') ?>" required>
            </div>
            <div class="col-sm-4 mb-2">
              <label class="flbl">Middle Name</label>
              <input type="text" name="middle_name" class="fctrl" value="<?= sanitize($member['middle_name'] ?? '') ?>">
            </div>
            <div class="col-sm-4 mb-2">
              <label class="flbl">Surname</label>
              <input type="text" name="last_name" class="fctrl" value="<?= sanitize($member['last_name'] ?? '') ?>" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="flbl">House #/Street</label>
            <input type="text" name="address_line" class="fctrl" value="<?= sanitize($member['address_line'] ?? '') ?>" required>
          </div>
          <div class="row">
            <div class="col-sm-4 mb-2">
              <label class="flbl">Barangay</label>
              <input type="text" name="barangay" class="fctrl" value="<?= sanitize($member['barangay'] ?? '') ?>" required>
            </div>
            <div class="col-sm-4 mb-2">
              <label class="flbl">City/Municipality</label>
              <input type="text" name="city" class="fctrl" value="<?= sanitize($member['city'] ?? '') ?>" required>
            </div>
            <div class="col-sm-4 mb-2">
              <label class="flbl">Province</label>
              <input type="text" name="province" class="fctrl" value="<?= sanitize($member['province'] ?? '') ?>" required>
            </div>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-2">
              <label class="flbl">Contact Number</label>
              <input type="text" name="contact_number" class="fctrl" value="<?= sanitize($member['contact_number']) ?>" required>
            </div>
            <div class="col-sm-6 mb-2">
              <label class="flbl">Email Address (optional)</label>
              <input type="email" name="email" class="fctrl" value="<?= sanitize($member['email']) ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">Birthday</label>
            <input type="date" name="birthdate" class="fctrl" value="<?= sanitize($member['birthdate'] ?? '') ?>" required>
          </div>
          <button type="submit" class="btn-chip btn-chip-success"><i class="fas fa-floppy-disk"></i> Save Details</button>
        </form>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Adjust Purchase Line</h2>
        <form method="post">
          <input type="hidden" name="action" value="update_credit">
          <div class="mb-2">
            <label class="flbl">Weekly Purchase Limit</label>
            <input type="number" step="0.01" min="0" name="weekly_credit_limit" class="fctrl" value="<?= sanitize($member['weekly_credit_limit']) ?>" required>
          </div>
          <div class="mb-2">
            <label class="flbl">Emergency Loan Limit</label>
            <input type="number" step="0.01" min="0" name="emergency_credit_limit" class="fctrl" value="<?= sanitize($member['emergency_credit_limit']) ?>">
          </div>
          <?php if ($member['credit_limit_frozen']): ?>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="unfreeze" id="unfreezeCheck" value="1">
              <label class="form-check-label" for="unfreezeCheck">Unfreeze purchase limit</label>
            </div>
          <?php endif; ?>
          <button type="submit" class="btn-chip btn-chip-success"><i class="fas fa-floppy-disk"></i> Save</button>
        </form>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Send Email</h2>
        <?php if (empty($member['email'])): ?>
          <p class="text-muted small mb-0">This member has no email address on file.</p>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="send_email">
            <div class="mb-2">
              <label class="flbl">Subject</label>
              <input type="text" name="email_subject" class="fctrl" required>
            </div>
            <div class="mb-2">
              <label class="flbl">Message</label>
              <textarea name="email_message" class="fctrl" rows="4" required></textarea>
            </div>
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm(<?= js_str('Send this email to ' . $member['email'] . '?') ?>);"><i class="fas fa-paper-plane"></i> Send Email</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Send SMS</h2>
        <?php if (empty($member['contact_number'])): ?>
          <p class="text-muted small mb-0">This member has no contact number on file.</p>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="send_sms">
            <div class="mb-2">
              <label class="flbl">Message</label>
              <textarea name="sms_message" class="fctrl" rows="3" maxlength="480" required></textarea>
              <div class="form-text">Max 480 characters (~3 SMS segments).</div>
            </div>
            <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm(<?= js_str('Send this SMS to ' . $member['contact_number'] . '? This will use real SMS credits.') ?>);"><i class="fas fa-comment-sms"></i> Send SMS</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="panel-card mt-4">
        <h2 class="h6">Reset Password</h2>
        <p class="text-muted small">Passwords are stored as one-way hashes and can't be recovered — this generates a new temporary password, sends it to the member by email and SMS, and requires them to change it on next login.</p>
        <?php if (empty($member['email']) && empty($member['contact_number'])): ?>
          <p class="text-muted small mb-0">This member has no email address or contact number on file.</p>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="reset_password">
            <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm(<?= js_str('Reset the password for ' . $member['full_name'] . ' and send them a new temporary password by email and SMS?') ?>);"><i class="fas fa-key"></i> Reset &amp; Send New Password</button>
          </form>
        <?php endif; ?>
      </div>

      <?php endif; ?>

      <div class="panel-card mt-4">
        <h2 class="h6">Submitted Documents</h2>
        <?php if ($documents->num_rows === 0): ?>
          <p class="text-muted small mb-0">No documents on file.</p>
        <?php endif; ?>
        <?php while ($doc = $documents->fetch_assoc()): ?>
          <p class="mb-2">
            <a href="<?= BASE_URL ?>/basics/admin/kyc_view.php?doc_id=<?= (int) $doc['id'] ?>" target="_blank" class="btn-chip btn-chip-outline">
              <i class="fas fa-file-arrow-down"></i> <?= sanitize($doc_labels[$doc['doc_type']] ?? $doc['doc_type']) ?>
            </a>
          </p>
        <?php endwhile; ?>
      </div>
    </div>

    <?php if (!$is_view_only): ?>
    <div class="col-12 col-md-6">
      <h2 class="h6 mb-3">Recent Orders</h2>
      <div class="table-responsive mb-4">
        <table class="table-theme">
          <thead><tr><th>Date</th><th>Total</th><th>Status</th><th>Payment</th><th class="no-print"></th></tr></thead>
          <tbody>
          <?php if ($orders->num_rows === 0): ?>
            <tr><td colspan="5" class="text-muted">No orders yet.</td></tr>
          <?php endif; ?>
          <?php while ($o = $orders->fetch_assoc()): ?>
            <?php
              // Gift orders are pinned at total_amount=0 and never need payment
              // — basics_projected_penalty() must not even be called for them,
              // since once delivered+overdue it would compute a real (if
              // zero-amount) penalty rate against a free gift.
              $order_is_paid = !$o['is_gift'] && basics_order_is_paid($o['total_amount'], $o['amount_paid']);
              $order_projection = (!$o['is_gift'] && !$order_is_paid) ? basics_projected_penalty($conn, $o, $member['offense_count']) : null;
            ?>
            <tr>
              <td><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
              <td><?= format_price($o['total_amount']) ?><?php if ($o['is_gift']): ?> <span class="text-muted small">(Gift)</span><?php endif; ?></td>
              <td><span class="pill pill-<?= basics_order_status_pill($o['status']) ?>"><?= basics_order_status_label($o['status']) ?></span></td>
              <td>
                <?php if ($o['is_gift']): ?>
                  <?= basics_gift_pill() ?>
                <?php elseif ($order_is_paid): ?>
                  <span class="pill pill-paid">Paid</span>
                <?php elseif ($order_projection): ?>
                  <span class="pill pill-rejected">Overdue</span>
                  <div class="text-muted small"><?= format_price($order_projection['amount']) ?> (<?= (int) round($order_projection['rate'] * 100) ?>%) penalty + <?= $order_projection['impact'] ?> if paid now</div>
                <?php else: ?>
                  <span class="pill pill-pending">Unpaid</span>
                <?php endif; ?>
              </td>
              <td class="no-print"><a href="<?= BASE_URL ?>/basics/admin/order_view.php?id=<?= (int) $o['id'] ?>" class="btn-chip btn-chip-outline">View</a></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <h2 class="h6 mb-3">Payment History</h2>
      <div class="table-responsive">
        <table class="table-theme">
          <thead><tr><th>Paid</th><th>Penalty</th><th>On Time?</th><th>Date</th></tr></thead>
          <tbody>
          <?php if ($payments->num_rows === 0): ?>
            <tr><td colspan="4" class="text-muted">No payments yet.</td></tr>
          <?php endif; ?>
          <?php while ($p = $payments->fetch_assoc()): ?>
            <tr>
              <td><?= format_price($p['amount_paid']) ?></td>
              <td><?= format_price($p['penalty_amount']) ?></td>
              <td><?= $p['is_late'] ? 'No' : 'Yes' ?></td>
              <td><?= date('M j, Y', strtotime($p['paid_at'])) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
