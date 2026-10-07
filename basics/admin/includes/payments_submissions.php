<?php
// Payments page → "Member Submissions" tab: payments members submitted
// online with proof, to confirm or reject. Included by
// basics/admin/payments.php, which loads $submissions, $status_filter,
// $pending_count, $ai_allowed and $review_prefill.
?>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
      <?php foreach (['all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected'] as $key => $label): ?>
        <a href="?tab=submissions&amp;status=<?= $key ?>" class="filter-pill <?= $status_filter === $key ? 'active' : '' ?>">
          <?= $label ?><?= $key === 'pending' && $pending_count > 0 ? ' (' . $pending_count . ')' : '' ?>
        </a>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Member</th><th>For</th><th>Method</th><th>Sent To</th><th>Amount</th><th>Reference #</th><th>Paid At</th><th class="no-print">Proof</th><?php if ($ai_allowed): ?><th class="no-print">AI Check</th><?php endif; ?><th>Status</th><th>Reason</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (empty($submissions)): ?>
        <tr><td colspan="<?= $ai_allowed ? 12 : 11 ?>" class="text-muted">No submissions.</td></tr>
      <?php endif; ?>
      <?php $for_labels = ['grocery' => 'Grocery', 'loan' => 'Loan', 'other' => 'Other']; ?>
      <?php $status_pill = ['pending' => 'pending', 'confirmed' => 'approved', 'rejected' => 'rejected']; ?>
      <?php foreach ($submissions as $s): ?>
        <?php
          $amount_matches = $s['ai_analyzed_at'] && $s['ai_extracted_amount'] !== null && abs((float) $s['ai_extracted_amount'] - (float) $s['amount']) <= 0.01;
          $reference_matches = $s['ai_analyzed_at'] && $s['ai_extracted_reference'] !== null
              && strcasecmp(trim((string) $s['ai_extracted_reference']), trim((string) $s['reference_number'])) === 0;
        ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $s['member_id'] ?>"><?= sanitize($s['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($s['username']) ?>)</span></td>
          <td><?= $for_labels[$s['payment_for']] ?? sanitize($s['payment_for']) ?><?= $s['order_id'] ? ' #' . (int) $s['order_id'] : '' ?><?= $s['loan_request_id'] ? ' #' . (int) $s['loan_request_id'] : '' ?></td>
          <td><?= sanitize($s['payment_method']) ?></td>
          <td class="small"><?= sanitize($s['destination_account']) ?></td>
          <td><?= format_price($s['amount']) ?></td>
          <td><?= sanitize($s['reference_number']) ?></td>
          <td><?= date('M j, Y g:i A', strtotime($s['paid_at'])) ?></td>
          <td class="no-print"><?php if ($s['proof_image']): ?><button type="button" class="btn btn-link p-0" data-bs-toggle="modal" data-bs-target="#docViewerModal" data-doc-url="<?= BASE_URL ?>/basics/admin/payment_proof_view.php?id=<?= (int) $s['id'] ?>" data-doc-title="Payment Proof — <?= sanitize($s['full_name']) ?>">View</button><?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td>
          <?php if ($ai_allowed): ?>
          <td class="no-print small">
            <?php if (!$s['proof_image']): ?>
              <span class="text-muted">&mdash;</span>
            <?php elseif (!$s['ai_analyzed_at']): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="action" value="analyze_proof">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="btn-chip btn-chip-outline"><i class="fas fa-wand-magic-sparkles"></i> Analyze</button>
              </form>
            <?php else: ?>
              <div>Amount: <?= $s['ai_extracted_amount'] !== null ? format_price($s['ai_extracted_amount']) : '<span class="text-muted">not read</span>' ?>
                <?php if ($s['ai_extracted_amount'] !== null): ?><span class="pill pill-<?= $amount_matches ? 'approved' : 'rejected' ?>"><?= $amount_matches ? 'Match' : 'Mismatch' ?></span><?php endif; ?>
              </div>
              <div>Ref: <?= $s['ai_extracted_reference'] !== null ? sanitize($s['ai_extracted_reference']) : '<span class="text-muted">not read</span>' ?>
                <?php if ($s['ai_extracted_reference'] !== null): ?><span class="pill pill-<?= $reference_matches ? 'approved' : 'rejected' ?>"><?= $reference_matches ? 'Match' : 'Mismatch' ?></span><?php endif; ?>
              </div>
              <?php if ($s['ai_notes']): ?><div class="text-muted"><?= sanitize($s['ai_notes']) ?></div><?php endif; ?>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td><span class="pill pill-<?= $status_pill[$s['status']] ?? 'pending' ?>"><?= ucfirst($s['status']) ?></span></td>
          <td class="small"><?= $s['admin_notes'] ? sanitize($s['admin_notes']) : '<span class="text-muted">—</span>' ?></td>
          <td class="no-print">
            <?php if ($s['status'] === 'pending'): ?>
              <button type="button" class="btn-chip btn-chip-success" data-bs-toggle="modal" data-bs-target="#reviewModal-<?= (int) $s['id'] ?>">Review</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php foreach ($submissions as $s): ?>
  <?php if ($s['status'] !== 'pending') continue; ?>
  <div class="modal fade" id="reviewModal-<?= (int) $s['id'] ?>" tabindex="-1" aria-hidden="true" <?= $review_prefill === (int) $s['id'] ? 'data-autoshow="1"' : '' ?>>
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Review — <?= sanitize($s['full_name']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body d-flex flex-column gap-3">
          <?php if ($ai_allowed && $s['ai_analyzed_at']): ?>
            <?php
              $amount_matches = $s['ai_extracted_amount'] !== null && abs((float) $s['ai_extracted_amount'] - (float) $s['amount']) <= 0.01;
              $reference_matches = $s['ai_extracted_reference'] !== null
                  && strcasecmp(trim((string) $s['ai_extracted_reference']), trim((string) $s['reference_number'])) === 0;
            ?>
            <div class="errmsg" style="background:#f5f0ff; color:#3d2a66; border-color:#d9c8f5;">
              <p class="mb-1"><strong>AI Review</strong> (not a decision — just a cross-check against what the member typed)</p>
              <p class="mb-1">Amount read: <?= $s['ai_extracted_amount'] !== null ? format_price($s['ai_extracted_amount']) : 'not legible' ?>
                <?php if ($s['ai_extracted_amount'] !== null): ?> — <?= $amount_matches ? 'matches' : 'does NOT match' ?> the submitted amount (<?= format_price($s['amount']) ?>)<?php endif; ?></p>
              <p class="mb-1">Reference read: <?= $s['ai_extracted_reference'] !== null ? sanitize($s['ai_extracted_reference']) : 'not legible' ?>
                <?php if ($s['ai_extracted_reference'] !== null): ?> — <?= $reference_matches ? 'matches' : 'does NOT match' ?> the submitted reference (<?= sanitize($s['reference_number']) ?>)<?php endif; ?></p>
              <?php if ($s['ai_notes']): ?><p class="mb-0 small text-muted">Note: <?= sanitize($s['ai_notes']) ?></p><?php endif; ?>
            </div>
          <?php endif; ?>
          <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <div>
              <label class="flbl">Notes (optional)</label>
              <input type="text" name="admin_notes" class="fctrl">
            </div>
            <button type="submit" class="btn-red" onclick="return confirm('Confirm this payment?<?= $s['payment_for'] === 'grocery' ? ' This will apply the late-payment penalty tier automatically if applicable.' : '' ?>');"><i class="fas fa-check"></i>Confirm</button>
          </form>
          <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <div>
              <label class="flbl">Reason (required)</label>
              <input type="text" name="admin_notes" class="fctrl" required>
            </div>
            <button type="submit" class="btn-outline-theme" onclick="return confirm('Reject this submission?');"><i class="fas fa-xmark"></i>Reject</button>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
