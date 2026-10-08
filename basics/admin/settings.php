<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $basics_late_penalty_tier1 = (float) ($_POST['basics_late_penalty_tier1'] ?? 0);
    $basics_late_penalty_tier2 = (float) ($_POST['basics_late_penalty_tier2'] ?? 0);
    $basics_grace_period_days = (int) ($_POST['basics_grace_period_days'] ?? 0);
    $basics_dormancy_weeks = (int) ($_POST['basics_dormancy_weeks'] ?? 0);
    $basics_sms_notifications_enabled = isset($_POST['basics_sms_notifications_enabled']) ? '1' : '0';
    $basics_partner_override_rate = (float) ($_POST['basics_partner_override_rate'] ?? 0);
    $basics_cashout_processing_fee_rate = (float) ($_POST['basics_cashout_processing_fee_rate'] ?? 0);
    $basics_min_cashout_amount = round((float) ($_POST['basics_min_cashout_amount'] ?? 0), 2);
    $company_email = trim($_POST['company_email'] ?? '');
    $company_address = trim($_POST['company_address'] ?? '');

    if ($basics_late_penalty_tier1 < 0 || $basics_late_penalty_tier1 > 100) $errors[] = '1st offense penalty must be between 0 and 100%.';
    if ($basics_late_penalty_tier2 < 0 || $basics_late_penalty_tier2 > 100) $errors[] = '2nd/3rd offense penalty must be between 0 and 100%.';
    if ($basics_grace_period_days < 0) $errors[] = 'Grace period days cannot be negative.';
    if ($basics_dormancy_weeks < 1) $errors[] = 'Dormancy weeks must be at least 1.';
    if ($basics_partner_override_rate < 0 || $basics_partner_override_rate > 100) $errors[] = 'Partner override rate must be between 0 and 100%.';
    if ($basics_cashout_processing_fee_rate < 0 || $basics_cashout_processing_fee_rate > 100) $errors[] = 'Cashout processing fee must be between 0 and 100%.';
    if ($basics_min_cashout_amount < 0) $errors[] = 'Minimum cashout amount cannot be negative.';
    if ($company_email !== '' && !filter_var($company_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Company email must be a valid email address.';

    if (empty($errors)) {
        save_setting($conn, 'basics_late_penalty_tier1', (string) round($basics_late_penalty_tier1 / 100, 4));
        save_setting($conn, 'basics_late_penalty_tier2', (string) round($basics_late_penalty_tier2 / 100, 4));
        save_setting($conn, 'basics_late_penalty_tier3', (string) round($basics_late_penalty_tier2 / 100, 4));
        save_setting($conn, 'basics_grace_period_days', (string) $basics_grace_period_days);
        save_setting($conn, 'basics_dormancy_weeks', (string) $basics_dormancy_weeks);
        save_setting($conn, 'basics_sms_notifications_enabled', $basics_sms_notifications_enabled);
        save_setting($conn, 'basics_partner_override_rate', (string) round($basics_partner_override_rate / 100, 4));
        save_setting($conn, 'basics_cashout_processing_fee_rate', (string) round($basics_cashout_processing_fee_rate / 100, 4));
        save_setting($conn, 'basics_min_cashout_amount', (string) $basics_min_cashout_amount);
        save_setting($conn, 'company_email', $company_email);
        save_setting($conn, 'company_address', $company_address);
        log_activity($conn, 'update_basics_settings', 'Updated TindaGo settings');
        redirect('/basics/admin/settings.php?saved=1');
    }
}

$tier1_val = (float) setting($conn, 'basics_late_penalty_tier1', 0.03) * 100;
$tier2_val = (float) setting($conn, 'basics_late_penalty_tier2', 0.05) * 100;
$grace_val = (int) setting($conn, 'basics_grace_period_days', 7);
$dormancy_val = (int) setting($conn, 'basics_dormancy_weeks', 3);
$sms_enabled_val = setting($conn, 'basics_sms_notifications_enabled', '1') === '1';
$partner_override_val = (float) setting($conn, 'basics_partner_override_rate', 0.02) * 100;
$cashout_fee_val = (float) setting($conn, 'basics_cashout_processing_fee_rate', 0) * 100;
$min_cashout_val = (float) setting($conn, 'basics_min_cashout_amount', 500);
$company_email_val = setting($conn, 'company_email', '');
$company_address_val = setting($conn, 'company_address', '');

$page_title = 'Settings';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Settings</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['saved'])): ?>
    <div class="sucmsg is-visible"><p>Settings saved.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row">
    <div class="col-12 col-lg-7">
      <div class="panel-card">
        <div class="mb-4">
          <h2 class="h6 mb-2">Receiving Accounts</h2>
          <p class="form-text mb-2">GCash/bank destinations shown to members when submitting a payment are now managed on their own page — add, edit, enable/disable, or remove banks there.</p>
          <a href="<?= BASE_URL ?>/basics/admin/payment_banks.php" class="btn-chip btn-chip-success"><i class="fas fa-building-columns"></i> Manage Payment Banks</a>
        </div>

        <form method="post">
          <h2 class="h6 mb-3 mt-4">Company Contact</h2>
          <div class="mb-3">
            <label class="flbl">Company Email</label>
            <input type="email" name="company_email" class="fctrl" value="<?= sanitize($company_email_val) ?>">
            <div class="form-text">Shown in the site footer and on member-facing help messages.</div>
          </div>
          <div class="mb-3">
            <label class="flbl">Company Address</label>
            <textarea name="company_address" class="fctrl" rows="2"><?= sanitize($company_address_val) ?></textarea>
          </div>

          <h2 class="h6 mb-3 mt-4">Late Payment Policy</h2>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">1st Offense Penalty (%)</label>
              <input type="number" step="0.01" min="0" max="100" name="basics_late_penalty_tier1" class="fctrl" value="<?= sanitize($tier1_val) ?>">
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">2nd/3rd Offense Penalty (%)</label>
              <input type="number" step="0.01" min="0" max="100" name="basics_late_penalty_tier2" class="fctrl" value="<?= sanitize($tier2_val) ?>">
            </div>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Grace Period (days)</label>
              <input type="number" min="0" name="basics_grace_period_days" class="fctrl" value="<?= sanitize($grace_val) ?>">
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Dormancy Threshold (weeks)</label>
              <input type="number" min="1" name="basics_dormancy_weeks" class="fctrl" value="<?= sanitize($dormancy_val) ?>">
            </div>
          </div>

          <h2 class="h6 mb-3 mt-4">SMS Notifications</h2>
          <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" id="smsEnabled" name="basics_sms_notifications_enabled" <?= $sms_enabled_val ? 'checked' : '' ?>>
            <label class="form-check-label" for="smsEnabled">Send automatic SMS notifications (application status, orders, payment reminders, emergency loan requests, benefits)</label>
          </div>
          <div class="form-text mb-3">Turning this off stops all automatic triggers. It does not affect the Announcement broadcast, which you send manually.</div>

          <h2 class="h6 mb-3 mt-4">Community Partners</h2>
          <div class="row">
            <div class="col-sm-4 mb-3">
              <label class="flbl">Override Rate (%)</label>
              <input type="number" step="0.01" min="0" max="100" name="basics_partner_override_rate" class="fctrl" value="<?= sanitize($partner_override_val) ?>">
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Cashout Processing Fee (%)</label>
              <input type="number" step="0.01" min="0" max="100" name="basics_cashout_processing_fee_rate" class="fctrl" value="<?= sanitize($cashout_fee_val) ?>">
            </div>
            <div class="col-sm-4 mb-3">
              <label class="flbl">Minimum Cashout Amount (₱)</label>
              <input type="number" step="0.01" min="0" name="basics_min_cashout_amount" class="fctrl" value="<?= sanitize($min_cashout_val) ?>">
            </div>
          </div>
          <div class="form-text mb-3">Percentage of an order's total that a Community Partner earns when a tagged member's order is delivered.</div>

          <button type="submit" class="btn-red"><i class="fas fa-floppy-disk"></i>Save Settings</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="panel-card">
        <h2 class="h6 mb-3">SMS Status</h2>
        <p class="mb-1">Provider Status:
          <?php if (defined('SEMAPHORE_API_KEY') && SEMAPHORE_API_KEY !== ''): ?>
            <span class="pill pill-approved">Configured</span>
          <?php else: ?>
            <span class="pill pill-rejected">Not Configured</span>
          <?php endif; ?>
        </p>
        <p class="mb-3">Sender Name: <strong><?= (defined('SEMAPHORE_SENDER_NAME') && SEMAPHORE_SENDER_NAME !== '') ? sanitize(SEMAPHORE_SENDER_NAME) : 'Account default' ?></strong></p>
        <a href="<?= BASE_URL ?>/basics/admin/broadcast.php" class="btn-chip btn-chip-success"><i class="fas fa-comment-sms"></i> Send Announcement</a>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
