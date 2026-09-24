<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

$claim_days = basics_birthday_claim_days($conn);
$entries = basics_birthday_entries($conn, $claim_days - 1, 28);

$today_list = [];
$recent_list = [];
$upcoming_list = [];
foreach ($entries as $e) {
    if ($e['days_away'] === 0) {
        $today_list[] = $e;
    } elseif ($e['days_away'] < 0) {
        $recent_list[] = $e;
    } else {
        $upcoming_list[] = $e;
    }
}
$next_7 = count(array_filter($upcoming_list, fn($e) => $e['days_away'] <= 7));
$claimed_this_year = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_birthday_gifts WHERE birthday_year = " . (int) date('Y') . " AND claimed_at IS NOT NULL")->fetch_assoc()['c'];

function birthday_when($days_away) {
    if ($days_away === 0) return 'Today';
    if ($days_away === 1) return 'Tomorrow';
    if ($days_away === -1) return 'Yesterday';
    return $days_away > 0 ? 'In ' . $days_away . ' days' : abs($days_away) . ' days ago';
}

function birthday_table($rows, $show_claim, $empty_text) {
    ?>
    <div class="table-responsive mb-4">
      <table class="table-theme">
        <thead><tr>
          <th>Member</th><th>Birthday</th><th>Turning</th><th>When</th><th>Contact</th>
          <?php if ($show_claim): ?><th>Greeting</th><th>Gift</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="<?= $show_claim ? 7 : 5 ?>" class="text-muted"><?= sanitize($empty_text) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $e): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/basics/admin/member_view.php?id=<?= (int) $e['member_id'] ?>"><?= sanitize($e['full_name']) ?></a> <span class="text-muted small">(<?= sanitize($e['username']) ?>)</span></td>
            <td data-order="<?= sanitize($e['date']) ?>"><?= date('M j, Y', strtotime($e['date'])) ?></td>
            <td><?= (int) $e['age'] ?></td>
            <td><?= birthday_when($e['days_away']) ?></td>
            <td class="small"><?= sanitize($e['contact_number']) ?><?= $e['email'] ? '<br>' . sanitize($e['email']) : '' ?></td>
            <?php if ($show_claim): ?>
              <td><?= $e['greeted_at'] ? '<span class="text-muted small">Sent ' . date('g:i A', strtotime($e['greeted_at'])) . '</span>' : '<span class="text-muted">&mdash;</span>' ?></td>
              <td>
                <?php if (!empty($e['order_id'])): ?>
                  <a href="<?= BASE_URL ?>/basics/admin/order_view.php?id=<?= (int) $e['order_id'] ?>" class="pill pill-<?= basics_order_status_pill($e['order_status']) ?>"><?= basics_order_status_label($e['order_status']) ?></a>
                  <span class="text-muted small"><?= date('M j, g:i A', strtotime($e['claimed_at'])) ?></span>
                <?php elseif ($e['claimed_at']): ?>
                  <span class="pill pill-approved">Claimed</span> <span class="text-muted small"><?= date('M j, g:i A', strtotime($e['claimed_at'])) ?></span>
                <?php else: ?>
                  <span class="pill pill-pending">Not claimed</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

$page_title = 'Birthday Gifts';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Birthday Grocery Gifts</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num<?= count($today_list) > 0 ? ' accent' : '' ?>"><?= count($today_list) ?></div><div class="stat-lbl">Birthdays Today</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num"><?= $next_7 ?></div><div class="stat-lbl">Next 7 Days</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num"><?= count($upcoming_list) ?></div><div class="stat-lbl">Next 4 Weeks</div></div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-tile"><div class="stat-num"><?= $claimed_this_year ?></div><div class="stat-lbl">Gifts Claimed <?= date('Y') ?></div></div>
    </div>
  </div>

  <p class="text-muted small">Active members only. Celebrants are greeted by email, SMS and their dashboard at 6:00 AM on their birthday (sent on the first site visit at or after 6 AM), and can claim their gift for <?= (int) $claim_days ?> days starting on the birthday.</p>

  <h2 class="h6 mb-3">Birthday Today</h2>
  <?php birthday_table($today_list, true, 'No birthdays today.'); ?>

  <h2 class="h6 mb-3">Recent &mdash; Gift Still Claimable</h2>
  <?php birthday_table($recent_list, true, 'No recent birthdays within the claim window.'); ?>

  <h2 class="h6 mb-3">Upcoming &mdash; Next 4 Weeks</h2>
  <?php birthday_table($upcoming_list, false, 'No upcoming birthdays in the next 4 weeks.'); ?>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
