<?php
/**
 * Mini-Cart Slide-out Drawer Partial (Webflow Shop Style).
 * Pre-rendered server-side for zero-delay, instantaneous cart toggle without skeleton delay.
 */
$miniCart = cart_load();
$miniCartItems = $miniCart['items'] ?? [];
$miniCartSubtotal = $miniCart['subtotal'] ?? 0;
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
    <?php if (empty($miniCartItems)): ?>
      <div class="mini-cart-empty">
        <div class="empty-icon"><?= icon('bag') ?></div>
        <div class="empty-title">Your cart is empty</div>
        <p class="empty-text">Discover our latest essentials and find something you love.</p>
        <a href="<?= e(route('shop')) ?>" class="btn btn-outline-dark btn-sm mt-2">
          Continue Shopping
        </a>
      </div>
    <?php else: ?>
      <div class="mini-cart-list">
        <?php foreach ($miniCartItems as $item): ?>
          <div class="mini-cart-item" data-cart-key="<?= e($item['cart_key']) ?>">
            <a href="<?= e(route('product', ['slug' => $item['product']['slug']])) ?>" class="item-img-link">
              <img src="<?= e(product_image_url($item['image'])) ?>" alt="<?= e($item['product_name']) ?>" class="item-thumb" loading="lazy">
            </a>
            <div class="item-details">
              <div class="item-header">
                <a href="<?= e(route('product', ['slug' => $item['product']['slug']])) ?>" class="item-name">
                  <?= e($item['product_name']) ?>
                </a>
                <button type="button" class="item-remove-btn" data-cart-remove="<?= e($item['cart_key']) ?>" aria-label="Remove item">
                  <?= icon('x') ?>
                </button>
              </div>
              <?php if (!empty($item['variant_label'])): ?>
                <div class="item-variant"><?= e($item['variant_label']) ?></div>
              <?php endif; ?>
              <div class="item-footer">
                <div class="item-qty-stepper">
                  <button type="button" class="qty-btn" data-qty-change="-1" data-cart-key="<?= e($item['cart_key']) ?>" aria-label="Decrease quantity">&minus;</button>
                  <input type="number" class="qty-input" value="<?= (int)$item['qty'] ?>" min="1" max="<?= (int)$item['available_qty'] ?>" data-cart-key="<?= e($item['cart_key']) ?>" readonly>
                  <button type="button" class="qty-btn" data-qty-change="1" data-cart-key="<?= e($item['cart_key']) ?>" aria-label="Increase quantity" <?= $item['qty'] >= $item['available_qty'] ? 'disabled' : '' ?>>&plus;</button>
                </div>
                <div class="item-price">
                  <?= e(price($item['line_total'])) ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="drawer-footer">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <span class="fw-semibold">Subtotal</span>
      <span class="fw-bold price-current" id="miniCartSubtotal" style="font-family: var(--font-mono); font-size: 17px;"><?= e(price($miniCartSubtotal)) ?></span>
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
