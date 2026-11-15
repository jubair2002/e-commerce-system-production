<?php
/**
 * Shopping Cart (Cozastore Edition).
 * Cozastore table-shopping-cart markup with live quantity stepping, coupon code application,
 * shipping estimate selector, cart totals summary, and clean empty state.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$pageTitle = 'Shopping Cart';

$isAjax = (!empty($_GET['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));

// Helper to return JSON in AJAX mode
$respondJson = function (bool $ok, string $message = '', array $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    $cart = cart_load();
    $totals = cart_totals($cart, $_SESSION['shipping_method_id'] ?? null, $_SESSION['applied_coupon'] ?? null, 'cod');
    
    $lines = [];
    foreach ($cart['items'] as $item) {
        $lines[] = [
            'cart_key'       => $item['cart_key'],
            'qty'            => (int)$item['qty'],
            'unit_price'     => (float)$item['unit_price'],
            'unit_price_fmt' => price($item['unit_price']),
            'line_total'     => (float)$item['line_total'],
            'line_total_fmt' => price($item['line_total']),
            'available_qty'  => (int)$item['available_qty'],
        ];
    }

    echo json_encode(array_merge([
        'ok'               => $ok,
        'message'          => $message,
        'count'            => cart_count(),
        'subtotal'         => $cart['subtotal'],
        'subtotal_fmt'     => price($totals['subtotal']),
        'total'            => $totals['total'],
        'total_fmt'        => price($totals['total']),
        'discount'         => $totals['discount'],
        'discount_fmt'     => price($totals['discount']),
        'shipping_fee'     => $totals['shipping_fee'],
        'shipping_fee_fmt' => $totals['shipping_fee'] > 0 ? price($totals['shipping_fee']) : 'Free',
        'totals'           => $totals,
        'lines'            => $lines,
        'alerts'           => $cart['alerts'],
    ], $extra));
    exit;
};

// ----- Handle POST actions -----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $vid = !empty($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
        $qty = (int)($_POST['quantity'] ?? $_POST['qty'] ?? 1);

        if ($qty < 1) $qty = 1;
        if ($qty > 99) $qty = 99;

        if ($pid <= 0) {
            if ($isAjax) $respondJson(false, 'Invalid product.');
            flash('danger', 'Invalid product.');
            redirect(route('cart'));
        }

        $product = product_get($pid);
        if (!$product || $product['status'] !== 'active') {
            if ($isAjax) $respondJson(false, 'Product not found or inactive.');
            flash('danger', 'This product is no longer available.');
            redirect(route('cart'));
        }

        // Variable product requirement
        if (($product['product_type'] ?? 'simple') === 'variable') {
            if (!$vid) {
                if ($isAjax) $respondJson(false, 'Please select an option before adding to cart.');
                flash('warning', 'Please select an option before adding to cart.');
                redirect(route('product', ['slug' => $product['slug']]));
            }
            $variant = variant_get($vid);
            if (!$variant || (int)$variant['product_id'] !== $pid || $variant['status'] !== 'active') {
                if ($isAjax) $respondJson(false, 'The selected option is not available.');
                flash('warning', 'The selected option is not available.');
                redirect(route('product', ['slug' => $product['slug']]));
            }
            $avail = variant_available_qty($variant, $product);
            $itemName = $product['name'] . ' (' . $variant['label'] . ')';
        } else {
            $avail = product_available_qty($pid);
            $itemName = $product['name'];
            $vid = null;
        }

        if ($avail <= 0) {
            if ($isAjax) $respondJson(false, "\"{$itemName}\" is currently out of stock.");
            flash('warning', "\"{$itemName}\" is currently out of stock.");
            redirect(route('cart'));
        }

        $key = cart_key($pid, $vid);
        $currentInCart = (int)($_SESSION['cart'][$key] ?? 0);
        $targetQty = $currentInCart + $qty;

        if ($targetQty > $avail) {
            cart_set($key, $avail);
            $msg = "Only {$avail} available. Cart updated to maximum available quantity.";
            if ($isAjax) $respondJson(true, $msg);
            flash('warning', $msg);
        } else {
            cart_add($pid, $vid, $qty);
            $msg = "Added \"{$itemName}\" to your cart.";
            if ($isAjax) $respondJson(true, $msg);
            flash('success', $msg);
        }

        $return = (($_POST['action_type'] ?? '') === 'buy_now') ? route('checkout') : route('cart');
        redirect($return);
    }

    if ($action === 'update') {
        if (!empty($_POST['remove_key'])) {
            cart_remove((string)$_POST['remove_key']);
            if ($isAjax) $respondJson(true, 'Item removed.');
            flash('success', 'Item removed from cart.');
        } else {
            $items = $_POST['qty'] ?? [];
            if (is_array($items)) {
                foreach ($items as $k => $qty) {
                    $q = (int)$qty;
                    if ($q <= 0) {
                        cart_remove((string)$k);
                    } else {
                        cart_set((string)$k, min(99, $q));
                    }
                }
            }
            if ($isAjax) $respondJson(true, 'Cart updated.');
            if (empty($_POST['auto_update'])) {
                flash('success', 'Cart updated.');
            }
        }
        redirect(route('cart'));
    }

    if ($action === 'remove') {
        $key = (string)($_POST['cart_key'] ?? '');
        if ($key !== '') {
            cart_remove($key);
        }
        if ($isAjax) $respondJson(true, 'Item removed.');
        flash('success', 'Item removed from cart.');
        redirect(route('cart'));
    }

    if ($action === 'clear') {
        cart_clear();
        unset($_SESSION['applied_coupon']);
        if ($isAjax) $respondJson(true, 'Cart cleared.');
        flash('info', 'Cart cleared.');
        redirect(route('cart'));
    }

    if ($action === 'apply_coupon') {
        $code = trim((string)($_POST['coupon_code'] ?? ''));
        $cart = cart_load();
        $res = coupon_validate($code, $cart['subtotal']);
        if ($res['valid']) {
            $_SESSION['applied_coupon'] = $res['coupon']['code'];
            $msg = 'Coupon "' . htmlspecialchars($res['coupon']['code'], ENT_QUOTES, 'UTF-8') . '" applied! Discount: ' . format_price($res['discount']);
            if ($isAjax) $respondJson(true, $msg);
            flash('success', $msg);
        } else {
            unset($_SESSION['applied_coupon']);
            if ($isAjax) $respondJson(false, $res['error']);
            flash('danger', $res['error']);
        }
        redirect(route('cart'));
    }

    if ($action === 'remove_coupon') {
        unset($_SESSION['applied_coupon']);
        if ($isAjax) $respondJson(true, 'Coupon removed.');
        flash('info', 'Coupon removed.');
        redirect(route('cart'));
    }

    if ($action === 'set_shipping') {
        $smid = (int)($_POST['shipping_method_id'] ?? 0);
        if ($smid > 0) {
            $_SESSION['shipping_method_id'] = $smid;
        }
        if ($isAjax) $respondJson(true, 'Shipping method updated.');
        redirect(route('cart'));
    }
}

// Hydrate cart and trigger auto-revalidation against live DB
$cart = cart_load();

// Load shipping methods for estimate selector
$shippingMethods = [];
try {
    $shippingMethods = db()->query(
        "SELECT * FROM shipping_methods WHERE status = 'active' ORDER BY position ASC, id ASC"
    )->fetchAll();
} catch (Throwable $e) {
    $shippingMethods = [];
}

$selectedShippingId = isset($_SESSION['shipping_method_id']) ? (int)$_SESSION['shipping_method_id'] : ($shippingMethods[0]['id'] ?? null);
$appliedCouponCode = $_SESSION['applied_coupon'] ?? null;

$totals = cart_totals($cart, $selectedShippingId, $appliedCouponCode, 'cod');

$freeShippingThreshold = null;
foreach ($shippingMethods as $sm) {
    if ($sm['free_over'] !== null && (float)$sm['free_over'] > 0) {
        if ($freeShippingThreshold === null || (float)$sm['free_over'] < $freeShippingThreshold) {
            $freeShippingThreshold = (float)$sm['free_over'];
        }
    }
}

include __DIR__ . '/../../includes/partials/header.php';
?>

<!-- Modern Cart Stylesheet -->
<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/css/cart-modern.css'), ENT_QUOTES, 'UTF-8') ?>">

<!-- Unified Clean Page Header -->
<section class="sec-page-header p-tb-35" style="background: #f9fafb; border-bottom: 1px solid #eef0f3;">
	<div class="container text-center">
		<h1 class="page-title m-b-6" style="font-size: 28px; font-weight: 700; color: #111827; letter-spacing: -0.5px;">
			Shopping Cart
		</h1>
		<div class="bread-crumb flex-w flex-c-m">
			<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
				Home <i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
			</a>
			<span class="stext-109 cl4">
				Shopping Cart
			</span>
		</div>
	</div>
</section>

<!-- =================== MODERN SHOPPING CART =================== -->
<div class="sec-cart-page">
	<div class="container">

		<!-- Flashes & Cart Notice Alerts -->
		<?php foreach (get_flashes() as $f): ?>
			<div class="alert alert-<?= htmlspecialchars($f['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show p-lr-20 p-tb-12 m-b-20" role="alert" style="border-radius: 10px;">
				<?= htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8') ?>
				<button type="button" class="close" data-dismiss="alert" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
		<?php endforeach; ?>

		<?php if (!empty($cart['alerts'])): ?>
			<div class="alert alert-warning alert-dismissible fade show p-lr-20 p-tb-12 m-b-20" role="alert" style="border-radius: 10px;">
				<strong>Notice:</strong>
				<ul class="m-t-5 m-b-0 p-l-20">
					<?php foreach ($cart['alerts'] as $alt): ?>
						<li><?= htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') ?></li>
					<?php endforeach; ?>
				</ul>
				<button type="button" class="close" data-dismiss="alert" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
		<?php endif; ?>

		<!-- Free Delivery Progress Bar -->
		<?php if ($freeShippingThreshold !== null && $freeShippingThreshold > 0 && !empty($cart['items'])): ?>
			<?php
				$qualifies = ($totals['discounted_subtotal'] >= $freeShippingThreshold);
				$diff = max(0, $freeShippingThreshold - $totals['discounted_subtotal']);
				$pct = min(100, round(($totals['discounted_subtotal'] / $freeShippingThreshold) * 100));
			?>
			<div class="cart-free-shipping-card">
				<div class="cart-free-shipping-header">
					<span class="cart-free-shipping-title">
						<?php if ($qualifies): ?>
							<i class="zmdi zmdi-check-circle fs-18 text-success"></i> You unlocked <strong>FREE Delivery!</strong>
						<?php else: ?>
							<i class="zmdi zmdi-truck fs-18" style="color: #111827;"></i> Add <strong><?= price($diff) ?></strong> more to qualify for <strong>FREE Delivery</strong>
						<?php endif; ?>
					</span>
					<span class="cart-free-shipping-pct"><?= $pct ?>%</span>
				</div>
				<div class="cart-free-shipping-track">
					<div class="cart-free-shipping-bar" style="width: <?= $pct ?>%; background-color: <?= $qualifies ? '#10b981' : '#111827' ?>;"></div>
				</div>
			</div>
		<?php endif; ?>

		<?php if (empty($cart['items'])): ?>
			<!-- Empty Cart State -->
			<div class="cart-empty-card">
				<div class="cart-empty-icon">
					<i class="zmdi zmdi-shopping-cart"></i>
				</div>
				<h2 class="cart-empty-title">Your cart is currently empty</h2>
				<p class="cart-empty-text">Looks like you haven't added anything to your cart yet.</p>
				<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="cart-empty-btn">
					<i class="zmdi zmdi-shopping-basket"></i> Start Shopping
				</a>
			</div>
		<?php else: ?>
			<div class="row">
				<!-- Left Column: Cart Table & Actions -->
				<div class="col-lg-8 m-b-40">
					<form method="post" id="cozaCartForm">
						<?= csrf_field() ?>
						<input type="hidden" name="action" value="update">

						<div class="cart-items-card">
							<table class="cart-table">
								<thead>
									<tr>
										<th>Product</th>
										<th class="text-right">Price</th>
										<th class="text-center">Quantity</th>
										<th class="text-right">Total</th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($cart['items'] as $item): 
										$p = $item['product'];
										$cKey = (string)$item['cart_key'];
										$pImg = product_image_url($item['image'] ?? null, 'thumbnail', (int)$item['product_id']);
										$pUrl = !empty($p['slug']) ? route('product', ['slug' => $p['slug']]) : route('cart');
									?>
									<tr data-cart-row="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>">
										<td class="cart-product-cell-td">
											<div class="cart-product-cell">
												<a href="<?= htmlspecialchars($pUrl, ENT_QUOTES, 'UTF-8') ?>" class="cart-product-thumb">
													<img src="<?= htmlspecialchars($pImg, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?>">
												</a>
												<div class="cart-product-info">
													<a href="<?= htmlspecialchars($pUrl, ENT_QUOTES, 'UTF-8') ?>" class="cart-product-title">
														<?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?>
													</a>
													<?php if (!empty($item['variant_label'])): ?>
														<span class="cart-product-variant">
															<?= htmlspecialchars($item['variant_label'], ENT_QUOTES, 'UTF-8') ?>
														</span>
													<?php endif; ?>
													<?php if (!empty($item['sku'])): ?>
														<span class="cart-product-sku">
															SKU: <?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>
														</span>
													<?php endif; ?>
												</div>
											</div>
										</td>

										<td class="cart-price-cell-td text-right">
											<span class="cart-price-cell">
												<?= price((float)$item['unit_price']) ?>
											</span>
										</td>

										<td class="cart-stepper-cell-td text-center">
											<div class="cart-stepper wrap-num-product" data-cart-key="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>">
												<button type="button" class="cart-stepper-btn cart-minus-btn" aria-label="Decrease quantity">
													<i class="zmdi zmdi-minus"></i>
												</button>

												<input class="cart-stepper-input cart-qty-input" type="number" name="qty[<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>]" value="<?= (int)$item['qty'] ?>" min="1" max="<?= (int)$item['available_qty'] ?>" data-cart-key="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>">

												<button type="button" class="cart-stepper-btn cart-plus-btn" aria-label="Increase quantity">
													<i class="zmdi zmdi-plus"></i>
												</button>
											</div>
										</td>

										<td class="cart-total-cell-td text-right">
											<span class="cart-total-cell" data-line-total-key="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>">
												<?= price((float)$item['line_total']) ?>
											</span>
										</td>

										<td class="cart-remove-cell-td text-center">
											<button type="submit" name="remove_key" value="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>" class="cart-remove-btn" title="Remove item" formnovalidate onclick="return confirm('Remove this item from your cart?');">
												<i class="zmdi zmdi-delete"></i>
											</button>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<!-- Table Bottom Actions Bar -->
							<div class="cart-actions-bar">
								<div class="cart-actions-left">
									<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="cart-continue-link">
										<i class="zmdi zmdi-arrow-left"></i> Continue Shopping
									</a>
									<button type="submit" name="action" value="clear" class="cart-clear-btn" onclick="return confirm('Clear your entire cart?');">
										Clear Cart
									</button>
								</div>

								<button type="submit" name="action" value="update" class="cart-update-btn">
									Update Cart
								</button>
							</div>
						</div>
					</form>

					<!-- Coupon Box -->
					<div class="cart-coupon-card">
						<h5 class="cart-coupon-title">
							<i class="zmdi zmdi-label fs-16"></i> Have a promo or coupon code?
						</h5>
						<?php if (!empty($totals['coupon'])): ?>
							<div class="cart-coupon-applied">
								<span class="cart-coupon-applied-text">
									<i class="zmdi zmdi-check-circle fs-16"></i> Coupon <strong>"<?= htmlspecialchars($totals['coupon']['code'], ENT_QUOTES, 'UTF-8') ?>"</strong> applied! (-<?= price($totals['discount']) ?>)
								</span>
								<form method="post" class="m-0">
									<?= csrf_field() ?>
									<input type="hidden" name="action" value="remove_coupon">
									<button type="submit" class="cart-coupon-remove-btn">
										Remove
									</button>
								</form>
							</div>
						<?php else: ?>
							<form method="post" class="cart-coupon-form">
								<?= csrf_field() ?>
								<input type="hidden" name="action" value="apply_coupon">
								<input class="cart-coupon-input" type="text" name="coupon_code" placeholder="Enter coupon code" required>
								<button type="submit" class="cart-coupon-btn">
									Apply Coupon
								</button>
							</form>
						<?php endif; ?>
					</div>
				</div>

				<!-- Right Column: Order Summary (Sticky Sidebar) -->
				<div class="col-lg-4 m-b-50">
					<div class="cart-summary-card">
						<h3 class="cart-summary-title">
							Order Summary
						</h3>

						<div class="cart-summary-row">
							<span class="cart-summary-label">Subtotal</span>
							<span class="cart-summary-val" id="cartSummarySubtotal"><?= price($totals['subtotal']) ?></span>
						</div>

						<?php if ($totals['discount'] > 0): ?>
						<div class="cart-summary-row">
							<span class="cart-summary-discount-label">Coupon Discount</span>
							<span class="cart-summary-discount-val">-<?= price($totals['discount']) ?></span>
						</div>
						<?php endif; ?>

						<!-- Shipping selector -->
						<div style="margin-top: 14px; margin-bottom: 14px;">
							<div class="d-flex justify-content-between align-items-center">
								<span class="cart-summary-label">Shipping</span>
								<span class="cart-summary-val" id="cartSummaryShipping">
									<?= $totals['shipping_fee'] > 0 ? price($totals['shipping_fee']) : '<span class="text-success font-weight-bold">Free</span>' ?>
								</span>
							</div>

							<?php if (!empty($shippingMethods)): ?>
								<form method="post" id="cartShippingForm">
									<?= csrf_field() ?>
									<input type="hidden" name="action" value="set_shipping">
									<select class="cart-shipping-select" name="shipping_method_id" onchange="this.form.submit()">
										<?php foreach ($shippingMethods as $sm): 
											$isFree = ($sm['free_over'] !== null && (float)$sm['free_over'] > 0 && $totals['discounted_subtotal'] >= (float)$sm['free_over']);
											$costStr = $isFree ? 'Free' : price((float)$sm['cost']);
										?>
											<option value="<?= (int)$sm['id'] ?>" <?= ((int)$sm['id'] === (int)$selectedShippingId) ? 'selected' : '' ?>>
												<?= htmlspecialchars($sm['name'], ENT_QUOTES, 'UTF-8') ?> (<?= $costStr ?>)
											</option>
										<?php endforeach; ?>
									</select>
								</form>
							<?php endif; ?>
						</div>

						<hr class="cart-summary-divider">

						<div class="cart-summary-total-row">
							<span class="cart-summary-total-label">Total</span>
							<span class="cart-summary-total-val" id="cartSummaryTotal"><?= price($totals['total']) ?></span>
						</div>

						<a href="<?= htmlspecialchars(route('checkout'), ENT_QUOTES, 'UTF-8') ?>" class="cart-checkout-btn">
							<i class="zmdi zmdi-lock fs-18"></i> Proceed to Checkout
						</a>

						<div class="cart-trust-note">
							<i class="zmdi zmdi-shield-check text-success fs-16"></i>
							<span>Cash on Delivery available at checkout</span>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>

	</div>
</div>

<!-- Live AJAX Cart Updates Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var cartForm = document.getElementById('cozaCartForm');
    if (!cartForm) return;

    var isUpdating = false;
    var pendingUpdate = false;
    var debounceTimer = null;

    function applyCartUpdate(data) {
        if (!data || !data.ok) {
            cartForm.submit();
            return;
        }

        if (Array.isArray(data.lines)) {
            data.lines.forEach(function(item) {
                var rowTotalEl = document.querySelector('[data-line-total-key="' + item.cart_key + '"]');
                if (rowTotalEl && item.line_total_fmt) {
                    rowTotalEl.textContent = item.line_total_fmt;
                }
                var input = document.querySelector('input.cart-qty-input[data-cart-key="' + item.cart_key + '"]');
                if (input) {
                    input.value = item.qty;
                }
            });
        }

        var subtotalEl = document.getElementById('cartSummarySubtotal');
        if (subtotalEl && data.subtotal_fmt) {
            subtotalEl.textContent = data.subtotal_fmt;
        }

        var totalEl = document.getElementById('cartSummaryTotal');
        if (totalEl && data.total_fmt) {
            totalEl.textContent = data.total_fmt;
        }

        var shippingEl = document.getElementById('cartSummaryShipping');
        if (shippingEl && data.shipping_fee_fmt) {
            shippingEl.textContent = data.shipping_fee_fmt;
        }

        // Header notify badge
        $('.js-show-cart').attr('data-notify', data.count);
    }

    function doAjaxUpdate() {
        if (isUpdating) {
            pendingUpdate = true;
            return;
        }
        isUpdating = true;
        var fd = new FormData(cartForm);
        fd.set('action', 'update');

        fetch(window.location.pathname + '?ajax=1', {
            method: 'POST',
            body: fd,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            isUpdating = false;
            applyCartUpdate(data);
            if (pendingUpdate) {
                pendingUpdate = false;
                doAjaxUpdate();
            }
        })
        .catch(function() {
            isUpdating = false;
            cartForm.submit();
        });
    }

    // Handle +/- clicks
    cartForm.addEventListener('click', function(e) {
        var plusBtn = e.target.closest('.cart-plus-btn');
        var minusBtn = e.target.closest('.cart-minus-btn');

        if (plusBtn) {
            e.preventDefault();
            var wrap = plusBtn.closest('.wrap-num-product');
            var input = wrap ? wrap.querySelector('.cart-qty-input') : null;
            if (input) {
                var max = parseInt(input.getAttribute('max') || '99', 10);
                var cur = parseInt(input.value || '1', 10);
                if (cur < max) {
                    input.value = cur + 1;
                    doAjaxUpdate();
                }
            }
        } else if (minusBtn) {
            e.preventDefault();
            var wrap = minusBtn.closest('.wrap-num-product');
            var input = wrap ? wrap.querySelector('.cart-qty-input') : null;
            if (input) {
                var min = parseInt(input.getAttribute('min') || '1', 10);
                var cur = parseInt(input.value || '1', 10);
                if (cur > min) {
                    input.value = cur - 1;
                    doAjaxUpdate();
                }
            }
        }
    });

    cartForm.addEventListener('change', function(e) {
        if (e.target.classList.contains('cart-qty-input')) {
            doAjaxUpdate();
        }
    });

    cartForm.addEventListener('input', function(e) {
        if (e.target.classList.contains('cart-qty-input')) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(doAjaxUpdate, 150);
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
