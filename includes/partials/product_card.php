<?php
/**
 * Editorial Minimal Product Card (Prompt 04 / Phase B).
 * Expects $p (product row) in scope.
 * 4:5 Aspect Ratio, 2nd Image on Hover, Swatch Dots, Badges, Quick-Add.
 */
$pid         = (int)$p['id'];
$onSale      = product_is_on_sale($p);
$discount    = $onSale ? product_discount_percent($p) : 0;
$productType = $p['product_type'] ?? 'simple';
$isSimple    = ($productType === 'simple');
$stock       = (int)($p['stock'] ?? 0);
$lowThresh   = (int)($p['low_stock_threshold'] ?? 5);
$isSoldOut   = ($stock <= 0 && empty($p['allow_backorder']));
$isLowStock  = ($stock > 0 && $stock <= $lowThresh);

$showSaleBadge = (int)setting('shop_show_sale_badge', '1') === 1;
$showLowBadge  = (int)setting('shop_show_low_stock_badge', '1') === 1;

$primaryImg   = product_image_url($p['image'] ?? null);
$secondImgRaw = array_key_exists('second_image', $p) ? $p['second_image'] : product_second_image($pid);
$secondImg    = $secondImgRaw ? product_image_url($secondImgRaw) : null;
$swatches     = array_key_exists('swatches', $p) ? $p['swatches'] : product_color_swatches($pid);
$productUrl   = route('product', ['slug' => $p['slug']]);
?>
<div class="product-card" data-product-id="<?= $pid ?>">
  <!-- Wishlist Heart Button -->
  <button type="button"
          class="product-card-wishlist"
          data-wishlist-btn="<?= $pid ?>"
          aria-label="Save to wishlist"
          title="Save to wishlist">
    <?= icon('heart') ?>
  </button>

  <a href="<?= e($productUrl) ?>" class="product-card-media" aria-label="<?= e($p['name']) ?>">
    <!-- Primary Image -->
    <img src="<?= e($primaryImg) ?>"
         alt="<?= e($p['name']) ?>"
         class="product-card-img product-img-primary"
         loading="lazy"
         width="320"
         height="400">

    <!-- Secondary Hover Image -->
    <?php if ($secondImg): ?>
      <img src="<?= e($secondImg) ?>"
           alt="<?= e($p['name']) ?> - alternate view"
           class="product-card-img product-img-hover"
           loading="lazy"
           width="320"
           height="400">
    <?php endif; ?>

    <!-- Badges Container -->
    <div class="product-card-badge">
      <?php if ($isSoldOut): ?>
        <span class="badge badge-outline">Sold out</span>
      <?php elseif ($onSale && $showSaleBadge && $discount > 0): ?>
        <span class="badge badge-sale">&minus;<?= $discount ?>%</span>
      <?php elseif ($isLowStock && $showLowBadge): ?>
        <span class="badge badge-low-stock">Only <?= $stock ?> left</span>
      <?php endif; ?>

      <?php if (!empty($p['featured'])): ?>
        <span class="badge badge-new"><?= icon('star') ?> Featured</span>
      <?php endif; ?>
    </div>
  </a>

  <div class="product-card-body">
    <?php if (!empty($p['category_name'])): ?>
      <div class="product-card-category"><?= e($p['category_name']) ?></div>
    <?php endif; ?>

    <h3 class="product-card-title">
      <a href="<?= e($productUrl) ?>"><?= e($p['name']) ?></a>
    </h3>

    <div class="product-card-footer">
      <div class="price-wrap">
        <?php if ($onSale): ?>
          <span class="price-current text-sale"><?= e(price((float)$p['sale_price'])) ?></span>
          <span class="price-strike"><?= e(price((float)$p['price'])) ?></span>
        <?php else: ?>
          <span class="price-current"><?= e(price((float)$p['price'])) ?></span>
        <?php endif; ?>
      </div>

      <!-- Quick Add button for simple in-stock products -->
      <?php if ($isSimple && !$isSoldOut): ?>
        <button type="button"
                class="btn btn-secondary btn-sm"
                data-quick-add="<?= $pid ?>"
                aria-label="Quick add <?= e($p['name']) ?> to cart">
          + Add
        </button>
      <?php endif; ?>
    </div>

    <!-- Swatch Dots -->
    <?php if (!empty($swatches)): ?>
      <div class="swatch-group mt-2" aria-label="Available colors">
        <?php foreach (array_slice($swatches, 0, 5) as $sw): ?>
          <span class="swatch-color-circle"
                style="background-color: <?= e($sw['swatch_hex'] ?: '#e0e0e0') ?>"
                title="<?= e($sw['value']) ?>"></span>
        <?php endforeach; ?>
        <?php if (count($swatches) > 5): ?>
          <span class="small text-muted">&plus;<?= count($swatches) - 5 ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
