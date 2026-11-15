<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../../includes/shop_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


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

    if (count($_SESSION['track_attempts']) >= 30) {
        $errors[] = 'Too many tracking lookups. Please wait a minute before trying again.';
    } else {
        $_SESSION['track_attempts'][] = $now;

        $cleanOrder = ltrim(trim($orderNumber), '#');
        $contactTrim = trim($contact);

        if ($cleanOrder === '' && $contactTrim === '') {
            $errors[] = 'Please enter your order reference or tracking number.';
        } else {
            try {
                if ($cleanOrder !== '' && $contactTrim !== '') {
                    $contactDigits = preg_replace('/\D/', '', $contactTrim);
                    $phoneSuffix = strlen($contactDigits) >= 7 ? substr($contactDigits, -8) : $contactDigits;

                    $stmt = db()->prepare(
                        "SELECT * FROM orders
                         WHERE (order_number = :num OR order_number = :clean_num OR tracking_number = :track_num)
                           AND (
                             customer_email = :c_email
                             OR customer_phone = :c_phone
                             OR REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', '') LIKE :phone_sub
                           )
                         LIMIT 1"
                    );
                    $stmt->execute([
                        ':num'        => $orderNumber,
                        ':clean_num'  => $cleanOrder,
                        ':track_num'  => $cleanOrder,
                        ':c_email'    => $contactTrim,
                        ':c_phone'    => $contactTrim,
                        ':phone_sub'  => '%' . ($phoneSuffix ?: $contactTrim),
                    ]);
                    $order = $stmt->fetch();
                } elseif ($cleanOrder !== '') {
                    $stmt = db()->prepare(
                        "SELECT * FROM orders
                         WHERE order_number = :num OR order_number = :clean_num OR tracking_number = :track_num
                         LIMIT 1"
                    );
                    $stmt->execute([
                        ':num'       => $orderNumber,
                        ':clean_num' => $cleanOrder,
                        ':track_num' => $cleanOrder,
                    ]);
                    $order = $stmt->fetch();
                } else {
                    $stmt = db()->prepare(
                        "SELECT * FROM orders
                         WHERE customer_email = :c_email OR customer_phone = :c_phone
                         ORDER BY id DESC LIMIT 1"
                    );
                    $stmt->execute([
                        ':c_email' => $contactTrim,
                        ':c_phone' => $contactTrim,
                    ]);
                    $order = $stmt->fetch();
                }

                if (!$order) {
                    $errors[] = 'No matching order was found. Please check your order number or contact details.';
                } else {
                    // Fetch items with variant labels
                    $stmtItems = db()->prepare(
                        "SELECT * FROM order_items WHERE order_id = :oid ORDER BY id ASC"
                    );
                    $stmtItems->execute([':oid' => $order['id']]);
                    $items = $stmtItems->fetchAll() ?: [];

                    // Fetch status history timeline
                    $stmtHist = db()->prepare(
                        "SELECT * FROM order_status_history WHERE order_id = :oid ORDER BY created_at ASC"
                    );
                    $stmtHist->execute([':oid' => $order['id']]);
                    $history = $stmtHist->fetchAll() ?: [];
                }
            } catch (Throwable $ex) {
                $errors[] = 'An error occurred while retrieving order status. Please try again.';
            }
        }
    }
}

$statusBadge = [
    'pending'    => 'bg-warning',
    'processing' => 'bg-info',
    'shipped'    => 'bg-primary',
    'completed'  => 'bg-success',
    'cancelled'  => 'bg-danger',
];

$timelineSteps = [
    'pending'    => ['title' => 'Order Placed', 'icon' => 'bag'],
    'processing' => ['title' => 'Processing',   'icon' => 'bag'],
    'shipped'    => ['title' => 'Shipped',      'icon' => 'truck'],
    'completed'  => ['title' => 'Delivered',    'icon' => 'check-circle'],
];

include __DIR__ . '/../../includes/partials/header.php';
?>

<!-- Unified Clean Page Header -->
<section class="sec-page-header p-tb-35" style="background: #f9fafb; border-bottom: 1px solid #eef0f3;">
	<div class="container text-center">
		<h1 class="page-title m-b-6" style="font-size: 28px; font-weight: 700; color: #111827; letter-spacing: -0.5px;">
			Track Your Order
		</h1>
		<div class="bread-crumb flex-w flex-c-m">
			<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
				Home <i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
			</a>
			<span class="stext-109 cl4">
				Track Order
			</span>
		</div>
	</div>
