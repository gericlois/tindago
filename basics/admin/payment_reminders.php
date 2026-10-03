<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

// No cron on this hosting — due-date reminders are manual, admin-initiated
// actions (same pattern as the Dormancy Report), not scheduled jobs.

$sent = 0;

// Every delivered-but-unpaid order with its due date (and offense_count, for
// the overdue penalty projection below) attached — the shared base list that
// due-today/due-tomorrow/overdue below all filter by date. Due date is
// per-order (delivered_at + 7 days) rather than a shared cycle date, so the
// filtering happens in PHP against each order's own due date rather than a
// SQL column comparison.
function basics_orders_awaiting_payment_with_due_dates($conn) {
    $stmt = $conn->prepare("SELECT o.id AS order_id, bm.id AS member_id, bm.offense_count, o.total_amount, o.delivered_at, u.full_name, u.username, u.contact_number, u.email,
                                    (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS amount_paid
                             FROM basics_orders o
                             JOIN basics_members bm ON bm.id = o.member_id
                             JOIN basics_users u ON u.id = bm.user_id
                             WHERE o.status = 'delivered'
                             HAVING amount_paid < o.total_amount
                             ORDER BY o.id ASC");
    $stmt->execute();
    $result = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
        $o['payment_due_date'] = basics_payment_due_date($o);
        $result[] = $o;
    }
    return $result;
}

function basics_orders_due_on($conn, $target_date) {
    return array_values(array_filter(
        basics_orders_awaiting_payment_with_due_dates($conn),
        fn($o) => $o['payment_due_date'] === $target_date
    ));
}

// Due date already passed — oldest due date (most overdue) first, so the
// most urgent accounts sort to the top of the table.
function basics_orders_overdue($conn) {
    $today = date('Y-m-d');
    $overdue = array_filter(
        basics_orders_awaiting_payment_with_due_dates($conn),
        fn($o) => $o['payment_due_date'] !== null && $o['payment_due_date'] < $today
    );
    usort($overdue, fn($a, $b) => strcmp($a['payment_due_date'], $b['payment_due_date']));
    return array_values($overdue);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminders') {
    $kind = $_POST['kind'] ?? '';
    if ($kind === 'overdue') {
        $orders = basics_orders_overdue($conn);
    } else {
        $target_date = $kind === 'due_today' ? date('Y-m-d') : date('Y-m-d', strtotime('+1 day'));
        $orders = basics_orders_due_on($conn, $target_date);
    }
    $emails_sent = 0;

    foreach ($orders as $o) {
        $remaining = $o['total_amount'] - $o['amount_paid'];
        if ($kind === 'overdue') {
            $days_overdue = (int) ((strtotime(date('Y-m-d')) - strtotime($o['payment_due_date'])) / 86400);
            $projection = basics_projected_penalty($conn, $o, $o['offense_count']);
            $penalty_note = $projection
                ? ' A late penalty of ' . format_price($projection['amount']) . ' (' . (int) round($projection['rate'] * 100) . '%) applies, and paying now will trigger ' . $projection['impact'] . '.'
                : '';
            $message = "URGENT: Hi {$o['full_name']}, your balance of " . format_price($remaining) . " is now $days_overdue day" . ($days_overdue === 1 ? '' : 's') . " overdue (was due " . date('M j, Y', strtotime($o['payment_due_date'])) . ")." . $penalty_note . " Please settle immediately. - JMC Foodies Basics";
        } elseif ($kind === 'due_today') {
            $message = "URGENT: Hi {$o['full_name']}, your balance of " . format_price($remaining) . " is due TODAY. Please settle it as soon as possible to avoid a late payment penalty. - JMC Foodies Basics";
        } else {
            $message = "Hi {$o['full_name']}, this is a reminder that your balance of " . format_price($remaining) . " is due tomorrow (" . date('M j, Y', strtotime($o['payment_due_date'])) . "). - JMC Foodies Basics";
        }
        if (send_sms($o['contact_number'] ?? '', $message)) {
            $sent++;
        }
        $reminder_titles = ['overdue' => 'Payment overdue', 'due_today' => 'Payment due today', 'due_tomorrow' => 'Payment due tomorrow'];
        basics_add_notification($conn, $o['member_id'], 'payment', $reminder_titles[$kind] ?? 'Payment reminder',
            basics_notification_text(str_replace('URGENT: ', '', $message)), '/order_view.php?id=' . (int) $o['order_id']);

        // Email goes out alongside the SMS for due-today and overdue (the two
        // urgent cases); due-tomorrow stays an SMS-only heads-up.
        if ($kind === 'due_today' || $kind === 'overdue') {
            if ($kind === 'overdue') {
                $email_body = "Hi {$o['full_name']},\r\n\r\n"
                    . "Your balance of " . format_price($remaining) . " for Order #{$o['order_id']} is now $days_overdue day" . ($days_overdue === 1 ? '' : 's') . " overdue (was due " . date('M j, Y', strtotime($o['payment_due_date'])) . ")." . $penalty_note . "\r\n\r\n"
                    . "Please settle immediately.\r\n\r\n"
                    . '— JMC Foodies Basics Team';
                $email_subject = 'Payment Overdue — JMC Foodies Basics';
            } else {
                $email_body = "Hi {$o['full_name']},\r\n\r\n"
                    . "This is an urgent reminder that your balance of " . format_price($remaining) . " for Order #{$o['order_id']} is due TODAY (" . date('M j, Y', strtotime($o['payment_due_date'])) . ").\r\n\r\n"
                    . "Please settle it as soon as possible to avoid a late payment penalty.\r\n\r\n"
                    . '— JMC Foodies Basics Team';
                $email_subject = 'Payment Due Today — JMC Foodies Basics';
            }
            if (send_email($o['email'] ?? '', $email_subject, $email_body)) {
                $emails_sent++;
            }
        }
    }

    if ($sent > 0 || $emails_sent > 0) {
        log_activity($conn, 'send_basics_payment_reminders', 'Sent ' . $sent . ' Basics payment reminder SMS and ' . $emails_sent . ' email(s) (' . $kind . ')');
    }
    redirect('/basics/admin/payment_reminders.php?sent=' . $sent . '&emails=' . $emails_sent . '&kind=' . $kind);
}

$overdue = basics_orders_overdue($conn);
$due_today = basics_orders_due_on($conn, date('Y-m-d'));
$due_tomorrow = basics_orders_due_on($conn, date('Y-m-d', strtotime('+1 day')));

$page_title = 'Payment Reminders';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Payment Reminders</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['sent'])): ?>
    <div class="sucmsg is-visible">
      <p class="mb-0"><?= (int) $_GET['sent'] ?> reminder SMS sent<?php if (isset($_GET['emails']) && (int) $_GET['emails'] > 0): ?> and <?= (int) $_GET['emails'] ?> email(s) sent<?php endif; ?>.</p>
    </div>
  <?php endif; ?>
  <p class="text-muted">There is no automatic scheduler on this hosting, so due-date reminders must be sent manually from here. Overdue and due-today reminders go out by SMS and email; due-tomorrow reminders are SMS only.</p>

  <div class="panel-card mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="h6 mb-0">Overdue &mdash; Past Due Date</h2>
      <?php if (count($overdue) > 0): ?>
        <form method="post">
          <input type="hidden" name="action" value="send_reminders">
          <input type="hidden" name="kind" value="overdue">
          <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Send an urgent overdue notice by SMS and email to everyone past their due date?');">Send Reminders to All (<?= count($overdue) ?>)</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>Order #</th><th>Member</th><th>Balance</th><th>Due Date</th><th>Days Overdue</th><th>Penalty If Paid Now</th></tr></thead>
        <tbody>
        <?php if (count($overdue) === 0): ?>
          <tr><td colspan="6" class="text-muted">No overdue orders.</td></tr>
        <?php endif; ?>
        <?php foreach ($overdue as $o): ?>
          <?php
            $days_overdue = (int) ((strtotime(date('Y-m-d')) - strtotime($o['payment_due_date'])) / 86400);
            $projection = basics_projected_penalty($conn, $o, $o['offense_count']);
          ?>
          <tr>
            <td>#<?= (int) $o['order_id'] ?></td>
            <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $o['member_id'] ?>"><?= sanitize($o['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($o['username']) ?>)</span></td>
            <td><?= format_price($o['total_amount'] - $o['amount_paid']) ?></td>
            <td><?= date('M j, Y', strtotime($o['payment_due_date'])) ?></td>
            <td><span class="pill pill-rejected"><?= $days_overdue ?> day<?= $days_overdue === 1 ? '' : 's' ?></span></td>
            <td><?php if ($projection): ?><?= format_price($projection['amount']) ?> (<?= (int) round($projection['rate'] * 100) ?>%) + <?= $projection['impact'] ?><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel-card mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="h6 mb-0">Due Tomorrow &mdash; Reminder</h2>
      <?php if (count($due_tomorrow) > 0): ?>
        <form method="post">
          <input type="hidden" name="action" value="send_reminders">
          <input type="hidden" name="kind" value="due_tomorrow">
          <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Send a payment reminder SMS to everyone due tomorrow?');">Send Reminders to All (<?= count($due_tomorrow) ?>)</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>Order #</th><th>Member</th><th>Balance</th><th>Due Date</th></tr></thead>
        <tbody>
        <?php if (count($due_tomorrow) === 0): ?>
          <tr><td colspan="4" class="text-muted">No orders due tomorrow.</td></tr>
        <?php endif; ?>
        <?php foreach ($due_tomorrow as $o): ?>
          <tr>
            <td>#<?= (int) $o['order_id'] ?></td>
            <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $o['member_id'] ?>"><?= sanitize($o['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($o['username']) ?>)</span></td>
            <td><?= format_price($o['total_amount'] - $o['amount_paid']) ?></td>
            <td><?= date('M j, Y', strtotime($o['payment_due_date'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel-card">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="h6 mb-0">Due Today &mdash; Urgent Settlement Notice</h2>
      <?php if (count($due_today) > 0): ?>
        <form method="post">
          <input type="hidden" name="action" value="send_reminders">
          <input type="hidden" name="kind" value="due_today">
          <button type="submit" class="btn-chip btn-chip-success" onclick="return confirm('Send an urgent settlement notice by SMS and email to everyone due today?');">Send Reminders to All (<?= count($due_today) ?>)</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>Order #</th><th>Member</th><th>Balance</th><th>Due Date</th></tr></thead>
        <tbody>
        <?php if (count($due_today) === 0): ?>
          <tr><td colspan="4" class="text-muted">No orders due today.</td></tr>
        <?php endif; ?>
        <?php foreach ($due_today as $o): ?>
          <tr>
            <td>#<?= (int) $o['order_id'] ?></td>
            <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $o['member_id'] ?>"><?= sanitize($o['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($o['username']) ?>)</span></td>
            <td><?= format_price($o['total_amount'] - $o['amount_paid']) ?></td>
            <td><?= date('M j, Y', strtotime($o['payment_due_date'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
