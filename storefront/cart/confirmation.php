<?php
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

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

$guestAccountCreated = false;
$guestAccountError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_guest_account']) && $order && empty($order['customer_id'])) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $guestAccountError = 'Invalid or expired session. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $email = strtolower(trim($order['customer_email']));
        $name = trim($order['customer_name']);
        $phone = trim($order['customer_phone'] ?? '');

        $pwdErr = password_ok($password, $email, $name);
        if ($pwdErr !== null) {
            $guestAccountError = $pwdErr;
        } else {
            $pdo = db();
            $stmtC = $pdo->prepare("SELECT id FROM customers WHERE email = :e LIMIT 1");
            $stmtC->execute([':e' => $email]);
            $existing = $stmtC->fetch();

            if ($existing) {
                // Link this order to the existing customer
                $pdo->prepare("UPDATE orders SET customer_id = :cid WHERE id = :oid")
                    ->execute([':cid' => (int)$existing['id'], ':oid' => (int)$order['id']]);
                $order['customer_id'] = (int)$existing['id'];
                $guestAccountCreated = true;
                flash('success', 'This order has been linked to your existing account.');
            } else {
                $requireVerify = (int)setting('customer_require_email_verification', '1') === 1;
                $status = $requireVerify ? 'pending' : 'active';
                $hash = customer_password_hash($password);

                $stmtIns = $pdo->prepare(
                    "INSERT INTO customers (name, email, phone, password_hash, status, created_at)
                     VALUES (:name, :email, :phone, :hash, :status, NOW())"
                );
                $stmtIns->execute([
                    ':name'   => $name,
                    ':email'  => $email,
                    ':phone'  => $phone ?: null,
                    ':hash'   => $hash,
                    ':status' => $status,
                ]);
                $newCid = (int)$pdo->lastInsertId();

                // Link this order
                $pdo->prepare("UPDATE orders SET customer_id = :cid WHERE id = :oid")
                    ->execute([':cid' => $newCid, ':oid' => (int)$order['id']]);
                $order['customer_id'] = $newCid;

                if ($requireVerify) {
                    $verifyHours = (int)setting('customer_verify_token_hours', '48');
                    $token = token_issue($newCid, 'verify_email', max(3600, $verifyHours * 3600));
                    $verifyUrl = route('verify_email', ['t' => $token]);

                    mail_queue_template('customer_verify_email', $email, $name, [
                        'customer_name' => $name,
                        'verify_url'    => $verifyUrl,
                        'expires_in'    => $verifyHours . ' hours',
                        'site_name'     => setting('site_name', 'Store'),
                    ]);
                    flash('success', 'Account created! Please check your email to verify your account.');
                } else {
                    customer_session_start([
                        'id' => $newCid,
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                        'status' => 'active',
                        'email_verified_at' => date('Y-m-d H:i:s'),
                        'password_hash' => $hash,
                    ]);
                    customer_claim_guest_orders($newCid, $email);
                    flash('success', 'Your account has been created and you are now signed in!');
                }

                $guestAccountCreated = true;
            }
        }
    }
}

$storePhone = setting('store_phone', '');
$storeWhatsapp = setting('social_whatsapp', '');
$storeEmail = setting('store_email', '');

include __DIR__ . '/../../includes/partials/header.php';
?>

