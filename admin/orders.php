<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Orders';

// Helper for allowed status transitions
function is_valid_status_transition(string $from, string $to): bool
{
    if ($from === $to) return true;
    $validTransitions = [
        'pending'    => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped'    => ['completed', 'cancelled'],
        'completed'  => ['cancelled'], // return / refund
        'cancelled'  => [], // terminal
    ];
    return in_array($to, $validTransitions[$from] ?? [], true);
}

// ----- POST Actions: Single delete, Bulk status update -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT order_number FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($o = $stmt->fetch()) {
            db()->prepare('DELETE FROM orders WHERE id = :id')->execute([':id' => $id]);
            log_activity('order.delete', 'Deleted order ' . $o['order_number']);
            flash('success', 'Order ' . $o['order_number'] . ' deleted.');
        } else {
            flash('warning', 'Order not found.');
        }
        admin_redirect('orders.php');
    }

    if ($action === 'bulk_status') {
        $orderIds = $_POST['selected_orders'] ?? [];
        $targetStatus = trim((string)($_POST['bulk_status_target'] ?? ''));
        $allowedStatuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];

        if (!in_array($targetStatus, $allowedStatuses, true)) {
            flash('danger', 'Invalid target status.');
            admin_redirect('orders.php');
        }

        if (empty($orderIds) || !is_array($orderIds)) {
            flash('warning', 'No orders were selected.');
            admin_redirect('orders.php');
        }

        $pdo = db();
        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($orderIds as $rawId) {
            $oid = (int)$rawId;
            if ($oid <= 0) continue;

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT id, order_number, status, payment_method, payment_status, stock_restored FROM orders WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $oid]);
                $ord = $stmt->fetch();

                if (!$ord) {
                    $pdo->rollBack();
                    $skippedCount++;
                    continue;
                }

                $oldStatus = $ord['status'];
                if ($oldStatus === $targetStatus) {
                    $pdo->rollBack();
                    continue;
                }

                if (!is_valid_status_transition($oldStatus, $targetStatus)) {
                    $pdo->rollBack();
                    $skippedCount++;
                    continue;
                }

                // Update order status
                $updateSql = "UPDATE orders SET status = :status";
                $updateParams = [':status' => $targetStatus, ':id' => $oid];

                if ($targetStatus === 'completed' && $ord['payment_method'] === 'cod') {
                    $updateSql .= ", payment_status = 'paid'";
                }

                $updateSql .= " WHERE id = :id";
                $pdo->prepare($updateSql)->execute($updateParams);

                // If cancelled -> stock restore once
                if ($targetStatus === 'cancelled') {
                    stock_restore_for_order($oid);
                }

                // Update product_sales_summary in same transaction
                if ($targetStatus === 'completed' && $oldStatus !== 'completed') {
                    product_sales_summary_record_order($oid, $pdo);
                } elseif ($targetStatus === 'cancelled' && $oldStatus === 'completed') {
                    product_sales_summary_reverse_order($oid, $pdo);
                }

                // Order status history
                $uid = $_SESSION['user']['id'] ?? null;
                $pdo->prepare(
                    "INSERT INTO order_status_history (order_id, status, note, user_id)
                     VALUES (:oid, :st, :note, :uid)"
                )->execute([
                    ':oid'  => $oid,
                    ':st'   => $targetStatus,
                    ':note' => "Bulk status updated to {$targetStatus} by admin",
                    ':uid'  => $uid,
                ]);

                $pdo->commit();
                $updatedCount++;

                hook_fire('order.status_changed', [
                    'order_id'   => $oid,
                    'old_status' => $oldStatus,
                    'new_status' => $targetStatus,
                    'note'       => 'Bulk update',
                ]);

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $skippedCount++;
            }
        }

        log_activity('order.bulk_status', "Bulk updated {$updatedCount} orders to {$targetStatus}");
        flash('success', "Updated {$updatedCount} order(s) to " . ucfirst($targetStatus) . ($skippedCount > 0 ? " ({$skippedCount} skipped due to invalid transition rules)." : "."));
        admin_redirect('orders.php');
    }
}

