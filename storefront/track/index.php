<?php
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$pageTitle = 'Track Your Order';

// Pre-fill from GET or POST
$orderNumber = trim((string)($_GET['order_number'] ?? $_GET['order'] ?? $_POST['order_number'] ?? $_POST['order'] ?? ''));
$contact     = trim((string)($_GET['contact'] ?? $_GET['phone'] ?? $_GET['email'] ?? $_POST['contact'] ?? $_POST['phone'] ?? $_POST['email'] ?? ''));

$order   = null;
$items   = [];
$history = [];
$errors  = [];
$searched = false;

// Rate limiting: 15 lookups per minute
$_SESSION['track_attempts'] = $_SESSION['track_attempts'] ?? [];
$now = time();
$_SESSION['track_attempts'] = array_filter($_SESSION['track_attempts'], function ($t) use ($now) {
    return ($now - $t) < 60;
});

if ($orderNumber !== '' || $contact !== '') {
    $searched = true;

    if (count($_SESSION['track_attempts']) >= 15) {
        $errors[] = 'Too many tracking lookups. Please wait a minute before trying again.';
    } else {
        $_SESSION['track_attempts'][] = $now;

        if ($orderNumber === '') {
            $errors[] = 'Please enter your order reference number.';
        }
        if ($contact === '') {
            $errors[] = 'Please enter the phone number or email address used at checkout.';
        }

        if (!$errors) {
            // Check matching order number AND (customer_phone = contact OR customer_email = contact)
            // For phone, normalize digits if needed
            $contactDigits = preg_replace('/\D/', '', $contact);

            $stmt = db()->prepare(
                "SELECT * FROM orders
                 WHERE order_number = :num
                   AND (
                     customer_email = :contact
                     OR customer_phone = :contact
                     OR REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', '') LIKE :phone_sub
                   )
                 LIMIT 1"
            );
            $stmt->execute([
                ':num'       => $orderNumber,
                ':contact'   => $contact,
                ':phone_sub' => '%' . ($contactDigits ?: $contact),
            ]);
            $order = $stmt->fetch();

            if (!$order) {
                $errors[] = 'No matching order was found. Please check your order number and phone or email address.';
            } else {
                // Fetch items with variant labels
                $stmtItems = db()->prepare(
                    "SELECT * FROM order_items WHERE order_id = :oid ORDER BY id ASC"
                );
                $stmtItems->execute([':oid' => $order['id']]);
                $items = $stmtItems->fetchAll();

                // Fetch status history timeline
                $stmtHist = db()->prepare(
                    "SELECT * FROM order_status_history WHERE order_id = :oid ORDER BY created_at ASC"
                );
                $stmtHist->execute([':oid' => $order['id']]);
                $history = $stmtHist->fetchAll();
            }
        }
    }
}

$statusBadge = [
    'pending'    => 'bg-warning text-dark',
    'processing' => 'bg-info text-white',
    'shipped'    => 'bg-primary text-white',
    'completed'  => 'bg-success text-white',
    'cancelled'  => 'bg-danger text-white',
];

$timelineSteps = [
    'pending'    => ['title' => 'Order Placed', 'icon' => 'bag'],
    'processing' => ['title' => 'Processing',   'icon' => 'bag'],
    'shipped'    => ['title' => 'Shipped',      'icon' => 'truck'],
    'completed'  => ['title' => 'Delivered',    'icon' => 'check-circle'],
];

include __DIR__ . '/../../includes/partials/header.php';
?>

