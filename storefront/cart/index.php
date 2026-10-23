<?php
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
        'ok'           => $ok,
        'message'      => $message,
        'count'        => cart_count(),
        'subtotal'     => $cart['subtotal'],
        'subtotal_fmt' => price($totals['subtotal']),
        'total'        => $totals['total'],
        'total_fmt'    => price($totals['total']),
        'discount'     => $totals['discount'],
        'discount_fmt' => price($totals['discount']),
        'shipping_fee' => $totals['shipping_fee'],
        'shipping_fee_fmt' => $totals['shipping_fee'] > 0 ? price($totals['shipping_fee']) : 'Free',
        'totals'       => $totals,
        'lines'        => $lines,
        'alerts'       => $cart['alerts'],
    ], $extra));
    exit;
};

// ----- Handle POST actions -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                if ($isAjax) $respondJson(false, 'Please select a variant combination before adding to cart.');
                flash('warning', 'Please select an option before adding to cart.');
                redirect(route('product', ['slug' => $product['slug']]));
            }
            $variant = variant_get($vid);
            if (!$variant || (int)$variant['product_id'] !== $pid || $variant['status'] !== 'active') {
                if ($isAjax) $respondJson(false, 'Selected variant is not available.');
                flash('warning', 'The selected variant is not available.');
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
            $msg = 'Coupon "' . htmlspecialchars($res['coupon']['code']) . '" applied! Discount: ' . format_price($res['discount']);
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

<section class="section">
  <div class="container">

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= e(route('home')) ?>">Home</a></li>
        <li class="breadcrumb-item active">Cart</li>
      </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
      <div>
        <h2 class="mb-1">Your Cart</h2>
        <p class="text-muted small mb-0"><?= count($cart['items']) ?> unique item<?= count($cart['items']) === 1 ? '' : 's' ?> (<?= cart_count() ?> total units)</p>
      </div>
      <div>
        <a href="<?= e(route('shop')) ?>" class="btn btn-editorial-secondary">
          <?= icon('arrow-left', 'me-1') ?> Continue Shopping
        </a>
      </div>
    </div>

    <?php if ($freeShippingThreshold !== null && $freeShippingThreshold > 0 && !empty($cart['items'])): ?>
      <?php
        $qualifies = ($totals['discounted_subtotal'] >= $freeShippingThreshold);
        $diff = max(0, $freeShippingThreshold - $totals['discounted_subtotal']);
        $pct = min(100, round(($totals['discounted_subtotal'] / $freeShippingThreshold) * 100));
      ?>
      <div class="card border-0 bg-light p-3 mb-4 rounded-3 shadow-sm">
        <div class="d-flex justify-content-between align-items-center mb-1 small fw-semibold">
          <span>
            <?php if ($qualifies): ?>
              <?= icon('check-circle', 'text-success me-1') ?> You unlocked <strong>FREE Delivery!</strong>
            <?php else: ?>
              <?= icon('truck', 'text-primary me-1') ?> Add <strong><?= e(price($diff)) ?></strong> more to qualify for <strong>FREE Delivery</strong>
            <?php endif; ?>
          </span>
          <span class="text-muted"><?= $pct ?>%</span>
        </div>
        <div class="progress" style="height: 6px;">
          <div class="progress-bar <?= $qualifies ? 'bg-success' : 'bg-primary' ?>" role="progressbar" style="width: <?= $pct ?>%;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach (get_flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show py-2 mb-3">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>

    <?php if (!empty($cart['alerts'])): ?>
      <div class="alert alert-warning alert-dismissible fade show py-2 mb-3">
        <strong><?= icon('alert-circle', 'me-1') ?> Cart Notice:</strong>
        <ul class="mb-0 mt-1 ps-3">
          <?php foreach ($cart['alerts'] as $alt): ?>
            <li><?= e($alt) ?></li>
          <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <?php if (empty($cart['items'])): ?>
      <div class="empty-state-card text-center py-5">
        <div class="empty-state-icon mb-3"><?= icon('bag') ?></div>
        <h3>Your cart is empty</h3>
        <p class="text-muted">Explore our curated collection and add your favorite items.</p>
        <a href="<?= e(route('shop')) ?>" class="btn btn-editorial-primary mt-2">
          <?= icon('bag', 'me-1') ?> Continue Shopping
        </a>
      </div>
    <?php else: ?>
      <div class="row g-4">
        <div class="col-lg-8">

          <form method="post" id="cartForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">

            <div class="card shadow-sm border-0">
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table align-middle mb-0 cart-table">
                    <thead class="table-light">
                      <tr>
                        <th colspan="2">Product</th>
                        <th>Price</th>
                        <th style="width:130px">Quantity</th>
                        <th class="text-end">Total</th>
                        <th style="width:46px"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($cart['items'] as $i): $p = $i['product']; ?>
                        <tr>
                          <td style="width:84px">
                            <img src="<?= e(product_image_url($i['image'])) ?>"
                                 class="rounded border" width="64" height="64"
                                 style="object-fit:cover" alt="<?= e($i['product_name']) ?>">
                          </td>
                          <td>
                            <a href="<?= e(route('product', ['slug' => $p['slug']])) ?>"
                               class="fw-semibold text-reset text-decoration-none">
                              <?= e($i['product_name']) ?>
                            </a>
                            <?php if (!empty($i['variant_label'])): ?>
                              <div class="small text-muted mt-1">
                                <span class="badge bg-light text-dark border"><?= e($i['variant_label']) ?></span>
                              </div>
                            <?php endif; ?>
                            <?php if (!empty($i['sku'])): ?>
                              <div class="small text-muted">SKU: <code><?= e($i['sku']) ?></code></div>
                            <?php endif; ?>
                          </td>
                          <td><?= e(price($i['unit_price'])) ?></td>
                          <td>
                            <div class="cart-qty-stepper" data-cart-key="<?= e($i['cart_key']) ?>">
                              <button type="button" class="cart-qty-btn cart-qty-minus" aria-label="Decrease quantity" <?= (int)$i['qty'] <= 1 ? 'disabled' : '' ?>>&minus;</button>
                              <input type="number" name="qty[<?= e($i['cart_key']) ?>]"
                                     class="cart-qty-input"
                                     value="<?= (int)$i['qty'] ?>"
                                     min="1" max="<?= (int)$i['available_qty'] ?>"
                                     data-cart-key="<?= e($i['cart_key']) ?>"
                                     aria-label="Item quantity">
                              <button type="button" class="cart-qty-btn cart-qty-plus" aria-label="Increase quantity" <?= (int)$i['qty'] >= (int)$i['available_qty'] ? 'disabled' : '' ?>>&plus;</button>
                            </div>
                            <div class="text-muted" style="font-size: 11px; margin-top: 4px;"><?= (int)$i['available_qty'] ?> in stock</div>
                          </td>
                          <td class="text-end fw-semibold" data-line-total-key="<?= e($i['cart_key']) ?>"><?= e(price($i['line_total'])) ?></td>
                          <td class="text-end">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    name="remove_key" value="<?= e($i['cart_key']) ?>"
                                    title="Remove" formnovalidate
                                    onclick="return confirm('Remove this item from your cart?');">
                              <?= icon('trash') ?>
                            </button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="d-flex gap-2 mt-3 flex-wrap justify-content-between align-items-center">
              <div>
                <a href="<?= e(route('shop')) ?>" class="btn btn-editorial-secondary">
                  <?= icon('arrow-left', 'me-1') ?> Continue Shopping
                </a>
              </div>
              <div>
                <button type="submit" name="action" value="clear" class="btn btn-link text-danger text-decoration-none px-0"
                        onclick="return confirm('Clear all items from your cart?');">
                  <?= icon('trash', 'me-1') ?> Clear Cart
                </button>
              </div>
            </div>
          </form>

        </div>

        <div class="col-lg-4">
          <div class="sticky-top" style="top: 90px;">

            <!-- Coupon Code: Right side, Top of Order Summary -->
            <div class="card shadow-sm border-0 mb-3">
              <div class="card-body p-3">
                <h6 class="card-title fw-semibold mb-2 d-flex align-items-center gap-1" style="font-size: 0.875rem;">
                  <?= icon('bag') ?> Have a Coupon Code?
                </h6>
                <?php if (!empty($totals['coupon'])): ?>
                  <div class="d-flex align-items-center justify-content-between p-2 rounded" style="background: var(--bg-soft); border: 1px solid var(--line);">
                    <div class="d-flex align-items-center gap-2">
                      <span class="badge" style="background-color: var(--accent); color: #ffffff; letter-spacing: 0.05em; font-weight: 600; font-size: 11px; padding: 4px 8px;"><?= e($totals['coupon']['code']) ?></span>
                      <span class="small fw-bold" style="color: var(--ok);">-<?= e(price($totals['discount'])) ?></span>
                    </div>
                    <form method="post" class="d-inline m-0">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="remove_coupon">
                      <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.75rem;">Remove</button>
                    </form>
                  </div>
                <?php else: ?>
                  <form method="post" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_coupon">
                    <input type="text" name="coupon_code" class="form-control form-control-sm" placeholder="Enter coupon code" required style="text-transform: uppercase;">
                    <button type="submit" class="btn btn-editorial-secondary btn-sm flex-shrink-0">Apply</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <!-- Order Summary Card -->
            <div class="card shadow-sm border-0">
              <div class="card-body">
                <h5 class="card-title fw-semibold">Order Summary</h5>
                <hr>

                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted">Subtotal</span>
                  <strong id="cartSummarySubtotal"><?= e(price($totals['subtotal'])) ?></strong>
                </div>

                <?php if ($totals['discount'] > 0): ?>
                  <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Coupon Discount</span>
                    <strong style="color: var(--ok);">-<?= e(price($totals['discount'])) ?></strong>
                  </div>
                <?php endif; ?>

                <!-- Shipping estimate selector -->
                <?php if (!empty($shippingMethods)): ?>
                  <div class="mb-3 mt-3">
                    <label class="form-label small fw-semibold text-muted text-uppercase mb-1">Shipping Estimate</label>
                    <form method="post" id="shippingMethodForm">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="set_shipping">
                      <select name="shipping_method_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($shippingMethods as $sm): ?>
                          <?php
                            $isFree = ($sm['free_over'] !== null && (float)$sm['free_over'] > 0 && $totals['discounted_subtotal'] >= (float)$sm['free_over']);
                            $costStr = $isFree ? 'Free' : price($sm['cost']);
                          ?>
                          <option value="<?= (int)$sm['id'] ?>" <?= ((int)$sm['id'] === (int)$selectedShippingId) ? 'selected' : '' ?>>
                            <?= e($sm['name']) ?> (<?= $costStr ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </form>
                  </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted">Estimated Shipping</span>
                  <strong id="cartSummaryShipping">
                    <?= $totals['shipping_fee'] > 0 ? e(price($totals['shipping_fee'])) : '<span class="text-success">Free</span>' ?>
                  </strong>
                </div>

                <?php if ($totals['cod_fee'] > 0): ?>
                  <div class="d-flex justify-content-between mb-2 text-muted">
                    <span>Cash on Delivery Fee</span>
                    <strong><?= e(price($totals['cod_fee'])) ?></strong>
                  </div>
                <?php endif; ?>

                <hr>

                <div class="d-flex justify-content-between align-items-center mb-3">
                  <span class="fs-5 fw-bold">Total</span>
                  <span class="fs-4 fw-bold text-primary" id="cartSummaryTotal"><?= e(price($totals['total'])) ?></span>
                </div>

                <a href="<?= e(route('checkout')) ?>" class="btn btn-editorial-primary btn-lg w-100">
                  <?= icon('lock', 'me-1') ?> Proceed to Checkout
                </a>

                <div class="text-center mt-3 text-muted small">
                  <?= icon('shield', 'text-success me-1') ?> Cash on Delivery available at checkout
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cartForm = document.getElementById('cartForm');
    if (!cartForm) return;

    let isUpdating = false;
    let pendingUpdate = false;
    let debounceTimer = null;

    function applyCartUpdate(data) {
        if (!data || !data.ok) {
            cartForm.submit();
            return;
        }

        // 1. Update line totals and stepper states
        if (Array.isArray(data.lines)) {
            data.lines.forEach(item => {
                const rowTotalEl = document.querySelector(`[data-line-total-key="${item.cart_key}"]`);
                if (rowTotalEl && item.line_total_fmt) {
                    rowTotalEl.textContent = item.line_total_fmt;
                }
                const stepper = document.querySelector(`.cart-qty-stepper[data-cart-key="${item.cart_key}"]`);
                if (stepper) {
                    const input = stepper.querySelector('.cart-qty-input');
                    const minusBtn = stepper.querySelector('.cart-qty-minus');
                    const plusBtn = stepper.querySelector('.cart-qty-plus');
                    if (input) input.value = item.qty;
                    if (minusBtn) minusBtn.disabled = (item.qty <= 1);
                    if (plusBtn) plusBtn.disabled = (item.qty >= item.available_qty);
                }
            });
        }

        // 2. Update summary totals
        const subtotalEl = document.getElementById('cartSummarySubtotal');
        if (subtotalEl && data.subtotal_fmt) {
            subtotalEl.textContent = data.subtotal_fmt;
        }

        const totalEl = document.getElementById('cartSummaryTotal');
        if (totalEl && data.total_fmt) {
            totalEl.textContent = data.total_fmt;
        }

        const shippingEl = document.getElementById('cartSummaryShipping');
        if (shippingEl && data.shipping_fee_fmt) {
            shippingEl.textContent = data.shipping_fee_fmt;
        }

        // 3. Update header cart badges
        document.querySelectorAll('.cart-badge-count').forEach(b => {
            b.textContent = data.count;
            b.style.display = data.count > 0 ? 'inline-block' : 'none';
        });
    }

    function doAjaxUpdate() {
        if (isUpdating) {
            pendingUpdate = true;
            return;
        }
        isUpdating = true;
        const fd = new FormData(cartForm);
        fd.set('action', 'update');

        fetch(window.location.pathname + '?ajax=1', {
            method: 'POST',
            body: fd,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(r => r.json())
        .then(data => {
            isUpdating = false;
            applyCartUpdate(data);
            if (pendingUpdate) {
                pendingUpdate = false;
                doAjaxUpdate();
            }
        })
        .catch(() => {
            isUpdating = false;
            cartForm.submit();
        });
    }

    // Handle plus and minus stepper clicks instantly
    cartForm.addEventListener('click', function(e) {
        const plusBtn = e.target.closest('.cart-qty-plus');
        const minusBtn = e.target.closest('.cart-qty-minus');

        if (plusBtn) {
            e.preventDefault();
            const stepper = plusBtn.closest('.cart-qty-stepper');
            const input = stepper?.querySelector('.cart-qty-input');
            if (input) {
                const max = parseInt(input.getAttribute('max') || '99', 10);
                const cur = parseInt(input.value || '1', 10);
                if (cur < max) {
                    input.value = cur + 1;
                    if (minusBtn) minusBtn.disabled = false;
                    if (cur + 1 >= max) plusBtn.disabled = true;
                    doAjaxUpdate();
                }
            }
        } else if (minusBtn) {
            e.preventDefault();
            const stepper = minusBtn.closest('.cart-qty-stepper');
            const input = stepper?.querySelector('.cart-qty-input');
            if (input) {
                const min = parseInt(input.getAttribute('min') || '1', 10);
                const cur = parseInt(input.value || '1', 10);
                if (cur > min) {
                    input.value = cur - 1;
                    if (cur - 1 <= min) minusBtn.disabled = true;
                    const plus = stepper.querySelector('.cart-qty-plus');
                    if (plus) plus.disabled = false;
                    doAjaxUpdate();
                }
            }
        }
    });

    // Handle manual input change
    cartForm.addEventListener('change', function(e) {
        if (e.target.classList.contains('cart-qty-input')) {
            doAjaxUpdate();
        }
    });

    // Rapid input typing with short 100ms debounce
    cartForm.addEventListener('input', function(e) {
        if (e.target.classList.contains('cart-qty-input')) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(doAjaxUpdate, 120);
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
