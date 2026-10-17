<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

$stmtItems = db()->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC');
$stmtItems->execute([':id' => $id]);
$items = $stmtItems->fetchAll();

$siteName = setting('site_name', 'E-Commerce Store');
$storeAddress = setting('store_address', '');
$storePhone = setting('store_phone', '');
$storeEmail = setting('store_email', '');
$storeLogo = setting('store_logo', '');
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
    .signature-box {
      border-top: 1px dashed #6c757d;
      margin-top: 50px;
      padding-top: 8px;
      text-align: center;
      font-size: 13px;
      color: #6c757d;
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

  <!-- Screen-only action toolbar -->
  <div class="container text-center mb-4 no-print" style="max-width: 860px;">
    <button onclick="window.print()" class="btn btn-primary btn-lg px-4 me-2">
      <i class="bi bi-printer me-2"></i> Print Packing Slip / Invoice
    </button>
    <button onclick="window.close()" class="btn btn-outline-secondary btn-lg">
      Close Window
    </button>
  </div>

  <div class="invoice-card">
    <!-- Header -->
    <div class="row align-items-center mb-4 pb-3 border-bottom">
      <div class="col-7">
        <?php if (!empty($storeLogo)): ?>
          <img src="<?= htmlspecialchars(upload_url('branding/' . $storeLogo), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" style="max-height: 50px;" class="mb-2">
        <?php else: ?>
          <h2 class="fw-bold mb-1 text-primary"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php endif; ?>
        <div class="small text-muted">
          <?php if ($storeAddress): ?><?= nl2br(htmlspecialchars($storeAddress, ENT_QUOTES, 'UTF-8')) ?><br><?php endif; ?>
          <?php if ($storePhone): ?>Phone: <?= htmlspecialchars($storePhone, ENT_QUOTES, 'UTF-8') ?> · <?php endif; ?>
          <?php if ($storeEmail): ?>Email: <?= htmlspecialchars($storeEmail, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
        </div>
      </div>
      <div class="col-5 text-end">
        <h4 class="fw-bold mb-0 text-uppercase tracking-wider">PACKING SLIP</h4>
        <div class="fs-6 fw-bold text-dark mt-1">#<?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="small text-muted">Date: <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></div>
      </div>
    </div>

    <!-- COD Collection Banner -->
    <?php if ($order['payment_method'] === 'cod'): ?>
      <div class="cod-collect-banner">
        <div class="text-uppercase fw-bold text-danger fs-6 mb-1">
          <i class="bi bi-cash-stack"></i> CASH ON DELIVERY
        </div>
        <div class="fs-4 fw-bolder text-dark">
          AMOUNT TO COLLECT: <?= htmlspecialchars(format_price($order['total']), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="small text-muted mt-1">
          Courier must collect the exact amount before handing over the parcel.
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-success text-center py-2 mb-4">
        <strong>Payment Method:</strong> <?= strtoupper(htmlspecialchars($order['payment_method'], ENT_QUOTES, 'UTF-8')) ?> · Status: <strong><?= strtoupper(htmlspecialchars($order['payment_status'], ENT_QUOTES, 'UTF-8')) ?></strong>
      </div>
    <?php endif; ?>

    <!-- Recipient & Shipping Information -->
    <div class="row g-3 mb-4">
      <div class="col-6">
        <div class="p-3 bg-light rounded border h-100">
          <h6 class="text-uppercase fw-bold text-muted small mb-2">Ship To / Recipient</h6>
          <div class="fw-bold fs-6"><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="fw-medium text-dark mt-1"><?= nl2br(htmlspecialchars($order['shipping_address'], ENT_QUOTES, 'UTF-8')) ?></div>
          <div><?= htmlspecialchars($order['shipping_city'], ENT_QUOTES, 'UTF-8') ?><?= !empty($order['shipping_zip']) ? ' - ' . htmlspecialchars($order['shipping_zip'], ENT_QUOTES, 'UTF-8') : '' ?></div>
          <div class="mt-2 fw-semibold"><i class="bi bi-telephone"></i> <?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>
      <div class="col-6">
        <div class="p-3 bg-light rounded border h-100">
          <h6 class="text-uppercase fw-bold text-muted small mb-2">Shipping Details</h6>
          <div>Method: <strong><?= htmlspecialchars($order['shipping_method_name'] ?: 'Standard Delivery', ENT_QUOTES, 'UTF-8') ?></strong></div>
          <?php if (!empty($order['courier'])): ?>
            <div>Courier: <strong><?= htmlspecialchars($order['courier'], ENT_QUOTES, 'UTF-8') ?></strong></div>
          <?php endif; ?>
          <?php if (!empty($order['tracking_number'])): ?>
            <div>Tracking #: <code><?= htmlspecialchars($order['tracking_number'], ENT_QUOTES, 'UTF-8') ?></code></div>
          <?php endif; ?>
          <?php if (!empty($order['notes'])): ?>
            <div class="mt-2 text-danger small">
              <strong>Notes:</strong> <?= htmlspecialchars($order['notes'], ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Items Table -->
    <table class="table table-bordered mb-4">
      <thead class="table-light">
        <tr>
          <th style="width: 50px;" class="text-center">#</th>
          <th>Item Description</th>
          <th style="width: 140px;">SKU</th>
          <th style="width: 80px;" class="text-center">Qty</th>
          <th style="width: 120px;" class="text-end">Price</th>
          <th style="width: 120px;" class="text-end">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $idx => $it): ?>
          <tr>
            <td class="text-center"><?= $idx + 1 ?></td>
            <td>
              <div class="fw-bold"><?= htmlspecialchars($it['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php if (!empty($it['variant_label'])): ?>
                <div class="small text-muted fw-semibold"><?= htmlspecialchars($it['variant_label'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </td>
            <td><code><?= htmlspecialchars($it['variant_sku'] ?: ($it['product_sku'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
            <td class="text-center fw-bold fs-6"><?= (int)$it['quantity'] ?></td>
            <td class="text-end"><?= htmlspecialchars(format_price($it['unit_price']), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-end fw-semibold"><?= htmlspecialchars(format_price($it['line_total']), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="5" class="text-end fw-medium">Subtotal</td>
          <td class="text-end"><?= htmlspecialchars(format_price($order['subtotal']), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php if ((float)$order['discount_total'] > 0): ?>
          <tr class="text-success">
            <td colspan="5" class="text-end">Discount <?= !empty($order['coupon_code']) ? '(' . htmlspecialchars($order['coupon_code'], ENT_QUOTES, 'UTF-8') . ')' : '' ?></td>
            <td class="text-end">-<?= htmlspecialchars(format_price($order['discount_total']), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
        <?php endif; ?>
        <tr>
          <td colspan="5" class="text-end fw-medium">Shipping</td>
          <td class="text-end"><?= htmlspecialchars(format_price($order['shipping_fee']), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php if ((float)$order['cod_fee'] > 0): ?>
          <tr>
            <td colspan="5" class="text-end fw-medium">COD Fee</td>
            <td class="text-end"><?= htmlspecialchars(format_price($order['cod_fee']), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
        <?php endif; ?>
        <tr class="fs-5 fw-bold">
          <td colspan="5" class="text-end">Grand Total</td>
          <td class="text-end"><?= htmlspecialchars(format_price($order['total']), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      </tfoot>
    </table>

    <!-- Signatures -->
    <div class="row pt-4 mt-4">
      <div class="col-4">
        <div class="signature-box">
          Prepared By / Warehouse
        </div>
      </div>
      <div class="col-4">
        <div class="signature-box">
          Delivery Courier Signature
        </div>
      </div>
      <div class="col-4">
        <div class="signature-box">
          Customer Received Signature
        </div>
      </div>
    </div>

  </div>

</body>
</html>