<section class="section py-5">
  <div class="container" style="max-width: 860px;">

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= e(route('home')) ?>">Home</a></li>
        <li class="breadcrumb-item active">Track Order</li>
      </ol>
    </nav>

    <div class="section-header text-center mb-4">
      <h2 class="fw-bold"><?= icon('map-pin', 'text-primary me-2') ?> Track Your Order</h2>
      <p class="text-muted">Enter your order number and the phone number or email you used when placing the order.</p>
    </div>

    <!-- Search Form -->
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-body p-4">
        <form method="get" class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Order Number <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text"><?= icon('bag') ?></span>
              <input type="text" name="order_number" class="form-control"
                     value="<?= e($orderNumber) ?>"
                     placeholder="e.g. ORD-202610-0001" required>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Phone Number or Email <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text"><?= icon('user') ?></span>
              <input type="text" name="contact" class="form-control"
                     value="<?= e($contact) ?>"
                     placeholder="01712345678 or you@example.com" required>
            </div>
          </div>
          <div class="col-12 mt-3">
            <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
              <?= icon('search', 'me-1') ?> Track Order Status
            </button>
          </div>
        </form>
      </div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger alert-dismissible fade show mb-4">
        <?= icon('alert-circle', 'me-2') ?>
        <?= e($errors[0]) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <?php if ($order): ?>
      <?php
        $isCancelled = ($order['status'] === 'cancelled');
        $statusKeys = array_keys($timelineSteps);
        $currentIdx = array_search($order['status'], $statusKeys, true);
        if ($currentIdx === false) $currentIdx = -1;
      ?>

      <!-- Order Status Summary Card -->
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
              <span class="text-muted small">Tracking Order:</span>
              <h4 class="fw-bold mb-0"><?= e($order['order_number']) ?></h4>
            </div>
            <div>
              <span class="badge <?= e($statusBadge[$order['status']] ?? 'bg-secondary') ?> fs-6 px-3 py-2 text-uppercase">
                <?= e($order['status']) ?>
              </span>
            </div>
          </div>

          <div class="text-muted small mb-4">
            Placed on <?= date('F j, Y, g:i a', strtotime($order['created_at'])) ?>
            · Customer: <strong><?= e($order['customer_name']) ?></strong>
          </div>

          <?php if ($isCancelled): ?>
            <div class="alert alert-danger mb-0">
              <h6 class="fw-bold mb-1"><?= icon('x', 'me-1') ?> This order was cancelled</h6>
              <p class="mb-0 small">
                If you have questions regarding this cancellation, please contact our support team.
              </p>
            </div>
          <?php else: ?>
            <!-- Visual Timeline Progress -->
            <div class="py-3">
              <div class="d-flex justify-content-between position-relative">
                <div class="position-absolute top-50 start-0 translate-middle-y w-100 bg-light" style="height: 4px; z-index: 1;"></div>
                <div class="position-absolute top-50 start-0 translate-middle-y bg-primary"
                     style="height: 4px; z-index: 1; width: <?= max(0, min(100, ($currentIdx / (count($statusKeys) - 1)) * 100)) ?>%;"></div>

                <?php foreach ($statusKeys as $idx => $sKey): ?>
                  <?php
                    $step = $timelineSteps[$sKey];
                    $isDone = ($idx <= $currentIdx);
                    $isCur = ($idx === $currentIdx);
                  ?>
                  <div class="text-center position-relative" style="z-index: 2;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-2 text-white <?= $isDone ? 'bg-primary' : 'bg-secondary' ?>"
                         style="width: 40px; height: 40px; font-size: 18px; border: 3px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.1);">
                      <?= icon($step['icon']) ?>
                    </div>
                    <div class="small fw-semibold <?= $isCur ? 'text-primary' : 'text-muted' ?>">
                      <?= e($step['title']) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Courier & Tracking Info -->
      <?php if (!empty($order['courier']) || !empty($order['tracking_number'])): ?>
        <div class="card shadow-sm border-0 mb-4 bg-light">
          <div class="card-body p-4">
            <h6 class="fw-bold mb-2"><?= icon('truck', 'text-primary me-2') ?> Courier Shipment Details</h6>
            <div class="row g-2">
              <?php if (!empty($order['courier'])): ?>
                <div class="col-sm-6">
                  <span class="text-muted small">Delivery Partner:</span>
                  <div class="fw-semibold fs-6"><?= e($order['courier']) ?></div>
                </div>
              <?php endif; ?>
              <?php if (!empty($order['tracking_number'])): ?>
                <div class="col-sm-6">
                  <span class="text-muted small">Tracking / Consignment Number:</span>
                  <div class="fw-semibold fs-6"><code><?= e($order['tracking_number']) ?></code></div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Detailed Status History Logs -->
      <?php if (!empty($history)): ?>
        <div class="card shadow-sm border-0 mb-4">
          <div class="card-header bg-white py-3 border-bottom">
            <h6 class="mb-0 fw-semibold"><?= icon('rotate-ccw', 'text-primary me-2') ?> Status Timeline Logs</h6>
          </div>
          <div class="card-body p-4">
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
                    </span>
                  </div>
                  <?php if (!empty($h['note'])): ?>
                    <div class="text-muted small mt-1"><?= e($h['note']) ?></div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>

      <!-- Items in Order -->
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h6 class="mb-0 fw-semibold"><?= icon('bag', 'text-primary me-2') ?> Items in This Order</h6>
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
                  <td colspan="3" class="text-end text-muted">Total Amount</td>
                  <td class="text-end fw-bold fs-6 text-primary"><?= e(price($order['total'])) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
