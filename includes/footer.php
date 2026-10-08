<?php
// Same module-data-driven convention as navbar.php.
$module_name = $module_name ?? SITE_NAME;
$module_logo_url = $module_logo_url ?? BASE_URL . '/assets/img/basics/logo.jpg';
$module_footer_desc = $module_footer_desc ?? '';
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
        <div class="col-lg-4">
          <div class="ftit">Get In Touch</div>
          <?php $company_email = setting($conn, 'company_email', ''); ?>
          <?php if ($company_email !== ''): ?>
            <div class="fci"><div class="fciinfo"><strong>Email:</strong> <?= sanitize($company_email) ?></div></div>
          <?php endif; ?>
          <?php if (FACEBOOK_URL !== ''): ?>
            <div class="fci"><div class="fciinfo"><strong>Facebook:</strong> <a href="<?= sanitize(FACEBOOK_URL) ?>" target="_blank" rel="noopener"><?= sanitize(SITE_NAME) ?> on Facebook</a></div></div>
          <?php endif; ?>
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
