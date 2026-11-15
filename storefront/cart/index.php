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

<!-- Breadcrumb -->
<div class="container m-t-20 m-b-20">
	<div class="bread-crumb flex-w p-l-0 p-r-15 p-t-15 p-lr-0-lg">
		<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
			Home
			<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
		</a>

		<span class="stext-109 cl4">
			Shopping Cart
		</span>
	</div>
</div>

<!-- =================== COZASTORE SHOPPING CART =================== -->
<div class="bg0 p-t-40 p-b-85">
	<div class="container">

		<!-- Flashes & Cart Notice Alerts -->
		<?php foreach (get_flashes() as $f): ?>
			<div class="alert alert-<?= htmlspecialchars($f['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show p-lr-20 p-tb-12 m-b-20" role="alert">
				<?= htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8') ?>
				<button type="button" class="close" data-dismiss="alert" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
		<?php endforeach; ?>

		<?php if (!empty($cart['alerts'])): ?>
			<div class="alert alert-warning alert-dismissible fade show p-lr-20 p-tb-12 m-b-20" role="alert">
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
			<div class="bor10 p-lr-25 p-tb-15 m-b-30 bg6" style="border-radius: 6px;">
				<div class="flex-w flex-sb-m p-b-8">
					<span class="stext-102 cl2 font-weight-bold">
						<?php if ($qualifies): ?>
							<i class="zmdi zmdi-check-circle cl1 m-r-6"></i> You unlocked <strong>FREE Delivery!</strong>
						<?php else: ?>
							<i class="zmdi zmdi-truck cl1 m-r-6"></i> Add <strong><?= price($diff) ?></strong> more to qualify for <strong>FREE Delivery</strong>
						<?php endif; ?>
					</span>
					<span class="stext-102 cl6"><?= $pct ?>%</span>
				</div>
				<div class="progress" style="height: 6px;">
					<div class="progress-bar <?= $qualifies ? 'bg-success' : 'bg-dark' ?>" role="progressbar" style="width: <?= $pct ?>%;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
				</div>
			</div>
		<?php endif; ?>

		<?php if (empty($cart['items'])): ?>
			<!-- Empty Cart State -->
			<div class="text-center p-tb-100">
				<i class="zmdi zmdi-shopping-cart-plus cl6 d-block m-b-20" style="font-size: 64px;"></i>
				<h3 class="mtext-105 cl2 p-b-10">Your cart is currently empty</h3>
				<p class="stext-115 cl6 p-b-30">Looks like you haven't added anything to your cart yet.</p>
				<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-101 bg1 bor1 hov-btn1 p-lr-15 trans-04 m-auto" style="max-width: 220px;">
					Start Shopping
				</a>
			</div>
		<?php else: ?>
			<div class="row">
				<!-- Left Column: Cart Table & Actions -->
				<div class="col-lg-10 col-xl-7 m-lr-auto m-b-50">
					<div class="m-l-0 m-r--38 m-lr-0-xl">
						<form method="post" id="cozaCartForm">
							<?= csrf_field() ?>
							<input type="hidden" name="action" value="update">

							<div class="wrap-table-shopping-cart">
								<table class="table-shopping-cart">
									<tr class="table_head">
										<th class="column-1">Product</th>
										<th class="column-2"></th>
										<th class="column-3">Price</th>
										<th class="column-4">Quantity</th>
										<th class="column-5">Total</th>
										<th class="p-r-20"></th>
									</tr>

									<?php foreach ($cart['items'] as $item): 
										$p = $item['product'];
										$cKey = (string)$item['cart_key'];
										$pImg = product_image_url($item['image'] ?? null, 'thumbnail', (int)$item['product_id']);
										$pUrl = !empty($p['slug']) ? route('product', ['slug' => $p['slug']]) : route('cart');
									?>
									<tr class="table_row" data-cart-row="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>">
										<td class="column-1">
											<div class="how-itemcart1">
												<img src="<?= htmlspecialchars($pImg, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 60px; height: 60px; object-fit: cover;">
											</div>
										</td>

										<td class="column-2">
											<a href="<?= htmlspecialchars($pUrl, ENT_QUOTES, 'UTF-8') ?>" class="stext-104 cl2 hov-cl1 trans-04 font-weight-bold d-block">
												<?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?>
											</a>
											<?php if (!empty($item['variant_label'])): ?>
												<span class="stext-109 cl6 d-block m-t-2">
													<?= htmlspecialchars($item['variant_label'], ENT_QUOTES, 'UTF-8') ?>
												</span>
											<?php endif; ?>
											<?php if (!empty($item['sku'])): ?>
												<span class="stext-109 cl9 d-block fs-11">
													SKU: <?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>
												</span>
											<?php endif; ?>
										</td>

										<td class="column-3">
											<?= price((float)$item['unit_price']) ?>
										</td>

										<td class="column-4">
											<div class="wrap-num-product flex-w m-l-auto m-r-0" data-cart-key="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>">
												<div class="btn-num-product-down cl8 hov-btn3 trans-04 flex-c-m cart-minus-btn pointer">
													<i class="fs-16 zmdi zmdi-minus"></i>
												</div>

												<input class="mtext-104 cl3 txt-center num-product cart-qty-input" type="number" name="qty[<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>]" value="<?= (int)$item['qty'] ?>" min="1" max="<?= (int)$item['available_qty'] ?>" data-cart-key="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>">

												<div class="btn-num-product-up cl8 hov-btn3 trans-04 flex-c-m cart-plus-btn pointer">
													<i class="fs-16 zmdi zmdi-plus"></i>
												</div>
											</div>
										</td>

										<td class="column-5" data-line-total-key="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>">
											<?= price((float)$item['line_total']) ?>
										</td>

										<td class="p-r-20 text-center">
											<button type="submit" name="remove_key" value="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>" class="btn-link text-danger border-0 bg-transparent pointer" title="Remove item" formnovalidate onclick="return confirm('Remove this item from your cart?');">
												<i class="zmdi zmdi-delete fs-20"></i>
											</button>
										</td>
									</tr>
									<?php endforeach; ?>
								</table>
							</div>

							<!-- Table Bottom Bar (Coupon & Update Cart) -->
							<div class="flex-w flex-sb-m bor15 p-t-18 p-b-15 p-lr-40 p-lr-15-sm">
								<div class="flex-w flex-m m-r-20 m-tb-5">
									<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl2 size-119 bg8 bor13 hov-btn3 p-lr-15 trans-04 pointer m-r-10 m-tb-5">
										Continue Shopping
									</a>
									<button type="submit" name="action" value="clear" class="flex-c-m stext-101 cl1 size-119 bg8 bor13 hov-btn3 p-lr-15 trans-04 pointer m-tb-5" onclick="return confirm('Clear your entire cart?');">
										Clear Cart
									</button>
								</div>

								<button type="submit" name="action" value="update" class="flex-c-m stext-101 cl0 size-119 bg1 bor1 hov-btn1 p-lr-15 trans-04 pointer m-tb-10">
									Update Cart
								</button>
							</div>
						</form>

						<!-- Coupon Bar -->
						<div class="bor15 p-t-20 p-b-20 p-lr-40 p-lr-15-sm m-t-20 bg6">
							<h5 class="stext-101 cl2 font-weight-bold m-b-10">Have a coupon?</h5>
							<?php if (!empty($totals['coupon'])): ?>
								<div class="flex-w flex-sb-m p-lr-15 p-tb-10 bg0 bor10">
									<span class="stext-102 cl1 font-weight-bold">
										Coupon "<?= htmlspecialchars($totals['coupon']['code'], ENT_QUOTES, 'UTF-8') ?>" applied! (-<?= price($totals['discount']) ?>)
									</span>
									<form method="post" class="m-0">
										<?= csrf_field() ?>
										<input type="hidden" name="action" value="remove_coupon">
										<button type="submit" class="stext-109 cl1 hov-cl2 pointer bg-transparent border-0">
											Remove
										</button>
									</form>
								</div>
							<?php else: ?>
								<form method="post" class="flex-w flex-m">
									<?= csrf_field() ?>
									<input type="hidden" name="action" value="apply_coupon">
									<input class="stext-104 cl2 plh4 size-117 bor13 p-lr-20 m-r-10 m-tb-5" type="text" name="coupon_code" placeholder="Enter coupon code" required style="text-transform: uppercase;">
									<button type="submit" class="flex-c-m stext-101 cl2 size-118 bg8 bor13 hov-btn3 p-lr-15 trans-04 pointer m-tb-5">
										Apply coupon
									</button>
								</form>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Right Column: Cart Totals Box -->
				<div class="col-sm-10 col-lg-7 col-xl-5 m-lr-auto m-b-50">
					<div class="bor10 p-lr-40 p-t-30 p-b-40 m-l-63 m-r-40 m-lr-0-xl p-lr-15-sm">
						<h4 class="mtext-109 cl2 p-b-30">
							Cart Totals
						</h4>

						<div class="flex-w flex-t bor12 p-b-13">
							<div class="size-208">
								<span class="stext-110 cl2">
									Subtotal:
								</span>
							</div>

							<div class="size-209">
								<span class="mtext-110 cl2" id="cartSummarySubtotal">
									<?= price($totals['subtotal']) ?>
								</span>
							</div>
						</div>

						<?php if ($totals['discount'] > 0): ?>
						<div class="flex-w flex-t bor12 p-tb-13">
							<div class="size-208">
								<span class="stext-110 cl1">
									Coupon:
								</span>
							</div>

							<div class="size-209">
								<span class="mtext-110 cl1 font-weight-bold">
									-<?= price($totals['discount']) ?>
								</span>
							</div>
						</div>
						<?php endif; ?>

						<!-- Shipping selector -->
						<div class="flex-w flex-t bor12 p-t-15 p-b-30">
							<div class="size-208 w-full-ssm">
								<span class="stext-110 cl2">
									Shipping:
								</span>
							</div>

							<div class="size-209 p-r-18 p-r-0-sm w-full-ssm">
								<?php if (!empty($shippingMethods)): ?>
									<form method="post" id="cartShippingForm">
										<?= csrf_field() ?>
										<input type="hidden" name="action" value="set_shipping">
										<div class="rs1-select2 rs2-select2 bor8 bg0 m-b-12 m-t-9">
											<select class="js-select2" name="shipping_method_id" onchange="this.form.submit()">
												<?php foreach ($shippingMethods as $sm): 
													$isFree = ($sm['free_over'] !== null && (float)$sm['free_over'] > 0 && $totals['discounted_subtotal'] >= (float)$sm['free_over']);
													$costStr = $isFree ? 'Free' : price((float)$sm['cost']);
												?>
													<option value="<?= (int)$sm['id'] ?>" <?= ((int)$sm['id'] === (int)$selectedShippingId) ? 'selected' : '' ?>>
														<?= htmlspecialchars($sm['name'], ENT_QUOTES, 'UTF-8') ?> (<?= $costStr ?>)
													</option>
												<?php endforeach; ?>
											</select>
											<div class="dropDownSelect2"></div>
										</div>
									</form>
								<?php endif; ?>

								<p class="stext-111 cl6 p-t-2" id="cartSummaryShipping">
									<?= $totals['shipping_fee'] > 0 ? 'Estimated: ' . price($totals['shipping_fee']) : 'Free Shipping' ?>
								</p>
							</div>
						</div>

						<div class="flex-w flex-t p-t-27 p-b-33">
							<div class="size-208">
								<span class="mtext-101 cl2 font-weight-bold">
									Total:
								</span>
							</div>

							<div class="size-209 p-t-1">
								<span class="mtext-110 cl1 font-weight-bold" id="cartSummaryTotal">
									<?= price($totals['total']) ?>
								</span>
							</div>
						</div>

						<a href="<?= htmlspecialchars(route('checkout'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-116 bg3 bor14 hov-btn3 p-lr-15 trans-04 pointer">
							Proceed to Checkout
						</a>

						<div class="text-center p-t-20 stext-102 cl6">
							<i class="zmdi zmdi-shield-check cl1 m-r-4"></i> Cash on Delivery available at checkout
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
