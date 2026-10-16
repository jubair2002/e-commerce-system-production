<?php
require_once __DIR__ . '/includes/shop_bootstrap.php';

$pageTitle = 'Order Confirmation';

$orderNumber = trim((string)($_GET['o'] ?? ''));
$token = trim((string)($_GET['t'] ?? ''));
$appKey = defined('APP_KEY') ? APP_KEY : 'secret-key-fallback';
$expectedToken = hash_hmac('sha256', $orderNumber, $appKey);

$isAuthorized = false;
if ($orderNumber !== '') {
    if ($token !== '' && hash_equals($expectedToken, $token)) {
        $isAuthorized = true;
    } elseif (isset($_SESSION['last_placed_order']) && $_SESSION['last_placed_order'] === $orderNumber) {
        $isAuthorized = true;
    }
}

$order = null;
$items = [];

if ($isAuthorized) {
    $stmt = db()->prepare("SELECT * FROM orders WHERE order_number = :n LIMIT 1");
    $stmt->execute([':n' => $orderNumber]);
    $order = $stmt->fetch();

    if ($order) {
        $stmtItems = db()->prepare("SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC");
        $stmtItems->execute([':id' => $order['id']]);
        $items = $stmtItems->fetchAll();
    }
}

$storePhone = setting('store_phone', '');
$storeWhatsapp = setting('social_whatsapp', '');
$storeEmail = setting('store_email', '');

include __DIR__ . '/includes/shop_header.php';
?>

