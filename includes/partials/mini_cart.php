<?php
/**
 * Mini-Cart Slide-out Drawer Partial (Phase B).
 */
?>
<!-- Mini-Cart Drawer Backdrop -->
<div class="mini-cart-backdrop drawer-backdrop" id="miniCartBackdrop" data-drawer="miniCartDrawer"></div>

<!-- Mini-Cart Slide-out Drawer -->
<div class="shop-drawer drawer-right mini-cart-drawer" id="miniCartDrawer" role="dialog" aria-modal="true" aria-labelledby="miniCartTitle" aria-hidden="true">
  <div class="drawer-header">
    <h3 class="drawer-title d-flex align-items-center gap-2" id="miniCartTitle">
      <?= icon('bag') ?> <span>Shopping Bag</span>
    </h3>
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
      <span class="fw-bold price-current" id="miniCartSubtotal"><?= e(price(0)) ?></span>
    </div>
    <div class="d-flex flex-column gap-2">
      <a href="<?= e(route('checkout')) ?>" class="btn btn-primary btn-w-full">
        Proceed to Checkout
      </a>
      <a href="<?= e(route('cart')) ?>" class="btn btn-secondary btn-w-full">
        View Shopping Bag
      </a>
    </div>
    <p class="text-muted text-center mt-3 mb-0" style="font-size: var(--fs-xs);">Shipping & taxes calculated at checkout.</p>
  </div>
</div>
