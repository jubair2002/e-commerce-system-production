<?php
$newsletterEnabled = (int)setting('newsletter_enabled', '1') === 1;
$mobileNavEnabled  = (int)setting('mobile_bottom_nav', '1') === 1;
?>
</main><!-- /#mainContent -->

<!-- Storefront Footer -->
<footer class="site-footer" role="contentinfo">
  <div class="container">

    <!-- Newsletter Strip (Webflow Shop Style) -->
    <?php if ($newsletterEnabled): ?>
      <div class="card mb-8 p-6 p-md-8" style="background-color: var(--surface-card); border: 1px solid var(--line); border-radius: var(--radius-xl);">
        <div class="row align-items-center g-4">
          <div class="col-lg-6">
            <h3 class="mb-1" style="font-weight: 700; letter-spacing: -0.02em;">Join our newsletter</h3>
            <p class="text-muted mb-0 small">Get updates on new collections, exclusive drops, and insider stories.</p>
          </div>
          <div class="col-lg-6">
            <form action="<?= e(route('newsletter')) ?>" method="post" class="d-flex flex-wrap gap-2 justify-content-lg-end">
              <?= csrf_field() ?>
              <input type="email" name="email" class="form-control" placeholder="Enter your email address" required style="max-width: 320px; border-radius: var(--radius-pill); padding: 12px 20px;">
              <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">Subscribe</button>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="row g-4 g-lg-6">

      <!-- Col 1: About & Tagline -->
      <div class="col-lg-4 col-md-6">
        <h4 class="footer-col-title"><?= e(setting('site_name', APP_NAME)) ?></h4>
        <?php $storeTagline = trim((string)setting('store_tagline', '')); if ($storeTagline !== ''): ?>
          <p class="small text-muted mb-3" style="line-height: 1.6;">
            <?= e($storeTagline) ?>
          </p>
        <?php endif; ?>
        <?php 
        $siteAbout = trim((string)setting('site_about', ''));
        if ($siteAbout !== '' && stripos($siteAbout, 'admin panel') === false): ?>
          <p class="small text-muted mb-0">
            <?= e($siteAbout) ?>
          </p>
        <?php endif; ?>
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
        <?php $customFooter = trim((string)setting('footer_text', '')); ?>
        <span>&copy; <?= date('Y') ?> <?= e($customFooter !== '' ? $customFooter : setting('site_name', APP_NAME)) ?>.</span>
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

<!-- Floating Message / Support Launcher -->
<a href="<?= e(route('contact')) ?>" class="storefront-floating-msg-btn shadow-lg d-inline-flex align-items-center gap-2" id="storefrontMsgBtn" aria-label="Send us a message" title="Need help? Send us a message">
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
  <span class="d-none d-sm-inline fw-semibold small">Message Us</span>
</a>
<style>
.storefront-floating-msg-btn {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 990;
  background: var(--accent, #0f172a);
  color: #ffffff !important;
  padding: 12px 20px;
  border-radius: 9999px;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  text-decoration: none;
}
.storefront-floating-msg-btn:hover {
  transform: translateY(-2px) scale(1.03);
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.35);
  color: #ffffff !important;
}
@media (max-width: 768px) {
  .storefront-floating-msg-btn {
    bottom: 74px; /* above mobile bottom nav */
    right: 16px;
    padding: 12px;
  }
}
</style>

<!-- Central Storefront Toast Container -->
<div id="shopToastContainer" class="toast-container" role="region" aria-live="polite"></div>

<!-- Storefront Standalone JavaScript -->
<script src="<?= e(url('assets/js/shop/ui.js')) ?>?v=<?= file_exists(__DIR__ . '/../../assets/js/shop/ui.js') ? filemtime(__DIR__ . '/../../assets/js/shop/ui.js') : time() ?>" defer></script>
<script src="<?= e(url('assets/js/shop.js')) ?>?v=<?= file_exists(__DIR__ . '/../../assets/js/shop.js') ? filemtime(__DIR__ . '/../../assets/js/shop.js') : time() ?>" defer></script>

</body>
</html>
<?php if (function_exists('app_finish_response')) app_finish_response(); ?>
