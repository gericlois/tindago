<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $amount_paid = round((float) ($_POST['amount_paid'] ?? 0), 2);
    $paid_at = trim($_POST['paid_at'] ?? '') ?: date('Y-m-d H:i:s');
    $notes = trim($_POST['notes'] ?? '') ?: null;

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
    if ($pay_order) {
        $due_date = basics_payment_due_date($pay_order);
        $is_late = $due_date !== null && date('Y-m-d', strtotime($paid_at)) > $due_date;
        $penalty = $is_late ? round((float) $pay_order['total_amount'] * basics_late_penalty_rate($conn, (int) $pay_order['offense_count'] + 1), 2) : 0.0;
        $required = round((float) $pay_order['total_amount'] + $penalty - (float) $pay_order['amount_paid'], 2);
    }

    if ($amount_paid <= 0) {
        $errors[] = 'Enter a valid amount paid.';
    } elseif ($required !== null && $amount_paid < $required) {
        $errors[] = 'Amount paid (' . format_price($amount_paid) . ') is less than the amount due (' . format_price($required) . ') for order #' . $order_id
            . ($penalty > 0 ? ', which includes the ' . format_price($penalty) . ' late penalty' : '')
            . '. Partial payments are not accepted — record the full amount due.';
    } else {
        $conn->begin_transaction();
        try {
            $result = basics_record_payment($conn, $order_id, $amount_paid, $paid_at, basics_current_admin_id(), $notes);
            $conn->commit();
            redirect('/basics/admin/order_view.php?id=' . $order_id . '&recorded=1');
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = safe_error_message($e);
        }
    }
}

$order_id_prefill = (int) ($_GET['order_id'] ?? 0);

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

$page_title = 'Record Payment';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Record Payment</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['recorded'])): ?>
    <div class="sucmsg is-visible"><p>Payment recorded.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Order #</th><th>Member</th><th>Due</th><th>Amount Due</th><th>Already Paid</th><th>Payment Due Date</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($awaiting)): ?>
        <tr><td colspan="7" class="text-muted">No orders currently awaiting payment.</td></tr>
      <?php endif; ?>
      <?php foreach ($awaiting as $o): ?>
        <?php
          $due_date = basics_payment_due_date($o);
          $projection = basics_projected_penalty($conn, $o, $o['offense_count']);
          $effective_total = $o['total_amount'] + ($projection['amount'] ?? 0);
          $remaining = $effective_total - $o['amount_paid'];
        ?>
        <tr>
          <td>#<?= (int) $o['id'] ?></td>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $o['member_id'] ?>"><?= sanitize($o['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($o['username']) ?>)</span></td>
          <td><?= format_price($remaining) ?></td>
          <td>
            <?= format_price($effective_total) ?>
            <?php if ($projection): ?>
              <div class="text-muted small"><?= format_price($o['total_amount']) ?> order + <?= format_price($projection['amount']) ?> (<?= (int) round($projection['rate'] * 100) ?>%) penalty</div>
            <?php endif; ?>
          </td>
          <td><?= format_price($o['amount_paid']) ?></td>
          <td>
            <?php if ($due_date === null): ?>
              <span class="text-muted">Due 7 days after delivery</span>
            <?php else: ?>
              <?= date('M j, Y', strtotime($due_date)) ?>
              <?php if ($projection): ?>
                <span class="pill pill-rejected">Overdue</span>
                <div class="text-muted small">Paying now triggers <?= $projection['impact'] ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="no-print">
            <button type="button" class="btn-chip btn-chip-success" data-bs-toggle="modal" data-bs-target="#payModal-<?= (int) $o['id'] ?>">Record Payment</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($awaiting as $o): ?>
  <?php
    $projection = basics_projected_penalty($conn, $o, $o['offense_count']);
    $remaining = $o['total_amount'] + ($projection['amount'] ?? 0) - $o['amount_paid'];
  ?>
  <div class="modal fade" id="payModal-<?= (int) $o['id'] ?>" tabindex="-1" aria-hidden="true" <?= $order_id_prefill === (int) $o['id'] ? 'data-autoshow="1"' : '' ?>>
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header">
            <h5 class="modal-title">Record Payment — Order #<?= (int) $o['id'] ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
            <?php if ($projection): ?>
              <div class="form-text mb-2">Overdue — amount below already includes the <?= format_price($projection['amount']) ?> (<?= (int) round($projection['rate'] * 100) ?>%) late penalty.</div>
            <?php endif; ?>
            <div class="mb-2">
              <label class="flbl">Amount Paid</label>
              <input type="number" step="0.01" min="0.01" name="amount_paid" class="fctrl" value="<?= sanitize($remaining) ?>" required>
            </div>
            <div class="mb-2">
              <label class="flbl">Date/Time Paid</label>
              <input type="datetime-local" name="paid_at" class="fctrl" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div class="mb-0">
              <label class="flbl">Notes (optional)</label>
              <input type="text" name="notes" class="fctrl">
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn-red" onclick="return confirm('Record this payment? A late payment will apply the penalty tier automatically.');"><i class="fas fa-check"></i>Confirm</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var autoshow = document.querySelector('.modal[data-autoshow="1"]');
  if (autoshow) {
    new bootstrap.Modal(autoshow).show();
  }
});
</script>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
