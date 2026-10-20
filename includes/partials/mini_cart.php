<?php
/**
 * Mini-Cart Slide-out Drawer Partial (Webflow Shop Style).
 */
?>
<!-- Mini-Cart Drawer Backdrop -->
<div class="mini-cart-backdrop drawer-backdrop" id="miniCartBackdrop" data-drawer="miniCartDrawer"></div>

<!-- Mini-Cart Slide-out Drawer -->
<div class="shop-drawer drawer-right mini-cart-drawer" id="miniCartDrawer" role="dialog" aria-modal="true" aria-labelledby="miniCartTitle" aria-hidden="true">
  <div class="drawer-header">
    <h3 class="drawer-title" id="miniCartTitle">Your cart</h3>
    <button type="button" class="drawer-close" data-drawer-close data-cart-drawer-close aria-label="Close cart">
      <?= icon('x') ?>
    </button>
  </div>
  <div class="drawer-body" id="miniCartBody">
    <div class="text-center py-5">
      <div class="skeleton skeleton-text" style="width: 60%; margin: 0 auto;"></div>
    </div>
  </div>
  <div class="drawer-footer">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <span class="fw-semibold">Subtotal</span>
      <span class="fw-bold price-current" id="miniCartSubtotal" style="font-family: var(--font-mono); font-size: 17px;"><?= e(price(0)) ?></span>
    </div>
    <div class="d-flex flex-column gap-2">
      <a href="<?= e(route('checkout')) ?>" class="btn btn-primary btn-w-full" style="border-radius: var(--radius-pill); font-weight: 600; padding: 13px 20px;">
        Continue to checkout <?= icon('arrow-right') ?>
      </a>
      <a href="<?= e(route('cart')) ?>" class="btn btn-secondary btn-w-full" style="border-radius: var(--radius-pill); font-weight: 550; padding: 11px 20px;">
        View Shopping Bag
      </a>
    </div>
    <p class="text-muted text-center mt-3 mb-0" style="font-size: 11px;">Shipping & taxes calculated at checkout.</p>
  </div>
</div>

