</main><!-- /#mainContent -->

<footer class="site-footer" role="contentinfo">
  <div class="container">
    <div class="row g-4 g-lg-5">

      <!-- Col 1: About & Tagline -->
      <div class="col-lg-4 col-md-6">
        <h4 class="footer-col-title"><?= e(setting('site_name', APP_NAME)) ?></h4>
        <p class="small text-muted mb-3" style="line-height: 1.6;">
          <?= e(setting('store_tagline', 'Curated everyday essentials, made with intention and delivered straight to your door.')) ?>
        </p>
        <p class="small text-muted mb-0">
          <?= e(setting('site_about', '')) ?>
        </p>
      </div>

      <!-- Col 2: Shop Links -->
      <div class="col-lg-2 col-md-6 col-6">
        <h4 class="footer-col-title">Shop</h4>
        <ul class="footer-links">
          <li><a href="<?= e(shop_url('shop.php')) ?>">All Products</a></li>
          <li><a href="<?= e(shop_url('shop.php?sort=newest')) ?>">New Arrivals</a></li>
          <li><a href="<?= e(shop_url('shop.php?sort=best_selling')) ?>">Best Sellers</a></li>
          <li><a href="<?= e(shop_url('shop.php?featured=1')) ?>">Featured</a></li>
          <li><a href="<?= e(shop_url('cart.php')) ?>">Shopping Cart</a></li>
        </ul>
      </div>

      <!-- Col 3: Customer Service & Help -->
      <div class="col-lg-3 col-md-6 col-6">
        <h4 class="footer-col-title">Customer Care</h4>
        <ul class="footer-links">
          <li><a href="<?= e(shop_url('track.php')) ?>">Track Your Order</a></li>
          <li><a href="<?= e(shop_url('index.php#about')) ?>">About Our Store</a></li>
          <li><a href="<?= e(shop_url('checkout.php')) ?>">Cash on Delivery Policy</a></li>
          <li><a href="mailto:<?= e(setting('site_email', 'support@example.com')) ?>">Customer Support</a></li>
        </ul>
      </div>

      <!-- Col 4: Contact & Social -->
      <div class="col-lg-3 col-md-6">
        <h4 class="footer-col-title">Get in Touch</h4>
        <ul class="footer-links mb-3">
          <?php $storeEmail = setting('site_email'); if ($storeEmail): ?>
            <li><i class="bi bi-envelope me-2 text-muted"></i><?= e($storeEmail) ?></li>
          <?php endif; ?>
          <?php $storePhone = setting('store_phone'); if ($storePhone): ?>
            <li><i class="bi bi-telephone me-2 text-muted"></i><?= e($storePhone) ?></li>
          <?php endif; ?>
          <?php $storeAddress = setting('store_address'); if ($storeAddress): ?>
            <li><i class="bi bi-geo-alt me-2 text-muted"></i><?= e($storeAddress) ?></li>
          <?php endif; ?>
        </ul>

        <?php
        $fb = setting('social_facebook');
        $ig = setting('social_instagram');
        $wa = setting('social_whatsapp');
        if ($fb || $ig || $wa):
        ?>
          <div class="social-links mt-2">
            <?php if ($fb): ?>
              <a href="<?= e($fb) ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
            <?php endif; ?>
            <?php if ($ig): ?>
              <a href="<?= e($ig) ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
            <?php endif; ?>
            <?php if ($wa): ?>
              <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $wa)) ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- Bottom Bar -->
    <div class="footer-bottom">
      <div class="footer-payment-note">
        <i class="bi bi-shield-check text-success"></i>
        <span>Cash on Delivery (COD) available nationwide</span>
      </div>
      <div>
        &copy; <?= date('Y') ?> <?= e(setting('site_name', APP_NAME)) ?>. All rights reserved.
      </div>
    </div>
  </div>
</footer>

<!-- Bootstrap 5.3 JS Bundle (Self-Hosted) -->
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>" defer></script>

<!-- Storefront JS Module -->
<script src="<?= e(url('assets/js/shop.js')) ?>?v=<?= filemtime(__DIR__ . '/../assets/js/shop.js') ?>" defer></script>

<?php if (function_exists('app_finish_response')) app_finish_response(); ?>
</body>
</html>
