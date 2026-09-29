<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

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
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
