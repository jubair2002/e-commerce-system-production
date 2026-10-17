<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$order = $stmt->fetch();

if (!$order) {
    flash('warning', 'Order not found.');
    admin_redirect('orders.php');
}

$pageTitle = 'Order ' . $order['order_number'];

// Valid status transitions map
$validTransitions = [
    'pending'    => ['processing', 'cancelled'],
    'processing' => ['shipped', 'cancelled'],
    'shipped'    => ['completed', 'cancelled'],
    'completed'  => ['cancelled'], // return / refund
    'cancelled'  => [], // terminal
];

// ----- POST Actions: Status update & Shipping/Tracking details -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $newStatus = trim((string)($_POST['status'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $allowedTargetStatuses = $validTransitions[$order['status']] ?? [];

        if ($newStatus === $order['status']) {
            flash('info', 'Status remains unchanged.');
        } elseif (!in_array($newStatus, $allowedTargetStatuses, true)) {
            flash('danger', 'Invalid status transition from ' . ucfirst($order['status']) . ' to ' . ucfirst($newStatus) . '.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $oldStatus = $order['status'];

                $updateSql = "UPDATE orders SET status = :status";
                $updateParams = [':status' => $newStatus, ':id' => $id];

                // Auto-mark payment as paid when completed for COD
                if ($newStatus === 'completed' && $order['payment_method'] === 'cod') {
                    $updateSql .= ", payment_status = 'paid'";
                }

                $updateSql .= " WHERE id = :id";
                $pdo->prepare($updateSql)->execute($updateParams);

                // If cancelled -> atomic stock restore
                if ($newStatus === 'cancelled') {
                    stock_restore_for_order($id);
                }

                // Update product_sales_summary in same transaction
                if ($newStatus === 'completed' && $oldStatus !== 'completed') {
                    product_sales_summary_record_order($id, $pdo);
                } elseif ($newStatus === 'cancelled' && $oldStatus === 'completed') {
                    product_sales_summary_reverse_order($id, $pdo);
                }

                // Insert into order_status_history
                $uid = $_SESSION['user']['id'] ?? null;
                $historyNote = $note ?: ('Status changed from ' . ucfirst($oldStatus) . ' to ' . ucfirst($newStatus));
                $stmtHist = $pdo->prepare(
                    "INSERT INTO order_status_history (order_id, status, note, user_id)
                     VALUES (:oid, :st, :note, :uid)"
                );
                $stmtHist->execute([
                    ':oid'  => $id,
                    ':st'   => $newStatus,
                    ':note' => $historyNote,
                    ':uid'  => $uid,
                ]);

                $pdo->commit();

                // Fire order.status_changed hook
                hook_fire('order.status_changed', [
                    'order_id'   => $id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'note'       => $historyNote,
                ]);

                log_activity('order.status', "Order {$order['order_number']} status updated: {$oldStatus} -> {$newStatus}");
                flash('success', "Order status successfully updated to " . ucfirst($newStatus) . ".");

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('danger', 'Error updating status: ' . $e->getMessage());
            }
        }
        admin_redirect('order_view.php?id=' . $id);
    }

    if ($action === 'update_tracking') {
        $courier = trim((string)($_POST['courier'] ?? ''));
        $trackingNumber = trim((string)($_POST['tracking_number'] ?? ''));
        $paymentStatus = trim((string)($_POST['payment_status'] ?? $order['payment_status']));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $allowedPay = ['unpaid', 'paid', 'refunded'];
        if (!in_array($paymentStatus, $allowedPay, true)) {
            $paymentStatus = $order['payment_status'];
        }

        db()->prepare(
            "UPDATE orders
             SET courier = :c, tracking_number = :tn, payment_status = :ps, notes = :notes
             WHERE id = :id"
        )->execute([
            ':c'     => $courier ?: null,
            ':tn'    => $trackingNumber ?: null,
            ':ps'    => $paymentStatus,
            ':notes' => $notes ?: null,
            ':id'    => $id,
        ]);

        log_activity('order.update_details', "Updated tracking / notes for order {$order['order_number']}");
        flash('success', 'Order details updated.');
        admin_redirect('order_view.php?id=' . $id);
    }
}

// Fetch items
$stmt = db()->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC');
$stmt->execute([':id' => $id]);
$items = $stmt->fetchAll();

// Fetch timeline history
$stmtHist = db()->prepare(
    "SELECT h.*, u.name as admin_name
     FROM order_status_history h
     LEFT JOIN users u ON u.id = h.user_id
     WHERE h.order_id = :id
     ORDER BY h.created_at ASC"
);
$stmtHist->execute([':id' => $id]);
$history = $stmtHist->fetchAll();

$statusBadge = [
    'pending'    => 'text-bg-warning',
    'processing' => 'text-bg-info',
    'shipped'    => 'text-bg-primary',
    'completed'  => 'text-bg-success',
    'cancelled'  => 'text-bg-secondary',
];

