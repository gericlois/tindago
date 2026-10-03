<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin']);

// Default announcement wording — friendlier when the minimum goes down.
function minimum_order_default_message($new_amount, $old_amount) {
    return $new_amount < $old_amount
        ? 'Good news! Our minimum order is now just ' . format_price($new_amount) . ' (was ' . format_price($old_amount) . '). Order your groceries anytime in the app. - JMC Foodies Basics'
        : 'Heads up: our minimum order is now ' . format_price($new_amount) . ' (was ' . format_price($old_amount) . '), effective today. - JMC Foodies Basics';
}

$current_minimum = basics_minimum_order($conn);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $new_minimum = round((float) ($_POST['minimum_order'] ?? 0), 2);
    $send_sms = !empty($_POST['send_sms']);
    $send_email = !empty($_POST['send_email']);
    $message = trim($_POST['message'] ?? '') ?: minimum_order_default_message($new_minimum, $current_minimum);

    if ($new_minimum <= 0) {
        $errors[] = 'Enter a minimum order greater than ₱0.';
    } elseif ($new_minimum == $current_minimum) {
        $errors[] = 'That is already the current minimum order (' . format_price($current_minimum) . ').';
    }
    if ($send_sms && strlen($message) > 480) {
        $errors[] = 'Message is too long for SMS (max 480 characters, about 3 SMS segments).';
    }

    if (empty($errors)) {
        save_setting($conn, 'basics_minimum_order_previous', (string) $current_minimum);
        save_setting($conn, 'basics_minimum_order', (string) $new_minimum);
        // Starts the 24-hour catalog banner (basics/catalog.php).
        save_setting($conn, 'basics_minimum_order_changed_at', date('Y-m-d H:i:s'));

        $sms_count = null;
        $email_count = null;
        if ($send_sms || $send_email) {
            // Same audience and sending approach as the Basics broadcast
            // page; keep going even if the browser stops waiting so it never
            // stops half-way.
            ignore_user_abort(true);
            @set_time_limit(0);

            $numbers = [];
            $emails = [];
            $result = $conn->query("SELECT u.contact_number, u.email FROM basics_users u
                                     JOIN basics_members bm ON bm.user_id = u.id
                                     WHERE bm.application_status = 'approved' AND bm.membership_status IN ('active','dormant')");
            while ($row = $result->fetch_assoc()) {
                if (trim((string) $row['contact_number']) !== '') $numbers[] = trim($row['contact_number']);
                if (trim((string) $row['email']) !== '') $emails[] = trim($row['email']);
            }

            if ($send_sms) {
                $sms_count = 0;
                foreach (array_chunk(array_values(array_unique($numbers)), 1000) as $chunk) {
                    if (send_sms(implode(',', $chunk), $message)) {
                        $sms_count += count($chunk);
                    }
                }
            }
            if ($send_email) {
                $email_count = send_email_bulk($emails, 'Our minimum order is now ' . format_price($new_minimum), $message)['sent'];
            }
        }

        basics_notify_all_members($conn, 'announcement', 'Minimum order is now ' . format_price($new_minimum), basics_notification_text($message), '/catalog.php');

        $sent_parts = [];
        if ($sms_count !== null) $sent_parts[] = $sms_count . ' SMS';
        if ($email_count !== null) $sent_parts[] = $email_count . ' email';
        log_activity($conn, 'change_basics_minimum_order', 'Changed Basics minimum order from ' . format_price($current_minimum) . ' to ' . format_price($new_minimum)
            . ($sent_parts ? ', announced to ' . implode(' and ', $sent_parts) . ' recipient(s)' : ', no SMS/email sent'));
        redirect('/basics/admin/minimum_order.php?' . http_build_query(['saved' => 1, 'sms' => $sms_count, 'email' => $email_count]));
    }
}

$changed_at = setting($conn, 'basics_minimum_order_changed_at');
$banner_until = $changed_at ? strtotime($changed_at . ' +1 day') : null;
$previous_minimum = (float) setting($conn, 'basics_minimum_order_previous', '0');

