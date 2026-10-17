<?php
/**
 * Customer Invoice / Packing Slip View (Prompt 13).
 * Customer-safe printable invoice strictly enforcing customer ownership.
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

$customer = customer_require_login();
$cid = (int)$customer['id'];

$orderNumber = trim((string)($_GET['o'] ?? ''));
$orderId = (int)($_GET['id'] ?? 0);

if ($orderNumber === '' && $orderId <= 0) {
    http_response_code(404);
    echo '<div style="font-family:sans-serif;text-align:center;padding:50px;"><h2>Order Not Found</h2><p>Invalid order reference.</p></div>';
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT * FROM orders
     WHERE (:onum != '' AND order_number = :onum AND customer_id = :cid)
        OR (:oid > 0 AND id = :oid AND customer_id = :cid)
     LIMIT 1"
);
$stmt->execute([
    ':onum' => $orderNumber,
    ':oid'  => $orderId,
    ':cid'  => $cid,
]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo '<div style="font-family:sans-serif;text-align:center;padding:50px;"><h2>Order Not Found</h2><p>This invoice does not exist or you do not have permission to view it.</p></div>';
    exit;
}

$stmtItems = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC");
$stmtItems->execute([':id' => (int)$order['id']]);
$items = $stmtItems->fetchAll();

$siteName     = setting('site_name', APP_NAME);
$storeAddress = setting('store_address', '');
$storePhone   = setting('store_phone', '');
$storeEmail   = setting('store_email', '');
$storeLogo    = setting('store_logo', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice - <?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      color: #212529;
      background: #f8f9fa;
      padding: 24px;
    }
    .invoice-card {
      background: #ffffff;
      max-width: 860px;
      margin: 0 auto;
      padding: 40px;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    }
    .cod-collect-banner {
      background: #fff3cd;
      border: 2px dashed #ffc107;
      border-radius: 6px;
      padding: 16px;
      text-align: center;
      margin-bottom: 24px;
    }
    @media print {
      body {
        background: #ffffff !important;
        padding: 0 !important;
      }
      .invoice-card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        max-width: 100% !important;
      }
      .no-print {
        display: none !important;
      }
    }
  </style>
</head>
<body>

  <!-- Screen Action Toolbar -->
  <div class="container text-center mb-4 no-print" style="max-width: 860px;">
    <button onclick="window.print()" class="btn btn-dark btn-lg px-4 me-2">
      <?= icon('printer', 'i-sm me-2') ?> Print Invoice
    </button>
    <a href="<?= e(shop_url('account/order.php?o=' . urlencode($order['order_number']))) ?>" class="btn btn-outline-secondary btn-lg">
      <?= icon('arrow-left', 'i-sm me-1') ?> Back to Order Details
    </a>
  </div>

  <div class="invoice-card">
    <!-- Header -->
    <div class="row align-items-center mb-4 pb-3 border-bottom">
      <div class="col-7">
        <?php if (!empty($storeLogo)): ?>
          <img src="<?= e(UPLOADS_URL . 'branding/' . $storeLogo) ?>" alt="<?= e($siteName) ?>" style="max-height: 50px; margin-bottom: 8px;">
        <?php else: ?>
          <h3 class="fw-bold mb-1"><?= e($siteName) ?></h3>
        <?php endif; ?>
        <div class="text-muted small">
          <?php if (!empty($storeAddress)): ?>
            <?= nl2br(e($storeAddress)) ?><br>
          <?php endif; ?>
          <?php if (!empty($storePhone)): ?>
            Phone: <?= e($storePhone) ?> &bull;
          <?php endif; ?>
          <?php if (!empty($storeEmail)): ?>
            Email: <?= e($storeEmail) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-5 text-end">
        <h2 class="h3 fw-bold text-uppercase mb-1">Invoice</h2>
        <div class="fs-6 fw-semibold text-primary mb-1"><?= e($order['order_number']) ?></div>
        <div class="text-muted small">Date: <?= date('F j, Y', strtotime($order['created_at'])) ?></div>
        <div class="text-muted small">Status: <span class="badge bg-secondary text-uppercase"><?= e($order['status']) ?></span></div>
      </div>
    </div>

    <!-- COD Banner -->
    <?php if ($order['payment_method'] === 'cod'): ?>
      <div class="cod-collect-banner">
        <div class="fs-5 fw-bold text-ink d-flex align-items-center">
          <?= icon('cash', 'i-md text-warning me-2') ?>
          Cash on Delivery Amount: <?= e(price($order['total'])) ?>
        </div>
        <div class="text-muted small mt-1">Please keep this amount ready to hand to the delivery courier.</div>
      </div>
    <?php endif; ?>

    <!-- Bill To / Deliver To -->
    <div class="row mb-4">
      <div class="col-6">
        <h6 class="text-uppercase text-muted small fw-bold mb-2">Deliver To</h6>
        <div class="fw-bold"><?= e($order['customer_name']) ?></div>
        <div><?= nl2br(e($order['shipping_address'])) ?></div>
        <div><?= e($order['shipping_city']) ?><?= !empty($order['shipping_zip']) ? ' - ' . e($order['shipping_zip']) : '' ?></div>
        <div><?= e($order['shipping_country'] ?: 'Bangladesh') ?></div>
        <div class="mt-2 text-muted small"><?= icon('phone', 'i-sm me-1') ?> <?= e($order['customer_phone']) ?></div>
        <?php if (!empty($order['customer_email'])): ?>
          <div class="text-muted small"><?= icon('mail', 'i-sm me-1') ?> <?= e($order['customer_email']) ?></div>
        <?php endif; ?>
      </div>
      <div class="col-6 text-end">
        <h6 class="text-uppercase text-muted small fw-bold mb-2">Order Information</h6>
        <div class="small">Payment Method: <strong><?= e(strtoupper($order['payment_method'])) ?></strong></div>
        <div class="small">Payment Status: <strong><?= e(ucfirst($order['payment_status'])) ?></strong></div>
        <div class="small">Shipping: <strong><?= e($order['shipping_method_name'] ?: 'Standard') ?></strong></div>
        <?php if (!empty($order['tracking_number'])): ?>
          <div class="small mt-1">
            Tracking #: <strong><?= e($order['tracking_number']) ?></strong>
            <?= !empty($order['courier']) ? '(' . e($order['courier']) . ')' : '' ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Line Items Table -->
    <table class="table table-bordered align-middle mb-4">
      <thead class="table-light">
        <tr>
          <th style="width: 50px;" class="text-center">#</th>
          <th>Item Description</th>
          <th style="width: 120px;" class="text-end">Price</th>
          <th style="width: 70px;" class="text-center">Qty</th>
          <th style="width: 120px;" class="text-end">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $idx => $it): ?>
          <tr>
            <td class="text-center small text-muted"><?= $idx + 1 ?></td>
            <td>
              <div class="fw-semibold"><?= e($it['product_name']) ?></div>
              <?php if (!empty($it['variant_label'])): ?>
                <div class="small text-muted"><?= e($it['variant_label']) ?></div>
              <?php endif; ?>
              <?php if (!empty($it['variant_sku']) || !empty($it['product_sku'])): ?>
                <div class="text-muted" style="font-size: 0.75rem;">SKU: <?= e($it['variant_sku'] ?: $it['product_sku']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= e(price($it['unit_price'])) ?></td>
            <td class="text-center"><?= (int)$it['quantity'] ?></td>
            <td class="text-end fw-semibold"><?= e(price($it['line_total'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" class="text-end text-muted">Subtotal</td>
          <td class="text-end fw-semibold"><?= e(price($order['subtotal'])) ?></td>
        </tr>
        <?php if ((float)$order['discount_total'] > 0): ?>
          <tr class="text-success">
            <td colspan="4" class="text-end">Discount <?= !empty($order['coupon_code']) ? '(' . e($order['coupon_code']) . ')' : '' ?></td>
            <td class="text-end fw-semibold">-<?= e(price($order['discount_total'])) ?></td>
          </tr>
        <?php endif; ?>
        <tr>
          <td colspan="4" class="text-end text-muted">Shipping Fee</td>
          <td class="text-end"><?= (float)$order['shipping_fee'] > 0 ? e(price($order['shipping_fee'])) : 'Free' ?></td>
        </tr>
        <?php if ((float)$order['cod_fee'] > 0): ?>
          <tr>
            <td colspan="4" class="text-end text-muted">COD Handling Fee</td>
            <td class="text-end"><?= e(price($order['cod_fee'])) ?></td>
          </tr>
        <?php endif; ?>
        <tr class="fs-5 fw-bold table-light">
          <td colspan="4" class="text-end">Total</td>
          <td class="text-end text-primary"><?= e(price($order['total'])) ?></td>
        </tr>
      </tfoot>
    </table>

    <div class="text-center text-muted small mt-4 pt-3 border-top">
      Thank you for shopping with <?= e($siteName) ?>! If you have questions about your order, please contact our support team.
    </div>
  </div>

</body>
</html>