<section class="section py-5">
  <div class="container" style="max-width: 840px;">

    <?php if (!$isAuthorized || !$order): ?>
      <div class="card shadow-sm border-0 text-center p-5">
        <div class="text-warning display-4 mb-3"><?= icon('shield', 'text-warning', 'Security') ?></div>
        <h3>Order Confirmation Unavailable</h3>
        <p class="text-muted">
          We could not verify the security token for this order, or the order does not exist.<br>
          If you placed this order recently, you can easily look it up on our tracking page.
        </p>
        <div class="mt-3">
          <a href="<?= e(route('track')) ?>" class="btn btn-editorial-primary me-2">
            <?= icon('search', 'me-1') ?> Track Your Order
          </a>
          <a href="<?= e(route('shop')) ?>" class="btn btn-editorial-secondary">
            Continue Shopping
          </a>
        </div>
      </div>
    <?php else: ?>

      <div class="text-center mb-4">
        <div class="display-3 text-success mb-2"><?= icon('check-circle', 'text-success', 'Success') ?></div>
        <h2 class="fw-bold">Thank You for Your Order!</h2>
        <p class="lead text-muted mb-3">
          Your order <strong id="orderNumberVal"><?= e($order['order_number']) ?></strong> has been received and is being processed.
          <button type="button" class="btn btn-sm btn-editorial-secondary ms-2 py-0 px-2" id="btnCopyOrder" title="Copy Order Number">
            <?= icon('copy', 'me-1') ?> Copy
          </button>
        </p>
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
              <?= icon('cash', 'text-warning') ?>
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
            <h5 class="fw-bold mb-2"><?= icon('info', 'me-2 text-primary') ?> Bank Transfer Instructions</h5>
            <p class="mb-0 text-dark">
              <?= nl2br(e(setting('bank_transfer_instructions', 'Please transfer the total amount to our bank account. Use your order number as reference.'))) ?>
            </p>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($guestAccountCreated): ?>
        <div class="card border-success bg-success bg-opacity-10 mb-4 shadow-sm">
          <div class="card-body p-4 d-flex align-items-center">
            <div class="fs-1 text-success me-3"><?= icon('check-circle', 'text-success') ?></div>
            <div>
              <h5 class="fw-bold mb-1">Account Ready &amp; Linked!</h5>
              <p class="mb-0 text-dark">
                This order has been linked to your account. You can now track its progress and view your past orders in your customer dashboard.
              </p>
            </div>
          </div>
        </div>
      <?php elseif ($order && empty($order['customer_id']) && !customer_current()): ?>
        <div class="card border-primary bg-primary bg-opacity-10 mb-4 shadow-sm">
          <div class="card-body p-4">
            <div class="d-flex align-items-center mb-2">
              <div class="fs-3 text-primary me-2"><?= icon('user', 'text-primary') ?></div>
              <h5 class="fw-bold mb-0">Create an Account to Track This Order</h5>
            </div>
            <p class="text-muted small mb-3">
              We already have your name (<strong><?= e($order['customer_name']) ?></strong>) and email (<strong><?= e($order['customer_email']) ?></strong>). Just choose a password to track this delivery and save your information for next time.
            </p>

            <?php if ($guestAccountError): ?>
              <div class="alert alert-danger small py-2 mb-3">
                <?= icon('alert-circle', 'me-1') ?> <?= e($guestAccountError) ?>
              </div>
            <?php endif; ?>

            <form method="post" action="<?= e(route('order_confirmation', ['o' => $order['order_number'], 't' => $token])) ?>" class="row g-2 align-items-end" novalidate>
              <?= csrf_field() ?>
              <input type="hidden" name="create_guest_account" value="1">
              <div class="col-md-7">
                <label for="guestPass" class="form-label small fw-semibold">Choose a Password</label>
                <div class="input-group">
                  <input type="password" name="password" id="guestPass" class="form-control" placeholder="At least 8 characters" required autocomplete="new-password">
                  <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="guestPass" aria-label="Toggle password visibility">
                    <?= icon('eye') ?>
                  </button>
                </div>
              </div>
              <div class="col-md-5">
                <button type="submit" class="btn btn-editorial-primary w-100">
                  Create Account &amp; Link Order
                </button>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <!-- Order Details Cards -->
      <div class="row g-4 mb-4">
        <div class="col-md-6">
          <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
              <h6 class="mb-0 fw-semibold"><?= icon('map-pin', 'text-primary me-2') ?> Delivery Information</h6>
            </div>
            <div class="card-body">
              <div class="fw-bold"><?= e($order['customer_name']) ?></div>
              <div><?= icon('phone', 'text-muted me-1') ?> <?= e($order['customer_phone']) ?></div>
              <?php if (!empty($order['customer_email'])): ?>
                <div><?= icon('mail', 'text-muted me-1') ?> <?= e($order['customer_email']) ?></div>
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
              <h6 class="mb-0 fw-semibold"><?= icon('info', 'text-primary me-2') ?> Need Help?</h6>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
              <p class="text-muted small">
                If you have any questions about this order, please feel free to reach out to our customer care team:
              </p>
              <div class="d-flex flex-column gap-2">
                <?php if ($storeWhatsapp): ?>
                  <a href="https://wa.me/<?= preg_replace('/\D/', '', $storeWhatsapp) ?>?text=<?= urlencode('Hello, I have an inquiry about order ' . $order['order_number']) ?>"
                     target="_blank" rel="noopener" class="btn btn-outline-success btn-sm text-start">
                    <?= icon('phone', 'me-2') ?> Chat on WhatsApp (<?= e($storeWhatsapp) ?>)
                  </a>
                <?php endif; ?>
                <?php if ($storePhone): ?>
                  <a href="tel:<?= e($storePhone) ?>" class="btn btn-editorial-secondary btn-sm text-start">
                    <?= icon('phone', 'me-2') ?> Call: <?= e($storePhone) ?>
                  </a>
                <?php endif; ?>
                <?php if ($storeEmail): ?>
                  <a href="mailto:<?= e($storeEmail) ?>" class="btn btn-editorial-secondary btn-sm text-start">
                    <?= icon('mail', 'me-2') ?> Email: <?= e($storeEmail) ?>
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
          <h6 class="mb-0 fw-semibold"><?= icon('bag', 'text-primary me-2') ?> Purchased Items</h6>
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
        <div class="d-flex gap-2">
          <a href="<?= e(route('track', ['order_number' => $order['order_number'], 'phone' => $order['customer_phone']])) ?>"
             class="btn btn-editorial-secondary btn-lg">
            <?= icon('map-pin', 'me-1') ?> Track Order Status
          </a>
          <button type="button" onclick="window.print()" class="btn btn-editorial-secondary btn-lg d-none d-sm-inline-flex align-items-center">
            <?= icon('printer', 'me-1') ?> Print Receipt
          </button>
        </div>
        <a href="<?= e(route('shop')) ?>" class="btn btn-editorial-primary btn-lg">
          <?= icon('bag', 'me-1') ?> Continue Shopping
        </a>
      </div>

    <?php endif; ?>

  </div>
</section>

<script>
document.getElementById('btnCopyOrder')?.addEventListener('click', function() {
    const num = document.getElementById('orderNumberVal')?.textContent?.trim();
    if (num && navigator.clipboard) {
        navigator.clipboard.writeText(num).then(() => {
            this.innerHTML = '<i class="bi bi-check2"></i> Copied!';
            setTimeout(() => { this.innerHTML = '<i class="bi bi-clipboard"></i> Copy'; }, 2000);
        });
    }
});
</script>
<script src="<?= e(url('assets/js/auth.js')) ?>"></script>
<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
