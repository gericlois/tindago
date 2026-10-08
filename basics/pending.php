<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_login($conn);

$member = basics_get_member($conn, basics_current_user_id());
if (!$member) {
    redirect('/basics/apply.php');
}
if ($member['application_status'] === 'approved' && in_array($member['membership_status'], ['active', 'dormant'], true)) {
    redirect('/basics/catalog.php');
}

$page_title = 'Application Status';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Membership Status</span>
    <h1 class="stitle">Your <span>Application</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
      <div class="panel-card text-center">
        <?php if (isset($_GET['submitted'])): ?>
          <div class="sucmsg is-visible mb-3"><p>Your application has been submitted!</p></div>
        <?php endif; ?>

        <?php if ($member['application_status'] === 'pending'): ?>
          <div class="hbi mx-auto mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-hourglass-half"></i></div>
          <h2 class="h5 mb-2">Application Under Review</h2>
          <p class="text-muted mb-0">We're reviewing your documents. You'll be able to log in and start ordering once an admin approves your membership.</p>
        <?php elseif ($member['application_status'] === 'denied'): ?>
          <div class="hbi mx-auto mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-circle-xmark"></i></div>
          <h2 class="h5 mb-2">Application Denied</h2>
          <p class="text-muted mb-0"><?= $member['admin_notes'] ? sanitize($member['admin_notes']) : 'Your application was not approved. Contact support for more information.' ?></p>
        <?php elseif ($member['membership_status'] === 'suspended'): ?>
          <div class="hbi mx-auto mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-ban"></i></div>
          <h2 class="h5 mb-2">Account Suspended</h2>
          <p class="text-muted mb-1">Your TindaGo membership is suspended<?= $member['suspended_until'] ? ' until ' . date('M j, Y', strtotime($member['suspended_until'])) : '' ?>.</p>
          <p class="text-muted mb-0">Contact support if you believe this is an error.</p>
        <?php elseif ($member['membership_status'] === 'terminated'): ?>
          <div class="hbi mx-auto mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-lock"></i></div>
          <h2 class="h5 mb-2">Membership Terminated</h2>
          <p class="text-muted mb-0">Your TindaGo membership has been permanently terminated. Contact support for more information.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
