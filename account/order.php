<?php
/**
 * Customer Order Details View (Prompt 13).
 * Strict ownership enforcement: returns generic 404 for unknown or other customer orders.
 * Status timeline, track link, print invoice, buy again (with stock clamp), and cancel order.
 */
require_once __DIR__ . '/_layout.php';

$customer = customer_require_login();
$cid = (int)$customer['id'];
$pdo = db();

$orderNumber = trim((string)($_GET['o'] ?? ''));

// Strict ownership query
$stmt = $pdo->prepare(
    "SELECT * FROM orders
     WHERE order_number = :onum AND customer_id = :cid
     LIMIT 1"
);
$stmt->execute([
    ':onum' => $orderNumber,
    ':cid'  => $cid,
]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order Not Found';
    include __DIR__ . '/../includes/partials/header.php';
    ?>
    <div class="container py-5 text-center" style="max-width: 600px;">
      <div class="fs-1 text-muted mb-3"><i class="bi bi-question-circle"></i></div>
      <h1 class="h3 fw-bold mb-2">Order Not Found</h1>
      <p class="text-muted small mb-4">
        The order you requested could not be found or does not belong to your account.
      </p>
      <a href="<?= e(shop_url('account/orders.php')) ?>" class="btn btn-dark btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Back to My Orders
      </a>
    </div>
    <?php
    include __DIR__ . '/../includes/partials/footer.php';
    exit;
}

$orderId = (int)$order['id'];
$canCancelSetting = (int)setting('customer_can_cancel', '1') === 1;
$canCancel = ($canCancelSetting && $order['status'] === 'pending');

// Handle POST actions: Cancel Order & Buy Again
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        flash('danger', 'Invalid or expired session. Please try again.');
        header('Location: ' . shop_url('account/order.php?o=' . urlencode($orderNumber)));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Cancel Order
    if ($action === 'cancel_order') {
        if (!$canCancel) {
            flash('danger', 'This order can no longer be cancelled.');
        } else {
            $pdo->beginTransaction();
            try {
                $stmtCancel = $pdo->prepare(
                    "UPDATE orders
                     SET status = 'cancelled', updated_at = NOW()
                     WHERE id = :id AND customer_id = :cid AND status = 'pending'"
                );
                $stmtCancel->execute([':id' => $orderId, ':cid' => $cid]);

                if ($stmtCancel->rowCount() === 1) {
                    // Restore stock for cancelled order lines
                    if (function_exists('stock_restore_for_order')) {
                        stock_restore_for_order($orderId);
                    }

                    // Record status history
                    $stmtH = $pdo->prepare(
                        "INSERT INTO order_status_history (order_id, status, note, user_id, created_at)
                         VALUES (:oid, 'cancelled', 'Cancelled by customer', NULL, NOW())"
                    );
                    $stmtH->execute([':oid' => $orderId]);

                    $pdo->commit();

                    hook_fire('order.status_changed', [
                        'order_id'   => $orderId,
                        'old_status' => 'pending',
                        'new_status' => 'cancelled',
                        'note'       => 'Cancelled by customer',
                    ]);

                    flash('success', 'Order #' . $orderNumber . ' has been cancelled successfully.');
                } else {
                    $pdo->rollBack();
                    flash('danger', 'Order cancellation failed. The status may have already changed.');
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('danger', 'An error occurred while cancelling your order.');
            }
        }
        header('Location: ' . shop_url('account/order.php?o=' . urlencode($orderNumber)));
        exit;
    }

    // 2. Buy Again Action
    elseif ($action === 'buy_again') {
        $stmtItems = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :oid ORDER BY id ASC");
        $stmtItems->execute([':oid' => $orderId]);
        $orderLines = $stmtItems->fetchAll();

        $added = [];
        $skipped = [];

        foreach ($orderLines as $it) {
            $productId = (int)$it['product_id'];
            $variantId = !empty($it['variant_id']) ? (int)$it['variant_id'] : null;
            $desiredQty = max(1, (int)$it['quantity']);

            $p = product_get($productId);
            if (!$p || ($p['status'] ?? '') !== 'published' || !empty($p['deleted_at'])) {
                $skipped[] = $it['product_name'] . ' (No longer available)';
                continue;
            }

            $availableStock = null;
            if ($variantId) {
                $v = variant_get($variantId);
                if (!$v || empty($v['is_active'])) {
                    $skipped[] = $it['product_name'] . ' - ' . ($it['variant_label'] ?: 'Option') . ' (Unavailable)';
                    continue;
                }
                $availableStock = (int)$v['stock_quantity'];
            } else {
                if (!empty($p['manage_stock'])) {
                    $availableStock = (int)$p['stock_quantity'];
                }
            }

            if ($availableStock !== null) {
                if ($availableStock <= 0) {
                    $skipped[] = $it['product_name'] . ' (Out of stock)';
                    continue;
                }
                if ($desiredQty > $availableStock) {
                    $qtyToAdd = $availableStock;
                    cart_add($productId, $variantId, $qtyToAdd);
                    $added[] = $it['product_name'] . " ({$qtyToAdd} added, stock limited)";
                    continue;
                }
            }

            cart_add($productId, $variantId, $desiredQty);
            $added[] = $it['product_name'] . " ({$desiredQty} added)";
        }

        if (!empty($added)) {
            flash('success', 'Added to cart: ' . implode(', ', $added) . '.');
        }
        if (!empty($skipped)) {
            flash('warning', 'Some items could not be added: ' . implode(', ', $skipped) . '.');
        }

        header('Location: ' . shop_url('cart.php'));
        exit;
    }
}