$possibleTransitions = $validTransitions[$order['status']] ?? [];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap align-items-center mb-4 gap-2">
  <div>
    <a href="<?= e(admin_url('orders.php')) ?>" class="text-decoration-none small text-muted">
      <i class="bi bi-arrow-left"></i> Back to Orders
    </a>
    <h3 class="mt-1 mb-0">
      Order #<?= e($order['order_number']) ?>
      <span class="badge <?= e($statusBadge[$order['status']] ?? 'text-bg-secondary') ?> align-middle ms-2 text-uppercase fs-6">
        <?= e($order['status']) ?>
      </span>
      <?php if (!empty($order['stock_restored'])): ?>
        <span class="badge bg-secondary align-middle ms-1" style="font-size: 11px;">Stock Restored</span>
      <?php endif; ?>
    </h3>
    <div class="text-muted small">
      Placed on <?= date('F j, Y \a\t g:i a', strtotime($order['created_at'])) ?> · IP: <?= e($order['ip_address'] ?: 'N/A') ?>
    </div>
  </div>

  <div class="d-flex gap-2 align-items-center">
    <a href="<?= e(admin_url('order_print.php?id=' . $order['id'])) ?>" target="_blank" class="btn btn-outline-dark">
      <i class="bi bi-printer me-1"></i> Print Packing Slip / Invoice
    </a>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Customer Details Card (Prompt 14) -->
  <div class="col-md-4">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-person text-primary me-2"></i> Customer Info</h6>
        <?php if (!empty($order['customer_id'])): ?>
          <a href="<?= e(admin_url('customer_view.php?id=' . (int)$order['customer_id'])) ?>" class="badge bg-primary text-decoration-none">
            <i class="bi bi-person-check me-1"></i> Registered
          </a>
        <?php else: ?>
          <span class="badge bg-secondary-subtle text-muted border">Guest</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-bold fs-6 text-dark"><?= e($order['customer_name']) ?></div>
          <?php if (!empty($order['customer_id'])): ?>
            <a href="<?= e(admin_url('customer_view.php?id=' . (int)$order['customer_id'])) ?>" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.75rem;">
              <i class="bi bi-person me-1"></i> Profile
            </a>
          <?php endif; ?>
        </div>

        <div class="mt-2 d-flex align-items-center gap-2">
          <i class="bi bi-telephone text-muted"></i>
          <a href="tel:<?= e($order['customer_phone']) ?>" class="text-decoration-none text-dark fw-medium"><?= e($order['customer_phone']) ?></a>
          <?php $cleanPhone = preg_replace('/[^0-9]/', '', (string)$order['customer_phone']); ?>
          <?php if ($cleanPhone): ?>
            <a href="https://wa.me/<?= e($cleanPhone) ?>" target="_blank" class="badge bg-success-subtle text-success text-decoration-none border border-success-subtle" title="Chat on WhatsApp">
              <i class="bi bi-whatsapp"></i> WhatsApp
            </a>
          <?php endif; ?>
        </div>

        <?php if (!empty($order['customer_email'])): ?>
          <div class="mt-2 d-flex align-items-center gap-2">
            <i class="bi bi-envelope text-muted"></i>
            <a href="mailto:<?= e($order['customer_email']) ?>" class="text-decoration-none text-dark small"><?= e($order['customer_email']) ?></a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Shipping Address Card -->
  <div class="col-md-4">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-header bg-white py-3 border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-truck text-primary me-2"></i> Shipping & Delivery</h6>
      </div>
      <div class="card-body">
        <div class="text-muted small">Delivery Address:</div>
        <div class="fw-medium"><?= nl2br(e($order['shipping_address'])) ?></div>
        <div><?= e($order['shipping_city']) ?><?= !empty($order['shipping_zip']) ? ' - ' . e($order['shipping_zip']) : '' ?></div>
        <div class="mt-2 text-muted small">
          Method: <strong><?= e($order['shipping_method_name'] ?: 'Standard') ?></strong>
        </div>
      </div>
    </div>
  </div>

  <!-- Payment Status Card -->
  <div class="col-md-4">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-header bg-white py-3 border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-wallet2 text-primary me-2"></i> Payment Details</h6>
      </div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Method:</span>
          <span class="badge bg-light text-dark border text-uppercase"><?= e($order['payment_method']) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Payment Status:</span>
          <span class="badge <?= $order['payment_status'] === 'paid' ? 'bg-success' : ($order['payment_status'] === 'refunded' ? 'bg-danger' : 'bg-warning text-dark') ?>">
            <?= ucfirst($order['payment_status']) ?>
          </span>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Total Amount:</span>
          <span class="fw-bold fs-6 text-primary"><?= e(price($order['total'])) ?></span>
        </div>
        <?php if ($order['payment_method'] === 'cod'): ?>
          <div class="small text-muted bg-light p-2 rounded border mt-2">
            <i class="bi bi-info-circle text-primary"></i> Collect <strong><?= e(price($order['total'])) ?></strong> on delivery.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Left: Ordered Items Table -->
  <div class="col-lg-8">
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header bg-white py-3 border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-box text-primary me-2"></i> Order Items</h6>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Product</th>
                <th class="text-center" style="width: 80px;">Qty</th>
                <th class="text-end" style="width: 120px;">Price</th>
                <th class="text-end" style="width: 120px;">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= e($item['product_name']) ?></div>
                    <?php if (!empty($item['variant_label'])): ?>
                      <div><span class="badge bg-light text-dark border"><?= e($item['variant_label']) ?></span></div>
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
                <td class="text-end"><?= e(price($order['shipping_fee'])) ?></td>
              </tr>
              <?php if ((float)$order['cod_fee'] > 0): ?>
                <tr>
                  <td colspan="3" class="text-end text-muted">COD Fee</td>
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

    <!-- Status History Timeline -->
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header bg-white py-3 border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history text-primary me-2"></i> Status History Timeline</h6>
      </div>
      <div class="card-body p-4">
        <?php if (!$history): ?>
          <div class="text-muted small">No history entries logged yet.</div>
        <?php else: ?>
          <ul class="list-unstyled mb-0 position-relative ps-3" style="border-left: 2px solid #e9ecef;">
            <?php foreach ($history as $h): ?>
              <li class="mb-3 position-relative ps-3">
                <div class="position-absolute rounded-circle bg-primary"
                     style="width: 10px; height: 10px; left: -18px; top: 6px;"></div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="badge <?= e($statusBadge[$h['status']] ?? 'bg-secondary') ?> text-uppercase">
                    <?= e($h['status']) ?>
                  </span>
                  <span class="text-muted small">
                    <?= date('M j, Y - g:i a', strtotime($h['created_at'])) ?>
                    <?= !empty($h['admin_name']) ? 'by ' . e($h['admin_name']) : '' ?>
                  </span>
                </div>
                <?php if (!empty($h['note'])): ?>
                  <div class="text-muted small mt-1"><?= e($h['note']) ?></div>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Status Transition & Courier Controls -->
  <div class="col-lg-4">
    <!-- Status Transition Card -->
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header bg-white py-3 border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-arrow-repeat text-primary me-2"></i> Update Order Status</h6>
      </div>
      <div class="card-body">
        <?php if (empty($possibleTransitions)): ?>
          <div class="alert alert-secondary mb-0 small">
            Order is currently in terminal status <strong><?= e(strtoupper($order['status'])) ?></strong>. No further status transitions allowed.
          </div>
        <?php else: ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">

            <div class="mb-3">
              <label class="form-label small fw-semibold text-muted">Next Valid Status</label>
              <select name="status" class="form-select" required>
                <option value="">Select target status...</option>
                <?php foreach ($possibleTransitions as $target): ?>
                  <option value="<?= e($target) ?>">
                    Mark as <?= ucfirst($target) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label small fw-semibold text-muted">Status Note <span class="text-muted">(Optional)</span></label>
              <textarea name="note" class="form-control form-control-sm" rows="2" placeholder="e.g. Parcel picked up by courier"></textarea>
            </div>

            <button type="submit" class="btn btn-primary w-100"
                    onclick="return confirm('Are you sure you want to transition order status?');">
              <i class="bi bi-check2-circle me-1"></i> Update Status
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tracking and Internal Notes Card -->
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header bg-white py-3 border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-truck text-primary me-2"></i> Courier & Tracking</h6>
      </div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_tracking">

          <div class="mb-3">
            <label class="form-label small fw-semibold text-muted">Courier / Delivery Service</label>
            <input type="text" name="courier" class="form-control form-control-sm"
                   value="<?= e($order['courier'] ?? '') ?>" placeholder="e.g. Steadfast / Pathao / RedX">
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold text-muted">Tracking / Consignment Number</label>
            <input type="text" name="tracking_number" class="form-control form-control-sm"
                   value="<?= e($order['tracking_number'] ?? '') ?>" placeholder="e.g. SF-984210984">
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold text-muted">Payment Status</label>
            <select name="payment_status" class="form-select form-select-sm">
              <option value="unpaid" <?= $order['payment_status'] === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
              <option value="paid" <?= $order['payment_status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
              <option value="refunded" <?= $order['payment_status'] === 'refunded' ? 'selected' : '' ?>>Refunded</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold text-muted">Internal Admin Notes</label>
            <textarea name="notes" class="form-control form-control-sm" rows="3" placeholder="Notes for admin reference only..."><?= e($order['notes'] ?? '') ?></textarea>
          </div>

          <button type="submit" class="btn btn-outline-primary btn-sm w-100">
            <i class="bi bi-save me-1"></i> Save Courier & Notes
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
