<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

require_admin_login();

function wellness_broadcast_recipients($conn, $audience) {
    if ($audience === 'active') {
        $sql = "SELECT contact_number, email FROM users WHERE status = 'active'";
    } elseif ($audience === 'pending') {
        $sql = "SELECT contact_number, email FROM users WHERE status = 'pending'";
    } else {
        $sql = "SELECT contact_number, email FROM users";
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
    'active'  => 'Active Members',
    'pending' => 'Pending Members',
    'all'     => 'All Members',
];

$ai_allowed = true;

// "Draft with AI" — called by fetch() from the form below; only returns a
// draft for the admin to edit, never sends anything.
if ($ai_allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_draft') {
    $instruction = trim($_POST['instruction'] ?? '');
    header('Content-Type: application/json');
    if ($instruction === '' || strlen($instruction) > 500) {
        echo json_encode(['success' => false, 'error' => 'Describe what you want to say (up to 500 characters).']);
    } else {
        $language = ($_POST['language'] ?? '') === 'taglish' ? 'taglish' : 'english';
        echo json_encode(ai_draft_broadcast($instruction, $language, !empty($_POST['for_sms']), 'JMC Foodies Wellness'));
    }
    exit;
}

$errors = [];
$sent_count = null;
$email_sent_count = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $audience = $_POST['audience'] ?? '';
    $message = trim($_POST['message'] ?? '');
    $send_sms = !empty($_POST['send_sms']);
    $send_email = !empty($_POST['send_email']);
    $email_subject = trim($_POST['email_subject'] ?? '');

    if (!isset($audiences[$audience])) {
        $errors[] = 'Choose a valid audience.';
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

        [$numbers, $emails] = wellness_broadcast_recipients($conn, $audience);

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
        log_activity($conn, 'send_wellness_broadcast', 'Sent Wellness announcement to ' . implode(' and ', $log_parts) . ' (' . $audiences[$audience] . ')');
    }
}

$page_title = 'Announcement Broadcast';
require __DIR__ . '/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">JMC Foodies Wellness</span>
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
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
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
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="send_sms" id="sendSms" value="1" checked onchange="updateBroadcastLimit();">
              <label class="form-check-label" for="sendSms">SMS (uses real SMS credits)</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="send_email" id="sendEmail" value="1" onchange="document.getElementById('emailSubjectField').style.display = this.checked ? '' : 'none';">
              <label class="form-check-label" for="sendEmail">Email — recipients with an address on file</label>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">Message</label>
            <textarea name="message" id="messageField" class="fctrl" rows="5" required placeholder="e.g. New product drop this Friday — check the catalog!"></textarea>
            <div class="form-text" id="messageHint">Max 480 characters (~3 SMS segments). Keep it clear and short.</div>
          </div>
          <div class="mb-3" id="emailSubjectField" style="display:none;">
            <label class="flbl">Email Subject</label>
            <input type="text" name="email_subject" class="fctrl" placeholder="e.g. New product drop this Friday!">
          </div>
          <button type="submit" class="btn-red" onclick="return confirm('Send this announcement to the selected audience?');"><i class="fas fa-paper-plane"></i>Send Announcement</button>
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
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
