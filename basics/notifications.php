<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_access($conn);

$member = basics_get_member($conn, basics_current_user_id());

// Latest 100 — loaded before marking them read, so this visit can still
// highlight which ones are new.
$notifications = [];
if (basics_notifications_ready($conn)) {
    $stmt = $conn->prepare("SELECT * FROM basics_notifications WHERE member_id = ? ORDER BY created_at DESC, id DESC LIMIT 100");
    $stmt->bind_param('i', $member['id']);
    $stmt->execute();
    $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("UPDATE basics_notifications SET read_at = NOW() WHERE member_id = ? AND read_at IS NULL");
    $stmt->bind_param('i', $member['id']);
    $stmt->execute();
    $stmt->close();
}

$type_icons = basics_notification_types();

$page_title = 'Notifications';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Your Account Activity</span>
    <h1 class="stitle">My <span>Notifications</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5" style="max-width:760px;">
  <?php if (!$notifications): ?>
    <div class="text-center text-muted py-4">
      <i class="fas fa-bell-slash mb-2" style="font-size:1.6rem;"></i>
      <p class="mb-0">No notifications yet. Updates about your orders, payments, benefits and account will show up here.</p>
    </div>
  <?php else: ?>
    <ul class="notif-list">
      <?php foreach ($notifications as $n): ?>
        <?php $is_new = $n['read_at'] === null; ?>
        <li class="notif-item<?= $is_new ? ' is-new' : '' ?>">
          <span class="notif-icon"><i class="fas <?= sanitize($type_icons[$n['type']] ?? 'fa-bell') ?>"></i></span>
          <div class="notif-body">
            <div class="notif-head">
              <strong>
                <?php if ($n['link']): ?>
                  <a href="<?= BASICS_URL . sanitize($n['link']) ?>"><?= sanitize($n['title']) ?></a>
                <?php else: ?>
                  <?= sanitize($n['title']) ?>
                <?php endif; ?>
                <?php if ($is_new): ?><span class="pill pill-pending">New</span><?php endif; ?>
              </strong>
              <span class="small text-muted"><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></span>
            </div>
            <p class="mb-1"><?= nl2br(sanitize($n['message'])) ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
