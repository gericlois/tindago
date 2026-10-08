<?php
// Payments page → "Awaiting Payment" tab: orders not yet fully paid, each
// with a Record Payment modal (COD, bank, or other). Included by
// basics/admin/payments.php, which loads $awaiting, $payment_methods,
// $pending_submission_by_order and $order_id_prefill.
?>
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
            <?php if (isset($pending_submission_by_order[(int) $o['id']])): $sub = $pending_submission_by_order[(int) $o['id']]; ?>
              <?php // The member already sent proof for this order — review that instead of recording it again. ?>
              <a href="?tab=submissions&amp;review=<?= (int) $sub['id'] ?>" class="btn-chip btn-chip-success"><i class="fas fa-receipt"></i> Review Submission</a>
              <div class="small text-muted mt-1">Member submitted <?= format_price($sub['amount']) ?> via <?= sanitize($sub['payment_method']) ?></div>
            <?php else: ?>
              <button type="button" class="btn-chip btn-chip-success" data-bs-toggle="modal" data-bs-target="#payModal-<?= (int) $o['id'] ?>">Record Payment</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
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
              <div class="form-text">Less than this is recorded as a partial payment &mdash; the rest stays due on the order until it's paid.</div>
            </div>
            <div class="mb-2">
              <label class="flbl">Payment Method</label>
              <select name="payment_method" class="fctrl" required>
                <?php foreach ($payment_methods as $method): ?>
                  <option value="<?= sanitize($method) ?>" <?= $method === BASICS_PAYMENT_METHOD_COD ? 'selected' : '' ?>><?= sanitize($method) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Cash on Delivery = cash collected from the member when the order was delivered. For "Other", say how in Notes.</div>
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

