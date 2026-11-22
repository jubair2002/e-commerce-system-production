<?php
/**
 * Cozastore Style Product Card (Block2)
 * Expects $p (product row) in scope.
 */
$pid         = (int)$p['id'];
$onSale      = product_is_on_sale($p);
$discount    = $onSale ? product_discount_percent($p) : 0;
$productType = $p['product_type'] ?? 'simple';
$isSimple    = ($productType === 'simple');
$stock       = (int)($p['stock'] ?? 0);
$lowThresh   = (int)($p['low_stock_threshold'] ?? 5);
$isSoldOut   = ($stock <= 0 && empty($p['allow_backorder']));

$primaryImg   = product_image_url($p['image'] ?? null, 'thumbnail', $pid);
$fullImg      = product_image_url($p['image'] ?? null, 'full', $pid);
$secondImgRaw = !empty($p['second_image']) ? $p['second_image'] : (function_exists('product_second_image') ? product_second_image($pid) : null);
$secondImg    = !empty($secondImgRaw) ? product_image_url($secondImgRaw, 'thumbnail', $pid) : null;
$secondFull   = !empty($secondImgRaw) ? product_image_url($secondImgRaw, 'full', $pid) : null;
$productUrl   = route('product', ['slug' => $p['slug']]);
$catSlug     = !empty($p['category_slug']) ? $p['category_slug'] : 'all';
$itemCol     = !empty($productCardCol) ? $productCardCol : 'col-6 col-md-4 col-lg-3';
$extraClass  = !empty($productCardExtraClass) ? ' ' . $productCardExtraClass : '';
?>
<div class="<?= htmlspecialchars($itemCol, ENT_QUOTES, 'UTF-8') ?> p-b-35 isotope-item <?= htmlspecialchars($catSlug, ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') ?>">
	<!-- Block2 -->
	<div class="block2">
		<div class="block2-pic hov-img0 pos-relative">
			<a href="<?= htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8') ?>" class="dis-block" title="<?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>">
				<img src="<?= htmlspecialchars($primaryImg, ENT_QUOTES, 'UTF-8') ?>"
				     alt="<?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>"
				     class="block2-img-primary dis-block w-full trans-04"
				     loading="lazy"
				     style="width: 100%; aspect-ratio: 1/1; object-fit: cover;"
				     onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='<?= htmlspecialchars($fullImg, ENT_QUOTES, 'UTF-8') ?>';}">
				<?php if ($secondImg): ?>
				<img src="<?= htmlspecialchars($secondImg, ENT_QUOTES, 'UTF-8') ?>"
				     alt="<?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?> - alternate"
				     class="block2-img-hover pos-absolute ab-t-l dis-block w-full h-full trans-04"
				     loading="lazy"
				     style="width: 100%; aspect-ratio: 1/1; object-fit: cover;"
				     onerror="if(!this.dataset.fallback && '<?= htmlspecialchars($secondFull, ENT_QUOTES, 'UTF-8') ?>'){this.dataset.fallback='1';this.src='<?= htmlspecialchars($secondFull, ENT_QUOTES, 'UTF-8') ?>';}">
				<?php endif; ?>
			</a>

			<?php if ($isSoldOut): ?>
				<span class="stext-109 cl0 bg-dark p-lr-8 p-tb-2 pos-absolute ab-t-l m-2" style="border-radius: 2px;">Sold Out</span>
			<?php elseif ($onSale && $discount > 0): ?>
				<span class="stext-109 cl0 bg1 p-lr-8 p-tb-2 pos-absolute ab-t-l m-2" style="border-radius: 2px;">-<?= (int)$discount ?>%</span>
			<?php elseif (!empty($p['featured'])): ?>
				<span class="stext-109 cl0 bg3 p-lr-8 p-tb-2 pos-absolute ab-t-l m-2" style="border-radius: 2px;">Featured</span>
			<?php endif; ?>

			<!-- Quick Actions Overlay (Add to Cart / Buy Now) -->
			<div class="block2-action-wrap pos-absolute w-full p-lr-12 p-b-12 trans-03">
				<?php if ($isSoldOut): ?>
					<a href="<?= htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-card-action btn-card-soldout flex-c-m w-full trans-03 text-decoration-none">
						View Details
					</a>
				<?php elseif (!$isSimple): ?>
					<div class="d-flex gap-2 w-full">
						<a href="<?= htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-card-action btn-card-cart flex-c-m flex-grow-1 trans-03 text-decoration-none" title="Select Options">
							<i class="zmdi zmdi-shopping-cart-plus m-r-5"></i> Add to Cart
						</a>
						<a href="<?= htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-card-action btn-card-buynow flex-c-m flex-grow-1 trans-03 text-decoration-none" title="Buy Now">
							<i class="zmdi zmdi-flash m-r-5"></i> Buy Now
						</a>
					</div>
				<?php else: ?>
					<div class="d-flex gap-2 w-full">
						<button type="button" class="btn-card-action btn-card-cart js-quick-add-cart flex-c-m flex-grow-1 trans-03" data-pid="<?= $pid ?>" title="Add to Cart">
							<i class="zmdi zmdi-shopping-cart-plus m-r-5"></i> Add to Cart
						</button>
						<button type="button" class="btn-card-action btn-card-buynow js-quick-buy-now flex-c-m flex-grow-1 trans-03" data-pid="<?= $pid ?>" title="Buy Now">
							<i class="zmdi zmdi-flash m-r-5"></i> Buy Now
						</button>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="block2-txt flex-w flex-t p-t-14">
			<div class="block2-txt-child1 flex-col-l">
				<a href="<?= htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8') ?>" class="stext-104 cl4 hov-cl1 trans-04 js-name-b2 p-b-6 text-truncate d-block w-full" title="<?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>">
					<?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
				</a>

				<div class="price-wrap">
					<?php if ($onSale): ?>
						<span class="stext-105 cl1 font-weight-bold m-r-6"><?= price((float)$p['sale_price']) ?></span>
						<span class="stext-105 cl3" style="text-decoration: line-through; opacity: 0.7;"><?= price((float)$p['price']) ?></span>
					<?php else: ?>
						<span class="stext-105 cl3 font-weight-bold"><?= price((float)$p['price']) ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="block2-txt-child2 flex-r p-t-3">
				<a href="#" class="btn-addwish-b2 dis-block pos-relative js-addwish-b2" onclick="window.cozaToggleWishlist(<?= $pid ?>, this); return false;" title="Save to wishlist">
					<img class="icon-heart1 dis-block trans-04" src="<?= htmlspecialchars(url('assets/coza/images/icons/icon-heart-01.png'), ENT_QUOTES, 'UTF-8') ?>" alt="ICON">
					<img class="icon-heart2 dis-block trans-04 ab-t-l" src="<?= htmlspecialchars(url('assets/coza/images/icons/icon-heart-02.png'), ENT_QUOTES, 'UTF-8') ?>" alt="ICON">
				</a>
			</div>
		</div>
	</div>
</div>
