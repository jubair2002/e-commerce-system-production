<?php
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$pageTitle = 'Shopping Cart';

$isAjax = (!empty($_GET['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));

// Helper to return JSON in AJAX mode
$respondJson = function (bool $ok, string $message = '', array $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    $cart = cart_load();
    $totals = cart_totals($cart, $_SESSION['shipping_method_id'] ?? null, $_SESSION['applied_coupon'] ?? null);
    echo json_encode(array_merge([
        'ok'       => $ok,
        'message'  => $message,
        'count'    => cart_count(),
        'subtotal' => $cart['subtotal'],
        'totals'   => $totals,
        'lines'    => $cart['items'],
        'alerts'   => $cart['alerts'],
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
        $qty = (int)($_POST['qty'] ?? 1);

        if ($qty < 1) $qty = 1;
        if ($qty > 99) $qty = 99;

        if ($pid <= 0) {
            if ($isAjax) $respondJson(false, 'Invalid product.');
            flash('danger', 'Invalid product.');
            redirect('cart.php');
        }

        $product = product_get($pid);
        if (!$product || $product['status'] !== 'active') {
            if ($isAjax) $respondJson(false, 'Product not found or inactive.');
            flash('danger', 'This product is no longer available.');
            redirect('cart.php');
        }

        // Variable product requirement
        if (($product['product_type'] ?? 'simple') === 'variable') {
            if (!$vid) {
                if ($isAjax) $respondJson(false, 'Please select a variant combination before adding to cart.');
                flash('warning', 'Please select an option before adding to cart.');
                redirect('product.php?slug=' . urlencode($product['slug']));
            }
            $variant = variant_get($vid);
            if (!$variant || (int)$variant['product_id'] !== $pid || $variant['status'] !== 'active') {
                if ($isAjax) $respondJson(false, 'Selected variant is not available.');
                flash('warning', 'The selected variant is not available.');
                redirect('product.php?slug=' . urlencode($product['slug']));
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
            redirect('cart.php');
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

        $return = (($_POST['action_type'] ?? '') === 'buy_now') ? 'checkout.php' : ($_POST['return_to'] ?? 'cart.php');
        $allowed = ['cart.php', 'checkout.php', 'shop.php', 'index.php'];
        redirect(in_array($return, $allowed, true) ? $return : 'cart.php');
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
            flash('success', 'Cart updated.');
        }
        redirect('cart.php');
    }

    if ($action === 'remove') {
        $key = (string)($_POST['cart_key'] ?? '');
        if ($key !== '') {
            cart_remove($key);
        }
        if ($isAjax) $respondJson(true, 'Item removed.');
        flash('success', 'Item removed from cart.');
        redirect('cart.php');
    }

    if ($action === 'clear') {
        cart_clear();
        unset($_SESSION['applied_coupon']);
        if ($isAjax) $respondJson(true, 'Cart cleared.');
        flash('info', 'Cart cleared.');
        redirect('cart.php');
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
        redirect('cart.php');
    }

    if ($action === 'remove_coupon') {
        unset($_SESSION['applied_coupon']);
        if ($isAjax) $respondJson(true, 'Coupon removed.');
        flash('info', 'Coupon removed.');
        redirect('cart.php');
    }

    if ($action === 'set_shipping') {
        $smid = (int)($_POST['shipping_method_id'] ?? 0);
        if ($smid > 0) {
            $_SESSION['shipping_method_id'] = $smid;
        }
        if ($isAjax) $respondJson(true, 'Shipping method updated.');
        redirect('cart.php');
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

include __DIR__ . '/../../includes/partials/header.php';
?>

<section class="section">
  <div class="container">

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= e(shop_url()) ?>">Home</a></li>
        <li class="breadcrumb-item active">Cart</li>
      </ol>
    </nav>

    <div class="section-header mb-4">
      <h2>Your Cart</h2>
      <p class="text-muted"><?= count($cart['items']) ?> unique item<?= count($cart['items']) === 1 ? '' : 's' ?> (<?= cart_count() ?> total units)</p>
    </div>

    <?php foreach (get_flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show py-2 mb-3">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endforeach; ?>

    <?php if (!empty($cart['alerts'])): ?>
      <div class="alert alert-warning alert-dismissible fade show py-2 mb-3">
        <strong><i class="bi bi-exclamation-triangle"></i> Cart Notice:</strong>
        <ul class="mb-0 mt-1 ps-3">
          <?php foreach ($cart['alerts'] as $alt): ?>
            <li><?= e($alt) ?></li>
          <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <?php if (empty($cart['items'])): ?>
      <div class="empty-state text-center py-5 border rounded bg-light">
        <i class="bi bi-cart-x fs-1 text-muted d-block mb-3"></i>
        <h3>Your cart is empty</h3>
        <p class="text-muted">Explore our curated collection and add your favorite items.</p>
        <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-primary mt-2">
          <i class="bi bi-bag"></i> Continue Shopping
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
                            <a href="<?= e(shop_url('product.php?slug=' . urlencode($p['slug']))) ?>"
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
                            <div class="input-group input-group-sm" style="max-width: 110px;">
                              <input type="number" name="qty[<?= e($i['cart_key']) ?>]"
                                     class="form-control text-center"
                                     value="<?= (int)$i['qty'] ?>"
                                     min="1" max="<?= (int)$i['available_qty'] ?>">
                            </div>
                            <div class="text-muted" style="font-size: 11px;"><?= (int)$i['available_qty'] ?> in stock</div>
                          </td>
                          <td class="text-end fw-semibold"><?= e(price($i['line_total'])) ?></td>
                          <td class="text-end">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    name="remove_key" value="<?= e($i['cart_key']) ?>"
                                    title="Remove" formnovalidate
                                    onclick="return confirm('Remove this item from your cart?');">
                              <i class="bi bi-trash"></i>
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
              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary">
                  <i class="bi bi-arrow-clockwise"></i> Update Cart
                </button>
                <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-outline-secondary">
                  <i class="bi bi-arrow-left"></i> Continue Shopping
                </a>
              </div>
              <div>
                <button type="submit" name="action" value="clear" class="btn btn-link text-danger text-decoration-none px-0"
                        onclick="return confirm('Clear all items from your cart?');">
                  <i class="bi bi-x-circle"></i> Clear Cart
                </button>
              </div>
            </div>
          </form>

          <!-- Coupon code input -->
          <div class="card shadow-sm border-0 mt-4">
            <div class="card-body">
              <h6 class="card-title mb-3"><i class="bi bi-ticket-perforated"></i> Have a Coupon Code?</h6>
              <?php if (!empty($totals['coupon'])): ?>
                <div class="d-flex align-items-center justify-content-between p-2 bg-success bg-opacity-10 rounded border border-success">
                  <div>
                    <span class="badge bg-success me-2"><?= e($totals['coupon']['code']) ?></span>
                    <span class="text-success fw-medium">Coupon applied: -<?= e(price($totals['discount'])) ?></span>
                  </div>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove_coupon">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                  </form>
                </div>
              <?php else: ?>
                <form method="post" class="row g-2">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="apply_coupon">
                  <div class="col-sm-8 col-md-6">
                    <input type="text" name="coupon_code" class="form-control" placeholder="Enter coupon code" required style="text-transform: uppercase;">
                  </div>
                  <div class="col-sm-4 col-md-3">
                    <button type="submit" class="btn btn-outline-dark w-100">Apply Code</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>

        </div>

        <div class="col-lg-4">
          <div class="card shadow-sm border-0 sticky-top" style="top: 90px;">
            <div class="card-body">
              <h5 class="card-title fw-semibold">Order Summary</h5>
              <hr>

              <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">Subtotal</span>
                <strong><?= e(price($totals['subtotal'])) ?></strong>
              </div>

              <?php if ($totals['discount'] > 0): ?>
                <div class="d-flex justify-content-between mb-2 text-success">
                  <span>Coupon Discount</span>
                  <strong>-<?= e(price($totals['discount'])) ?></strong>
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
                <strong>
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
                <span class="fs-4 fw-bold text-primary"><?= e(price($totals['total'])) ?></span>
              </div>

              <a href="<?= e(shop_url('checkout.php')) ?>" class="btn btn-primary btn-lg w-100">
                <i class="bi bi-lock-fill me-1"></i> Proceed to Checkout
              </a>

              <div class="text-center mt-3 text-muted small">
                <i class="bi bi-shield-check text-success"></i> Cash on Delivery available at checkout
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
