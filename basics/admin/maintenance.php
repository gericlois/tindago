<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $new_state = ($_POST['state'] ?? '') === 'on' ? '1' : '0';
    save_setting($conn, 'basics_maintenance_enabled', $new_state);
    log_activity($conn, 'toggle_basics_maintenance', $new_state === '1' ? 'Enabled maintenance mode' : 'Disabled maintenance mode');
    redirect('/basics/admin/maintenance.php');
}

$maintenance_on = setting($conn, 'basics_maintenance_enabled', '0') === '1';

$page_title = 'Maintenance Mode';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Maintenance Mode</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="panel-card">
    <h2 class="h6">Member-Facing Site</h2>
    <p class="text-muted small">When on, every member page (login, catalog, orders, etc.) shows a simple "under maintenance" notice instead of normal content. This admin panel keeps working the whole time so you can turn it back off.</p>
    <p class="mb-3">Status: <span class="pill pill-<?= $maintenance_on ? 'rejected' : 'active' ?>"><?= $maintenance_on ? 'Maintenance ON' : 'Live' ?></span></p>
    <form method="post" onsubmit="return confirm('<?= $maintenance_on ? 'Turn maintenance mode off and bring the site back online?' : 'Turn maintenance mode on? Members will not be able to log in, browse, or order until you turn it back off.' ?>');">
      <input type="hidden" name="action" value="toggle">
      <input type="hidden" name="state" value="<?= $maintenance_on ? 'off' : 'on' ?>">
      <button type="submit" class="btn-chip <?= $maintenance_on ? 'btn-chip-success' : 'btn-chip-outline' ?>">
        <i class="fas fa-power-off"></i> <?= $maintenance_on ? 'Turn Maintenance OFF' : 'Turn Maintenance ON' ?>
      </button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
