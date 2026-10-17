<?php
$newsletterEnabled = (int)setting('newsletter_enabled', '1') === 1;
$mobileNavEnabled  = (int)setting('mobile_bottom_nav', '1') === 1;
?>
</main><!-- /#mainContent -->

<!-- Storefront Footer -->
<footer class="site-footer" role="contentinfo">
  <div class="container">

    <!-- Newsletter Strip -->
    <?php if ($newsletterEnabled): ?>
      <div class="card mb-8 p-6" style="background-color: var(--surface);">
        <div class="row align-items-center g-4">
          <div class="col-lg-6">
            <h3 class="mb-1">Join our newsletter</h3>
            <p class="text-muted mb-0 small">Get updates on new collections, exclusive drops, and insider stories.</p>
          </div>
          <div class="col-lg-6">
            <form action="<?= e(route('newsletter')) ?>" method="post" class="d-flex gap-2">
              <?= csrf_field() ?>
              <input type="email" name="email" class="form-control" placeholder="Enter your email address" required style="max-width: 320px;">
              <button type="submit" class="btn btn-primary">Subscribe</button>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="row g-4 g-lg-6">

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
          <li><a href="<?= e(route('shop')) ?>">All Products</a></li>
          <li><a href="<?= e(route('shop', ['sort' => 'newest'])) ?>">New Arrivals</a></li>
          <li><a href="<?= e(route('shop', ['sort' => 'best_selling'])) ?>">Best Sellers</a></li>
          <li><a href="<?= e(route('cart')) ?>">Shopping Cart</a></li>
        </ul>
      </div>

      <!-- Col 3: Customer Care & CMS Pages -->
      <div class="col-lg-3 col-md-6 col-6">
        <h4 class="footer-col-title">Customer Care</h4>
        <ul class="footer-links">
          <li><a href="<?= e(route('track')) ?>">Track Your Order</a></li>
          <li><a href="<?= e(route('contact')) ?>">Contact Us</a></li>
          <?php
          try {
              $stmtFp = db()->query("SELECT slug, title FROM pages WHERE show_in_footer = 1 AND status = 'published' ORDER BY position ASC, id ASC");
              $footerPages = $stmtFp ? $stmtFp->fetchAll() : [];
              foreach ($footerPages as $fp): ?>
                <li><a href="<?= e(route('page', ['slug' => $fp['slug']])) ?>"><?= e($fp['title']) ?></a></li>
              <?php endforeach;
          } catch (Throwable $e) {}
          ?>
        </ul>
      </div>

      <!-- Col 4: Contact & Social -->
      <div class="col-lg-3 col-md-6">
        <h4 class="footer-col-title">Get in Touch</h4>
        <ul class="footer-links mb-3">
          <?php $storeEmail = setting('contact_email', setting('site_email')); if ($storeEmail): ?>
            <li class="d-flex align-items-center gap-2 small text-muted">
              <?= icon('mail') ?> <span><?= e($storeEmail) ?></span>
            </li>
          <?php endif; ?>
          <?php $storePhone = setting('contact_phone', setting('store_phone')); if ($storePhone): ?>
            <li class="d-flex align-items-center gap-2 small text-muted">
              <?= icon('phone') ?> <span><?= e($storePhone) ?></span>
            </li>
          <?php endif; ?>
          <?php $storeAddress = setting('store_address'); if ($storeAddress): ?>
            <li class="d-flex align-items-center gap-2 small text-muted">
              <?= icon('map-pin') ?> <span><?= e($storeAddress) ?></span>
            </li>
          <?php endif; ?>
        </ul>

        <?php
        $fb = setting('social_facebook');
        $ig = setting('social_instagram');
        $wa = setting('social_whatsapp');
        if ($fb || $ig || $wa):
        ?>
          <div class="social-links mt-3">
            <?php if ($fb): ?>
              <a href="<?= e($fb) ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><?= icon('share') ?></a>
            <?php endif; ?>
            <?php if ($ig): ?>
              <a href="<?= e($ig) ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><?= icon('share') ?></a>
            <?php endif; ?>
            <?php if ($wa): ?>
              <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $wa)) ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><?= icon('phone') ?></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- Bottom Bar -->
    <div class="footer-bottom">
      <div class="d-flex align-items-center gap-2">
        <span class="text-ok"><?= icon('shield') ?></span>
        <span>Cash on Delivery (COD) available nationwide</span>
      </div>
      <div class="d-flex align-items-center gap-4">
        <button type="button" class="btn btn-ghost btn-sm" data-theme-toggle>
          <span class="theme-icon-sun" style="display:none;"><?= icon('sun') ?> Light</span>
          <span class="theme-icon-moon"><?= icon('moon') ?> Dark</span>
        </button>
        <button type="button" class="btn btn-ghost btn-sm" data-back-to-top>
          <?= icon('chevron-up') ?> Back to top
        </button>
        <span>&copy; <?= date('Y') ?> <?= e(setting('site_name', APP_NAME)) ?>.</span>
      </div>
    </div>
  </div>
</footer>

<!-- Mobile Bottom Navigation Bar -->
<?php if ($mobileNavEnabled && !route_is('checkout')): ?>
  <nav class="mobile-bottom-nav" aria-label="Mobile Navigation">
    <a href="<?= e(route('home')) ?>" class="mobile-nav-item <?= route_is('home') ? 'is-active' : '' ?>">
      <?= icon('bag') ?>
      <span>Home</span>
    </a>
    <a href="<?= e(route('shop')) ?>" class="mobile-nav-item <?= route_is('shop') ? 'is-active' : '' ?>">
      <?= icon('grid-4') ?>
      <span>Shop</span>
    </a>
    <a href="#" class="mobile-nav-item" data-toggle-search>
      <?= icon('search') ?>
      <span>Search</span>
    </a>
    <a href="<?= e(route('account_wishlist')) ?>" class="mobile-nav-item <?= route_is('account_wishlist') ? 'is-active' : '' ?>">
      <?= icon('heart') ?>
      <span>Wishlist</span>
    </a>
    <a href="<?= e(route('account')) ?>" class="mobile-nav-item <?= route_is('account') ? 'is-active' : '' ?>">
      <?= icon('user') ?>
      <span>Account</span>
    </a>
  </nav>
<?php endif; ?>

<!-- Central Storefront Toast Container -->
<div id="shopToastContainer" class="toast-container" role="region" aria-live="polite"></div>

<!-- Storefront Standalone JavaScript -->
<script src="<?= e(url('assets/js/shop/ui.js')) ?>?v=<?= file_exists(__DIR__ . '/../../assets/js/shop/ui.js') ? filemtime(__DIR__ . '/../../assets/js/shop/ui.js') : time() ?>" defer></script>
<script src="<?= e(url('assets/js/shop.js')) ?>?v=<?= file_exists(__DIR__ . '/../../assets/js/shop.js') ? filemtime(__DIR__ . '/../../assets/js/shop.js') : time() ?>" defer></script>

</body>
</html>
