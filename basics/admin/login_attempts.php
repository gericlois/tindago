<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin']);

// Only the admin-panel scope — this page is about who tried to log in
// as an admin, not the much noisier member-login scope (basics).
$scopes_in_play = ['basics_admin'];
$scope_placeholders = implode(',', array_fill(0, count($scopes_in_play), '?'));
$scope_types = str_repeat('s', count($scopes_in_play));

// One row per (scope, ip) — mirrors exactly what login_throttle_blocked() in
// includes/auth.php checks for admin scopes: 3 attempts from one IP within
// 15 minutes locks that IP out, regardless of which username it tried
// (admin accounts are shared, so blocking is IP-based, not username-based).
$stmt = $conn->prepare("SELECT scope, ip,
        GROUP_CONCAT(DISTINCT identifier ORDER BY identifier SEPARATOR ', ') AS usernames_tried,
        COUNT(*) AS total_attempts,
        SUM(created_at > (NOW() - INTERVAL 15 MINUTE)) AS recent_attempts,
        MAX(created_at) AS last_attempt
    FROM login_attempts
    WHERE scope IN ($scope_placeholders)
    GROUP BY scope, ip
    ORDER BY last_attempt DESC
    LIMIT 300");
$stmt->bind_param($scope_types, ...$scopes_in_play);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// The IP-wide guard (60 attempts from one IP in 15 min, across ANY scope,
// admin or member) is a separate, blunter lock — surfaced here too since an
// IP tripping it is hammering logins hard enough to matter regardless of
// which panel it's aimed at.
$ip_wide = $conn->query("SELECT ip, COUNT(*) AS c, MAX(created_at) AS last_attempt
    FROM login_attempts
    WHERE created_at > (NOW() - INTERVAL 15 MINUTE)
    GROUP BY ip
    HAVING c >= 60
    ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);

$scope_labels = ['basics_admin' => 'Admin Panel'];

$page_title = 'Admin Login Attempts';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Admin Login Attempts</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <p class="text-muted small mb-3">IPs that have attempted to log into an admin panel. Since admin accounts are shared by multiple staff, lockout is by IP, not username: an IP locks out of a given admin panel after 3 failed attempts within 15 minutes, no matter which username it tried (a separate, wider guard also blocks any single IP making 60+ attempts across any login form).</p>

  <?php if ($ip_wide): ?>
    <div class="errmsg mb-4">
      <p class="mb-2"><strong>IP-wide throttle currently tripped</strong> (60+ login attempts from one IP in the last 15 minutes, across any login form):</p>
      <ul class="mb-0">
        <?php foreach ($ip_wide as $row): ?>
          <li><code><?= sanitize($row['ip']) ?></code> — <?= (int) $row['c'] ?> attempts, last at <?= date('M j, Y g:i:s A', strtotime($row['last_attempt'])) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
  </div>

  <div class="panel-card">
    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>IP Address</th><th>Panel</th><th>Usernames Attempted</th><th>Attempts (15 min)</th><th>Attempts (total)</th><th>Last Attempt</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="text-muted">No admin login attempts recorded.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <?php $locked = (int) $row['recent_attempts'] >= 3; ?>
          <tr>
            <td><code><?= sanitize($row['ip']) ?></code></td>
            <td><?= sanitize($scope_labels[$row['scope']] ?? $row['scope']) ?></td>
            <td><?= sanitize($row['usernames_tried']) ?></td>
            <td><?= (int) $row['recent_attempts'] ?></td>
            <td><?= (int) $row['total_attempts'] ?></td>
            <td class="small"><?= date('M j, Y g:i:s A', strtotime($row['last_attempt'])) ?></td>
            <td><span class="pill pill-<?= $locked ? 'rejected' : 'approved' ?>"><?= $locked ? 'Locked' : 'Clear' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
