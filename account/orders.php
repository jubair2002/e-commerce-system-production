<?php
/**
 * Customer Orders List (Prompt 13).
 * Filter by status, search by order number, server-side pagination (10/page).
 * Strictly scoped to currently logged-in customer (including claimed guest orders).
 */
require_once __DIR__ . '/_layout.php';

$customer = customer_require_login();
$cid = (int)$customer['id'];
$pdo = db();

$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$searchQuery  = trim((string)($_GET['q'] ?? ''));
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

$where = ["customer_id = :cid"];
$params = [':cid' => $cid];

$allowedStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = "status = :st";
    $params[':st'] = $statusFilter;
}

if ($searchQuery !== '') {
    $where[] = "order_number LIKE :q";
    $params[':q'] = '%' . $searchQuery . '%';
}

$whereClause = implode(' AND ', $where);

// Total count
$totalOrders = 0;
try {
    $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE {$whereClause}");
    $stmtCnt->execute($params);
    $totalOrders = (int)$stmtCnt->fetchColumn();
} catch (Throwable $e) {
    $totalOrders = 0;
}

$totalPages = max(1, (int)ceil($totalOrders / $perPage));
if ($page > $totalPages && $totalOrders > 0) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Fetch orders page
$orders = [];
try {
    $stmtO = $pdo->prepare(
        "SELECT id, order_number, total, subtotal, shipping_fee, discount_total, cod_fee,
                status, payment_method, payment_status, created_at,
                (SELECT COUNT(*) FROM order_items WHERE order_id = orders.id) AS item_count
         FROM orders
         WHERE {$whereClause}
         ORDER BY id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmtO->execute($params);
    $orders = $stmtO->fetchAll();
} catch (Throwable $e) {
    $orders = [];
}

account_layout_start('orders', 'My Orders', $customer);
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">My Orders</h1>
    <p class="text-muted small mb-0">View all your past and active orders, track status, and re-order essentials.</p>
  </div>
  <div>
    <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary btn-sm">
      <?= icon('bag', 'me-1') ?> Start New Order
    </a>
  </div>
</div>

<!-- Search & Status Filter Bar -->
<div class="account-card p-3 mb-4">
  <div class="row g-2 align-items-center">
    <div class="col-md-7">
      <div class="d-flex flex-wrap gap-1">
        <a href="<?= e(shop_url('account/orders.php' . ($searchQuery ? '?q=' . urlencode($searchQuery) : ''))) ?>"
           class="btn btn-sm <?= empty($statusFilter) ? 'btn-editorial-primary' : 'btn-editorial-secondary' ?>">
          All (<?= $totalOrders ?>)
        </a>
        <?php foreach ($allowedStatuses as $st): ?>
          <a href="<?= e(shop_url('account/orders.php?status=' . $st . ($searchQuery ? '&q=' . urlencode($searchQuery) : ''))) ?>"
             class="btn btn-sm <?= $statusFilter === $st ? 'btn-editorial-primary' : 'btn-editorial-secondary' ?> text-capitalize">
            <?= $st ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-md-5">
      <form method="get" action="orders.php" class="d-flex gap-2">
        <?php if ($statusFilter): ?>
          <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
        <?php endif; ?>
        <div class="input-group input-group-sm">
          <input type="text"
                 name="q"
                 class="form-control"
                 placeholder="Search by order #"
                 value="<?= e($searchQuery) ?>">
          <button type="submit" class="btn btn-editorial-primary" aria-label="Search orders">
            <?= icon('search') ?>
          </button>
          <?php if ($searchQuery !== ''): ?>
            <a href="<?= e(shop_url('account/orders.php' . ($statusFilter ? '?status=' . $statusFilter : ''))) ?>" class="btn btn-editorial-secondary" aria-label="Clear search">
              <?= icon('x') ?>
            </a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Orders Table / List -->
<?php if (empty($orders)): ?>
  <div class="account-card text-center py-5">
    <div class="empty-state-icon mb-3" style="width: 52px; height: 52px; margin: 0 auto; display: flex; align-items: center; justify-content: center; background: var(--surface-muted); border-radius: 50%; color: var(--ink-2);">
      <?= icon('box') ?>
    </div>
    <h3 class="h5 fw-bold mb-1">No Orders Found</h3>
    <p class="text-muted small mb-3">
      <?= ($statusFilter || $searchQuery) ? 'No orders matched your active filters.' : 'You have not placed any orders yet.' ?>
    </p>
    <?php if ($statusFilter || $searchQuery): ?>
      <a href="<?= e(shop_url('account/orders.php')) ?>" class="btn btn-editorial-secondary btn-sm">Clear Filters</a>
    <?php else: ?>
      <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary btn-sm">Browse Products</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="account-card p-0 overflow-hidden">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3 py-3 small text-uppercase">Order #</th>
            <th class="py-3 small text-uppercase">Date</th>
            <th class="py-3 small text-uppercase">Items</th>
            <th class="py-3 small text-uppercase">Status</th>
            <th class="py-3 small text-uppercase">Total</th>
            <th class="pe-3 py-3 small text-uppercase text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <?php
            $statusBadge = 'bg-secondary';
            if ($o['status'] === 'completed' || $o['status'] === 'delivered') $statusBadge = 'bg-success';
            elseif ($o['status'] === 'processing' || $o['status'] === 'shipped') $statusBadge = 'bg-info text-dark';
            elseif ($o['status'] === 'cancelled') $statusBadge = 'bg-danger';
            elseif ($o['status'] === 'pending') $statusBadge = 'bg-warning text-dark';
            ?>
            <tr>
              <td class="ps-3">
                <a href="<?= e(shop_url('account/order.php?o=' . urlencode($o['order_number']))) ?>" class="fw-bold text-dark text-decoration-none">
                  <?= e($o['order_number']) ?>
                </a>
              </td>
              <td class="small text-muted"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
              <td class="small"><?= (int)$o['item_count'] ?> <?= (int)$o['item_count'] === 1 ? 'item' : 'items' ?></td>
              <td>
                <span class="badge <?= $statusBadge ?> text-uppercase" style="font-size: 0.72rem;">
                  <?= e($o['status']) ?>
                </span>
              </td>
              <td class="fw-bold"><?= e(price($o['total'])) ?></td>
              <td class="pe-3 text-end">
                <a href="<?= e(shop_url('account/order.php?o=' . urlencode($o['order_number']))) ?>" class="btn btn-outline-dark btn-sm py-1 px-3" style="font-size: 0.8rem;">
                  View &rarr;
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="p-3 border-top d-flex justify-content-between align-items-center">
        <span class="small text-muted">
          Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalOrders ?> total)
        </span>
        <nav aria-label="Order pages">
          <ul class="pagination pagination-sm mb-0">
            <?php if ($page > 1): ?>
              <li class="page-item">
                <a class="page-link text-dark" href="<?= e(shop_url('account/orders.php?page=' . ($page - 1) . ($statusFilter ? '&status=' . $statusFilter : '') . ($searchQuery ? '&q=' . urlencode($searchQuery) : ''))) ?>">&laquo; Prev</a>
              </li>
            <?php endif; ?>

            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
              <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link <?= $p === $page ? 'bg-dark border-dark text-white' : 'text-dark' ?>" href="<?= e(shop_url('account/orders.php?page=' . $p . ($statusFilter ? '&status=' . $statusFilter : '') . ($searchQuery ? '&q=' . urlencode($searchQuery) : ''))) ?>">
                  <?= $p ?>
                </a>
              </li>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
              <li class="page-item">
                <a class="page-link text-dark" href="<?= e(shop_url('account/orders.php?page=' . ($page + 1) . ($statusFilter ? '&status=' . $statusFilter : '') . ($searchQuery ? '&q=' . urlencode($searchQuery) : ''))) ?>">Next &raquo;</a>
              </li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
account_layout_end();
