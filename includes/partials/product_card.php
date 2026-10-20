<?php
/**
 * Webflow Shop Style Product Card
 * Expects $p (product row) in scope.
 * 1:1 Aspect Ratio, Dual Image Swap, Webflow Blue Hover, Swatch Dots, Badges, Quick-Add & Buy Now.
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

$primaryImg   = product_image_url($p['image'] ?? null, 'thumbnail', $pid);
$fullImg      = product_image_url($p['image'] ?? null, 'full', $pid);
$secondImgRaw = array_key_exists('second_image', $p) ? $p['second_image'] : product_second_image($pid);
$secondImg    = $secondImgRaw ? product_image_url($secondImgRaw, 'thumbnail', $pid) : null;
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
         height="320"
         onerror="if (!this.dataset.fallback && this.src !== '<?= e($fullImg) ?>') { this.dataset.fallback = '1'; this.src = '<?= e($fullImg) ?>'; }">

    <!-- Secondary Hover Image (if present) -->
    <?php if ($secondImg): ?>
      <img src="<?= e($secondImg) ?>"
           alt="<?= e($p['name']) ?> - alternate view"
           class="product-card-img product-img-hover"
           loading="lazy"
           width="320"
           height="320">
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
    <h3 class="product-card-title">
      <a href="<?= e($productUrl) ?>"><?= e($p['name']) ?></a>
    </h3>

    <!-- Webflow Shop Metadata Row ($Price • Category • Badge) -->
    <div class="product-card-meta">
      <div class="price-wrap">
        <?php if ($onSale): ?>
          <span class="price-current text-sale"><?= e(price((float)$p['sale_price'])) ?></span>
          <span class="price-strike"><?= e(price((float)$p['price'])) ?></span>
        <?php else: ?>
          <span class="price-current"><?= e(price((float)$p['price'])) ?></span>
        <?php endif; ?>
      </div>

      <?php if (!empty($p['category_name'])): ?>
        <span class="meta-dot">&bull;</span>
        <span class="meta-tag"><?= e($p['category_name']) ?></span>
      <?php endif; ?>

      <?php if (!empty($p['featured'])): ?>
        <span class="meta-dot">&bull;</span>
        <span class="meta-tag">Popular</span>
      <?php elseif ($onSale): ?>
        <span class="meta-dot">&bull;</span>
        <span class="meta-tag">Sale</span>
      <?php endif; ?>
    </div>

    <!-- Swatch Dots -->
    <?php if (!empty($swatches)): ?>
      <div class="swatch-group mb-3" aria-label="Available colors">
        <?php foreach (array_slice($swatches, 0, 5) as $sw): ?>
          <span class="swatch-color-circle"
                style="background-color: <?= e($sw['swatch_hex'] ?: '#e0e0e0') ?>"
                title="<?= e($sw['value']) ?>"></span>
        <?php endforeach; ?>
        <?php if (count($swatches) > 5): ?>
          <span class="small opacity-75" style="font-size: 11px;">&plus;<?= count($swatches) - 5 ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Action Buttons & Circular Arrow -->
    <div class="product-card-footer">
      <?php if (!$isSoldOut): ?>
        <div class="product-card-actions">
          <?php if ($isSimple): ?>
            <button type="button"
                    class="btn btn-secondary btn-sm product-card-btn-add"
                    data-quick-add="<?= $pid ?>"
                    aria-label="Add <?= e($p['name']) ?> to cart">
              + Add
            </button>
            <button type="button"
                    class="btn btn-primary btn-sm product-card-btn-buy"
                    data-buy-now="<?= $pid ?>"
                    aria-label="Buy <?= e($p['name']) ?> now">
              Buy Now
            </button>
          <?php else: ?>
            <a href="<?= e($productUrl) ?>"
               class="btn btn-primary btn-sm product-card-btn-buy"
               aria-label="Select options for <?= e($p['name']) ?>">
              Options
            </a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="product-card-actions">
          <span class="badge badge-outline py-2 px-3">Sold Out</span>
        </div>
      <?php endif; ?>

      <a href="<?= e($productUrl) ?>" class="circle-btn" title="View details" aria-label="View <?= e($p['name']) ?>">
        <span class="circle-btn-arrow">&#x2197;</span>
      </a>
    </div>
  </div>
</div>

