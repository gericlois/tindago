<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

// Deliberate, admin-initiated broadcasts always send regardless of the
// basics_sms_notifications_enabled toggle (that flag only gates the
// automatic triggers in basics_notify()) — so this calls send_sms()
// directly rather than going through basics_notify().
function basics_broadcast_recipients($conn, $audience) {
    if ($audience === 'active') {
        $sql = "SELECT u.contact_number, u.email FROM basics_users u
                JOIN basics_members bm ON bm.user_id = u.id
                WHERE bm.application_status = 'approved' AND bm.membership_status IN ('active','dormant')";
    } elseif ($audience === 'pending') {
        $sql = "SELECT u.contact_number, u.email FROM basics_users u
                JOIN basics_members bm ON bm.user_id = u.id
                WHERE bm.application_status = 'pending'";
    } else {
        $sql = "SELECT contact_number, email FROM basics_users";
    }
    $numbers = [];
    $emails = [];
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        if (trim((string) $row['contact_number']) !== '') {
            $numbers[] = trim($row['contact_number']);
        }
        if (trim((string) $row['email']) !== '') {
            $emails[] = trim($row['email']);
        }
    }
    return [$numbers, $emails];
}

$audiences = [
    'active'  => 'Active & Approved Members',
    'pending' => 'Pending Applicants',
    'all'     => 'All Member Accounts',
];

$ai_allowed = basics_ai_review_allowed();

// "Draft with AI" — called by fetch() from the form below; only returns a
// draft for the admin to edit, never sends anything.
if ($ai_allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_draft') {
    $instruction = trim($_POST['instruction'] ?? '');
    header('Content-Type: application/json');
    if ($instruction === '' || strlen($instruction) > 500) {
        echo json_encode(['success' => false, 'error' => 'Describe what you want to say (up to 500 characters).']);
    } else {
        $language = ($_POST['language'] ?? '') === 'taglish' ? 'taglish' : 'english';
        echo json_encode(ai_draft_broadcast($instruction, $language, !empty($_POST['for_sms']), 'TindaGo'));
    }
    exit;
}

$errors = [];
$sent_count = null;
$email_sent_count = null;

// Super-admin switch per announcement channel. A reason is required both
// ways — it's shown to every admin on this page and kept in the activity
// log. Stored in settings as basics_broadcast_<channel>_{enabled,reason,
// changed_by,changed_at}; a channel with no setting yet is on.
$channel_names = ['sms' => 'SMS', 'email' => 'Email'];
$can_toggle_channels = basics_admin_role() === 'super_admin';

if ($can_toggle_channels && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_channel') {
    $channel = $_POST['channel'] ?? '';
    $turn_on = ($_POST['state'] ?? '') === 'on';
    $reason = trim(preg_replace('/\s+/', ' ', $_POST['reason'] ?? ''));

    if (!isset($channel_names[$channel])) {
        $errors[] = 'Unknown channel.';
    } elseif ($reason === '') {
        $errors[] = 'Enter a reason for ' . ($turn_on ? 'activating' : 'deactivating') . ' ' . $channel_names[$channel] . ' announcements.';
    } elseif (mb_strlen($reason) > 255) {
        $errors[] = 'Reason is too long (max 255 characters).';
    } else {
        $prefix = 'basics_broadcast_' . $channel . '_';
        save_setting($conn, $prefix . 'enabled', $turn_on ? '1' : '0');
        save_setting($conn, $prefix . 'reason', $reason);
        save_setting($conn, $prefix . 'changed_by', $_SESSION['basics_admin_name'] ?? 'Super admin');
        save_setting($conn, $prefix . 'changed_at', date('Y-m-d H:i:s'));
        log_activity($conn, ($turn_on ? 'enable' : 'disable') . '_basics_broadcast_' . $channel,
            ($turn_on ? 'Activated ' : 'Deactivated ') . $channel_names[$channel] . ' announcements. Reason: ' . $reason);
        redirect('/basics/admin/broadcast.php?' . http_build_query(['channel_updated' => $channel]));
    }
}

