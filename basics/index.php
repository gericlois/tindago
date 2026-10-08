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

<?php // The banner image carries its own headline and benefit tiles, so it's
      // shown whole (never cropped or overlaid) with the CTAs in a strip below. ?>
<section class="main-banner">
  <h1 class="visually-hidden">TindaGo &mdash; Mas Mura. Mas Madali. Mas Malaki ang Kita.</h1>
  <img src="<?= BASE_URL ?>/assets/img/mainbanner.jpg"
       srcset="<?= BASE_URL ?>/assets/img/mainbanner-1024.jpg 1024w, <?= BASE_URL ?>/assets/img/mainbanner.jpg 2056w"
       sizes="100vw" width="2056" height="765"
       alt="TindaGo: digital wholesale marketplace para sa mga tindahan. Credit line up to ₱10,000 with 7-day payment terms, electric subsidy, free daily coffee packs, hospital assistance, medicine subsidy, and burial assistance.">
</section>


<section class="main-banner-cta">
  <div class="container text-center">
    <p class="banner-tagline mb-2">Mas Mura. Mas Madali. Mas Malaki ang Kita.</p>
    <p class="mb-3">
      TindaGo is a digital wholesale marketplace para sa mga tindahan &mdash; order your
      store's stock at wholesale prices, get it delivered, and pay within 7 days.
    </p>
    <div class="d-flex flex-wrap justify-content-center gap-3">
      <a href="<?= BASICS_URL ?>/apply.php" class="btn-red"><i class="fas fa-store"></i>Become a Store Partner</a>
      <a href="<?= BASICS_URL ?>/login.php" class="btn-outline-theme"><i class="fas fa-right-to-bracket"></i>Login</a>
    </div>
  </div>
</section>

<section id="why-tindago">
  <div class="container">
    <div class="text-center mb-5">
      <span class="slbl">Bakit TindaGo?</span>
      <h2 class="stitle">Kasama sa <span>Paglago ng Tindahan</span></h2>
      <div class="sline"></div>
    </div>
    <div class="row g-4 justify-content-center">
      <?php
      $pillars = [
          ['fa-cart-shopping', 'Wholesale Prices', 'Stock up on rice, groceries, drinks, and household items at wholesale prices.'],
          ['fa-truck', 'Reliable Delivery', 'Order from your phone and we deliver straight to your tindahan.'],
          ['fa-gift', 'Rewards & Promos', 'Flash deals and promos that help you sell more and spend less.'],
          ['fa-handshake', 'Business Partner', 'A credit line, 7-day payment terms, and benefits for you and your family.'],
          ['fa-chart-line', 'Mas Malaking Kita', 'Lower costs per item means a bigger margin on every sale.'],
      ];
      foreach ($pillars as [$icon, $title, $desc]): ?>
        <div class="col-sm-6 col-lg-4 col-xl">
          <div class="stat-tile h-100">
            <div class="hbi mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas <?= $icon ?>"></i></div>
            <h3 class="h6"><?= sanitize($title) ?></h3>
            <p class="text-muted mb-0 small"><?= sanitize($desc) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="partner-benefits" class="shop-bg">
  <div class="container">
    <div class="text-center mb-5">
      <span class="slbl">Store Partner Benefits</span>
      <h2 class="stitle">More Than <span>Just Supplies</span></h2>
      <div class="sline"></div>
    </div>
    <div class="row g-4">
      <?php
      $benefits = [
          ['fa-credit-card', 'Credit Line up to ₱10,000', 'Order now, pay within 7 days after delivery.'],
          ['fa-bolt', '10% Monthly Electric Subsidy', 'Up to ₱10,000 on your electric bill.'],
          ['fa-mug-hot', 'Free Daily Coffee Packs', 'Kape mo, sagot ko — araw-araw.'],
          ['fa-hospital', 'Hospital Assistance', 'Up to ₱5,000 when you need it most.'],
          ['fa-pills', '20% Personal Medicine Subsidy', 'Help with the cost of your medicines.'],
          ['fa-dove', '₱20,000 Personal Burial Assistance', 'Financial support for your family.'],
          ['fa-people-group', '₱10,000 Burial Assistance to Beneficiaries', 'Support for your loved ones.'],
      ];
      foreach ($benefits as [$icon, $title, $desc]): ?>
        <div class="col-sm-6 col-lg-3">
          <div class="stat-tile h-100">
            <div class="hbi mb-3" style="width:48px;height:48px;font-size:1.2rem;"><i class="fas <?= $icon ?>"></i></div>
            <h3 class="h6"><?= sanitize($title) ?></h3>
            <p class="text-muted mb-0 small"><?= sanitize($desc) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="text-center text-muted small mt-4 mb-0">Benefits are subject to approval and fund availability.</p>
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
          <div class="hbi mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-mobile-screen-button"></i></div>
          <h3 class="h6">Order Any Time</h3>
          <p class="text-muted mb-0 small">No fixed ordering window &mdash; browse the catalog and check out whenever your store needs restocking.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-tile h-100">
          <div class="hbi mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-truck"></i></div>
          <h3 class="h6">We Deliver to Your Store</h3>
          <p class="text-muted mb-0 small">Delivery doesn't wait on payment &mdash; your order goes out on schedule.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-tile h-100">
          <div class="hbi mb-3" style="width:56px;height:56px;font-size:1.4rem;"><i class="fas fa-money-bill-wave"></i></div>
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
    <div class="sptag mx-auto"><i class="fas fa-store me-1"></i>Para sa mga Tindahan</div>
    <h2 class="sptitle">Ready to Become a Store Partner?</h2>
    <p class="spdesc mx-auto" style="max-width:520px;">You'll need a valid government ID, photos of your store (front and inside), and proof of your store address such as a Barangay Certificate.</p>
    <a href="<?= BASICS_URL ?>/apply.php" class="btn-red"><i class="fas fa-store"></i>Become a Store Partner</a>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
