<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

if (basics_is_logged_in()) {
    $member = basics_get_member($conn, basics_current_user_id());
    if ($member && $member['application_status'] === 'approved' && in_array($member['membership_status'], ['active', 'dormant'], true)) {
        redirect('/basics/catalog.php');
    }
    redirect('/basics/pending.php');
}

$page_title = 'Home';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<section id="hero" style="background:linear-gradient(rgba(13, 40, 24, 0.8), rgba(13, 40, 24, 0.72)), url('<?= BASE_URL ?>/assets/img/basics/JMCBasics_catalognew.jpg') center/cover no-repeat;">
  <div class="container">
    <div class="row align-items-center g-5" style="min-height:60vh;">
      <div class="col-lg-8 mx-auto text-center">
        <div class="hbadge mx-auto">
          <div class="hbi"><i class="fas fa-basket-shopping"></i></div>
          <span>JMC Foodies Basics</span>
        </div>
        <h1 class="htitle">Basic Needs,<br/><span class="hl">Everyday, For Every Family</span></h1>
        <p class="hdesc mx-auto">
          A grocery purchase line for employees of partner companies. Order rice, breakfast
          essentials, and viand any time &mdash; settle up 7 days after delivery, 0% interest.
        </p>
        <div class="d-flex flex-wrap justify-content-center gap-3 mb-2">
          <a href="<?= BASICS_URL ?>/apply.php" class="btn-red"><i class="fas fa-file-signature"></i>Apply for Membership</a>
          <a href="<?= BASICS_URL ?>/login.php" class="btn-outline-theme"><i class="fas fa-right-to-bracket"></i>Login</a>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="how-it-works">
  <div class="container">
    <div class="text-center mb-5">
      <span class="slbl">How It Works</span>
      <h2 class="stitle">Simple, <span>No-Fuss Ordering</span></h2>
      <div class="sline"></div>
    </div>
    <div class="row g-4">
      <div class="col-md-4">
        <div class="stat-tile h-100">
          <div class="hbi mx-auto mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-cart-shopping"></i></div>
          <h3 class="h6">Order Any Time</h3>
          <p class="text-muted mb-0 small">No fixed ordering window &mdash; browse and check out whenever you need groceries.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-tile h-100">
          <div class="hbi mx-auto mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-truck"></i></div>
          <h3 class="h6">We Deliver</h3>
          <p class="text-muted mb-0 small">Delivery doesn't wait on payment &mdash; your order goes out on schedule.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-tile h-100">
          <div class="hbi mx-auto mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-money-bill-wave"></i></div>
          <h3 class="h6">Pay Within 7 Days</h3>
          <p class="text-muted mb-0 small">Settle your balance 7 days after your order is delivered, 0% interest.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="special">
  <div class="spbg"></div>
  <div class="container text-center" style="position:relative;z-index:2;">
    <div class="sptag mx-auto"><i class="fas fa-briefcase me-1"></i>Employees of Partner Companies</div>
    <h2 class="sptitle">Ready to Apply?</h2>
    <p class="spdesc mx-auto" style="max-width:520px;">You'll need 2 valid IDs, a Barangay Clearance, and a Certificate of Employment or Company Work Clearance.</p>
    <a href="<?= BASICS_URL ?>/apply.php" class="btn-red"><i class="fas fa-file-signature"></i>Apply for Membership</a>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