// ----- Filters & Query setup -----
$search   = trim((string)($_GET['q'] ?? ''));
$status   = trim((string)($_GET['status'] ?? ''));
$payStatus = trim((string)($_GET['payment_status'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo   = trim((string)($_GET['to'] ?? ''));
$perPage  = max(5, min(100, (int)($_GET['per_page'] ?? setting('items_per_page', '15'))));
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;

$allowedStatuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];
$allowedPayStatuses = ['unpaid', 'paid', 'refunded'];

$where = []; $params = [];
if ($search !== '') {
    $where[] = '(order_number LIKE :q1 OR customer_name LIKE :q2 OR customer_email LIKE :q3 OR customer_phone LIKE :q4)';
    $params[':q1'] = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
    $params[':q4'] = "%$search%";
}
if (in_array($status, $allowedStatuses, true)) {
    $where[] = 'status = :st';
    $params[':st'] = $status;
}
if (in_array($payStatus, $allowedPayStatuses, true)) {
    $where[] = 'payment_status = :pst';
    $params[':pst'] = $payStatus;
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'created_at >= :df';
    $params[':df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'created_at <= :dt';
    $params[':dt'] = $dateTo . ' 23:59:59';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ----- CSV Export -----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = db()->prepare(
        "SELECT * FROM orders $whereSql ORDER BY created_at DESC"
    );
    $exportStmt->execute($params);
    $exportRows = $exportStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders_' . date('Ymd_His') . '.csv"');

    // UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    fputcsv($out, [
        'Order Number',
        'Date Placed',
        'Customer Name',
        'Phone',
        'Email',
        'Shipping Address',
        'City',
        'Subtotal',
        'Discount',
        'Shipping Fee',
        'COD Fee',
        'Total',
        'Payment Method',
        'Payment Status',
        'Order Status',
        'Courier',
        'Tracking Number',
    ]);

    foreach ($exportRows as $r) {
        fputcsv($out, [
            $r['order_number'],
            $r['created_at'],
            $r['customer_name'],
            $r['customer_phone'],
            $r['customer_email'],
            $r['shipping_address'],
            $r['shipping_city'],
            $r['subtotal'],
            $r['discount_total'],
            $r['shipping_fee'],
            $r['cod_fee'],
            $r['total'],
            strtoupper($r['payment_method']),
            strtoupper($r['payment_status']),
            ucfirst($r['status']),
            $r['courier'] ?? '',
            $r['tracking_number'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ----- Pagination and Fetch -----
$stmt = db()->prepare("SELECT COUNT(*) FROM orders $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare(
    "SELECT o.id, o.order_number, o.customer_name, o.customer_email, o.customer_phone, o.total,
            o.status, o.payment_status, o.payment_method, o.created_at
     FROM orders o
     $whereSql
     ORDER BY o.created_at DESC
     LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll() ?: [];

// Batch load item counts for current page orders (eliminates correlated subquery)
if (!empty($rows)) {
    $orderIds = array_column($rows, 'id');
    $inClause = implode(',', array_map('intval', $orderIds));
    $cntStmt = db()->query("SELECT order_id, COUNT(*) AS cnt FROM order_items WHERE order_id IN ($inClause) GROUP BY order_id");
    $counts = [];
    while ($cRow = $cntStmt->fetch()) {
        $counts[$cRow['order_id']] = (int)$cRow['cnt'];
    }
    foreach ($rows as &$r) {
        $r['item_count'] = $counts[$r['id']] ?? 0;
    }
    unset($r);
}

// Headline metrics for toolbar
$stats = db()->query(
    "SELECT
       COUNT(*) AS total_orders,
       SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
       SUM(CASE WHEN status = 'pending' AND payment_method = 'cod' AND created_at < NOW() - INTERVAL 48 HOUR THEN 1 ELSE 0 END) AS pending_cod_48h,
       SUM(CASE WHEN status NOT IN ('cancelled') THEN total ELSE 0 END) AS revenue
     FROM orders"
)->fetch();

$statusBadge = [
    'pending'    => 'text-bg-warning',
    'processing' => 'text-bg-info',
    'shipped'    => 'text-bg-primary',
    'completed'  => 'text-bg-success',
    'cancelled'  => 'text-bg-secondary',
];

include __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-md-3">
    <div class="card stat-card">
      <div class="stat-icon bg-indigo"><i class="bi bi-receipt"></i></div>
      <div>
        <p class="stat-label">Total Orders</p>
        <p class="stat-value"><?= number_format((int)$stats['total_orders']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card stat-card">
      <div class="stat-icon bg-amber"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <p class="stat-label">Pending Orders</p>
        <p class="stat-value"><?= number_format((int)$stats['pending_orders']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card stat-card">
      <div class="stat-icon bg-rose"><i class="bi bi-exclamation-circle"></i></div>
      <div>
        <p class="stat-label">Pending COD &gt; 48h</p>
        <p class="stat-value text-danger"><?= number_format((int)$stats['pending_cod_48h']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card stat-card">
      <div class="stat-icon bg-emerald"><i class="bi bi-cash-stack"></i></div>
      <div>
        <p class="stat-label">Net Revenue</p>
        <p class="stat-value"><?= e(price((float)$stats['revenue'])) ?></p>
      </div>
    </div>
  </div>
</div>

<!-- Filters Toolbar -->
<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-3">
        <label class="form-label small text-muted">Search</label>
        <input type="text" name="q" class="form-control"
               value="<?= e($search) ?>" placeholder="Order #, name, phone or email">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">Status</label>
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach ($allowedStatuses as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">Payment</label>
        <select name="payment_status" class="form-select">
          <option value="">All Payments</option>
          <?php foreach ($allowedPayStatuses as $ps): ?>
            <option value="<?= $ps ?>" <?= $payStatus === $ps ? 'selected' : '' ?>><?= ucfirst($ps) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">From</label>
        <input type="date" name="from" class="form-control" value="<?= e($dateFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">To</label>
        <input type="date" name="to" class="form-control" value="<?= e($dateTo) ?>">
      </div>
      <div class="col-md-1 d-flex gap-1">
        <button class="btn btn-primary w-100" title="Apply filter"><i class="bi bi-funnel"></i></button>
      </div>
    </form>
    <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
      <div class="small text-muted d-flex align-items-center gap-2">
        <span>Showing <?= count($rows) ?> of <?= number_format($total) ?> order<?= $total === 1 ? '' : 's' ?></span>
        <span class="text-muted">·</span>
        <label class="d-flex align-items-center gap-1 mb-0">
          <span class="text-muted">Per page:</span>
          <select class="form-select form-select-sm" style="width: auto; display: inline-block;" onchange="const url = new URL(window.location.href); url.searchParams.set('per_page', this.value); url.searchParams.set('page', 1); window.location.href = url.toString();">
            <option value="15" <?= $perPage === 15 ? 'selected' : '' ?>>15</option>
            <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
          </select>
        </label>
      </div>
      <div class="d-flex gap-2">
        <?php if ($search || $status || $payStatus || $dateFrom || $dateTo): ?>
          <a href="<?= e(admin_url('orders.php')) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-x-circle"></i> Clear Filters
          </a>
        <?php endif; ?>
        <a href="<?= e(admin_url('orders.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))) ?>"
           class="btn btn-sm btn-outline-success">
          <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Bulk Status Form & Orders Table -->
<form method="post" id="bulkOrdersForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_status">

  <div class="card mb-3">
    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-2">
        <span class="small fw-semibold text-muted">Bulk Actions:</span>
        <select name="bulk_status_target" class="form-select form-select-sm" style="width: auto;" required>
          <option value="">Choose target status...</option>
          <option value="processing">Mark Processing</option>
          <option value="shipped">Mark Shipped</option>
          <option value="completed">Mark Completed</option>
          <option value="cancelled">Mark Cancelled</option>
        </select>
        <button type="submit" class="btn btn-sm btn-outline-primary"
                onclick="return confirm('Apply status update to all selected orders?');">
          Apply
        </button>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width: 40px;" class="text-center">
              <input type="checkbox" class="form-check-input" id="selectAll">
            </th>
            <th>Order #</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Items</th>
            <th>Total</th>
            <th>Payment</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="9" class="text-center py-4 text-muted">No orders found matching criteria.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $isPendingCod48h = ($r['status'] === 'pending' && $r['payment_method'] === 'cod' && (time() - strtotime($r['created_at'])) > 172800);
              ?>
              <tr class="<?= $isPendingCod48h ? 'table-warning' : '' ?>">
                <td class="text-center">
                  <input type="checkbox" name="selected_orders[]" value="<?= (int)$r['id'] ?>" class="form-check-input order-select">
                </td>
                <td>
                  <a href="<?= e(admin_url('order_view.php?id=' . $r['id'])) ?>" class="fw-bold text-decoration-none">
                    <?= e($r['order_number']) ?>
                  </a>
                  <?php if ($isPendingCod48h): ?>
                    <div><span class="badge bg-danger" style="font-size: 10px;">Pending &gt; 48h</span></div>
                  <?php endif; ?>
                </td>
                <td class="small text-muted">
                  <?= date('M j, Y', strtotime($r['created_at'])) ?><br>
                  <?= date('g:i a', strtotime($r['created_at'])) ?>
                </td>
                <td>
                  <div class="fw-semibold"><?= e($r['customer_name']) ?></div>
                  <?php if (!empty($r['customer_phone'])): ?>
                    <div class="small text-muted"><?= e($r['customer_phone']) ?></div>
                  <?php elseif (!empty($r['customer_email'])): ?>
                    <div class="small text-muted"><?= e($r['customer_email']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge bg-light text-dark border"><?= (int)$r['item_count'] ?> item(s)</span></td>
                <td class="fw-semibold"><?= e(price($r['total'])) ?></td>
                <td>
                  <span class="badge bg-light text-dark border text-uppercase" style="font-size: 11px;">
                    <?= e($r['payment_method']) ?>
                  </span>
                  <div class="small">
                    <span class="badge <?= $r['payment_status'] === 'paid' ? 'bg-success' : ($r['payment_status'] === 'refunded' ? 'bg-danger' : 'bg-warning text-dark') ?>" style="font-size: 10px;">
                      <?= ucfirst($r['payment_status']) ?>
                    </span>
                  </div>
                </td>
                <td>
                  <span class="badge <?= e($statusBadge[$r['status']] ?? 'text-bg-secondary') ?> text-uppercase">
                    <?= e($r['status']) ?>
                  </span>
                </td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm">
                    <a href="<?= e(admin_url('order_view.php?id=' . $r['id'])) ?>" class="btn btn-outline-secondary" title="View Order">
                      <i class="bi bi-eye"></i>
                    </a>
                    <a href="<?= e(admin_url('order_print.php?id=' . $r['id'])) ?>" target="_blank" class="btn btn-outline-secondary" title="Print Invoice">
                      <i class="bi bi-printer"></i>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?= render_admin_pagination($page, $pages, $_GET, 15) ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const selectAll = document.getElementById('selectAll');
  const checkboxes = document.querySelectorAll('.order-select');
  if (selectAll) {
    selectAll.addEventListener('change', function() {
      checkboxes.forEach(cb => cb.checked = selectAll.checked);
    });
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
