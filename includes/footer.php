<?php
// Same module-data-driven convention as navbar.php.
$module_name = $module_name ?? SITE_NAME;
$module_logo_url = $module_logo_url ?? BASE_URL . '/assets/img/wellness/logo.jpg';
$module_home_url = $module_home_url ?? BASE_URL . '/index.php';
$module_nav_items = $module_nav_items ?? [];
$module_guest_nav_items = $module_guest_nav_items ?? [];
$module_register_url = $module_register_url ?? null;
$module_footer_desc = $module_footer_desc ?? 'JMC Digital brings together JMC Foodies Wellness and JMC Foodies Basics — two ways to save, earn, and shop with JMC Foodies.';
// Wellness and Basics have fully separate login sessions — see navbar.php.
$module_is_logged_in = $module_name === 'JMC Foodies Basics' ? basics_is_logged_in() : is_logged_in();
$module_login_url = $module_name === 'JMC Foodies Basics' ? BASICS_URL . '/login.php' : BASE_URL . '/login.php';
?>
  <footer>
    <div class="container">
      <div class="row g-5 justify-content-center text-center">
        <div class="col-lg-4">
          <div class="brand-logo-box mb-3">
            <img src="<?= sanitize($module_logo_url) ?>" alt="<?= sanitize($module_name) ?>" class="brand-logo">
          </div>
          <p class="fdesc"><?= sanitize($module_footer_desc) ?></p>
        </div>
        <?php // Basics' navbar already covers all of these on mobile (its own
              // menu), so the footer copy just duplicates it — kept for
              // Wellness/hub pages, hidden for Basics. ?>
        <?php if ($module_name !== 'JMC Foodies Basics'): ?>
        <div class="col-sm-6 col-lg-4">
          <div class="ftit">Quick Links</div>
          <ul class="flinks ps-0">
            <li><a href="<?= sanitize($module_home_url) ?>"><i class="fas fa-chevron-right"></i>Home</a></li>
            <?php if ($module_is_logged_in): ?>
              <?php foreach ($module_nav_items as $label => $url): ?>
                <li><a href="<?= sanitize($url) ?>"><i class="fas fa-chevron-right"></i><?= sanitize($label) ?></a></li>
              <?php endforeach; ?>
            <?php else: ?>
              <?php foreach ($module_guest_nav_items as $label => $url): ?>
                <li><a href="<?= sanitize($url) ?>"><i class="fas fa-chevron-right"></i><?= sanitize($label) ?></a></li>
              <?php endforeach; ?>
              <?php if ($module_register_url): ?>
                <li><a href="<?= sanitize($module_register_url) ?>"><i class="fas fa-chevron-right"></i>Register</a></li>
              <?php endif; ?>
              <li><a href="<?= $module_login_url ?>"><i class="fas fa-chevron-right"></i>Login</a></li>
            <?php endif; ?>
          </ul>
        </div>
        <?php endif; ?>
        <div class="col-lg-4">
          <div class="ftit">Get In Touch</div>
          <div class="fci"><div class="fciinfo"><strong>Email:</strong> <?= sanitize(setting($conn, 'company_email', 'support@example.com')) ?></div></div>
          <div class="fci"><div class="fciinfo"><strong>Facebook:</strong> <a href="<?= FACEBOOK_URL ?>" target="_blank" rel="noopener">JMC Foodies on Facebook</a></div></div>
          <?php $company_address = setting($conn, 'company_address', ''); ?>
          <?php if ($company_address !== ''): ?>
            <div class="fci"><div class="fciinfo"><strong>Address:</strong> <?= sanitize($company_address) ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="fbot">
      <div class="container">
        <div class="d-flex justify-content-center align-items-center flex-wrap gap-2">
          <p>&copy; <?= date('Y') ?> <span><?= SITE_NAME ?></span>. All rights reserved.</p>
        </div>
      </div>
    </div>
  </footer>
  <button id="btt" onclick="window.scrollTo({top:0,behavior:'smooth'})"><i class="fas fa-chevron-up"></i></button>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js');
      });
    }
  </script>
</body>
</html>