// Fetch Order Items with product image and slug
$stmtItems = $pdo->prepare(
    "SELECT oi.*, p.slug AS product_slug,
            (SELECT file_path FROM product_images WHERE product_id = oi.product_id ORDER BY is_featured DESC, sort_order ASC, id ASC LIMIT 1) AS product_image
     FROM order_items oi
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE oi.order_id = :oid
     ORDER BY oi.id ASC"
);
$stmtItems->execute([':oid' => $orderId]);
$items = $stmtItems->fetchAll();

// Fetch status history timeline
$stmtHist = $pdo->prepare("SELECT * FROM order_status_history WHERE order_id = :oid ORDER BY id ASC");
$stmtHist->execute([':oid' => $orderId]);
$statusHistory = $stmtHist->fetchAll();

account_layout_start('orders', 'Order ' . $order['order_number'], $customer);
?>

<!-- Header with Order Status and Action Buttons -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <div class="d-flex align-items-center gap-2 mb-1">
      <h1 class="h3 fw-bold mb-0">Order <?= e($order['order_number']) ?></h1>
      <?php
      $statusBadge = 'bg-secondary';
      if ($order['status'] === 'completed' || $order['status'] === 'delivered') $statusBadge = 'bg-success';
      elseif ($order['status'] === 'processing' || $order['status'] === 'shipped') $statusBadge = 'bg-info text-dark';
      elseif ($order['status'] === 'cancelled') $statusBadge = 'bg-danger';
      elseif ($order['status'] === 'pending') $statusBadge = 'bg-warning text-dark';
      ?>
      <span class="badge <?= $statusBadge ?> text-uppercase"><?= e($order['status']) ?></span>
    </div>
    <div class="text-muted small">
      Placed on <?= date('F j, Y, g:i a', strtotime($order['created_at'])) ?> &bull; Payment: <?= strtoupper(e($order['payment_method'])) ?> (<?= ucfirst(e($order['payment_status'])) ?>)
    </div>
  </div>

  <!-- Action Buttons Toolbar -->
  <div class="d-flex flex-wrap align-items-center gap-2">
    <!-- Track Order Button -->
    <a href="<?= e(shop_url('track.php?order_number=' . urlencode($order['order_number']) . '&phone=' . urlencode($order['customer_phone']))) ?>" class="btn btn-outline-dark btn-sm">
      <i class="bi bi-geo-alt me-1"></i> Track Order
    </a>

    <!-- Print Invoice Button -->
    <a href="<?= e(shop_url('account/invoice.php?o=' . urlencode($order['order_number']))) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-printer me-1"></i> Print Invoice
    </a>

    <!-- Buy Again Form -->
    <form method="post" action="order.php?o=<?= urlencode($order['order_number']) ?>" class="m-0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="buy_again">
      <button type="submit" class="btn btn-editorial-primary btn-sm">
        <i class="bi bi-arrow-repeat me-1"></i> Buy Again
      </button>
    </form>

    <!-- Cancel Order Button (Only if pending & allowed) -->
    <?php if ($canCancel): ?>
      <form method="post" action="order.php?o=<?= urlencode($order['order_number']) ?>" class="m-0" onsubmit="return confirm('Are you sure you want to cancel this order? This action cannot be reversed.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel_order">
        <button type="submit" class="btn btn-outline-danger btn-sm">
          <i class="bi bi-x-circle me-1"></i> Cancel Order
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="row g-4">
  <!-- Left Column: Items and Timeline -->
  <div class="col-lg-8">

    <!-- Order Items Card -->
    <div class="account-card p-0 overflow-hidden mb-4">
      <div class="account-card-header p-3 border-bottom m-0">
        <h2 class="account-card-title fs-6">Order Items (<?= count($items) ?>)</h2>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3 py-2 small text-uppercase">Product</th>
              <th class="py-2 small text-uppercase text-end">Price</th>
              <th class="py-2 small text-uppercase text-center">Qty</th>
              <th class="pe-3 py-2 small text-uppercase text-end">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td class="ps-3">
                  <div class="d-flex align-items-center gap-3">
                    <?php if (!empty($it['product_image']) && file_exists(UPLOADS_PATH . '/products/' . $it['product_image'])): ?>
                      <img src="<?= e(UPLOADS_URL . 'products/' . $it['product_image']) ?>" alt="<?= e($it['product_name']) ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;" class="border">
                    <?php else: ?>
                      <div class="border rounded bg-light d-flex align-items-center justify-content-center text-muted" style="width: 50px; height: 50px;">
                        <i class="bi bi-image"></i>
                      </div>
                    <?php endif; ?>
                    <div>
                      <?php if (!empty($it['product_slug'])): ?>
                        <a href="<?= e(shop_url('product.php?slug=' . urlencode($it['product_slug']))) ?>" class="fw-semibold text-dark text-decoration-none">
                          <?= e($it['product_name']) ?>
                        </a>
                      <?php else: ?>
                        <span class="fw-semibold text-dark"><?= e($it['product_name']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($it['variant_label'])): ?>
                        <div class="small text-muted"><?= e($it['variant_label']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($it['variant_sku']) || !empty($it['product_sku'])): ?>
                        <div class="text-muted" style="font-size: 0.72rem;">SKU: <?= e($it['variant_sku'] ?: $it['product_sku']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="text-end small"><?= e(price($it['unit_price'])) ?></td>
                <td class="text-center small"><?= (int)$it['quantity'] ?></td>
                <td class="pe-3 text-end fw-bold"><?= e(price($it['line_total'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Status Timeline Card -->
    <div class="account-card">
      <div class="account-card-header">
        <h2 class="account-card-title fs-6"><i class="bi bi-clock-history me-2 text-primary"></i> Status Timeline</h2>
      </div>

      <?php if (empty($statusHistory)): ?>
        <p class="text-muted small mb-0">Order received on <?= date('M j, Y, g:i a', strtotime($order['created_at'])) ?>.</p>
      <?php else: ?>
        <ul class="order-timeline">
          <?php foreach ($statusHistory as $idx => $h): ?>
            <li class="order-timeline-item">
              <div class="order-timeline-dot <?= ($idx === count($statusHistory) - 1) ? 'active' : '' ?>"></div>
              <div class="fw-bold small text-capitalize mb-0"><?= e($h['status']) ?></div>
              <div class="text-muted small" style="font-size: 0.78rem;">
                <?= date('M j, Y, g:i a', strtotime($h['created_at'])) ?>
                <?php if (!empty($h['note'])): ?>
                  &bull; <?= e($h['note']) ?>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

  </div>

  <!-- Right Column: Delivery Details & Payment Summary -->
  <div class="col-lg-4">

    <!-- Payment Summary -->
    <div class="account-card mb-4">
      <div class="account-card-header">
        <h2 class="account-card-title fs-6">Order Summary</h2>
      </div>
      <div class="d-flex justify-content-between small text-muted mb-2">
        <span>Subtotal</span>
        <span class="text-dark fw-semibold"><?= e(price($order['subtotal'])) ?></span>
      </div>
      <?php if ((float)$order['discount_total'] > 0): ?>
        <div class="d-flex justify-content-between small text-success mb-2">
          <span>Discount <?= !empty($order['coupon_code']) ? '(' . e($order['coupon_code']) . ')' : '' ?></span>
          <span class="fw-semibold">-<?= e(price($order['discount_total'])) ?></span>
        </div>
      <?php endif; ?>
      <div class="d-flex justify-content-between small text-muted mb-2">
        <span>Shipping (<?= e($order['shipping_method_name'] ?: 'Standard') ?>)</span>
        <span class="text-dark fw-semibold"><?= (float)$order['shipping_fee'] > 0 ? e(price($order['shipping_fee'])) : 'Free' ?></span>
      </div>
      <?php if ((float)$order['cod_fee'] > 0): ?>
        <div class="d-flex justify-content-between small text-muted mb-2">
          <span>COD Handling Fee</span>
          <span class="text-dark fw-semibold"><?= e(price($order['cod_fee'])) ?></span>
        </div>
      <?php endif; ?>
      <hr class="my-2">
      <div class="d-flex justify-content-between fs-6 fw-bold mb-3">
        <span>Total</span>
        <span class="text-primary"><?= e(price($order['total'])) ?></span>
      </div>
      <div class="p-2 bg-light rounded text-center small text-muted">
        Payment: <strong><?= strtoupper(e($order['payment_method'])) ?></strong> &bull; Status: <strong class="text-capitalize"><?= e($order['payment_status']) ?></strong>
      </div>
    </div>

    <!-- Shipping Address & Courier Tracking -->
    <div class="account-card">
      <div class="account-card-header">
        <h2 class="account-card-title fs-6">Delivery Information</h2>
      </div>
      <div class="fw-bold mb-1"><?= e($order['customer_name']) ?></div>
      <div class="text-muted small mb-1"><i class="bi bi-telephone me-1"></i> <?= e($order['customer_phone']) ?></div>
      <div class="text-muted small mb-3">
        <?= nl2br(e($order['shipping_address'])) ?><br>
        <?= e($order['shipping_city']) ?><?= !empty($order['shipping_zip']) ? ' - ' . e($order['shipping_zip']) : '' ?><br>
        <?= e($order['shipping_country'] ?: 'Bangladesh') ?>
      </div>

      <?php if (!empty($order['tracking_number'])): ?>
        <div class="p-3 bg-light rounded border">
          <div class="small fw-bold mb-1"><i class="bi bi-truck me-1"></i> Courier Tracking</div>
          <div class="small text-muted">Courier: <strong><?= e($order['courier'] ?: 'Standard Courier') ?></strong></div>
          <div class="small text-muted">Tracking #: <strong><?= e($order['tracking_number']) ?></strong></div>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php
account_layout_end();
