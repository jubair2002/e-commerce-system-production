<?php
/**
 * Product Detail Page (Prompt 04).
 * Editorial, restrained, responsive.
 * Features:
 * - Vertical desktop / horizontal mobile thumbnail gallery
 * - Click-to-zoom vanilla lightbox
 * - Dynamic option pickers (Buttons for size, Swatches for color, Dropdowns for others)
 * - Live real-time availability sync (Prompt 03 availability.js integration)
 * - Quantity stepper + Add to Cart + Buy Now
 * - Delivery estimate from shipping_methods
 * - Accordion tabs (Description, Specifications, Shipping & Returns)
 * - Related products & Recently viewed section
 * - JSON-LD Schema & OpenGraph meta tags
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    header('Location: ' . shop_url('shop.php'));
    exit;
}

$pdo = db();

try {
    $stmt = $pdo->prepare(
        "SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.slug = :slug AND p.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug]);
    $product = $stmt->fetch();
} catch (Throwable $e) {
    $product = false;
}

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product Not Found';
    include __DIR__ . '/../../includes/partials/header.php';
    ?>
    <section class="section">
      <div class="container">
        <div class="empty-state-card">
          <div class="empty-state-icon"><i class="bi bi-box-seam"></i></div>
          <h2>Product Not Found</h2>
          <p>The product you are looking for has been moved or is no longer available.</p>
          <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary">
            Browse All Products
          </a>
        </div>
      </div>
    </section>
    <?php
    include __DIR__ . '/../../includes/partials/footer.php';
    exit;
}

$productId  = (int)$product['id'];
$pageTitle  = $product['name'];
$siteName   = setting('site_name', APP_NAME);

$onSale     = product_is_on_sale($product);
$discount   = $onSale ? product_discount_percent($product) : 0;
$isVariable = ($product['product_type'] === 'variable');
$options    = $isVariable ? product_options_get($productId) : [];
$variants   = $isVariable ? variants_get($productId, true) : [];

// Gallery Images
$gallery = product_gallery_images($productId);
if (empty($gallery) && !empty($product['image'])) {
    $gallery = [
        [
            'filename'   => $product['image'],
            'alt_text'   => $product['name'],
            'is_primary' => 1,
        ]
    ];
}
$mainImgFile = $gallery[0]['filename'] ?? $product['image'];
$mainImgUrl  = product_image_url($mainImgFile);

// Stock & Delivery
$currentStock = product_available_qty($productId);
$inStock      = $currentStock > 0;
$deliveryEst  = shipping_delivery_estimate();

// Related Products (same category)
$related = [];
if (!empty($product['category_id'])) {
    try {
        $stmtRel = $pdo->prepare(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.category_id = :cid
               AND p.id <> :pid
               AND p.status = 'active'
             ORDER BY p.created_at DESC
             LIMIT 4"
        );
        $stmtRel->execute([':cid' => $product['category_id'], ':pid' => $productId]);
        $related = $stmtRel->fetchAll();
        if (!empty($related)) {
            products_attach_card_data($related);
        }
    } catch (Throwable $e) { $related = []; }
}

include __DIR__ . '/../../includes/partials/header.php';
?>

<!-- OpenGraph & SEO JSON-LD Tags -->
<meta property="og:title" content="<?= e($product['name']) ?> · <?= e($siteName) ?>">
<meta property="og:description" content="<?= e(strip_tags($product['short_description'] ?: $product['description'] ?: '')) ?>">
<meta property="og:image" content="<?= e($mainImgUrl) ?>">
<meta property="og:url" content="<?= e(shop_url('product.php?slug=' . urlencode($product['slug']))) ?>">
<meta property="og:type" content="product">

<script type="application/ld+json">
{
  "@context": "https://schema.org/",
  "@type": "Product",
  "name": <?= json_encode($product['name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  "image": <?= json_encode($mainImgUrl, JSON_UNESCAPED_SLASHES) ?>,
  "description": <?= json_encode(strip_tags($product['short_description'] ?: $product['description'] ?: ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  "sku": <?= json_encode($product['sku'] ?? '', JSON_UNESCAPED_SLASHES) ?>,
  "offers": {
    "@type": "Offer",
    "priceCurrency": <?= json_encode(setting('currency_code', 'BDT')) ?>,
    "price": "<?= number_format(product_effective_price($product), 2, '.', '') ?>",
    "availability": "https://schema.org/<?= $inStock ? 'InStock' : 'OutOfStock' ?>",
    "url": <?= json_encode(shop_url('product.php?slug=' . urlencode($product['slug'])), JSON_UNESCAPED_SLASHES) ?>
  }
}
</script>

<section class="section py-4"
         data-product-availability
         data-product-id="<?= $productId ?>"
         data-currency-symbol="<?= e(setting('currency_symbol', '')) ?>"
         data-poll-seconds="<?= (int)setting('realtime_poll_seconds', '10') ?>"
         data-realtime-mode="<?= e(setting('realtime_mode', 'poll')) ?>"
         data-uploads-url="<?= e(UPLOADS_URL . 'products/') ?>">
  <div class="container">

    <!-- Screen Reader Live Announcer -->
    <div id="availability-announcer" class="visually-hidden" aria-live="polite"></div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="pdp-breadcrumb">
      <a href="<?= e(shop_url()) ?>">Home</a> &nbsp;/&nbsp;
      <a href="<?= e(shop_url('shop.php')) ?>">Shop</a>
      <?php if (!empty($product['category_name'])): ?>
        &nbsp;/&nbsp; <a href="<?= e(shop_url('category.php?slug=' . urlencode($product['category_slug']))) ?>"><?= e($product['category_name']) ?></a>
      <?php endif; ?>
      &nbsp;/&nbsp; <span class="text-dark"><?= e($product['name']) ?></span>
    </nav>

    <!-- Main PDP Grid (Gallery + Details) -->
    <div class="pdp-grid">

      <!-- Left Column: Gallery -->
      <div class="pdp-gallery-wrap">

        <!-- Thumbnails Column -->
        <?php if (count($gallery) > 1): ?>
          <div class="gallery-thumbs-col" role="tablist" aria-label="Product thumbnails">
            <?php foreach ($gallery as $idx => $gImg): ?>
              <?php $thumbUrl = product_image_url($gImg['filename']); ?>
              <button type="button"
                      class="gallery-thumb-btn <?= $idx === 0 ? 'active' : '' ?>"
                      data-full-img="<?= e($thumbUrl) ?>"
                      role="tab"
                      aria-label="View image <?= $idx + 1 ?>">
                <img src="<?= e($thumbUrl) ?>" alt="<?= e($gImg['alt_text'] ?: $product['name']) ?>" loading="lazy">
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Main Image Viewport -->
        <div class="gallery-main-viewport" id="mainGalleryViewport" title="Click to zoom">
          <img src="<?= e($mainImgUrl) ?>"
               id="mainGalleryImg"
               alt="<?= e($gallery[0]['alt_text'] ?? $product['name']) ?>"
               loading="eager"
               width="600"
               height="750">
        </div>

      </div>

      <!-- Right Column: Product Info & Purchase Actions -->
      <div class="pdp-info-col">

        <h1 class="pdp-title"><?= e($product['name']) ?></h1>

        <!-- Price Block -->
        <div class="pdp-price-row">
          <span class="pdp-current-price <?= $onSale ? 'is-sale' : '' ?>" id="pdpPrice">
            <?= e(price(product_effective_price($product))) ?>
          </span>
          <span class="pdp-old-price" id="pdpOldPrice" style="<?= $onSale ? '' : 'display:none;' ?>">
            <?= e(price((float)$product['price'])) ?>
          </span>
          <?php if ($onSale && $discount > 0): ?>
            <span class="pdp-sale-tag" id="pdpSaleBadge">&minus;<?= $discount ?>%</span>
          <?php endif; ?>
        </div>

        <!-- Short Description -->
        <?php if (!empty($product['short_description'])): ?>
          <div class="pdp-short-desc">
            <?= nl2br(e($product['short_description'])) ?>
          </div>
        <?php endif; ?>

        <!-- Form for Add to Cart / Buy Now -->
        <form method="post" action="<?= e(shop_url('cart.php')) ?>" id="pdpAddToCartForm">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?= $productId ?>">
          <input type="hidden" name="variant_id" id="selectedVariantId" value="">

          <!-- Option Pickers (Size, Color, etc.) -->
          <?php if ($isVariable && !empty($options)): ?>
            <div class="pdp-options-wrap">
              <?php foreach ($options as $opt): ?>
                <?php
                $optName     = $opt['name'];
                $displayType = $opt['display_type']; // 'button', 'color', 'dropdown'
                $isColor     = ($displayType === 'color' || strtolower($optName) === 'color' || strtolower($optName) === 'colour');
                ?>
                <div class="option-group" data-option-id="<?= (int)$opt['id'] ?>">
                  <div class="option-group-label">
                    <span><?= e($optName) ?></span>
                    <span class="option-selected-val" id="selectedValLabel_<?= (int)$opt['id'] ?>">Select <?= e($optName) ?></span>
                  </div>

                  <div class="option-values-row">
                    <?php if ($displayType === 'dropdown'): ?>
                      <select class="form-select option-dropdown-select option-select"
                              data-option-id="<?= (int)$opt['id'] ?>"
                              aria-label="<?= e($optName) ?>">
                        <option value="">Choose <?= e($optName) ?>...</option>
                        <?php foreach ($opt['values'] as $val): ?>
                          <option value="<?= (int)$val['id'] ?>"><?= e($val['value']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php elseif ($isColor): ?>
                      <?php foreach ($opt['values'] as $val): ?>
                        <button type="button"
                                class="opt-swatch option-btn option-picker-btn"
                                data-option-id="<?= (int)$opt['id'] ?>"
                                data-value-id="<?= (int)$val['id'] ?>"
                                data-value-name="<?= e($val['value']) ?>"
                                style="background-color: <?= e($val['swatch_hex'] ?: '#e0e0e0') ?>"
                                title="<?= e($val['value']) ?>"
                                aria-label="<?= e($val['value']) ?>">
                        </button>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <?php foreach ($opt['values'] as $val): ?>
                        <button type="button"
                                class="opt-btn option-btn option-picker-btn"
                                data-option-id="<?= (int)$opt['id'] ?>"
                                data-value-id="<?= (int)$val['id'] ?>"
                                data-value-name="<?= e($val['value']) ?>">
                          <?= e($val['value']) ?>
                        </button>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- Live Availability Status Line -->
          <div class="pdp-stock-status" id="stockStatusContainer">
            <span class="status-dot in_stock" id="stockStatusDot"></span>
            <span id="stockStatusText">
              <?php if ($inStock): ?>
                In stock &bull; Ready to ship
              <?php else: ?>
                Out of stock
              <?php endif; ?>
            </span>
          </div>

          <!-- Just Sold Out Notice -->
          <div class="just-sold-out-alert" id="justSoldOutAlert" role="alert">
            <i class="bi bi-exclamation-circle me-1"></i>
            This combination just sold out while you were looking.
          </div>

          <!-- Quantity Stepper & CTA Buttons -->
          <div class="pdp-purchase-actions">
            <div class="pdp-qty-row">
              <div class="pdp-stepper">
                <button type="button" class="step-btn" id="pdpQtyDec" aria-label="Decrease quantity">&minus;</button>
                <input type="number"
                       name="quantity"
                       id="pdpQtyInput"
                       class="step-input"
                       value="1"
                       min="1"
                       max="<?= $currentStock > 0 ? $currentStock : 99 ?>"
                       aria-label="Quantity">
                <button type="button" class="step-btn" id="pdpQtyInc" aria-label="Increase quantity">&plus;</button>
              </div>

              <!-- Add to Cart -->
              <button type="submit"
                      name="action_type"
                      value="add_to_cart"
                      class="btn btn-editorial-primary pdp-add-btn flex-grow-1"
                      id="pdpAddBtn"
                      <?= !$inStock ? 'disabled' : '' ?>>
                <i class="bi bi-bag me-1"></i> Add to Cart
              </button>

              <!-- Wishlist Heart Button -->
              <button type="button"
                      class="btn-wishlist pdp-wishlist-btn flex-shrink-0"
                      data-wishlist-btn="<?= (int)$product['id'] ?>"
                      style="width: 44px; height: 44px; font-size: 19px;"
                      aria-label="Save to wishlist"
                      title="Save to wishlist">
                <i class="bi bi-heart"></i>
              </button>
            </div>

            <!-- Buy Now Button (Direct to Checkout) -->
            <button type="submit"
                    name="action_type"
                    value="buy_now"
                    class="btn btn-editorial-secondary pdp-buy-now-btn"
                    id="pdpBuyNowBtn"
                    <?= !$inStock ? 'disabled' : '' ?>>
              Buy Now with COD
            </button>
          </div>

        </form>

        <!-- Meta Strip: SKU & Delivery -->
        <div class="pdp-meta-strip">
          <div class="pdp-meta-item">
            <i class="bi bi-upc-scan"></i>
            <span>SKU: <code id="pdpSku"><?= e($product['sku'] ?: '—') ?></code></span>
          </div>
          <div class="pdp-meta-item">
            <i class="bi bi-truck"></i>
            <span>Estimated Delivery: <strong><?= e($deliveryEst) ?></strong> nationwide</span>
          </div>
          <div class="pdp-meta-item">
            <i class="bi bi-shield-check"></i>
            <span>Payment: <strong>Cash on Delivery (COD)</strong> at doorstep</span>
          </div>
        </div>

        <!-- Accordion Tabs -->
        <div class="pdp-accordions">
          <!-- Tab 1: Description -->
          <div class="pdp-accordion-item">
            <button type="button" class="accordion-trigger" aria-expanded="true">
              <span>Product Description</span>
              <i class="bi bi-chevron-down"></i>
            </button>
            <div class="accordion-panel show">
              <?= nl2br(e($product['description'] ?: $product['short_description'] ?: 'No detailed description available.')) ?>
            </div>
          </div>

          <!-- Tab 2: Specifications -->
          <div class="pdp-accordion-item">
            <button type="button" class="accordion-trigger" aria-expanded="false">
              <span>Details &amp; Specifications</span>
              <i class="bi bi-chevron-down"></i>
            </button>
            <div class="accordion-panel">
              <ul class="list-unstyled mb-0 d-flex flex-column gap-2 small">
                <li><strong>Product Type:</strong> <?= ucfirst($product['product_type']) ?></li>
                <?php if (!empty($product['category_name'])): ?>
                  <li><strong>Category:</strong> <?= e($product['category_name']) ?></li>
                <?php endif; ?>
                <li><strong>Inventory Tracking:</strong> <?= !empty($product['track_inventory']) ? 'Live Database Monitored' : 'Available' ?></li>
                <li><strong>Country of Origin:</strong> Locally sourced &amp; quality inspected</li>
              </ul>
            </div>
          </div>

          <!-- Tab 3: Shipping & Returns -->
          <div class="pdp-accordion-item">
            <button type="button" class="accordion-trigger" aria-expanded="false">
              <span>Shipping &amp; Returns</span>
              <i class="bi bi-chevron-down"></i>
            </button>
            <div class="accordion-panel">
              <p class="small mb-2">
                We offer seamless Cash on Delivery across the country. You can inspect your package upon delivery before completing payment.
              </p>
              <p class="small mb-0">
                If there are any issues with size, fit, or defects, contact customer service within 3 days for an immediate replacement or return.
              </p>
            </div>
          </div>
        </div>

      </div>

    </div>

    <!-- Related Products Section -->
    <?php if (!empty($related)): ?>
      <section class="section pt-5 border-top">
        <div class="section-header mb-4">
          <span class="section-eyebrow">Complementary</span>
          <h2 class="section-title">You Might Also Like</h2>
        </div>
        <div class="row g-4 row-cols-2 row-cols-md-3 row-cols-lg-4">
          <?php foreach ($related as $p): ?>
            <div class="col">
              <?php include __DIR__ . '/../../includes/partials/product_card.php'; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Recently Viewed Section (populated dynamically via localStorage + API) -->
    <section class="recently-viewed-section" id="recentlyViewedSection" style="display:none;">
      <div class="section-header mb-4">
        <span class="section-eyebrow">History</span>
        <h2 class="section-title">Recently Viewed</h2>
      </div>
      <div class="row g-4 row-cols-2 row-cols-md-3 row-cols-lg-4" id="recentlyViewedGrid"></div>
    </section>

  </div>
</section>

<!-- Vanilla Lightbox Modal -->
<div class="lightbox-modal" id="lightboxModal" role="dialog" aria-modal="true" aria-label="Zoom image">
  <button type="button" class="lightbox-close" id="lightboxClose" aria-label="Close zoom">
    <i class="bi bi-x-lg"></i>
  </button>
  <img src="<?= e($mainImgUrl) ?>" id="lightboxImg" class="lightbox-img" alt="Zoomed product view">
</div>

<!-- Prompt 03 Real-Time Availability Client Module -->
<script src="<?= e(url('assets/js/availability.js')) ?>"></script>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