</section>

<section class="section p-b-80">
  <div class="container" style="max-width: 960px;">

    <div class="section-header text-center mb-4">
      <p class="stext-115 cl6">Enter your order reference number or tracking ID below to check live delivery progress.</p>
    </div>

    <!-- Search Form -->
    <div class="bg0 p-all-30 m-b-35" style="border: 1px solid #eef0f3; border-radius: 14px; box-shadow: 0 4px 18px rgba(0,0,0,0.03);">
        <form method="get" class="row">
          <div class="col-md-6 m-b-20">
            <label class="form-label fw-semibold small" style="color: #333; margin-bottom: 8px; font-size: 13px;">Order or Tracking Number <span class="text-danger">*</span></label>
            <div class="pos-relative">
              <input type="text" name="order_number" class="stext-111 cl2 plh3 size-116 bor8" style="width: 100%; padding-left: 46px; padding-right: 18px; border-radius: 8px;"
                     value="<?= e($orderNumber) ?>"
                     placeholder="e.g. ORD-20261017-05000" required>
              <div style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #888;">
                <i class="zmdi zmdi-shopping-basket fs-20"></i>
              </div>
            </div>
          </div>
          <div class="col-md-6 m-b-20">
            <label class="form-label fw-semibold small" style="color: #333; margin-bottom: 8px; font-size: 13px;">Phone or Email <span class="text-muted fw-normal">(Optional)</span></label>
            <div class="pos-relative">
              <input type="text" name="contact" class="stext-111 cl2 plh3 size-116 bor8" style="width: 100%; padding-left: 46px; padding-right: 18px; border-radius: 8px;"
                     value="<?= e($contact) ?>"
                     placeholder="01712345678 or you@example.com">
              <div style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #888;">
                <i class="zmdi zmdi-account fs-20"></i>
              </div>
            </div>
          </div>
          <div class="col-12 m-t-10">
            <button type="submit" class="flex-c-m stext-101 cl0 size-116 bg3 bor1 hov-btn3 p-lr-15 trans-04 pointer w-full" style="border-radius: 8px; font-weight: 700; height: 50px;">
              <i class="zmdi zmdi-search m-r-8 fs-20"></i> Track Order Status
            </button>
          </div>
        </form>
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
                <div class="position-absolute top-50 start-0 translate-middle-y w-100" style="height: 4px; z-index: 1; background-color: var(--line);"></div>
                <div class="position-absolute top-50 start-0 translate-middle-y"
                     style="height: 4px; z-index: 1; background-color: var(--accent); width: <?= max(0, min(100, ($currentIdx / (count($statusKeys) - 1)) * 100)) ?>%;"></div>

                <?php foreach ($statusKeys as $idx => $sKey): ?>
                  <?php
                    $step = $timelineSteps[$sKey];
                    $isDone = ($idx <= $currentIdx);
                    $isCur = ($idx === $currentIdx);
                  ?>
                  <div class="text-center position-relative" style="z-index: 2;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-2"
                         style="width: 44px; height: 44px; font-size: 18px; border: 3px solid var(--surface); box-shadow: var(--shadow-sm); <?= $isDone ? 'background-color: var(--accent); color: #ffffff;' : 'background-color: var(--surface-muted); color: var(--muted); border: 2px solid var(--line);' ?> <?= $isCur ? 'box-shadow: 0 0 0 4px var(--accent-light);' : '' ?>">
                      <?= icon($step['icon']) ?>
                    </div>
                    <div class="small fw-semibold <?= $isCur ? 'text-primary' : ($isDone ? 'text-body' : 'text-muted') ?>">
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
        <div class="card shadow-sm border-0 mb-4">
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
          <div class="card-header py-3 border-bottom">
            <h6 class="mb-0 fw-semibold"><?= icon('rotate-ccw', 'text-primary me-2') ?> Status Timeline Logs</h6>
          </div>
          <div class="card-body p-4">
            <ul class="list-unstyled mb-0 position-relative ps-3" style="border-left: 2px solid var(--line);">
              <?php foreach ($history as $h): ?>
                <li class="mb-3 position-relative ps-3">
                  <div class="position-absolute rounded-circle"
                       style="width: 10px; height: 10px; left: -18px; top: 6px; background-color: var(--accent);"></div>
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
        <div class="card-header py-3 border-bottom">
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
