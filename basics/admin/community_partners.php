<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

$add_errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_partner') {
    $member_id = (int) ($_POST['member_id'] ?? 0);

    $stmt = $conn->prepare("SELECT is_community_partner, referral_code FROM basics_members WHERE id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        $add_errors[] = 'Select a member to add.';
    } elseif ($target['is_community_partner']) {
        $add_errors[] = 'That member is already a Community Partner.';
    } else {
        // Generate the code only if this member somehow doesn't have one yet
        // (mirrors member_view.php's toggle_partner — never regenerates).
        if (!$target['referral_code']) {
            $new_code = basics_generate_referral_code($conn);
            $stmt = $conn->prepare("UPDATE basics_members SET is_community_partner = 1, referral_code = ? WHERE id = ?");
            $stmt->bind_param('si', $new_code, $member_id);
        } else {
            $stmt = $conn->prepare("UPDATE basics_members SET is_community_partner = 1 WHERE id = ?");
            $stmt->bind_param('i', $member_id);
        }
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'toggle_basics_partner', 'Designated Community Partner status for member #' . $member_id);
        redirect('/basics/admin/member_view.php?id=' . $member_id);
    }
}

$eligible_members = $conn->query("SELECT bm.id, u.full_name, u.username FROM basics_members bm
    JOIN basics_users u ON u.id = bm.user_id
    WHERE bm.application_status = 'approved' AND bm.membership_status IN ('active', 'dormant') AND bm.is_community_partner = 0
    ORDER BY u.full_name ASC")->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT bm.id, bm.referral_code, u.full_name, u.username,
               (SELECT COUNT(*) FROM basics_members r WHERE r.referred_by = bm.id) AS referral_count
        FROM basics_members bm
        JOIN basics_users u ON u.id = bm.user_id
        WHERE bm.is_community_partner = 1
        ORDER BY u.full_name ASC";
$partners = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
foreach ($partners as &$partner) {
    $partner['total_override'] = basics_wallet_sum_by_type($conn, $partner['id'], 'referral_override');
}
unset($partner);

$page_title = 'Community Partners';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Community Partners</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if ($add_errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($add_errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn-chip btn-chip-success" data-bs-toggle="modal" data-bs-target="#addPartnerModal"><i class="fas fa-user-plus"></i> Add Community Partner</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Name</th><th>Referral Code</th><th>Tagged Members</th><th>Total Override Earned</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (empty($partners)): ?>
        <tr><td colspan="5" class="text-muted">No Community Partners yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($partners as $p): ?>
        <tr>
          <td><?= sanitize($p['full_name']) ?> <span class="text-muted small">(<?= sanitize($p['username']) ?>)</span></td>
          <td><?= sanitize($p['referral_code']) ?></td>
          <td><?= (int) $p['referral_count'] ?></td>
          <td><?= format_price($p['total_override']) ?></td>
          <td class="no-print"><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $p['id'] ?>" class="btn-chip btn-chip-outline">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="addPartnerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="add_partner">
        <div class="modal-header">
          <h5 class="modal-title">Add Community Partner</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (empty($eligible_members)): ?>
            <p class="text-muted mb-0">No eligible members to add — every active member is already a Community Partner.</p>
          <?php else: ?>
            <label class="flbl">Member</label>
            <select name="member_id" class="fctrl" required>
              <option value="">Select a member…</option>
              <?php foreach ($eligible_members as $m): ?>
                <option value="<?= (int) $m['id'] ?>"><?= sanitize($m['full_name']) ?> (<?= sanitize($m['username']) ?>)</option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-chip btn-chip-outline" data-bs-dismiss="modal">Cancel</button>
          <?php if (!empty($eligible_members)): ?>
            <button type="submit" class="btn-chip btn-chip-success">Add Partner</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
