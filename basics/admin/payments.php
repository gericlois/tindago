<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

$errors = [];

// How the payment was made: cash handed over on delivery (the usual case
// here, so it's the default), or any of the payment banks members can pay
// into (see payment_banks.php), or something else noted in Notes.
$payment_methods = [BASICS_PAYMENT_METHOD_COD];
$bank_rows = $conn->query("SELECT name FROM basics_payment_banks WHERE is_enabled = 1 ORDER BY sort_order ASC, name ASC");
while ($bank = $bank_rows->fetch_assoc()) {
    $payment_methods[] = $bank['name'];
}
$payment_methods[] = 'Other';
$payment_methods = array_values(array_unique($payment_methods));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $amount_paid = round((float) ($_POST['amount_paid'] ?? 0), 2);
    $paid_at = trim($_POST['paid_at'] ?? '') ?: date('Y-m-d H:i:s');
    $notes = trim($_POST['notes'] ?? '') ?: null;
    $payment_method = $_POST['payment_method'] ?? '';
    if (!in_array($payment_method, $payment_methods, true)) {
        $errors[] = 'Choose how the member paid.';
    } elseif ($payment_method === 'Other' && $notes === null) {
        $errors[] = 'Payment method "Other" needs a note saying how the member paid.';
    }

    // No partial payments, same rule as the member's Submit Payment page: the
    // amount must cover the order total minus what's already paid, plus the
    // late penalty if the payment date is past the due date (the same test
    // basics_record_payment() applies, so a backdated on-time payment owes
    // no penalty).
    $stmt = $conn->prepare("SELECT o.*, bm.offense_count,
                                   (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS amount_paid
                            FROM basics_orders o JOIN basics_members bm ON bm.id = o.member_id
                            WHERE o.id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $pay_order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $required = null;
    $penalty = 0.0;
    if ($pay_order) {
        $due_date = basics_payment_due_date($pay_order);
        $is_late = $due_date !== null && date('Y-m-d', strtotime($paid_at)) > $due_date;
        $penalty = $is_late ? round((float) $pay_order['total_amount'] * basics_late_penalty_rate($conn, (int) $pay_order['offense_count'] + 1), 2) : 0.0;
        $required = round((float) $pay_order['total_amount'] + $penalty - (float) $pay_order['amount_paid'], 2);
    }

    if ($errors) {
        // Payment method problem — reported above.
    } elseif ($amount_paid <= 0) {
        $errors[] = 'Enter a valid amount paid.';
    } elseif ($required !== null && $amount_paid < $required) {
        $errors[] = 'Amount paid (' . format_price($amount_paid) . ') is less than the amount due (' . format_price($required) . ') for order #' . $order_id
            . ($penalty > 0 ? ', which includes the ' . format_price($penalty) . ' late penalty' : '')
            . '. Partial payments are not accepted — record the full amount due.';
    } else {
        $conn->begin_transaction();
        try {
            $result = basics_record_payment($conn, $order_id, $amount_paid, $paid_at, basics_current_admin_id(), $notes, $payment_method);
            $conn->commit();
            redirect('/basics/admin/order_view.php?id=' . $order_id . '&recorded=1');
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = safe_error_message($e);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    $id = (int) ($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '') ?: null;

    $stmt = $conn->prepare("SELECT * FROM basics_payment_submissions WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$submission) {
        $errors[] = 'Submission not found or already reviewed.';
    } else {
        $conn->begin_transaction();
        try {
            if ($submission['payment_for'] === 'grocery') {
                if (!$submission['order_id']) {
                    throw new Exception('This submission has no linked order to record the payment against.');
                }
                basics_record_payment($conn, $submission['order_id'], (float) $submission['amount'], $submission['paid_at'], basics_current_admin_id(), $notes, $submission['payment_method']);
            } elseif ($submission['payment_for'] === 'loan') {
                $member = basics_member_by_id($conn, $submission['member_id']);
                if ($member) {
                    basics_notify($conn, $member, "Hi {$member['full_name']}, we've received your payment of " . format_price($submission['amount']) . " for your Emergency Cash Loan. - JMC Foodies Basics", 'payment', 'Loan payment received', '/payments.php');
                }
            } else {
                basics_add_notification($conn, $submission['member_id'], 'payment', 'Payment confirmed',
                    'Your payment of ' . format_price($submission['amount']) . ' has been confirmed.', '/payments.php');
            }
            $stmt = $conn->prepare("UPDATE basics_payment_submissions SET status = 'confirmed', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $admin_id = basics_current_admin_id();
            $stmt->bind_param('sii', $notes, $admin_id, $id);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            log_activity($conn, 'confirm_payment_submission', 'Confirmed ' . $submission['payment_for'] . ' payment submission #' . $id . ' (' . format_price($submission['amount']) . ')');
            redirect('/basics/admin/payments.php?tab=submissions&confirmed=1');
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = safe_error_message($e);
        }
    }
}

$ai_allowed = basics_ai_review_allowed();

if ($ai_allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze_proof') {
    $id = (int) ($_POST['id'] ?? 0);
    $result = basics_analyze_payment_proof($conn, $id);
    if ($result['success']) {
        redirect('/basics/admin/payments.php?' . http_build_query(['tab' => 'submissions', 'status' => $_GET['status'] ?? 'pending']));
    }
    $errors[] = $result['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    $id = (int) ($_POST['id'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '');

    if ($notes === '') {
        $errors[] = 'A reason is required to reject a submission.';
    } else {
        $admin_id = basics_current_admin_id();
        $stmt = $conn->prepare("UPDATE basics_payment_submissions SET status = 'rejected', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('sii', $notes, $admin_id, $id);
        $stmt->execute();
        $rejected = $stmt->affected_rows > 0;
        $stmt->close();
        if ($rejected) {
            log_activity($conn, 'reject_payment_submission', 'Rejected payment submission #' . $id . ': ' . $notes);
            $row = $conn->query("SELECT member_id, amount FROM basics_payment_submissions WHERE id = " . (int) $id)->fetch_assoc();
            basics_add_notification($conn, $row['member_id'], 'payment', 'Payment not accepted',
                'Your payment submission of ' . format_price($row['amount']) . ' could not be confirmed. Reason: ' . rtrim($notes, '.') . '. Please check it and submit again.', '/payments.php');
            redirect('/basics/admin/payments.php?tab=submissions&rejected=1');
        }
        $errors[] = 'Submission not found or already reviewed.';
    }
}

$status_filter = $_GET['status'] ?? 'pending';

// ---------------------------------------------------------------
// Which tab: "awaiting" (orders to record a payment for — COD, bank, other)
// or "submissions" (payments members sent online with proof). This page
// replaced the separate Payment Submissions page; that URL now redirects
// here (payment_submissions.php).
// ---------------------------------------------------------------
$tab = ($_GET['tab'] ?? '') === 'submissions' ? 'submissions' : 'awaiting';
$order_id_prefill = (int) ($_GET['order_id'] ?? 0);
$review_prefill = (int) ($_GET['review'] ?? 0);
if ($order_id_prefill) {
    $tab = 'awaiting';
}
if ($review_prefill) {
    $tab = 'submissions';
}

$pending_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_payment_submissions WHERE status = 'pending'")->fetch_assoc()['c'];

if ($tab === 'awaiting') {
    $stmt = $conn->prepare("SELECT o.*, u.full_name, u.username, bm.offense_count,
                                    (SELECT COALESCE(SUM(amount_paid),0) FROM basics_payments p WHERE p.order_id = o.id) AS amount_paid
                             FROM basics_orders o
                             JOIN basics_members bm ON bm.id = o.member_id
                             JOIN basics_users u ON u.id = bm.user_id
                             WHERE o.status IN ('confirmed', 'out_for_delivery', 'delivered')
                             HAVING amount_paid < o.total_amount
                             ORDER BY (o.delivered_at IS NULL), o.delivered_at ASC");
    $stmt->execute();
    $awaiting = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Orders whose member already sent proof that's waiting for review.
    $pending_submission_by_order = [];
    $result = $conn->query("SELECT id, order_id, amount, payment_method FROM basics_payment_submissions
                            WHERE status = 'pending' AND payment_for = 'grocery' AND order_id IS NOT NULL
                            ORDER BY created_at ASC");
    while ($row = $result->fetch_assoc()) {
        $pending_submission_by_order[(int) $row['order_id']] = $row;
    }
} else {
    $status_filter = $_GET['status'] ?? 'pending';
    if (!in_array($status_filter, ['pending', 'confirmed', 'rejected', 'all'], true)) {
        $status_filter = 'pending';
    }
    $sql = "SELECT s.*, u.full_name, u.username FROM basics_payment_submissions s
            JOIN basics_members bm ON bm.id = s.member_id
            JOIN basics_users u ON u.id = bm.user_id";
    if ($status_filter !== 'all') {
        $sql .= " WHERE s.status = '" . $conn->real_escape_string($status_filter) . "'";
    }
    $sql .= " ORDER BY s.created_at DESC";
    $submissions = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$page_title = 'Payments';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Payments</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['recorded'])): ?>
    <div class="sucmsg is-visible"><p>Payment recorded.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['confirmed'])): ?>
    <div class="sucmsg is-visible"><p>Submission confirmed.</p></div>
  <?php endif; ?>
  <?php if (isset($_GET['rejected'])): ?>
    <div class="sucmsg is-visible"><p>Submission rejected.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'awaiting' ? 'active' : '' ?>" href="?tab=awaiting"><i class="fas fa-money-bill-wave"></i> Awaiting Payment</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'submissions' ? 'active' : '' ?>" href="?tab=submissions">
        <i class="fas fa-receipt"></i> Member Submissions<?php if ($pending_count > 0): ?> <span class="pill pill-pending"><?= $pending_count ?></span><?php endif; ?>
      </a>
    </li>
  </ul>

  <?php if ($tab === 'awaiting'): ?>
    <p class="text-muted small">Orders not fully paid yet. Use <strong>Record Payment</strong> for cash collected on delivery (COD) or any payment made outside the member's online submission.</p>
    <?php require __DIR__ . '/includes/payments_awaiting.php'; ?>
  <?php else: ?>
    <p class="text-muted small">Payments members sent online with proof (bank transfer / e-wallet). Confirming one records the payment on the order.</p>
    <?php require __DIR__ . '/includes/payments_submissions.php'; ?>
  <?php endif; ?>
</div>

<script>
// Opens the Record Payment or Review pop-up when linked to directly
// (?order_id=… from an order, ?review=… from "Review Submission").
document.addEventListener('DOMContentLoaded', function () {
  var autoshow = document.querySelector('.modal[data-autoshow="1"]');
  if (autoshow) {
    new bootstrap.Modal(autoshow).show();
  }
});
</script>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
