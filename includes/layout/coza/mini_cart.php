<?php
$loadedCart    = cart_load();
$miniCartItems = $loadedCart['items'] ?? [];
$miniCartTotal = $loadedCart['subtotal'] ?? 0.0;
?>
	<!-- Slide-out Cart Panel -->
	<div class="wrap-header-cart js-panel-cart">
		<div class="s-full js-hide-cart"></div>

		<div class="header-cart flex-col-l p-l-65 p-r-25">
			<div class="header-cart-title flex-w flex-sb-m p-b-8">
				<span class="mtext-103 cl2">
					Your Cart (<?= (int)cart_count() ?>)
				</span>

				<div class="fs-35 lh-10 cl2 p-lr-5 pointer hov-cl1 trans-04 js-hide-cart">
					<i class="zmdi zmdi-close"></i>
				</div>
			</div>
			
			<div class="header-cart-content flex-w js-pscroll" id="cozaMiniCartContent">
				<?php if (empty($miniCartItems)): ?>
					<div class="w-full text-center p-t-50 p-b-50">
						<i class="zmdi zmdi-shopping-cart-plus fs-50 cl6 d-block m-b-15" style="font-size: 48px;"></i>
						<p class="stext-107 cl6 m-b-20">Your cart is currently empty.</p>
						<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-107 bg3 bor2 hov-btn3 p-lr-15 trans-04 m-auto">
							Start Shopping
						</a>
					</div>
				<?php else: ?>
					<ul class="header-cart-wrapitem w-full" id="cozaMiniCartList">
						<?php foreach ($miniCartItems as $cartKey => $item): ?>
							<?php
							$pImg = product_image_url($item['image'] ?? null, 'thumbnail', (int)($item['product_id'] ?? 0));
							$slug = !empty($item['product']['slug']) ? $item['product']['slug'] : ($item['slug'] ?? '');
							$pUrl = !empty($slug) ? route('product', ['slug' => $slug]) : route('cart');
							$itemQty = (int)($item['qty'] ?? ($item['quantity'] ?? 1));
							$itemPrice = (float)($item['unit_price'] ?? ($item['price'] ?? 0.0));
							?>
							<li class="header-cart-item flex-w flex-t m-b-12" data-cart-key="<?= htmlspecialchars((string)($item['cart_key'] ?? $cartKey), ENT_QUOTES, 'UTF-8') ?>">
								<div class="header-cart-item-img">
									<img src="<?= htmlspecialchars($pImg, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['product_name'] ?? 'Product', ENT_QUOTES, 'UTF-8') ?>" style="width: 60px; height: 60px; object-fit: cover;">
								</div>

								<div class="header-cart-item-txt p-t-8">
									<a href="<?= htmlspecialchars($pUrl, ENT_QUOTES, 'UTF-8') ?>" class="header-cart-item-name m-b-6 hov-cl1 trans-04 text-truncate d-block" style="max-width: 170px;">
										<?= htmlspecialchars($item['product_name'] ?? 'Product', ENT_QUOTES, 'UTF-8') ?>
									</a>

									<?php if (!empty($item['variant_label'])): ?>
										<span class="stext-109 cl6 d-block fs-12 m-b-4">
											<?= htmlspecialchars($item['variant_label'], ENT_QUOTES, 'UTF-8') ?>
										</span>
									<?php endif; ?>

									<span class="header-cart-item-info">
										<?= $itemQty ?> &times; <?= price($itemPrice) ?>
									</span>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
					
					<div class="w-full">
						<div class="header-cart-total w-full p-tb-30 fs-18 fw-bold">
							Subtotal: <span class="cl1" id="cozaMiniCartTotal"><?= price($miniCartTotal) ?></span>
						</div>

						<div class="header-cart-buttons flex-w w-full">
							<a href="<?= htmlspecialchars(route('cart'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-107 bg3 bor2 hov-btn3 p-lr-15 trans-04 m-r-8 m-b-10">
								View Cart
							</a>

							<a href="<?= htmlspecialchars(route('checkout'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-107 bg1 bor1 hov-btn1 p-lr-15 trans-04 m-b-10">
								Checkout
							</a>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