$page_title = 'Minimum Order';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Minimum Order</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['saved'])): ?>
    <div class="sucmsg is-visible">
      <p class="mb-0">Minimum order updated to <?= format_price($current_minimum) ?>.
        <?php if (($_GET['sms'] ?? '') !== ''): ?> SMS sent to <?= (int) $_GET['sms'] ?> number(s).<?php endif; ?>
        <?php if (($_GET['email'] ?? '') !== ''): ?> Email sent to <?= (int) $_GET['email'] ?> address(es).<?php endif; ?>
      </p>
    </div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="panel-card">
    <h2 class="h6">Current Minimum Order</h2>
    <p class="text-muted small">Members can't place an order from their cart below this amount. Gift orders added by an admin aren't affected.</p>
    <p class="mb-1" style="font-size:1.4rem;"><strong><?= format_price($current_minimum) ?></strong></p>
    <?php if ($changed_at): ?>
      <p class="small text-muted mb-0">
        Last changed <?= date('M j, Y g:i A', strtotime($changed_at)) ?> (was <?= format_price($previous_minimum) ?>).
        <?php if ($banner_until > time()): ?>
          The catalog banner is showing until <?= date('M j, g:i A', $banner_until) ?>.
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="panel-card mt-4">
    <h2 class="h6 mb-3">Change Minimum Order</h2>
    <form method="post" onsubmit="return confirm('Change the minimum order? Members will see it right away' + (document.getElementById('sendSms').checked || document.getElementById('sendEmail').checked ? ', and the announcement will be sent now.' : '.'));">
      <input type="hidden" name="action" value="save">
      <div class="mb-3" style="max-width:240px;">
        <label class="flbl" for="minimumOrder">New Minimum Order (₱)</label>
        <input type="number" step="0.01" min="1" name="minimum_order" id="minimumOrder" class="fctrl" value="<?= sanitize($current_minimum) ?>" required>
      </div>

      <label class="flbl">Announce It</label>
      <p class="small text-muted mb-2">A banner shows on the member catalog for 24 hours automatically. Optionally also send it to all approved, active members:</p>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="send_sms" id="sendSms" value="1" checked>
        <label class="form-check-label" for="sendSms">SMS (uses real SMS credits)</label>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="send_email" id="sendEmail" value="1" checked>
        <label class="form-check-label" for="sendEmail">Email &mdash; members with an address on file</label>
      </div>

      <div class="mb-3">
        <label class="flbl" for="messageField">Message (optional)</label>
        <textarea name="message" id="messageField" class="fctrl" rows="3" maxlength="480"></textarea>
        <div class="form-text" id="defaultMessageHint"></div>
      </div>

      <button type="submit" class="btn-red"><i class="fas fa-floppy-disk"></i>Save &amp; Announce</button>
    </form>
  </div>
</div>
<script>
// Shows the default wording for whatever amount is typed, so the admin
// knows exactly what goes out if they leave the message blank. Mirrors
// minimum_order_default_message() above.
(function () {
  var current = <?= json_encode($current_minimum) ?>;
  var input = document.getElementById('minimumOrder');
  var hint = document.getElementById('defaultMessageHint');
  function peso(n) { return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function update() {
    var next = parseFloat(input.value);
    if (!(next > 0) || next === current) {
      hint.textContent = 'Leave blank to use the default message.';
      return;
    }
    hint.textContent = 'Leave blank to send: "' + (next < current
      ? 'Good news! Our minimum order is now just ' + peso(next) + ' (was ' + peso(current) + '). Order your groceries anytime in the app. - JMC Foodies Basics'
      : 'Heads up: our minimum order is now ' + peso(next) + ' (was ' + peso(current) + '), effective today. - JMC Foodies Basics') + '"';
  }
  input.addEventListener('input', update);
  update();
})();
</script>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
