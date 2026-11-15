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

<!-- =================== COZASTORE PRODUCT DETAIL SECTION =================== -->
<section class="sec-product-detail bg0 p-t-30 p-b-60">
	<div class="container">
		<div class="row">
			<!-- Gallery Column (Left) -->
			<div class="col-md-6 col-lg-7 p-b-30">
				<div class="p-l-0 p-r-30 p-lr-0-lg">
					<div class="wrap-slick3 flex-sb flex-w">
						<div class="wrap-slick3-dots"></div>
						<div class="wrap-slick3-arrows flex-sb-m flex-w"></div>

						<div class="slick3 gallery-lb">
							<?php foreach ($gallery as $idx => $img): 
								$gUrl = product_image_url($img['filename'] ?? null, 'large', $productId);
								$tUrl = product_image_url($img['filename'] ?? null, 'thumbnail', $productId);
							?>
							<div class="item-slick3" data-thumb="<?= htmlspecialchars($tUrl, ENT_QUOTES, 'UTF-8') ?>">
								<div class="wrap-pic-w pos-relative">
									<img src="<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; aspect-ratio: 1/1; object-fit: cover;">

									<a class="flex-c-m size-108 how-pos1 bor0 fs-16 cl10 bg0 hov-btn3 trans-04" href="<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>">
										<i class="fa fa-expand"></i>
									</a>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
				
			<!-- Product Info & Purchase Column (Right) -->
			<div class="col-md-6 col-lg-5 p-b-30">
				<div class="p-r-50 p-t-5 p-lr-0-lg">
					<h4 class="mtext-105 cl2 js-name-detail p-b-14">
						<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>
					</h4>

					<!-- Star Rating & Review Count -->
					<div class="flex-w flex-m p-b-14">
						<div class="cl1 fs-14 m-r-10">
							<?php for ($i = 1; $i <= 5; $i++): ?>
								<i class="zmdi <?= $avgRating >= $i ? 'zmdi-star' : 'zmdi-star-outline' ?>" style="color: #f59e0b;"></i>
							<?php endfor; ?>
						</div>
						<a href="#reviews" class="stext-102 cl6 hov-cl1 trans-04">
							(<?= $totalReviews ?> customer review<?= $totalReviews === 1 ? '' : 's' ?>)
						</a>
					</div>

					<!-- Price Row -->
					<div class="p-b-14">
						<?php if ($onSale): ?>
							<span class="mtext-106 cl1 font-weight-bold m-r-10" id="pdpPrice">
								<?= price((float)$product['sale_price']) ?>
							</span>
							<span class="mtext-106 cl3" id="pdpOldPrice" style="text-decoration: line-through; opacity: 0.7;">
								<?= price((float)$product['price']) ?>
							</span>
							<span class="stext-109 cl0 bg1 p-lr-8 p-tb-2 m-l-10" style="border-radius: 2px;">
								-<?= (int)$discount ?>% OFF
							</span>
						<?php else: ?>
							<span class="mtext-106 cl2 font-weight-bold" id="pdpPrice">
								<?= price((float)$product['price']) ?>
							</span>
						<?php endif; ?>
					</div>

					<?php if (!empty($product['short_description'])): ?>
						<p class="stext-102 cl3 p-t-10 p-b-20" style="line-height: 1.6;">
							<?= htmlspecialchars($product['short_description'], ENT_QUOTES, 'UTF-8') ?>
						</p>
					<?php endif; ?>
					
					<!-- Form Actions -->
					<form method="post" action="<?= htmlspecialchars(route('cart'), ENT_QUOTES, 'UTF-8') ?>" id="pdpAddToCartForm" class="p-t-20">
						<?= csrf_field() ?>
						<input type="hidden" name="action" value="add">
						<input type="hidden" name="product_id" value="<?= $productId ?>">
						<input type="hidden" name="variant_id" id="selectedVariantId" value="<?= ($isVariable && !empty($variants[0]['id'])) ? (int)$variants[0]['id'] : '' ?>">

						<!-- Variant Option Selectors -->
						<?php if ($isVariable && !empty($options)): ?>
							<?php foreach ($options as $opt): ?>
								<div class="flex-w flex-r-m p-b-14">
									<div class="size-203 flex-c-m respon6">
										<?= htmlspecialchars($opt['name'], ENT_QUOTES, 'UTF-8') ?>
									</div>

									<div class="size-204 respon6-next">
										<div class="rs1-select2 bor8 bg0">
											<select class="js-select2 option-select" data-option-id="<?= (int)$opt['id'] ?>">
												<option value="">Choose <?= htmlspecialchars($opt['name'], ENT_QUOTES, 'UTF-8') ?></option>
												<?php foreach ($opt['values'] as $val): ?>
													<option value="<?= (int)$val['id'] ?>">
														<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>
													</option>
												<?php endforeach; ?>
											</select>
											<div class="dropDownSelect2"></div>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>

						<!-- Stock Availability Status -->
						<div class="flex-w flex-m p-b-20 p-t-10">
							<span class="stext-102 font-weight-bold <?= $inStock ? 'cl1' : 'cl3' ?>">
								<i class="zmdi <?= $inStock ? 'zmdi-check-circle' : 'zmdi-close-circle' ?> m-r-4"></i>
								<?= $inStock ? 'In Stock (Ready for fast delivery)' : 'Currently Sold Out' ?>
							</span>
						</div>

						<!-- Quantity Controls & Add To Cart Button -->
						<div class="flex-w flex-r-m p-b-10">
							<div class="size-204 flex-w flex-m respon6-next">
								<div class="wrap-num-product flex-w m-r-20 m-tb-10">
									<div class="btn-num-product-down cl8 hov-btn3 trans-04 flex-c-m pointer">
										<i class="fs-16 zmdi zmdi-minus"></i>
									</div>

									<input class="mtext-104 cl3 txt-center num-product" type="number" name="quantity" id="pdpQtyInput" value="1" min="1" max="<?= max(1, $currentStock) ?>">

									<div class="btn-num-product-up cl8 hov-btn3 trans-04 flex-c-m pointer">
										<i class="fs-16 zmdi zmdi-plus"></i>
									</div>
								</div>

								<button type="submit" name="action_type" value="add_to_cart" class="flex-c-m stext-101 cl0 size-101 bg1 bor1 hov-btn1 p-lr-15 trans-04" id="pdpAddBtn" <?= !$inStock ? 'disabled' : '' ?>>
									Add to cart
								</button>
							</div>
						</div>	
					</form>

					<!-- Wishlist & Trust Badges -->
					<div class="flex-w flex-m p-t-30">
						<div class="flex-m bor9 p-r-10 m-r-11">
							<a href="#" class="fs-14 cl3 hov-cl1 trans-04 lh-10 p-lr-5 p-tb-2 js-addwish-detail" onclick="window.cozaToggleWishlist(<?= $productId ?>, this); return false;" title="Add to Wishlist">
								<i class="zmdi zmdi-favorite fs-20"></i>
							</a>
						</div>
						<span class="stext-107 cl6">Add to wishlist</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Cozastore Details Tabs (Description, Information, Reviews) -->
		<div class="bor10 m-t-50 p-t-43 p-b-40">
			<div class="tab01">
				<!-- Nav tabs -->
				<ul class="nav nav-tabs" role="tablist">
					<li class="nav-item p-b-10">
						<a class="nav-link active" data-toggle="tab" href="#description" role="tab">Description</a>
					</li>

					<li class="nav-item p-b-10">
						<a class="nav-link" data-toggle="tab" href="#information" role="tab">Additional information</a>
					</li>

					<li class="nav-item p-b-10">
						<a class="nav-link" data-toggle="tab" href="#reviews" role="tab">Reviews (<?= $totalReviews ?>)</a>
					</li>
				</ul>

				<!-- Tab panes -->
				<div class="tab-content p-t-43">
					<!-- Tab 1: Description -->
					<div class="tab-pane fade show active" id="description" role="tabpanel">
						<div class="how-pos2 p-lr-15-md">
							<div class="stext-102 cl6" style="line-height: 1.8;">
								<?php
								$desc = $product['description'] ?: $product['short_description'] ?: 'Crafted with premium materials and verified for enduring durability.';
								if (strip_tags($desc) !== $desc) {
									echo strip_tags($desc, '<p><br><ul><ol><li><strong><em><b><i><h2><h3><h4><span><a>');
								} else {
									echo nl2br(htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'));
								}
								?>
							</div>
						</div>
					</div>

					<!-- Tab 2: Additional information -->
					<div class="tab-pane fade" id="information" role="tabpanel">
						<div class="row">
							<div class="col-sm-10 col-md-8 col-lg-6 m-lr-auto">
								<ul class="p-lr-28 p-lr-15-sm">
									<li class="flex-w flex-t p-b-7">
										<span class="stext-102 cl3 size-205 font-weight-bold">Product SKU</span>
										<span class="stext-102 cl6 size-206"><?= htmlspecialchars($product['sku'] ?: 'PROD-' . str_pad((string)$productId, 5, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></span>
									</li>

									<li class="flex-w flex-t p-b-7">
										<span class="stext-102 cl3 size-205 font-weight-bold">Product Type</span>
										<span class="stext-102 cl6 size-206"><?= ucfirst(htmlspecialchars($product['product_type'], ENT_QUOTES, 'UTF-8')) ?></span>
									</li>

									<?php if (!empty($product['category_name'])): ?>
									<li class="flex-w flex-t p-b-7">
										<span class="stext-102 cl3 size-205 font-weight-bold">Category</span>
										<span class="stext-102 cl6 size-206"><?= htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
									</li>
									<?php endif; ?>

									<?php if (!empty($product['brand'])): ?>
									<li class="flex-w flex-t p-b-7">
										<span class="stext-102 cl3 size-205 font-weight-bold">Brand</span>
										<span class="stext-102 cl6 size-206"><?= htmlspecialchars($product['brand'], ENT_QUOTES, 'UTF-8') ?></span>
									</li>
									<?php endif; ?>

									<li class="flex-w flex-t p-b-7">
										<span class="stext-102 cl3 size-205 font-weight-bold">Authenticity</span>
										<span class="stext-102 cl6 size-206">100% Genuine Direct from Verified Creators</span>
									</li>

									<li class="flex-w flex-t p-b-7">
										<span class="stext-102 cl3 size-205 font-weight-bold">Delivery Estimate</span>
										<span class="stext-102 cl6 size-206">24h Dhaka, 48-72h Nationwide COD</span>
									</li>
								</ul>
							</div>
						</div>
					</div>

					<!-- Tab 3: Reviews -->
					<div class="tab-pane fade" id="reviews" role="tabpanel">
						<div class="row">
							<div class="col-sm-10 col-md-8 col-lg-6 m-lr-auto">
								<div class="p-b-30 m-lr-15-sm">
									<!-- Review list -->
									<?php if (!empty($reviews)): ?>
										<?php foreach ($reviews as $rev): ?>
											<div class="flex-w flex-t p-b-30 bor12 m-b-25">
												<div class="wrap-pic-s size-109 bor0 of-hidden m-r-18 m-t-6">
													<img src="<?= htmlspecialchars(avatar_url($rev['author_name']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($rev['author_name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">
												</div>

												<div class="size-207">
													<div class="flex-w flex-sb-m p-b-10">
														<span class="mtext-107 cl2 p-r-20 font-weight-bold">
															<?= htmlspecialchars($rev['author_name'], ENT_QUOTES, 'UTF-8') ?>
														</span>

														<span class="fs-14 cl11">
															<?php for ($i = 1; $i <= (int)$rev['rating']; $i++): ?>
																<i class="zmdi zmdi-star" style="color: #f59e0b;"></i>
															<?php endfor; ?>
														</span>
													</div>

													<h5 class="stext-101 cl2 font-weight-bold m-b-4">
														<?= htmlspecialchars($rev['title'], ENT_QUOTES, 'UTF-8') ?>
													</h5>

													<p class="stext-102 cl6">
														<?= nl2br(htmlspecialchars($rev['body'], ENT_QUOTES, 'UTF-8')) ?>
													</p>

													<?php if (!empty($rev['admin_reply'])): ?>
														<div class="p-t-10 p-b-10 p-l-15 p-r-15 m-t-10 bg6 bor10" style="border-left: 3px solid #222;">
															<span class="stext-102 font-weight-bold cl2 d-block">Store Response:</span>
															<span class="stext-102 cl6"><?= nl2br(htmlspecialchars($rev['admin_reply'], ENT_QUOTES, 'UTF-8')) ?></span>
														</div>
													<?php endif; ?>
												</div>
											</div>
										<?php endforeach; ?>
									<?php else: ?>
										<div class="text-center p-tb-30 text-muted">
											<p class="stext-102 cl6">No reviews yet. Be the first to review this product!</p>
										</div>
									<?php endif; ?>
									
									<!-- Add review form -->
									<form class="w-full p-t-20" id="writeReviewForm" method="post" action="<?= htmlspecialchars(url('api/review.php'), ENT_QUOTES, 'UTF-8') ?>">
										<?= csrf_field() ?>
										<input type="hidden" name="product_id" value="<?= $productId ?>">
										<input type="hidden" name="form_time" value="<?= time() ?>">
										<div style="display:none;"><input type="text" name="website_hp" value=""></div>

										<h5 class="mtext-108 cl2 p-b-15">
											Add a review
										</h5>

										<div class="flex-w flex-m p-b-23">
											<span class="stext-102 cl3 m-r-16">
												Your Rating:
											</span>

											<span class="wrap-rating fs-18 cl11 pointer">
												<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
												<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
												<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
												<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
												<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
												<input class="dis-none" type="number" name="rating" value="5" required>
											</span>
										</div>

										<div class="row p-b-25">
											<div class="col-12 p-b-15">
												<label class="stext-102 cl3" for="reviewTitle">Review Headline</label>
												<input class="size-111 bor8 stext-102 cl2 p-lr-20" id="reviewTitle" type="text" name="title" placeholder="e.g. Excellent quality!" required maxlength="120">
											</div>

											<div class="col-12 p-b-15">
												<label class="stext-102 cl3" for="review">Your Review</label>
												<textarea class="size-110 bor8 stext-102 cl2 p-lr-20 p-tb-10" id="review" name="body" placeholder="Share your experience with this product..." required minlength="10" maxlength="2000"></textarea>
											</div>

											<div class="col-sm-6 p-b-15">
												<label class="stext-102 cl3" for="name">Your Name</label>
												<input class="size-111 bor8 stext-102 cl2 p-lr-20" id="name" type="text" name="author_name" value="<?= htmlspecialchars(function_exists('customer_current') && customer_current() ? customer_current()['name'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Your name">
											</div>
										</div>

										<button type="submit" id="submitReviewBtn" class="flex-c-m stext-101 cl0 size-112 bg7 bor11 hov-btn3 p-lr-15 trans-04 m-b-10 pointer">
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
	</div>

	<!-- Related Products Carousel -->
	<?php if (!empty($related)): ?>
	<div class="bg6 m-t-60 p-t-50 p-b-70">
		<div class="container">
			<div class="p-b-35 text-center">
				<h3 class="ltext-103 cl5">
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

<!-- Review AJAX Handler -->
<script>
document.addEventListener('DOMContentLoaded', function() {
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
