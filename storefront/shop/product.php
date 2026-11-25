<?php
/**
 * Product Detail Page (Cozastore Edition).
 * Cozastore PDP with wrap-slick3 multi-image thumbnail gallery, Magnific Popup zoom,
 * variant option selectors, quantity controls, AJAX review form, and related products.
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
    <div class="bg0 p-t-100 p-b-140">
        <div class="container text-center">
            <i class="zmdi zmdi-alert-circle-o fs-50 cl6 d-block m-b-20" style="font-size: 60px;"></i>
            <h2 class="ltext-103 cl2 p-b-15">Product Not Found</h2>
            <p class="stext-115 cl6 p-b-30">The product you are looking for has been moved or is no longer available.</p>
            <a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-101 bg1 bor1 hov-btn1 p-lr-15 trans-04 m-auto" style="max-width: 220px;">
                Browse All Products
            </a>
        </div>
    </div>
    <?php
    include __DIR__ . '/../../includes/partials/footer.php';
    exit;
}

$productId = (int)$product['id'];
hook_fire('product.viewed', [
    'product_id' => $productId,
    'price'      => (float)($product['sale_price'] ?: $product['price']),
]);

$pageTitle       = !empty($product['meta_title']) ? $product['meta_title'] : $product['name'];
$pageDescription = !empty($product['meta_description'])
    ? $product['meta_description']
    : (!empty($product['short_description'])
        ? $product['short_description']
        : substr(strip_tags((string)($product['description'] ?? '')), 0, 160));
$pageKeywords    = !empty($product['meta_keywords'])
    ? $product['meta_keywords']
    : (!empty($product['tags']) ? $product['tags'] : '');
$siteName        = setting('site_name', APP_NAME);

$onSale     = product_is_on_sale($product);
$discount   = $onSale ? product_discount_percent($product) : 0;
$isVariable = ($product['product_type'] === 'variable');
$options    = $isVariable ? product_options_get($productId) : [];
$variants   = $isVariable ? variants_get($productId, true) : [];

// Gallery Images
$rawGallery = product_gallery_images($productId);
$gallery = [];
$seenFiles = [];

// If primary product image exists, ensure it is the first entry
if (!empty($product['image'])) {
    $gallery[] = [
        'filename'   => $product['image'],
        'alt_text'   => $product['name'],
        'is_primary' => 1,
    ];
    $seenFiles[trim((string)$product['image'])] = true;
}

// Add additional gallery images from product_images table, deduplicated
foreach ($rawGallery as $gItem) {
    $fn = trim((string)($gItem['filename'] ?? ''));
    if ($fn === '' || isset($seenFiles[$fn])) {
        continue;
    }
    $seenFiles[$fn] = true;
    $gallery[] = $gItem;
}

// Fallback to placeholder if completely empty
if (empty($gallery)) {
    $gallery[] = [
        'filename'   => null,
        'alt_text'   => $product['name'],
        'is_primary' => 1,
    ];
}

$mainImgFile = $gallery[0]['filename'] ?? ($product['image'] ?? null);
$mainImgUrl  = product_image_url($mainImgFile);

// Stock & Delivery
$stockCount = (int)($product['stock'] ?? 0);
try {
    $stmtStock = $pdo->prepare("SELECT total_stock FROM v_product_stock WHERE product_id = ? LIMIT 1");
    $stmtStock->execute([$productId]);
    $vStock = $stmtStock->fetchColumn();
    if ($vStock !== false && $vStock !== null) {
        $stockCount = max(0, (int)$vStock);
    }
} catch (Throwable $e) {}

$currentStock = $stockCount;
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

// Related Products (same category)
$related = [];
if (!empty($product['category_id'])) {
    try {
        $stmtRel = $pdo->prepare(
            "SELECT p.id, p.category_id, p.name, p.slug, p.sku, p.price, p.sale_price, p.stock, p.image,
                    p.status, p.featured, p.product_type, p.low_stock_threshold, p.allow_backorder,
                    p.track_inventory, p.rating_avg, p.rating_count, p.sold_count,
                    c.name AS category_name, c.slug AS category_slug
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

<!-- Modern PDP Stylesheet -->
<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/css/pdp-modern.css'), ENT_QUOTES, 'UTF-8') ?>">

<!-- JSON-LD Structured Data for SEO -->
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

<!-- Breadcrumb -->
<div class="container m-t-20 m-b-20">
	<div class="bread-crumb flex-w p-l-0 p-r-15 p-t-15 p-lr-0-lg">
		<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
			Home
			<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
		</a>

		<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
			Shop
			<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
		</a>

		<?php if (!empty($product['category_name']) && !empty($product['category_slug'])): ?>
			<a href="<?= htmlspecialchars(route('category', ['slug' => $product['category_slug']]), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
				<?= htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') ?>
				<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
			</a>
		<?php endif; ?>

		<span class="stext-109 cl4">
			<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>
		</span>
	</div>
</div>

<!-- =================== CLEAN & MODERN PRODUCT DETAIL SHOWCASE =================== -->
<section class="sec-product-detail bg0 p-t-20 p-b-60">
	<div class="container">
		<div class="row">
			<!-- Gallery Column (Left) -->
			<div class="col-lg-6 p-b-30">
				<div class="pdp-gallery-card">
					<div class="pdp-main-image-wrap pos-relative">
						<?php if ($onSale): ?>
							<span class="pdp-badge-discount">-<?= (int)$discount ?>% OFF</span>
						<?php endif; ?>

						<a class="pdp-zoom-btn js-gallery-zoom" href="<?= htmlspecialchars($mainImgUrl, ENT_QUOTES, 'UTF-8') ?>" title="Zoom Image">
							<i class="zmdi zmdi-fullscreen"></i>
						</a>

						<img id="pdpMainImg" src="<?= htmlspecialchars($mainImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>" class="pdp-main-img">
					</div>

					<?php if (count($gallery) > 1): ?>
						<div class="pdp-thumbnails-strip">
							<?php foreach ($gallery as $idx => $img): 
								$gUrl = product_image_url($img['filename'] ?? null, 'large', $productId);
								$tUrl = product_image_url($img['filename'] ?? null, 'thumbnail', $productId);
							?>
							<div class="pdp-thumb-item <?= $idx === 0 ? 'active' : '' ?>" data-large="<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>" onclick="switchPdpImage(this, '<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>')">
								<img src="<?= htmlspecialchars($tUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>">
							</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
				
			<!-- Product Info & Purchase Column (Right) -->
			<div class="col-lg-6 p-b-30">
				<div class="pdp-info-card">
					<?php if (!empty($product['category_name']) && !empty($product['category_slug'])): ?>
						<div class="m-b-8">
							<a href="<?= htmlspecialchars(route('category', ['slug' => $product['category_slug']]), ENT_QUOTES, 'UTF-8') ?>" class="pdp-category-pill">
								<?= htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') ?>
							</a>
						</div>
					<?php endif; ?>

					<h1 class="pdp-product-title">
						<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>
					</h1>

					<div class="pdp-meta-row">
						<div class="d-flex align-items-center">
							<span class="pdp-stars">
								<?php for ($i = 1; $i <= 5; $i++): ?>
									<i class="zmdi <?= $avgRating >= $i ? 'zmdi-star' : 'zmdi-star-outline' ?>"></i>
								<?php endfor; ?>
							</span>
							<span class="pdp-rating-num"><?= $totalReviews > 0 ? number_format($avgRating, 1) : '5.0' ?></span>
							<a href="#reviews" class="pdp-reviews-link" onclick="$('#tabLinkReviews').tab('show');">(<?= $totalReviews ?> review<?= $totalReviews === 1 ? '' : 's' ?>)</a>
						</div>

						<?php if (!empty($product['is_preorder'])): ?>
							<span class="pdp-stock-badge in-stock pre-order" id="pdpStockBadge" style="background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;">
								<i class="zmdi zmdi-time-restore m-r-4"></i> Pre-Order
							</span>
						<?php else: ?>
							<span class="pdp-stock-badge <?= $inStock ? 'in-stock' : 'out-of-stock' ?>" id="pdpStockBadge">
								<i class="zmdi <?= $inStock ? 'zmdi-check-circle' : 'zmdi-alert-circle-o' ?> m-r-4"></i>
								<?= $inStock ? 'In Stock: ' . (int)$currentStock : 'Sold Out' ?>
							</span>
						<?php endif; ?>

						<?php if (!empty($product['sku'])): ?>
							<span class="pdp-sku-badge">SKU: <strong><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></strong></span>
						<?php endif; ?>
					</div>

					<!-- Pricing Box -->
					<div class="pdp-price-box">
						<?php if ($onSale): ?>
							<span class="pdp-price-current" id="pdpPrice"><?= price((float)$product['sale_price']) ?></span>
							<span class="pdp-price-original" id="pdpOldPrice"><?= price((float)$product['price']) ?></span>
							<span class="pdp-discount-tag">Save <?= (int)$discount ?>%</span>
						<?php else: ?>
							<span class="pdp-price-current" id="pdpPrice"><?= price((float)$product['price']) ?></span>
						<?php endif; ?>
					</div>

					<?php if (!empty($product['short_description']) && trim((string)$product['short_description']) !== trim((string)$product['description'])): ?>
						<p class="pdp-short-desc">
							<?= htmlspecialchars($product['short_description'], ENT_QUOTES, 'UTF-8') ?>
						</p>
					<?php endif; ?>

					<!-- Purchase Form -->
					<form method="post" action="<?= htmlspecialchars(route('cart'), ENT_QUOTES, 'UTF-8') ?>" id="pdpAddToCartForm">
						<?= csrf_field() ?>
						<input type="hidden" name="action" value="add">
						<input type="hidden" name="product_id" value="<?= $productId ?>">
						<input type="hidden" name="variant_id" id="selectedVariantId" value="<?= ($isVariable && empty($options) && !empty($variants[0]['id'])) ? (int)$variants[0]['id'] : '' ?>">

						<!-- Variant Option Selectors -->
						<?php if ($isVariable && !empty($options)): ?>
							<div class="pdp-options-wrap m-b-20">
								<?php foreach ($options as $opt): 
									$dispType = $opt['display_type'] ?? 'button';
									$optId = (int)$opt['id'];
								?>
									<div class="pdp-option-group m-b-18" data-option-id="<?= $optId ?>">
										<div class="d-flex align-items-center justify-content-between mb-2">
											<label class="pdp-option-label fw-bold mb-0 text-dark">
												<?= htmlspecialchars($opt['name'], ENT_QUOTES, 'UTF-8') ?>:
												<span class="pdp-selected-val text-primary fw-semibold ms-1" id="opt_label_<?= $optId ?>"></span>
											</label>
										</div>

										<?php if ($dispType === 'swatch'): ?>
											<!-- Color Swatch UI -->
											<div class="pdp-swatch-list d-flex flex-wrap" style="gap: 12px; margin-top: 8px; margin-bottom: 8px;">
												<?php foreach ($opt['values'] as $val): 
													$hex = !empty($val['swatch_hex']) ? $val['swatch_hex'] : '#3b82f6';
													$isWhite = in_array(strtolower($hex), ['#fff', '#ffffff', 'white', '#fafafa', '#f8fafc', '#f1f5f9']);
												?>
													<button type="button" 
													        class="pdp-swatch-btn js-pdp-opt-btn"
													        data-option-id="<?= $optId ?>"
													        data-val-id="<?= (int)$val['id'] ?>"
													        data-val-name="<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>"
													        title="<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>"
													        style="background-color: <?= htmlspecialchars($hex, ENT_QUOTES, 'UTF-8') ?>; width: 44px; height: 44px; min-width: 44px; min-height: 44px; border-radius: 50%; <?= $isWhite ? 'border: 2px solid #cbd5e1 !important;' : '' ?>">
														<i class="zmdi zmdi-check pdp-swatch-check <?= $isWhite ? 'text-dark' : 'text-white' ?>" style="display: none; font-size: 20px;"></i>
													</button>
												<?php endforeach; ?>
											</div>
											<input type="hidden" class="option-select" data-option-id="<?= $optId ?>" value="">

										<?php elseif ($dispType === 'button'): ?>
											<!-- Buttons Pill UI -->
											<div class="pdp-pill-list d-flex flex-wrap gap-2">
												<?php foreach ($opt['values'] as $val): ?>
													<button type="button"
													        class="pdp-pill-btn js-pdp-opt-btn"
													        data-option-id="<?= $optId ?>"
													        data-val-id="<?= (int)$val['id'] ?>"
													        data-val-name="<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>">
														<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>
													</button>
												<?php endforeach; ?>
											</div>
											<input type="hidden" class="option-select" data-option-id="<?= $optId ?>" value="">

										<?php elseif ($dispType === 'radio'): ?>
											<!-- Radio UI -->
											<div class="pdp-radio-list d-flex flex-wrap gap-3">
												<?php foreach ($opt['values'] as $val): ?>
													<label class="pdp-radio-pill d-inline-flex align-items-center gap-1 cursor-pointer">
														<input type="radio" 
														       name="opt_radio_<?= $optId ?>"
														       class="form-check-input js-pdp-radio-val"
														       data-option-id="<?= $optId ?>"
														       data-val-id="<?= (int)$val['id'] ?>"
														       data-val-name="<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>">
														<span class="small fw-semibold ms-1"><?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?></span>
													</label>
												<?php endforeach; ?>
											</div>
											<input type="hidden" class="option-select" data-option-id="<?= $optId ?>" value="">

										<?php else: ?>
											<!-- Dropdown UI -->
											<div class="rs1-select2 bor8 bg0" style="border-radius: 8px;">
												<select class="js-select2 option-select" data-option-id="<?= $optId ?>">
													<option value="">Choose <?= htmlspecialchars($opt['name'], ENT_QUOTES, 'UTF-8') ?></option>
													<?php foreach ($opt['values'] as $val): ?>
														<option value="<?= (int)$val['id'] ?>">
															<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>
														</option>
													<?php endforeach; ?>
												</select>
												<div class="dropDownSelect2"></div>
											</div>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<!-- Quantity Controls, Add To Cart & Buy Now Buttons -->
						<div class="pdp-actions-panel">
							<div class="pdp-actions-row">
								<div class="pdp-quantity-stepper">
									<button type="button" class="btn-num-product-down pdp-qty-btn" aria-label="Decrease quantity">
										<i class="fs-16 zmdi zmdi-minus"></i>
									</button>

									<input class="num-product pdp-qty-val" type="number" name="quantity" id="pdpQtyInput" value="1" min="1" max="<?= max(1, $currentStock) ?>">

									<button type="button" class="btn-num-product-up pdp-qty-btn" aria-label="Increase quantity">
										<i class="fs-16 zmdi zmdi-plus"></i>
									</button>
								</div>

								<!-- Add to Cart (AJAX - stays on page) -->
								<button type="button" class="pdp-btn-cart" id="pdpAddBtn" <?= !$inStock ? 'disabled' : '' ?>>
									<i class="zmdi zmdi-shopping-cart-plus m-r-8 fs-18"></i> Add to Cart
								</button>

								<!-- Buy Now (Direct Checkout) -->
								<button type="submit" name="action_type" value="buy_now" class="pdp-btn-buy" id="pdpBuyNowBtn" <?= !$inStock ? 'disabled' : '' ?>>
									<i class="zmdi zmdi-flash m-r-8 fs-18"></i> Buy Now
								</button>

								<!-- Wishlist Button -->
								<button type="button" class="pdp-btn-wish" onclick="window.cozaToggleWishlist(<?= $productId ?>, this); return false;" title="Add to Wishlist" aria-label="Add to Wishlist">
									<i class="zmdi zmdi-favorite-outline fs-20"></i>
								</button>
							</div>
						</div>	
					</form>

					<!-- Product Description (Immediately after Add to Cart & Buy Now) -->
					<?php
					$pdpDesc = trim((string)($product['description'] ?? ''));
					if ($pdpDesc === '') {
						$pdpDesc = trim((string)($product['short_description'] ?? ''));
					}
					if ($pdpDesc === '') {
						$pdpDesc = 'Crafted with premium materials and verified for enduring durability.';
					}
					?>
					<div class="pdp-description-box">
						<h5 class="pdp-desc-heading">Product Description</h5>
						<div class="pdp-desc-text">
							<?php
							if (strip_tags($pdpDesc) !== $pdpDesc) {
								echo strip_tags($pdpDesc, '<p><br><ul><ol><li><strong><em><b><i><h2><h3><h4><span><a>');
							} else {
								echo nl2br(htmlspecialchars($pdpDesc, ENT_QUOTES, 'UTF-8'));
							}
							?>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Details Tabs (Specifications, Reviews) -->
		<div class="pdp-tabs-wrap">
			<ul class="nav pdp-tabs-nav" role="tablist">
				<li class="nav-item">
					<a class="pdp-tab-item active" data-toggle="tab" href="#information" role="tab" id="tabLinkInfo">Specifications</a>
				</li>

				<li class="nav-item">
					<a class="pdp-tab-item" data-toggle="tab" href="#reviews" role="tab" id="tabLinkReviews">Verified Reviews (<?= $totalReviews ?>)</a>
				</li>
			</ul>

			<!-- Tab panes -->
			<div class="tab-content">
				<!-- Tab 1: Specifications -->
				<div class="tab-pane fade show active" id="information" role="tabpanel">
					<div class="col-lg-8 m-lr-auto">
						<div class="table-responsive pdp-spec-table-wrap">
							<table class="table table-borderless pdp-spec-table">
								<tbody>
									<tr>
										<td class="pdp-spec-label">Product SKU</td>
										<td class="pdp-spec-value"><?= htmlspecialchars($product['sku'] ?: 'PROD-' . str_pad((string)$productId, 5, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></td>
									</tr>
									<tr>
										<td class="pdp-spec-label">Product Type</td>
										<td class="pdp-spec-value"><?= ucfirst(htmlspecialchars($product['product_type'], ENT_QUOTES, 'UTF-8')) ?></td>
									</tr>
									<?php if (!empty($product['category_name'])): ?>
									<tr>
										<td class="pdp-spec-label">Category</td>
										<td class="pdp-spec-value"><?= htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') ?></td>
									</tr>
									<?php endif; ?>
									<?php if (!empty($product['brand'])): ?>
									<tr>
										<td class="pdp-spec-label">Brand</td>
										<td class="pdp-spec-value"><?= htmlspecialchars($product['brand'], ENT_QUOTES, 'UTF-8') ?></td>
									</tr>
									<?php endif; ?>
									<tr>
										<td class="pdp-spec-label">Authenticity</td>
										<td class="pdp-spec-value">100% Original and Brand New</td>
									</tr>
									<tr>
										<td class="pdp-spec-label">Delivery Time</td>
										<td class="pdp-spec-value">24h inside Dhaka, 48-72h outside Dhaka</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- Tab 2: Reviews -->
				<div class="tab-pane fade" id="reviews" role="tabpanel">
					<div class="col-lg-8 m-lr-auto">
						<div class="p-b-30">
							<!-- Review list -->
							<?php if (!empty($reviews)): ?>
								<?php foreach ($reviews as $rev): ?>
									<div class="p-all-20 m-b-20 bg0" style="border: 1px solid #eef0f3; border-radius: 12px;">
										<div class="flex-w flex-sb-m p-b-10">
											<div class="flex-m">
												<div class="wrap-pic-s size-109 bor0 of-hidden m-r-12">
													<img src="<?= htmlspecialchars(avatar_url($rev['author_name']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($rev['author_name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover;">
												</div>
												<div>
													<span class="font-weight-bold cl2 d-block" style="font-size: 15px;">
														<?= htmlspecialchars($rev['author_name'], ENT_QUOTES, 'UTF-8') ?>
													</span>
													<span class="badge" style="background: #ecfdf5; color: #059669; font-size: 11px; font-weight: 600;">Verified Customer</span>
												</div>
											</div>

											<span class="fs-14" style="color: #f59e0b;">
												<?php for ($i = 1; $i <= (int)$rev['rating']; $i++): ?>
													<i class="zmdi zmdi-star"></i>
												<?php endfor; ?>
											</span>
										</div>

										<h5 class="font-weight-bold cl2 m-b-6" style="font-size: 15px;">
											<?= htmlspecialchars($rev['title'], ENT_QUOTES, 'UTF-8') ?>
										</h5>

										<p class="stext-102 cl6" style="line-height: 1.6;">
											<?= nl2br(htmlspecialchars($rev['body'], ENT_QUOTES, 'UTF-8')) ?>
										</p>

										<?php if (!empty($rev['admin_reply'])): ?>
											<div class="p-all-15 m-t-15 bg6" style="border-left: 3px solid #111; border-radius: 6px;">
												<span class="stext-102 font-weight-bold cl2 d-block">Store Response:</span>
												<span class="stext-102 cl6"><?= nl2br(htmlspecialchars($rev['admin_reply'], ENT_QUOTES, 'UTF-8')) ?></span>
											</div>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							<?php else: ?>
								<div class="text-center p-tb-30 text-muted bg0 p-all-30 m-b-25" style="border: 1px dashed #d1d5db; border-radius: 12px;">
									<i class="zmdi zmdi-comment-text-alt fs-36 d-block m-b-10" style="color: #9ca3af; font-size: 40px;"></i>
									<p class="stext-102 cl6 mb-0">No customer reviews yet. Be the first to share your experience!</p>
								</div>
							<?php endif; ?>
							
							<!-- Add review form -->
							<div class="bg0 p-all-25 m-t-20" style="border: 1px solid #eef0f3; border-radius: 14px;">
								<form class="w-full" id="writeReviewForm" method="post" action="<?= htmlspecialchars(url('api/review.php'), ENT_QUOTES, 'UTF-8') ?>">
									<?= csrf_field() ?>
									<input type="hidden" name="product_id" value="<?= $productId ?>">
									<input type="hidden" name="form_time" value="<?= time() ?>">
									<div style="display:none;"><input type="text" name="website_hp" value=""></div>

									<h5 class="mtext-108 cl2 p-b-15 font-weight-bold">
										Write a Customer Review
									</h5>

									<div class="flex-w flex-m p-b-20">
										<span class="stext-102 cl3 m-r-16 font-weight-bold">
											Your Rating:
										</span>

										<span class="wrap-rating fs-18 cl11 pointer" style="cursor: pointer;">
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<input class="dis-none" type="number" name="rating" value="5" required>
										</span>
									</div>

									<div class="row p-b-15">
										<div class="col-12 p-b-15">
											<label class="stext-102 cl3 font-weight-bold" for="reviewTitle">Review Title</label>
											<input class="size-111 bor8 stext-102 cl2 p-lr-20" id="reviewTitle" type="text" name="title" placeholder="e.g. Excellent quality, fast delivery!" required maxlength="120" style="border-radius: 8px;">
										</div>

										<div class="col-12 p-b-15">
											<label class="stext-102 cl3 font-weight-bold" for="review">Your Review Details</label>
											<textarea class="size-110 bor8 stext-102 cl2 p-lr-20 p-tb-10" id="review" name="body" placeholder="Share your experience with this product..." required minlength="10" maxlength="2000" style="border-radius: 8px; height: 110px;"></textarea>
										</div>

										<div class="col-sm-6 p-b-15">
											<label class="stext-102 cl3 font-weight-bold" for="name">Your Name</label>
											<input class="size-111 bor8 stext-102 cl2 p-lr-20" id="name" type="text" name="author_name" value="<?= htmlspecialchars(function_exists('customer_current') && customer_current() ? customer_current()['name'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Your name" style="border-radius: 8px;">
										</div>
									</div>

									<button type="submit" id="submitReviewBtn" class="flex-c-m stext-101 cl0 size-112 bg3 bor1 hov-btn3 p-lr-25 trans-04 pointer" style="border-radius: 8px; font-weight: 700; height: 46px;">
										Submit Review
									</button>
								</form>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Related Products Carousel -->
	<?php if (!empty($related)): ?>
	<div class="bg6 m-t-60 p-t-50 p-b-70">
		<div class="container">
			<div class="p-b-35 text-center">
				<h3 class="ltext-103 cl5 font-weight-bold">
					Related Products
				</h3>
			</div>

			<div class="row isotope-grid">
				<?php foreach ($related as $p): ?>
					<?php include __DIR__ . '/../../includes/partials/product_card.php'; ?>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>
</section>

<!-- Switch PDP Main Image Function & Enhanced Controls -->
<script>
function switchPdpImage(el, url) {
	var thumbs = document.querySelectorAll('.pdp-thumb-item');
	thumbs.forEach(function(item) { item.classList.remove('active'); });
	if (el) el.classList.add('active');
	var main = document.getElementById('pdpMainImg');
	if (main) main.src = url;
	var zoom = document.querySelector('.js-gallery-zoom');
	if (zoom) zoom.href = url;
}
</script>

<!-- PDP AJAX Add to Cart & Review Handler -->
<script>
document.addEventListener('DOMContentLoaded', function() {
	// Ensure no duplicate jQuery listeners from legacy template scripts
	if (typeof $ !== 'undefined') {
		$('.btn-num-product-down, .btn-num-product-up').off('click');
	}

	// Stepper increment / decrement and input validation handler
	var downBtn = document.querySelector('.btn-num-product-down');
	var upBtn = document.querySelector('.btn-num-product-up');
	var qtyInput = document.getElementById('pdpQtyInput');

	function validatePdpQty() {
		if (!qtyInput) return;
		var val = parseInt(qtyInput.value, 10);
		var max = parseInt(qtyInput.max, 10) || 999;
		var min = parseInt(qtyInput.min, 10) || 1;
		if (isNaN(val) || val < min) {
			qtyInput.value = min;
		} else if (val > max) {
			qtyInput.value = max;
			if (typeof swal === 'function') {
				swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'warning');
			}
		}
	}

	if (qtyInput) {
		qtyInput.addEventListener('change', validatePdpQty);
		qtyInput.addEventListener('input', validatePdpQty);
	}

	if (downBtn && qtyInput) {
		downBtn.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			if (e.stopImmediatePropagation) e.stopImmediatePropagation();
			var current = parseInt(qtyInput.value, 10) || 1;
			var min = parseInt(qtyInput.min, 10) || 1;
			if (current > min) {
				qtyInput.value = current - 1;
				qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
				qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
			}
		});
	}
	if (upBtn && qtyInput) {
		upBtn.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			if (e.stopImmediatePropagation) e.stopImmediatePropagation();
			var current = parseInt(qtyInput.value, 10) || 1;
			var max = parseInt(qtyInput.max, 10) || 999;
			if (current < max) {
				qtyInput.value = current + 1;
				qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
				qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
			} else {
				if (typeof swal === 'function') {
					swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'info');
				}
			}
		});
	}

	// Initialize Magnific Popup zoom on main image
	if (typeof $ !== 'undefined' && typeof $.fn.magnificPopup === 'function') {
		$('.js-gallery-zoom').magnificPopup({
			type: 'image',
			closeOnContentClick: true,
			mainClass: 'mfp-img-mobile',
			image: { verticalFit: true }
		});
	}

	// Interactive Button / Swatch Click Handler
	$('.js-pdp-opt-btn').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var optId = $btn.data('option-id');
		var valId = $btn.data('val-id');
		var valName = $btn.data('val-name');

		var $group = $btn.closest('.pdp-option-group');
		$group.find('.js-pdp-opt-btn').removeClass('active');
		$group.find('.pdp-swatch-check').hide();

		$btn.addClass('active');
		$btn.find('.pdp-swatch-check').show();

		$('#opt_label_' + optId).text(valName);

		var $hiddenInput = $group.find('input.option-select');
		$hiddenInput.val(valId).trigger('change');
	});

	// Radio Handler
	$('.js-pdp-radio-val').on('change', function() {
		var $radio = $(this);
		var optId = $radio.data('option-id');
		var valId = $radio.data('val-id');
		var valName = $radio.data('val-name');

		$('#opt_label_' + optId).text(valName);

		var $hiddenInput = $radio.closest('.pdp-option-group').find('input.option-select');
		$hiddenInput.val(valId).trigger('change');
	});

	// Real-Time Variant Option & Stock Updates
	var variantsData = <?= json_encode($variants) ?>;
	var totalOptionsCount = <?= count($options ?? []) ?>;
	if (variantsData && variantsData.length > 0) {
		$('.option-select').on('change', function() {
			var selectedOpts = {};
			$('.option-select').each(function() {
				var optId = $(this).data('option-id');
				var valId = parseInt($(this).val(), 10);
				if (valId) selectedOpts[optId] = valId;
			});

			var selectedCount = Object.keys(selectedOpts).length;
			var match = null;
			if (selectedCount === totalOptionsCount && totalOptionsCount > 0) {
				for (var i = 0; i < variantsData.length; i++) {
					var v = variantsData[i];
					var isMatch = true;
					for (var optId in selectedOpts) {
						if (v.option_value_ids && v.option_value_ids[optId] != selectedOpts[optId]) {
							isMatch = false;
							break;
						}
					}
					if (isMatch) {
						match = v;
						break;
					}
				}
			}

			var vIdInput = document.getElementById('selectedVariantId');
			var priceElem = document.getElementById('pdpPrice');
			var stockBadge = document.getElementById('pdpStockBadge');
			var addBtn = document.getElementById('pdpAddBtn');
			var buyBtn = document.getElementById('pdpBuyNowBtn');
			var qtyInput = document.getElementById('pdpQtyInput');

			if (match) {
				if (vIdInput) vIdInput.value = match.id;

				var pVal = parseFloat(match.sale_price || match.price || 0);
				if (priceElem && pVal > 0) {
					var curSym = <?= json_encode(setting('currency_symbol', '৳')) ?>;
					priceElem.textContent = curSym + pVal.toFixed(2);
				}

				var isPreorder = <?= !empty($product['is_preorder']) ? 'true' : 'false' ?>;
				var vStock = parseInt(match.stock, 10);
				if (isPreorder) {
					if (stockBadge) {
						stockBadge.className = 'pdp-stock-badge in-stock pre-order';
						stockBadge.style.backgroundColor = '#e0f2fe';
						stockBadge.style.color = '#0369a1';
						stockBadge.style.borderColor = '#bae6fd';
						stockBadge.innerHTML = '<i class="zmdi zmdi-time-restore m-r-4"></i> Pre-Order';
					}
					if (addBtn) addBtn.disabled = false;
					if (buyBtn) buyBtn.disabled = false;
				} else if (vStock > 0) {
					if (stockBadge) {
						stockBadge.className = 'pdp-stock-badge in-stock';
						stockBadge.style.backgroundColor = '';
						stockBadge.style.color = '';
						stockBadge.style.borderColor = '';
						stockBadge.innerHTML = '<i class="zmdi zmdi-check-circle m-r-4"></i> In Stock: ' + vStock;
					}
					if (addBtn) addBtn.disabled = false;
					if (buyBtn) buyBtn.disabled = false;
					if (qtyInput) qtyInput.max = vStock;
				} else {
					if (stockBadge) {
						stockBadge.className = 'pdp-stock-badge out-of-stock';
						stockBadge.style.backgroundColor = '';
						stockBadge.style.color = '';
						stockBadge.style.borderColor = '';
						stockBadge.innerHTML = '<i class="zmdi zmdi-alert-circle-o m-r-4"></i> Sold Out';
					}
					if (addBtn) addBtn.disabled = true;
					if (buyBtn) buyBtn.disabled = true;
				}
			} else {
				if (vIdInput) vIdInput.value = '';
				if (stockBadge && totalOptionsCount > 0) {
					stockBadge.className = 'pdp-stock-badge out-of-stock';
					stockBadge.innerHTML = '<i class="zmdi zmdi-help-outline m-r-4"></i> Select Options';
				}
			}
		});

		// Auto-select first value for swatches and buttons on page load
		$('.pdp-option-group').each(function() {
			var $firstBtn = $(this).find('.js-pdp-opt-btn').first();
			if ($firstBtn.length) {
				$firstBtn.trigger('click');
			} else {
				var $firstRadio = $(this).find('.js-pdp-radio-val').first();
				if ($firstRadio.length) {
					$firstRadio.prop('checked', true).trigger('change');
				}
			}
		});
	}

	// AJAX Add to Cart (Stays on PDP, updates counter, triggers sweetalert)
	var addBtn = document.getElementById('pdpAddBtn');
	if (addBtn) {
		addBtn.addEventListener('click', function(e) {
			e.preventDefault();
			var isVar = <?= $isVariable ? 'true' : 'false' ?>;
			var vidInput = document.getElementById('selectedVariantId');
			var vid = vidInput ? vidInput.value : '';

			if (isVar && !vid) {
				if (typeof swal === 'function') {
					swal('Please Select Options', 'Please choose all available product options before adding to cart.', 'warning');
				} else {
					alert('Please choose all available product options before adding to cart.');
				}
				return;
			}

			var pid = <?= (int)$productId ?>;
			var max = parseInt(qtyInput ? qtyInput.max : 999, 10) || 999;
			var qty = parseInt(document.getElementById('pdpQtyInput').value, 10) || 1;
			if (qty > max) {
				if (typeof swal === 'function') {
					swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'warning');
				}
				if (qtyInput) qtyInput.value = max;
				return;
			}
			var pName = <?= json_encode($product['name']) ?>;

			if (typeof window.cozaAddToCart === 'function') {
				window.cozaAddToCart(pid, qty, vid, pName);
			} else {
				var fd = new FormData();
				fd.append('action', 'add');
				fd.append('product_id', pid);
				if (vid) fd.append('variant_id', vid);
				fd.append('quantity', qty);
				fd.append('_csrf', window.SHOP_CSRF_TOKEN || '');
				fetch((window.SHOP_BASE_URL || '/') + 'api/cart_ajax.php', {
					method: 'POST',
					body: fd,
					headers: { 'X-Requested-With': 'XMLHttpRequest' }
				})
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res && res.ok) {
						if (typeof window.cozaUpdateCartUI === 'function') {
							window.cozaUpdateCartUI(res);
						} else if (res.cart_count !== undefined) {
							$('.js-show-cart').attr('data-notify', res.cart_count);
						}
						if (typeof swal === 'function') {
							swal(pName, 'has been added to your cart!', 'success');
						} else {
							alert(pName + ' has been added to your cart!');
						}
					} else {
						var msg = (res && res.error) ? res.error : 'Could not add product to cart.';
						if (typeof swal === 'function') swal('Notice', msg, 'error');
						else alert(msg);
					}
				})
				.catch(function() {
					if (typeof swal === 'function') swal('Notice', 'Could not add to cart.', 'error');
				});
			}
		});
	}

	// Buy Now and Form submit stock limit protection
	var buyBtn = document.getElementById('pdpBuyNowBtn');
	if (buyBtn) {
		buyBtn.addEventListener('click', function(e) {
			var isVar = <?= $isVariable ? 'true' : 'false' ?>;
			var vidInput = document.getElementById('selectedVariantId');
			var vid = vidInput ? vidInput.value : '';
			if (isVar && !vid) {
				e.preventDefault();
				if (typeof swal === 'function') {
					swal('Please Select Options', 'Please choose all available product options before proceeding.', 'warning');
				} else {
					alert('Please choose all available product options before proceeding.');
				}
				return;
			}
			var max = parseInt(qtyInput ? qtyInput.max : 999, 10) || 999;
			var qty = parseInt(document.getElementById('pdpQtyInput').value, 10) || 1;
			if (qty > max) {
				e.preventDefault();
				if (typeof swal === 'function') {
					swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'warning');
				}
				if (qtyInput) qtyInput.value = max;
				return;
			}
		});
	}

	var pdpForm = document.getElementById('pdpAddToCartForm');
	if (pdpForm) {
		pdpForm.addEventListener('submit', function(e) {
			var isVar = <?= $isVariable ? 'true' : 'false' ?>;
			var vidInput = document.getElementById('selectedVariantId');
			var vid = vidInput ? vidInput.value : '';
			if (isVar && !vid) {
				e.preventDefault();
				if (typeof swal === 'function') {
					swal('Please Select Options', 'Please choose all available product options before adding to cart.', 'warning');
				} else {
					alert('Please choose all available product options before adding to cart.');
				}
				return;
			}
			var max = parseInt(qtyInput ? qtyInput.max : 999, 10) || 999;
			var qty = parseInt(document.getElementById('pdpQtyInput').value, 10) || 1;
			if (qty > max) {
				e.preventDefault();
				if (typeof swal === 'function') {
					swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'warning');
				}
				if (qtyInput) qtyInput.value = max;
				return;
			}
		});
	}

	var reviewForm = document.getElementById('writeReviewForm');
	if (reviewForm) {
		reviewForm.addEventListener('submit', function(e) {
			e.preventDefault();
			var btn = document.getElementById('submitReviewBtn');
			if (btn) {
				btn.disabled = true;
				btn.textContent = 'Submitting...';
			}

			var formData = new FormData(this);
			fetch(this.action, {
				method: 'POST',
				body: formData,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (btn) {
					btn.disabled = false;
					btn.textContent = 'Submit Review';
				}
				if (data && data.success) {
					swal('Thank you!', data.message || 'Your review has been submitted for moderation.', 'success');
					reviewForm.reset();
				} else {
					var err = (data && data.error) ? data.error : 'Failed to submit review.';
					swal('Notice', err, 'error');
				}
			})
			.catch(function() {
				if (btn) {
					btn.disabled = false;
					btn.textContent = 'Submit Review';
				}
				swal('Error', 'Network error. Please try again.', 'error');
			});
		});
	}
});
</script>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
