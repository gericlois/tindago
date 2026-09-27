<?php
// Shared body of the Database Backup page. The Wellness admin
// (admin/db_backup.php) and the Basics admin (basics/admin/db_backup.php)
// each have their own thin page so an admin never gets bounced into the other
// panel's layout/sidebar; both include this file. The wrapper page has
// already loaded the app, enforced its own login/role check, and set:
//   $db_backup_url      this page's own path (used for redirects/links)
//   $db_backup_sidebar  absolute path of the sidebar file to render
//   $db_backup_kicker   small heading above the title

$backup_file = __DIR__ . '/../../database/backups/latest.sql';
$log_file = __DIR__ . '/../../database/backups/last_run.txt';

if (($_GET['download'] ?? '') === '1') {
    if (!file_exists($backup_file)) {
        redirect($db_backup_url);
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="jmcfoodies_backup_' . date('Y-m-d_His') . '.sql"');
    header('Content-Length: ' . filesize($backup_file));
    readfile($backup_file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_now') {
    $ran_ok = write_database_backup($conn);
    save_setting($conn, 'db_backup_last_run_at', date('Y-m-d H:i:s'));
    log_activity($conn, 'run_db_backup', 'Manually ran a database backup (' . ($ran_ok ? 'succeeded' : 'failed') . ')');
    redirect($db_backup_url . ($ran_ok ? '' : '?failed=1'));
}

$last_run = file_exists($log_file) ? trim(file_get_contents($log_file)) : 'Never run yet.';
$backup_exists = file_exists($backup_file);
$backup_size_kb = $backup_exists ? round(filesize($backup_file) / 1024, 1) : 0;
$backup_modified = $backup_exists ? date('M j, Y g:i A', filemtime($backup_file)) : null;

$page_title = 'Database Backup';
require __DIR__ . '/admin_header.php';
require $db_backup_sidebar;
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl"><?= sanitize($db_backup_kicker) ?></span>
    <h1 class="stitle" style="font-size:2rem;">Database Backup</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['failed'])): ?>
    <div class="errmsg mb-4"><p class="mb-0">The backup run failed — check database/backups/last_run.txt for the error.</p></div>
  <?php endif; ?>
  <div class="panel-card">
    <h2 class="h6">Automatic Backup</h2>
    <p class="text-muted small">Runs on its own every 1 hour — no external service or server cron needed. It piggybacks on ordinary site traffic (checked on every page load, only actually runs once the interval has passed), and overwrites the same file each time so it never accumulates disk space. The backup covers the whole database — Wellness and Basics together.</p>
    <p class="mb-1">Last run: <?= sanitize($last_run) ?></p>
    <?php if ($backup_exists): ?>
      <p class="mb-1">Backup file date: <?= sanitize($backup_modified) ?></p>
      <p class="mb-3">Size: <?= sanitize($backup_size_kb) ?> KB</p>
      <a href="<?= BASE_URL . $db_backup_url ?>?download=1" class="btn-chip btn-chip-success"><i class="fas fa-download"></i> Download Latest Backup</a>
    <?php else: ?>
      <p class="text-muted mb-3">No backup has been generated yet.</p>
    <?php endif; ?>
    <form method="post" class="d-inline">
      <input type="hidden" name="action" value="run_now">
      <button type="submit" class="btn-chip btn-chip-outline"><i class="fas fa-rotate"></i> Run Backup Now</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/admin_footer.php'; ?>