<section class="section py-5">
  <div class="container" style="max-width: 840px;">

    <?php if (!$isAuthorized || !$order): ?>
      <div class="card shadow-sm border-0 text-center p-5">
        <div class="text-warning display-4 mb-3"><i class="bi bi-shield-lock"></i></div>
        <h3>Order Confirmation Unavailable</h3>
        <p class="text-muted">
          We could not verify the security token for this order, or the order does not exist.<br>
          If you placed this order recently, you can easily look it up on our tracking page.
        </p>
        <div class="mt-3">
          <a href="<?= e(shop_url('track.php')) ?>" class="btn btn-primary me-2">
            <i class="bi bi-search"></i> Track Your Order
          </a>
          <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-outline-secondary">
            Continue Shopping
          </a>
        </div>
      </div>
    <?php else: ?>

      <div class="text-center mb-4">
        <div class="display-3 text-success mb-2"><i class="bi bi-check-circle-fill"></i></div>
        <h2 class="fw-bold">Thank You for Your Order!</h2>
        <p class="lead text-muted mb-3">Your order <strong><?= e($order['order_number']) ?></strong> has been received and is being processed.</p>
        <div class="d-inline-flex align-items-center gap-2 bg-light px-3 py-2 rounded border">
          <span class="text-muted small">Status:</span>
          <span class="badge bg-primary text-uppercase"><?= e($order['status']) ?></span>
          <span class="text-muted small ms-2">Placed:</span>
          <span class="fw-semibold small"><?= date('F j, Y, g:i a', strtotime($order['created_at'])) ?></span>
        </div>
      </div>

      <?php if ($order['payment_method'] === 'cod'): ?>
        <!-- COD Amount to prepare callout -->
        <div class="card border-warning bg-warning bg-opacity-10 mb-4 shadow-sm">
          <div class="card-body p-4 d-flex align-items-center">
            <div class="fs-1 text-warning me-3 flex-shrink-0">
              <i class="bi bi-cash-coin"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-1">Cash on Delivery Reminder</h5>
              <p class="mb-0 text-dark">
                Please keep <strong><?= e(price($order['total'])) ?></strong> in cash ready to hand over to the delivery courier when your parcel arrives.
              </p>
            </div>
          </div>
        </div>
      <?php elseif ($order['payment_method'] === 'bank_transfer'): ?>
        <div class="card border-info bg-info bg-opacity-10 mb-4 shadow-sm">
          <div class="card-body p-4">
            <h5 class="fw-bold mb-2"><i class="bi bi-bank me-2"></i> Bank Transfer Instructions</h5>
            <p class="mb-0 text-dark">
              <?= nl2br(e(setting('bank_transfer_instructions', 'Please transfer the total amount to our bank account. Use your order number as reference.'))) ?>
            </p>
          </div>
        </div>
      <?php endif; ?>

      <!-- Order Details Cards -->
      <div class="row g-4 mb-4">
        <div class="col-md-6">
          <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
              <h6 class="mb-0 fw-semibold"><i class="bi bi-geo-alt text-primary me-2"></i> Delivery Information</h6>
            </div>
            <div class="card-body">
              <div class="fw-bold"><?= e($order['customer_name']) ?></div>
              <div><i class="bi bi-telephone text-muted me-1"></i> <?= e($order['customer_phone']) ?></div>
              <?php if (!empty($order['customer_email'])): ?>
                <div><i class="bi bi-envelope text-muted me-1"></i> <?= e($order['customer_email']) ?></div>
              <?php endif; ?>
              <hr class="my-2">
              <div class="text-muted small">Address:</div>
              <div><?= nl2br(e($order['shipping_address'])) ?></div>
              <div><?= e($order['shipping_city']) ?><?= !empty($order['shipping_zip']) ? ', ' . e($order['shipping_zip']) : '' ?></div>
              <div class="mt-2 text-muted small">
                Shipping via: <strong><?= e($order['shipping_method_name'] ?: 'Standard Delivery') ?></strong>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
              <h6 class="mb-0 fw-semibold"><i class="bi bi-info-circle text-primary me-2"></i> Need Help?</h6>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
              <p class="text-muted small">
                If you have any questions about this order, please feel free to reach out to our customer care team:
              </p>
              <div class="d-flex flex-column gap-2">
                <?php if ($storeWhatsapp): ?>
                  <a href="https://wa.me/<?= preg_replace('/\D/', '', $storeWhatsapp) ?>?text=<?= urlencode('Hello, I have an inquiry about order ' . $order['order_number']) ?>"
                     target="_blank" rel="noopener" class="btn btn-outline-success btn-sm text-start">
                    <i class="bi bi-whatsapp me-2"></i> Chat on WhatsApp (<?= e($storeWhatsapp) ?>)
                  </a>
                <?php endif; ?>
                <?php if ($storePhone): ?>
                  <a href="tel:<?= e($storePhone) ?>" class="btn btn-outline-primary btn-sm text-start">
                    <i class="bi bi-telephone me-2"></i> Call: <?= e($storePhone) ?>
                  </a>
                <?php endif; ?>
                <?php if ($storeEmail): ?>
                  <a href="mailto:<?= e($storeEmail) ?>" class="btn btn-outline-secondary btn-sm text-start">
                    <i class="bi bi-envelope me-2"></i> Email: <?= e($storeEmail) ?>
                  </a>
                <?php endif; ?>
              </div>
              <div class="mt-3 text-muted" style="font-size: 11px;">
                Support hours: 9:00 AM – 8:00 PM, Saturday to Thursday.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Items Breakdown -->
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-bag text-primary me-2"></i> Purchased Items</h6>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Product</th>
                  <th class="text-center" style="width: 80px;">Qty</th>
                  <th class="text-end" style="width: 130px;">Unit Price</th>
                  <th class="text-end" style="width: 130px;">Line Total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $item): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= e($item['product_name']) ?></div>
                      <?php if (!empty($item['variant_label'])): ?>
                        <div class="small"><span class="badge bg-light text-dark border"><?= e($item['variant_label']) ?></span></div>
                      <?php endif; ?>
                      <?php if (!empty($item['variant_sku']) || !empty($item['product_sku'])): ?>
                        <div class="small text-muted">SKU: <code><?= e($item['variant_sku'] ?: $item['product_sku']) ?></code></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-center fw-medium"><?= (int)$item['quantity'] ?></td>
                    <td class="text-end"><?= e(price($item['unit_price'])) ?></td>
                    <td class="text-end fw-semibold"><?= e(price($item['line_total'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot class="table-light">
                <tr>
                  <td colspan="3" class="text-end text-muted">Subtotal</td>
                  <td class="text-end fw-semibold"><?= e(price($order['subtotal'])) ?></td>
                </tr>
                <?php if ((float)$order['discount_total'] > 0): ?>
                  <tr class="text-success">
                    <td colspan="3" class="text-end">Coupon Discount <?= !empty($order['coupon_code']) ? '(' . e($order['coupon_code']) . ')' : '' ?></td>
                    <td class="text-end fw-semibold">-<?= e(price($order['discount_total'])) ?></td>
                  </tr>
                <?php endif; ?>
                <tr>
                  <td colspan="3" class="text-end text-muted">Shipping Fee</td>
                  <td class="text-end">
                    <?= ((float)$order['shipping_fee'] > 0) ? e(price($order['shipping_fee'])) : '<span class="text-success">Free</span>' ?>
                  </td>
                </tr>
                <?php if ((float)$order['cod_fee'] > 0): ?>
                  <tr>
                    <td colspan="3" class="text-end text-muted">Cash on Delivery Fee</td>
                    <td class="text-end"><?= e(price($order['cod_fee'])) ?></td>
                  </tr>
                <?php endif; ?>
                <tr class="fs-5 fw-bold">
                  <td colspan="3" class="text-end">Grand Total</td>
                  <td class="text-end text-primary"><?= e(price($order['total'])) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <a href="<?= e(shop_url('track.php?order_number=' . urlencode($order['order_number']) . '&phone=' . urlencode($order['customer_phone']))) ?>"
           class="btn btn-outline-primary btn-lg">
          <i class="bi bi-geo-alt me-1"></i> Track Order Status
        </a>
        <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-primary btn-lg">
          <i class="bi bi-bag me-1"></i> Continue Shopping
        </a>
      </div>

    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
