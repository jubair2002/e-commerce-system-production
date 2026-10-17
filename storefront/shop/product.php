<?php
/**
 * Product Detail Page (Phase C).
 * Editorial, restrained, modern, responsive.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    header('Location: ' . route('shop'));
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
    <section class="section-py">
      <div class="container">
        <?php
        $emptyIcon = 'bag';
        $emptyTitle = 'Product Not Found';
        $emptyMessage = 'The product you are looking for has been moved or is no longer available.';
        $emptyActionUrl = route('shop');
        $emptyActionLabel = 'Browse All Products';
        include __DIR__ . '/../../includes/partials/empty_state.php';
        ?>
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

// Reviews Data
$reviews = [];
$ratingCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$avgRating = (float)($product['rating_avg'] ?? 0.0);
$totalReviews = (int)($product['rating_count'] ?? 0);

try {
    $stmtRev = $pdo->prepare("
        SELECT * FROM product_reviews 
        WHERE product_id = ? AND status = 'approved' 
        ORDER BY id DESC
    ");
    $stmtRev->execute([$productId]);
    $reviews = $stmtRev->fetchAll();
    foreach ($reviews as $rev) {
        $rVal = min(5, max(1, (int)$rev['rating']));
        $ratingCounts[$rVal]++;
    }
    if ($totalReviews === 0 && !empty($reviews)) {
        $totalReviews = count($reviews);
        $avgRating = array_sum(array_column($reviews, 'rating')) / $totalReviews;
    }
} catch (Throwable $e) {}

// Size guide content from pages table
$sizeGuideSlug = setting('size_guide_page_slug', 'size-guide');
$sizeGuidePage = null;
if (!empty($sizeGuideSlug)) {
    try {
        $stSg = $pdo->prepare("SELECT title, content FROM pages WHERE slug = ? AND status = 'published' LIMIT 1");
        $stSg->execute([$sizeGuideSlug]);
        $sizeGuidePage = $stSg->fetch();
    } catch (Throwable $e) {}
}

// BD Districts data
$bdDistricts = [];
$bdDistrictsFile = __DIR__ . '/../../includes/data/bd_districts.php';
if (file_exists($bdDistrictsFile)) {
    $bdDistricts = require $bdDistrictsFile;
}

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

// Breadcrumbs
$breadcrumbs = [
    ['label' => 'Shop', 'url' => route('shop')],
];
if (!empty($product['category_name']) && !empty($product['category_slug'])) {
    $breadcrumbs[] = ['label' => $product['category_name'], 'url' => route('category', ['slug' => $product['category_slug']])];
}
$breadcrumbs[] = ['label' => $product['name'], 'url' => null];

include __DIR__ . '/../../includes/partials/header.php';
?>

<!-- JSON-LD Structured Data -->
<script type="application/ld+json">
{
  "@context": "https://schema.org/",
  "@type": "Product",
  "name": <?= json_encode($product['name']) ?>,
  "image": <?= json_encode($mainImgUrl) ?>,
  "description": <?= json_encode(strip_tags($product['short_description'] ?: $product['description'] ?: '')) ?>,
  "sku": <?= json_encode($product['sku'] ?: (string)$productId) ?>,
  "offers": {
    "@type": "Offer",
    "url": <?= json_encode(route('product', ['slug' => $product['slug']])) ?>,
    "priceCurrency": "BDT",
    "price": <?= json_encode((float)($onSale ? $product['sale_price'] : $product['price'])) ?>,
    "availability": "<?= $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' ?>",
    "itemCondition": "https://schema.org/NewCondition"
  }
  <?php if ($totalReviews > 0): ?>,
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "<?= number_format($avgRating, 1) ?>",
    "reviewCount": "<?= $totalReviews ?>"
  }
  <?php endif; ?>
}
</script>

<section class="section-py pt-4">
  <div class="container">

    <!-- Breadcrumb Navigation -->
    <?php include __DIR__ . '/../../includes/partials/breadcrumb.php'; ?>

    <div class="row g-6">

      <!-- Gallery Column -->
      <div class="col-lg-7">
        <div class="product-gallery">
          <!-- Thumbnails -->
          <?php if (count($gallery) > 1): ?>
            <div class="gallery-thumbs" aria-label="Product thumbnails">
              <?php foreach ($gallery as $idx => $img): ?>
                <?php $thumbUrl = product_image_url($img['filename']); ?>
                <button type="button"
                        class="thumb-btn <?= $idx === 0 ? 'is-active' : '' ?>"
                        data-gallery-thumb="<?= e($thumbUrl) ?>"
                        data-thumb-index="<?= $idx ?>"
                        aria-label="View image <?= $idx + 1 ?>">
                  <img src="<?= e($thumbUrl) ?>" alt="" loading="lazy">
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- Main Hero Image -->
          <div class="gallery-main" id="mainGalleryContainer">
            <img src="<?= e($mainImgUrl) ?>"
                 id="mainProductImage"
                 alt="<?= e($product['name']) ?>"
                 loading="eager"
                 fetchpriority="high"
                 data-zoom-src="<?= e($mainImgUrl) ?>">

            <?php if ($onSale && $discount > 0): ?>
              <span class="badge badge-sale position-absolute" style="top: 16px; left: 16px;">
                &minus;<?= $discount ?>% OFF
              </span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Buy Box Column -->
      <div class="col-lg-5">
        <div class="sticky-buy-box">
          
          <?php if (!empty($product['category_name'])): ?>
            <div class="text-muted small text-uppercase fw-semibold mb-1" style="letter-spacing: 0.05em;">
              <a href="<?= e(route('category', ['slug' => $product['category_slug']])) ?>" class="text-muted">
                <?= e($product['category_name']) ?>
              </a>
            </div>
          <?php endif; ?>

          <h1 class="display-serif fs-2xl mb-2"><?= e($product['name']) ?></h1>

          <!-- Rating Stars & Summary -->
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="rating-summary" title="<?= number_format($avgRating, 1) ?> out of 5 stars">
              <div class="rating-stars" aria-hidden="true">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <?= icon($avgRating >= $i ? 'star' : 'star-empty') ?>
                <?php endfor; ?>
              </div>
              <span class="rating-score"><?= number_format($avgRating, 1) ?></span>
              <a href="#reviewsSection" class="text-muted small text-decoration-underline">(<?= $totalReviews ?> review<?= $totalReviews === 1 ? '' : 's' ?>)</a>
            </div>
            <?php if ($sizeGuidePage): ?>
              <button type="button" class="btn btn-ghost btn-sm text-decoration-underline p-0" data-modal-open="sizeGuideModal">
                Size Guide
              </button>
            <?php endif; ?>
          </div>

          <!-- Price Presentation -->
          <div class="price-wrap mb-4">
            <?php if ($onSale): ?>
              <span class="price-current fs-xl text-sale" id="pdpPrice"><?= e(price((float)$product['sale_price'])) ?></span>
              <span class="price-strike fs-md" id="pdpOldPrice"><?= e(price((float)$product['price'])) ?></span>
              <span class="badge badge-sale">&minus;<?= $discount ?>%</span>
            <?php else: ?>
              <span class="price-current fs-xl" id="pdpPrice"><?= e(price((float)$product['price'])) ?></span>
            <?php endif; ?>
          </div>

          <?php if (!empty($product['short_description'])): ?>
            <p class="text-ink-2 mb-4 small" style="line-height: 1.6;">
              <?= e($product['short_description']) ?>
            </p>
          <?php endif; ?>

          <!-- Purchase Form -->
          <form method="post" action="<?= e(route('cart')) ?>" id="pdpForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="product_id" value="<?= $productId ?>">
            <input type="hidden" name="variant_id" id="selectedVariantId" value="">

            <!-- Variable Option Pickers -->
            <?php if ($isVariable && !empty($options)): ?>
              <div class="pdp-options-wrapper mb-4">
                <?php foreach ($options as $opt): ?>
                  <?php
                  $optNameLower = strtolower($opt['name']);
                  $isColor = in_array($optNameLower, ['color', 'colour']) || ($opt['display_type'] ?? '') === 'color';
                  ?>
                  <div class="form-group mb-3">
                    <label class="form-label d-flex justify-content-between">
                      <span><?= e($opt['name']) ?>: <strong id="selectedLabel_<?= (int)$opt['id'] ?>">—</strong></span>
                    </label>
                    <div class="swatch-group">
                      <?php foreach ($opt['values'] as $val): ?>
                        <?php if ($isColor): ?>
                          <button type="button"
                                  class="swatch-color-circle option-picker-btn"
                                  data-option-id="<?= (int)$opt['id'] ?>"
                                  data-value-id="<?= (int)$val['id'] ?>"
                                  data-value-name="<?= e($val['value']) ?>"
                                  style="background-color: <?= e($val['swatch_hex'] ?: '#e0e0e0') ?>;"
                                  title="<?= e($val['value']) ?>"
                                  aria-label="<?= e($val['value']) ?>">
                          </button>
                        <?php else: ?>
                          <button type="button"
                                  class="swatch-btn option-picker-btn"
                                  data-option-id="<?= (int)$opt['id'] ?>"
                                  data-value-id="<?= (int)$val['id'] ?>"
                                  data-value-name="<?= e($val['value']) ?>">
                            <?= e($val['value']) ?>
                          </button>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <!-- Live Availability Status Line -->
            <div class="d-flex align-items-center gap-2 mb-3 small" id="stockStatusContainer">
              <span class="badge <?= $inStock ? 'badge-stock' : 'badge-outline' ?>" id="stockStatusBadge">
                <?= $inStock ? '● In Stock' : 'Out of Stock' ?>
              </span>
              <span class="text-muted" id="stockStatusText">
                <?= $inStock ? 'Ready for fast dispatch' : 'Currently sold out' ?>
              </span>
            </div>

            <!-- Actions: Stepper + Add to Cart + Wishlist -->
            <div class="d-flex gap-2 mb-3">
              <div class="quantity-stepper">
                <button type="button" class="stepper-btn" id="pdpQtyDec" aria-label="Decrease quantity">−</button>
                <input type="number" name="quantity" id="pdpQtyInput" class="stepper-input" value="1" min="1" max="<?= max(1, $currentStock) ?>" aria-label="Quantity">
                <button type="button" class="stepper-btn" id="pdpQtyInc" aria-label="Increase quantity">+</button>
              </div>

              <button type="submit" name="action_type" value="add_to_cart" class="btn btn-primary flex-grow-1" id="pdpAddBtn" <?= !$inStock ? 'disabled' : '' ?>>
                <?= icon('bag') ?> Add to Cart
              </button>

              <button type="button" class="btn btn-secondary btn-icon" data-wishlist-btn="<?= $productId ?>" aria-label="Save to wishlist" title="Save to wishlist">
                <?= icon('heart') ?>
              </button>
            </div>

            <button type="submit" name="action_type" value="buy_now" class="btn btn-secondary btn-w-full mb-4" id="pdpBuyNowBtn" <?= !$inStock ? 'disabled' : '' ?>>
              Buy Now with COD
            </button>
          </form>

          <!-- District Delivery Estimator -->
          <div class="card p-3 mb-4 bg-soft" style="border: 1px dashed var(--line-strong);">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="small fw-semibold d-flex align-items-center gap-1">
                <?= icon('truck') ?> Delivery Estimate
              </span>
              <span class="small text-ok fw-bold" id="districtFee">৳60</span>
            </div>
            <div class="d-flex gap-2">
              <select id="districtSelect" class="form-select form-select-sm">
                <?php foreach ($bdDistricts as $dist): ?>
                  <option value="<?= e($dist['name']) ?>" data-zone="<?= e($dist['zone']) ?>" <?= $dist['name'] === 'Dhaka' ? 'selected' : '' ?>>
                    <?= e($dist['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="small text-muted mt-2" id="districtEtaText">
              Estimated delivery: <strong>24–48 hours</strong> inside Dhaka.
            </div>
          </div>

          <!-- Product Details Accordion -->
          <div class="accordion-group mb-4">
            <div class="accordion-item is-open">
              <button type="button" class="accordion-toggle" data-accordion-toggle aria-expanded="true">
                <span>Description</span>
                <span class="accordion-icon"><?= icon('chevron-down') ?></span>
              </button>
              <div class="accordion-content" style="max-height: 500px;">
                <div class="accordion-body">
                  <?= nl2br(e($product['description'] ?: $product['short_description'] ?: 'Crafted with premium materials.')) ?>
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <button type="button" class="accordion-toggle" data-accordion-toggle aria-expanded="false">
                <span>Specifications</span>
                <span class="accordion-icon"><?= icon('chevron-down') ?></span>
              </button>
              <div class="accordion-content">
                <div class="accordion-body">
                  <ul class="d-flex flex-column gap-2">
                    <li><strong>Product Type:</strong> <?= ucfirst($product['product_type']) ?></li>
                    <li><strong>SKU:</strong> <?= e($product['sku'] ?: '—') ?></li>
                    <li><strong>Origin:</strong> Crafted & Quality Verified in Bangladesh</li>
                  </ul>
                </div>
              </div>
            </div>

            <div class="accordion-item">
              <button type="button" class="accordion-toggle" data-accordion-toggle aria-expanded="false">
                <span>Shipping &amp; Returns</span>
                <span class="accordion-icon"><?= icon('chevron-down') ?></span>
              </button>
              <div class="accordion-content">
                <div class="accordion-body">
                  We offer nationwide Cash on Delivery. Open parcel inspection is permitted upon delivery. Easy 3-day exchanges for sizing or defect issues.
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

    </div>

    <!-- =================== REVIEWS SECTION =================== -->
    <section class="section-py border-top mt-6" id="reviewsSection">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-6">
        <div>
          <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Customer Feedback</span>
          <h2 class="display-serif mt-1">Customer Reviews (<?= $totalReviews ?>)</h2>
        </div>
        <button type="button" class="btn btn-primary" data-modal-open="writeReviewModal">
          Write a Review
        </button>
      </div>

      <div class="row g-6">
        <!-- Review Breakdown Column -->
        <div class="col-lg-4">
          <div class="card p-6 bg-soft text-center">
            <div class="fs-hero fw-bold text-ink mb-1"><?= number_format($avgRating, 1) ?></div>
            <div class="rating-stars justify-content-center mb-2" aria-hidden="true">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <?= icon($avgRating >= $i ? 'star' : 'star-empty') ?>
              <?php endfor; ?>
            </div>
            <p class="small text-muted mb-4">Based on <?= $totalReviews ?> verified review<?= $totalReviews === 1 ? '' : 's' ?></p>

            <!-- Star breakdown bars -->
            <div class="d-flex flex-column gap-2 text-start">
              <?php for ($s = 5; $s >= 1; $s--): ?>
                <?php $pct = $totalReviews > 0 ? round(($ratingCounts[$s] / $totalReviews) * 100) : 0; ?>
                <div class="d-flex align-items-center gap-2 small">
                  <span style="width: 50px;" class="text-muted"><?= $s ?> star</span>
                  <div class="flex-grow-1 bg-white rounded-pill overflow-hidden border" style="height: 8px;">
                    <div class="h-100 bg-dark" style="width: <?= $pct ?>%;"></div>
                  </div>
                  <span style="width: 30px;" class="text-end text-muted"><?= $pct ?>%</span>
                </div>
              <?php endfor; ?>
            </div>
          </div>
        </div>

        <!-- Reviews List Column -->
        <div class="col-lg-8">
          <?php if (empty($reviews)): ?>
            <div class="card p-6 text-center text-muted">
              <?= icon('star') ?>
              <h4 class="mt-2 mb-1">No reviews yet</h4>
              <p class="small mb-3">Be the first to share your experience with this item.</p>
              <button type="button" class="btn btn-secondary btn-sm mx-auto" data-modal-open="writeReviewModal">Write the First Review</button>
            </div>
          <?php else: ?>
            <div class="d-flex flex-column gap-4">
              <?php foreach ($reviews as $rev): ?>
                <div class="card p-4">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2">
                      <span class="fw-semibold text-ink"><?= e($rev['author_name']) ?></span>
                      <?php if (!empty($rev['order_id'])): ?>
                        <span class="badge badge-stock">Verified Buyer</span>
                      <?php endif; ?>
                    </div>
                    <span class="text-muted small"><?= e(date('M d, Y', strtotime($rev['created_at']))) ?></span>
                  </div>

                  <div class="rating-stars mb-2" aria-hidden="true">
                    <?php for ($i = 1; $i <= (int)$rev['rating']; $i++): ?>
                      <?= icon('star') ?>
                    <?php endfor; ?>
                  </div>

                  <h5 class="fw-semibold mb-2"><?= e($rev['title']) ?></h5>
                  <p class="small text-ink-2 mb-0" style="line-height: 1.6;"><?= nl2br(e($rev['body'])) ?></p>

                  <?php if (!empty($rev['admin_reply'])): ?>
                    <div class="p-3 mt-3 bg-soft rounded border-start border-3 border-dark small">
                      <div class="fw-bold mb-1">Official Response:</div>
                      <?= nl2br(e($rev['admin_reply'])) ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- Related Products -->
    <?php if (!empty($related)): ?>
      <section class="section-py pt-0 border-top mt-6">
        <div class="d-flex justify-content-between align-items-end mb-6">
          <div>
            <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Complementary</span>
            <h2 class="display-serif mt-1">You Might Also Like</h2>
          </div>
        </div>
        <div class="product-grid">
          <?php foreach ($related as $p): ?>
            <?php include __DIR__ . '/../../includes/partials/product_card.php'; ?>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

  </div>
</section>

<!-- Sticky Mobile Add to Cart Bar -->
<div class="mobile-sticky-atc" id="mobileStickyBar">
  <div class="d-flex align-items-center gap-2">
    <img src="<?= e($mainImgUrl) ?>" alt="" style="width: 44px; height: 44px; object-fit: cover; border-radius: var(--radius-sm);">
    <div>
      <div class="fw-semibold small text-truncate" style="max-width: 140px;"><?= e($product['name']) ?></div>
      <div class="fw-bold price-current small"><?= e(price((float)($onSale ? $product['sale_price'] : $product['price']))) ?></div>
    </div>
  </div>
  <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('pdpAddBtn').click();">
    <?= icon('bag') ?> Add to Cart
  </button>
</div>

<!-- Size Guide Modal -->
<?php if ($sizeGuidePage): ?>
  <div class="shop-modal" id="sizeGuideModal" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3 class="modal-title fs-base"><?= e($sizeGuidePage['title']) ?></h3>
        <button type="button" class="drawer-close" data-modal-close aria-label="Close size guide">
          <?= icon('x') ?>
        </button>
      </div>
      <div class="modal-body content-readable-column">
        <?= $sizeGuidePage['content'] ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Write Review Modal -->
<div class="shop-modal" id="writeReviewModal" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title fs-base">Write a Review for <?= e($product['name']) ?></h3>
      <button type="button" class="drawer-close" data-modal-close aria-label="Close review modal">
        <?= icon('x') ?>
      </button>
    </div>
    <form id="writeReviewForm" method="post" action="<?= e(url('api/review.php')) ?>" class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="product_id" value="<?= $productId ?>">
      <input type="hidden" name="form_time" value="<?= time() ?>">
      <div style="display:none;"><input type="text" name="website_hp" value=""></div>

      <div class="form-group mb-3">
        <label class="form-label">Overall Rating <span class="text-danger">*</span></label>
        <div class="d-flex gap-2">
          <?php for ($s = 5; $s >= 1; $s--): ?>
            <label class="d-flex align-items-center gap-1 small border p-2 rounded cursor-pointer">
              <input type="radio" name="rating" value="<?= $s ?>" <?= $s === 5 ? 'checked' : '' ?>>
              <span><?= $s ?> <?= icon('star') ?></span>
            </label>
          <?php endfor; ?>
        </div>
      </div>

      <div class="form-group mb-3">
        <label for="reviewAuthor" class="form-label">Your Name</label>
        <input type="text" name="author_name" id="reviewAuthor" class="form-control" value="<?= e(function_exists('customer_current') && customer_current() ? customer_current()['name'] : '') ?>" placeholder="e.g. John Doe">
      </div>

      <div class="form-group mb-3">
        <label for="reviewTitle" class="form-label">Review Headline <span class="text-danger">*</span></label>
        <input type="text" name="title" id="reviewTitle" class="form-control" placeholder="e.g. Great quality and fits perfectly!" required maxlength="120">
      </div>

      <div class="form-group mb-4">
        <label for="reviewBody" class="form-label">Review Description <span class="text-danger">*</span></label>
        <textarea name="body" id="reviewBody" class="form-control" rows="4" placeholder="What did you like or dislike? How was the fit and material?" required minlength="10" maxlength="2000"></textarea>
      </div>

      <button type="submit" class="btn btn-primary btn-w-full" id="submitReviewBtn">
        Submit Review for Moderation
      </button>
    </form>
  </div>
</div>

<script>
// District delivery rate & ETA calculator
document.getElementById('districtSelect')?.addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  const isDhaka = opt.getAttribute('data-zone') === 'inside_dhaka';
  const feeEl = document.getElementById('districtFee');
  const etaEl = document.getElementById('districtEtaText');
  if (isDhaka) {
    if (feeEl) feeEl.textContent = '৳60';
    if (etaEl) etaEl.innerHTML = 'Estimated delivery: <strong>24–48 hours</strong> inside Dhaka.';
  } else {
    if (feeEl) feeEl.textContent = '৳120';
    if (etaEl) etaEl.innerHTML = 'Estimated delivery: <strong>48–72 hours</strong> outside Dhaka.';
  }
});

// Review AJAX Submission
document.getElementById('writeReviewForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const btn = document.getElementById('submitReviewBtn');
  btn.disabled = true;
  btn.textContent = 'Submitting...';

  const formData = new FormData(this);
  fetch(this.action, {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.textContent = 'Submit Review for Moderation';
    if (data.success) {
      ShopUI.closeModal('writeReviewModal');
      ShopUI.toast(data.message, 'success');
      this.reset();
    } else {
      ShopUI.toast(data.error || 'Failed to submit review.', 'error');
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Submit Review for Moderation';
    ShopUI.toast('Network error. Please try again.', 'error');
  });
});
</script>

<!-- Availability Client Module -->
<script src="<?= e(url('assets/js/availability.js')) ?>"></script>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
