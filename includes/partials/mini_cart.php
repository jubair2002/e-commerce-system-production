<?php
/**
 * Mini-Cart Slide-out Drawer Partial.
 */
?>
<!-- Mini-Cart Drawer Backdrop -->
<div class="mini-cart-backdrop" id="miniCartBackdrop"></div>

<!-- Mini-Cart Slide-out Drawer -->
<div class="mini-cart-drawer" id="miniCartDrawer" role="dialog" aria-modal="true" aria-labelledby="miniCartTitle">
  <div class="drawer-header">
    <h3 class="drawer-title" id="miniCartTitle">
      <i class="bi bi-bag"></i> Your Cart
    </h3>
    <button type="button" class="drawer-close-btn" data-cart-drawer-close aria-label="Close cart">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <div class="drawer-body" id="miniCartBody">
    <div class="text-center py-5">
      <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
    </div>
  </div>
  <div class="drawer-footer">
    <div class="drawer-subtotal-row">
      <span>Subtotal</span>
      <span class="drawer-subtotal-amount" id="miniCartSubtotal"><?= e(price(0)) ?></span>
    </div>
    <div class="drawer-actions">
      <a href="<?= e(route('checkout')) ?>" class="btn btn-editorial-primary btn-full">
        Proceed to Checkout
      </a>
      <a href="<?= e(route('cart')) ?>" class="btn btn-editorial-secondary btn-full">
        View Full Cart
      </a>
    </div>
    <p class="drawer-shipping-note">Taxes and shipping calculated at checkout.</p>
  </div>
</div>