$channels = [];
foreach ($channel_names as $key => $name) {
    $prefix = 'basics_broadcast_' . $key . '_';
    $channels[$key] = [
        'name' => $name,
        'enabled' => setting($conn, $prefix . 'enabled', '1') === '1',
        'reason' => setting($conn, $prefix . 'reason', ''),
        'changed_by' => setting($conn, $prefix . 'changed_by', ''),
        'changed_at' => setting($conn, $prefix . 'changed_at', ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $audience = $_POST['audience'] ?? '';
    $message = trim($_POST['message'] ?? '');
    $send_sms = !empty($_POST['send_sms']);
    $send_email = !empty($_POST['send_email']);
    $email_subject = trim($_POST['email_subject'] ?? '');

    if (!isset($audiences[$audience])) {
        $errors[] = 'Choose a valid audience.';
    }
    foreach (['sms' => $send_sms, 'email' => $send_email] as $key => $chosen) {
        if ($chosen && !$channels[$key]['enabled']) {
            $errors[] = $channels[$key]['name'] . ' announcements are deactivated'
                . ($channels[$key]['reason'] !== '' ? ' (reason: ' . $channels[$key]['reason'] . ')' : '') . '.';
        }
    }
    if (!$send_sms && !$send_email) {
        $errors[] = 'Choose at least one channel (SMS or Email).';
    }
    if ($message === '') {
        $errors[] = 'Enter a message.';
    } elseif ($send_sms && strlen($message) > 480) {
        $errors[] = 'Message is too long (max 480 characters, about 3 SMS segments).';
    }
    if ($send_email && $email_subject === '') {
        $errors[] = 'Enter an email subject.';
    }

    if (empty($errors)) {
        // A big announcement can take a minute or more. Keep going even if
        // the browser/proxy stops waiting, so it never stops half-way (and
        // the admin doesn't resend, which would text everyone twice).
        ignore_user_abort(true);
        @set_time_limit(0);

        [$numbers, $emails] = basics_broadcast_recipients($conn, $audience);

        if ($send_sms) {
            $sent_count = 0;
            // Semaphore accepts up to 1000 comma-separated numbers per call.
            // Households often share one number — text each number once.
            foreach (array_chunk(array_values(array_unique($numbers)), 1000) as $chunk) {
                if (send_sms(implode(',', $chunk), $message)) {
                    $sent_count += count($chunk);
                }
            }
            if ($numbers && $sent_count === 0) {
                $errors[] = 'The SMS could not be sent — check the Semaphore SMS configuration (config/sms.php) and your SMS credits.';
            }
        }

        if ($send_email) {
            $email_result = send_email_bulk($emails, $email_subject, $message);
            $email_sent_count = $email_result['sent'];
            if ($email_result['failed'] > 0) {
                $errors[] = $email_result['failed'] . ' email(s) could not be sent — see the Communication Log for which ones.';
            }
        }

        $log_parts = [];
        if ($send_sms) $log_parts[] = $sent_count . ' SMS recipient(s)';
        if ($send_email) $log_parts[] = $email_sent_count . ' email recipient(s)';
        log_activity($conn, 'send_basics_broadcast', 'Sent announcement to ' . implode(' and ', $log_parts) . ' (' . $audiences[$audience] . ')');
        // Members also see it in their notification feed (applicants can't
        // log in to one yet, so a pending-only broadcast skips this).
        if ($audience !== 'pending') {
            basics_notify_all_members($conn, 'announcement', $email_subject !== '' ? $email_subject : 'Announcement', basics_notification_text($message));
        }
    }
}

$page_title = 'Announcement Broadcast';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Announcement Broadcast</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if ($sent_count !== null || $email_sent_count !== null):
    $result_parts = [];
    if ($sent_count !== null) $result_parts[] = (int) $sent_count . ' SMS recipient(s)';
    if ($email_sent_count !== null) $result_parts[] = (int) $email_sent_count . ' email recipient(s)';
  ?>
    <div class="sucmsg is-visible"><p>Announcement sent to <?= implode(' and ', $result_parts) ?>.</p></div>
  <?php endif; ?>
  <?php if (isset($channels[$_GET['channel_updated'] ?? ''])): $updated = $channels[$_GET['channel_updated']]; ?>
    <div class="sucmsg is-visible"><p><?= sanitize($updated['name']) ?> announcements are now <?= $updated['enabled'] ? 'activated' : 'deactivated' ?>.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($can_toggle_channels): ?>
    <div class="panel-card mb-4">
      <h2 class="h6">Channel Controls <span class="small text-muted fw-normal">&mdash; Super Admin only</span></h2>
      <p class="text-muted small">Deactivate a channel to stop every admin from sending announcements through it (e.g. out of SMS credits, email provider issue). A reason is required and is shown to admins.</p>
      <p class="small mb-3"><i class="fas fa-triangle-exclamation text-danger"></i> <strong>SMS is a site-wide switch:</strong> deactivating it stops <em>every</em> text on TindaGo &mdash; announcements, automatic member notifications, admin "Send SMS", payment reminders, password resets and birthday greetings. In-app notifications and emails still go out.</p>
      <div class="row g-3">
        <?php foreach ($channels as $key => $ch): ?>
          <div class="col-md-6">
            <div class="p-3 rounded h-100" style="border:1px solid rgba(0,0,0,.1);">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <strong><i class="fas <?= $key === 'sms' ? 'fa-comment-sms' : 'fa-envelope' ?>"></i> <?= sanitize($ch['name']) ?></strong>
                <span class="pill pill-<?= $ch['enabled'] ? 'active' : 'rejected' ?>"><?= $ch['enabled'] ? 'Active' : 'Deactivated' ?></span>
              </div>
              <?php if ($ch['changed_at'] !== ''): ?>
                <p class="small text-muted mb-2">
                  <?= $ch['enabled'] ? 'Activated' : 'Deactivated' ?> by <?= sanitize($ch['changed_by']) ?> on <?= date('M j, Y g:ia', strtotime($ch['changed_at'])) ?><br>
                  Reason: <?= sanitize($ch['reason']) ?>
                </p>
              <?php endif; ?>
              <form method="post">
                <input type="hidden" name="action" value="toggle_channel">
                <input type="hidden" name="channel" value="<?= $key ?>">
                <input type="hidden" name="state" value="<?= $ch['enabled'] ? 'off' : 'on' ?>">
                <input type="text" name="reason" class="fctrl mb-2" maxlength="255" required
                       placeholder="Reason for <?= $ch['enabled'] ? 'deactivating' : 'activating' ?> <?= sanitize($ch['name']) ?> announcements">
                <button type="submit" class="btn-chip <?= $ch['enabled'] ? 'btn-chip-outline' : 'btn-chip-success' ?>"
                        onclick="return confirm('<?= $ch['enabled'] ? 'Deactivate' : 'Activate' ?> <?= $ch['name'] ?> announcements for all admins?');">
                  <i class="fas fa-power-off"></i> <?= $ch['enabled'] ? 'Deactivate' : 'Activate' ?> <?= sanitize($ch['name']) ?>
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="row">
    <div class="col-12">
      <div class="panel-card">
        <?php if (!defined('SEMAPHORE_API_KEY') || SEMAPHORE_API_KEY === ''): ?>
          <div class="errmsg mb-3"><p class="mb-0">SMS is not configured yet — set up your Semaphore API key first (see Settings).</p></div>
        <?php endif; ?>
        <?php if ($ai_allowed): ?>
          <div class="mb-4 p-3 rounded" style="background:#f5f0ff; border:1px solid #d9c8f5;">
            <label class="flbl" for="aiInstruction"><i class="fas fa-wand-magic-sparkles"></i> Draft with AI (optional)</label>
            <textarea id="aiInstruction" class="fctrl mb-2" rows="2" maxlength="500" placeholder="e.g. remind members payment is due Friday"></textarea>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <select id="aiLanguage" class="fctrl" style="width:auto;">
                <option value="english">English</option>
                <option value="taglish">Taglish</option>
              </select>
              <button type="button" class="btn-outline-theme" id="aiDraftBtn" onclick="aiDraftBroadcast();"><i class="fas fa-pen-nib"></i>Write Draft</button>
              <span id="aiDraftStatus" class="small text-muted"></span>
            </div>
            <div class="form-text">Fills in the message below for you to review and edit &mdash; nothing is sent until you click Send Announcement.</div>
          </div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="send">
          <div class="mb-3">
            <label class="flbl">Audience</label>
            <select name="audience" class="fctrl" required>
              <?php foreach ($audiences as $key => $label): ?>
                <option value="<?= $key ?>"><?= sanitize($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="flbl">Channels</label>
            <?php // SMS is ticked by default; if it's deactivated, Email takes its place. ?>
            <?php $sms_on = $channels['sms']['enabled']; $email_on = $channels['email']['enabled']; $email_default = !$sms_on && $email_on; ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="send_sms" id="sendSms" value="1" <?= $sms_on ? 'checked' : 'disabled' ?> onchange="updateBroadcastLimit();">
              <label class="form-check-label" for="sendSms">SMS (uses real SMS credits)</label>
              <?php if (!$sms_on): ?>
                <div class="small text-danger"><i class="fas fa-ban"></i> Deactivated<?= $channels['sms']['reason'] !== '' ? ' &mdash; ' . sanitize($channels['sms']['reason']) : '' ?></div>
              <?php endif; ?>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="send_email" id="sendEmail" value="1" <?= $email_on ? ($email_default ? 'checked' : '') : 'disabled' ?> onchange="document.getElementById('emailSubjectField').style.display = this.checked ? '' : 'none';">
              <label class="form-check-label" for="sendEmail">Email — recipients with an address on file</label>
              <?php if (!$email_on): ?>
                <div class="small text-danger"><i class="fas fa-ban"></i> Deactivated<?= $channels['email']['reason'] !== '' ? ' &mdash; ' . sanitize($channels['email']['reason']) : '' ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">Message</label>
            <textarea name="message" id="messageField" class="fctrl" rows="5" required placeholder="e.g. Order cutoff for this week has been moved to Friday 5PM."></textarea>
            <div class="form-text" id="messageHint">Max 480 characters (~3 SMS segments). Keep it clear and short.</div>
          </div>
          <div class="mb-3" id="emailSubjectField" <?= $email_default ? '' : 'style="display:none;"' ?>>
            <label class="flbl">Email Subject</label>
            <input type="text" name="email_subject" class="fctrl" placeholder="e.g. Order cutoff moved to Friday 5PM">
          </div>
          <?php if (!$sms_on && !$email_on): ?>
            <div class="errmsg mb-3"><p class="mb-0">Both SMS and Email announcements are deactivated, so nothing can be sent right now.</p></div>
          <?php endif; ?>
          <button type="submit" class="btn-red" <?= !$sms_on && !$email_on ? 'disabled' : '' ?> onclick="return confirm('Send this announcement to the selected audience?');"><i class="fas fa-paper-plane"></i>Send Announcement</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
function updateBroadcastLimit() {
  var smsChecked = document.getElementById('sendSms').checked;
  var field = document.getElementById('messageField');
  var hint = document.getElementById('messageHint');
  if (smsChecked) {
    field.setAttribute('maxlength', '480');
    hint.textContent = 'Max 480 characters (~3 SMS segments). Keep it clear and short.';
  } else {
    field.removeAttribute('maxlength');
    hint.textContent = 'Email has no length limit, but keep it clear and short.';
  }
}
updateBroadcastLimit();

<?php if ($ai_allowed): ?>
function aiDraftBroadcast() {
  var instruction = document.getElementById('aiInstruction').value.trim();
  var status = document.getElementById('aiDraftStatus');
  var button = document.getElementById('aiDraftBtn');
  if (!instruction) {
    status.textContent = 'Type what you want to say first.';
    return;
  }
  var body = new FormData();
  body.append('action', 'ai_draft');
  body.append('instruction', instruction);
  body.append('language', document.getElementById('aiLanguage').value);
  if (document.getElementById('sendSms').checked) body.append('for_sms', '1');

  button.disabled = true;
  status.textContent = 'Writing…';
  fetch(window.location.href, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data.success) {
        status.textContent = data.error;
        return;
      }
      document.getElementById('messageField').value = data.message;
      if (data.subject) document.querySelector('input[name="email_subject"]').value = data.subject;
      status.textContent = 'Draft ready — review and edit it below before sending.';
    })
    .catch(function () { status.textContent = 'Could not reach the AI. Please try again.'; })
    .finally(function () { button.disabled = false; });
}
<?php endif; ?>
</script>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
