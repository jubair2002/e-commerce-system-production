<?php
/**
 * Product Analytics Deep-Dive (Prompt 09).
 * Detailed metrics, variant breakdown, traffic sources, order history, and stock ledger.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

if (!has_role('admin', 'editor')) {
    flash('danger', 'You do not have permission to view product analytics.');
    redirect(admin_url('analytics.php'));
}

$productId = (int)($_GET['id'] ?? 0);
if ($productId <= 0) {
    flash('warning', 'Please select a product to inspect.');
    redirect(admin_url('analytics.php'));
}

$pdo = db();
$stmtP = $pdo->prepare(
    "SELECT p.*, c.name AS category_name 
     FROM products p 
     LEFT JOIN categories c ON c.id = p.category_id 
     WHERE p.id = :id LIMIT 1"
);
$stmtP->execute([':id' => $productId]);
$product = $stmtP->fetch();

if (!$product) {
    flash('danger', 'Product not found.');
    redirect(admin_url('analytics.php'));
}

$pageTitle = 'Product Analytics: ' . $product['name'];

// Date range (defaults to last 30 days)
$from = trim((string)($_GET['from'] ?? date('Y-m-d', strtotime('-29 days'))));
$to   = trim((string)($_GET['to'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

// 1. Scoped KPIs
$stmtKpi = $pdo->prepare(
    "SELECT 
         COALESCE(ev.views, 0) AS views,
         COALESCE(ev.unique_visitors, 0) AS unique_visitors,
         COALESCE(ev.add_to_cart, 0) AS add_to_cart,
         COALESCE(ord.orders_count, 0) AS orders_count,
         COALESCE(ord.units_sold, 0) AS units_sold,
         COALESCE(ord.revenue, 0.00) AS revenue
     FROM (SELECT :pid AS pid) d
     LEFT JOIN (
         SELECT 
             product_id,
             SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS views,
             COUNT(DISTINCT CASE WHEN event_type = 'product_view' THEN visitor_hash END) AS unique_visitors,
             SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_cart
         FROM analytics_events
         WHERE product_id = :pid_ev AND DATE(created_at) BETWEEN :from_ev AND :to_ev
         GROUP BY product_id
     ) ev ON ev.product_id = d.pid
     LEFT JOIN (
         SELECT 
             oi.product_id,
             COUNT(DISTINCT o.id) AS orders_count,
             SUM(oi.quantity) AS units_sold,
             SUM(oi.line_total) AS revenue
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE oi.product_id = :pid_ord AND DATE(o.created_at) BETWEEN :from_ord AND :to_ord AND o.status <> 'cancelled'
         GROUP BY oi.product_id
     ) ord ON ord.product_id = d.pid"
);
$stmtKpi->execute([
    ':pid'      => $productId,
    ':pid_ev'   => $productId,
    ':from_ev'  => $from,
    ':to_ev'    => $to,
    ':pid_ord'  => $productId,
    ':from_ord' => $from,
    ':to_ord'   => $to,
]);
$kpis = $stmtKpi->fetch() ?: [
    'views' => 0, 'unique_visitors' => 0, 'add_to_cart' => 0,
    'orders_count' => 0, 'units_sold' => 0, 'revenue' => 0.00
];

$views = (int)$kpis['views'];
$cart  = (int)$kpis['add_to_cart'];
$units = (int)$kpis['units_sold'];
$rev   = (float)$kpis['revenue'];
$stock = (int)$product['stock'];

$viewToCart = $views > 0 ? round(($cart / $views) * 100, 1) : 0.0;
$cartToBuy  = $cart > 0 ? round(($units / $cart) * 100, 1) : 0.0;
$avgPrice   = $units > 0 ? round($rev / $units, 2) : (float)($product['sale_price'] ?: $product['price']);

// 2. Daily Chart Data
$stmtDaily = $pdo->prepare(
    "SELECT 
         DATE(created_at) AS day_date,
         SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS views,
         SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS cart_adds,
         SUM(CASE WHEN event_type = 'purchase' THEN qty ELSE 0 END) AS units_sold
     FROM analytics_events
     WHERE product_id = :pid AND DATE(created_at) BETWEEN :from AND :to
     GROUP BY day_date
     ORDER BY day_date ASC"
);
$stmtDaily->execute([':pid' => $productId, ':from' => $from, ':to' => $to]);
$dailyRows = $stmtDaily->fetchAll();

$chartLabels = [];
$chartViews  = [];
$chartCart   = [];
$chartUnits  = [];
foreach ($dailyRows as $dr) {
    $chartLabels[] = date('M j', strtotime($dr['day_date']));
    $chartViews[]  = (int)$dr['views'];
    $chartCart[]   = (int)$dr['cart_adds'];
    $chartUnits[]  = (int)$dr['units_sold'];
}

// 3. Variant Breakdown from v_sales_by_variant
$variantsSales = [];
try {
    $stmtV = $pdo->prepare(
        "SELECT vb.*, pv.stock AS variant_stock, pv.sku AS variant_sku
         FROM v_sales_by_variant vb
         LEFT JOIN product_variants pv ON pv.id = vb.variant_id
         WHERE vb.product_id = :pid
         ORDER BY vb.revenue DESC"
    );
    $stmtV->execute([':pid' => $productId]);
    $variantsSales = $stmtV->fetchAll();
} catch (Throwable $e) {}

// 4. Traffic Referrer Split
$stmtRef = $pdo->prepare(
    "SELECT referrer_host, COUNT(*) AS count
     FROM analytics_events
     WHERE product_id = :pid AND DATE(created_at) BETWEEN :from AND :to
     GROUP BY referrer_host
     ORDER BY count DESC
     LIMIT 8"
);
$stmtRef->execute([':pid' => $productId, ':from' => $from, ':to' => $to]);
$referrers = $stmtRef->fetchAll();

// 5. Last 20 Orders Containing this Product
$stmtO = $pdo->prepare(
    "SELECT o.id, o.order_number, o.created_at, o.customer_name, o.customer_phone, o.status,
            oi.variant_label, oi.quantity, oi.unit_price, oi.line_total
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE oi.product_id = :pid
     ORDER BY o.created_at DESC
     LIMIT 20"
);
$stmtO->execute([':pid' => $productId]);
$recentOrders = $stmtO->fetchAll();

// 6. Stock Movements Mini-Ledger
$movements = [];
try {
    $stmtM = $pdo->prepare(
        "SELECT im.*, u.name AS user_name
         FROM inventory_movements im
         LEFT JOIN users u ON u.id = im.user_id
         WHERE im.product_id = :pid
         ORDER BY im.id DESC
         LIMIT 15"
    );
    $stmtM->execute([':pid' => $productId]);
    $movements = $stmtM->fetchAll();
} catch (Throwable $e) {}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-0">

  <!-- Header Banner & Navigation -->
  <div class="card mb-4 border-0 shadow-sm">
    <div class="card-body p-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        
        <div class="d-flex align-items-center gap-3">
          <a href="<?= e(admin_url('analytics.php')) ?>" class="btn btn-outline-secondary btn-sm" title="Back to Analytics Overview">
            <i class="bi bi-arrow-left"></i>
          </a>
          <div>
            <h5 class="fw-bold mb-0"><?= e($product['name']) ?></h5>
            <div class="text-muted small">
              Category: <span class="badge bg-light text-dark border"><?= e($product['category_name'] ?: 'Uncategorized') ?></span> &bull;
              SKU: <strong><?= e($product['sku'] ?: 'N/A') ?></strong> &bull;
              Price: <strong><?= price($product['sale_price'] ?: $product['price']) ?></strong>
            </div>
          </div>
        </div>

        <!-- Date Range Filter -->
        <form method="get" class="d-flex align-items-center gap-2 m-0">
          <input type="hidden" name="id" value="<?= $productId ?>">
          <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" style="width: 135px;">
          <span class="text-muted small">&ndash;</span>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" style="width: 135px;">
          <button type="submit" class="btn btn-primary btn-sm">Filter</button>
          <a href="<?= e(admin_url('product_form.php?id=' . $productId)) ?>" class="btn btn-outline-dark btn-sm">
            <i class="bi bi-pencil me-1"></i> Edit Product
          </a>
        </form>

      </div>
    </div>
  </div>

  <!-- KPI Tiles -->
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body p-3">
          <div class="text-muted small fw-semibold mb-1">Product Revenue</div>
          <div class="fs-4 fw-bold text-success"><?= price($rev) ?></div>
          <div class="small text-muted" style="font-size: 0.75rem;">Excludes cancelled orders</div>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-md-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body p-3">
          <div class="text-muted small fw-semibold mb-1">Units Sold / Orders</div>
          <div class="fs-4 fw-bold text-dark"><?= number_format($units) ?> <span class="fs-6 text-muted fw-normal">(<?= number_format($kpis['orders_count']) ?> orders)</span></div>
          <div class="small text-muted" style="font-size: 0.75rem;">Avg Price: <?= price($avgPrice) ?></div>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-md-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body p-3">
          <div class="text-muted small fw-semibold mb-1">Views &amp; Cart Adds</div>
          <div class="fs-4 fw-bold text-primary"><?= number_format($views) ?> <span class="fs-6 text-muted fw-normal">(<?= number_format($cart) ?> in cart)</span></div>
          <div class="small text-muted" style="font-size: 0.75rem;">View&rarr;Cart: <strong><?= $viewToCart ?>%</strong></div>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-md-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body p-3">
          <div class="text-muted small fw-semibold mb-1">Current Stock</div>
          <div class="fs-4 fw-bold <?= $stock <= 5 ? 'text-danger' : 'text-dark' ?>"><?= number_format($stock) ?> units</div>
          <div class="small text-muted" style="font-size: 0.75rem;">Cart&rarr;Buy: <strong><?= $cartToBuy ?>%</strong></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart & Referrers -->
  <div class="row g-4 mb-4">
    <!-- Daily Trend Chart -->
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3">
          <h6 class="card-title fw-bold mb-0">Daily Views, Cart Adds &amp; Sales</h6>
        </div>
        <div class="card-body p-3">
          <div style="height: 280px;">
            <canvas id="productDailyChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Traffic Sources -->
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3">
          <h6 class="card-title fw-bold mb-0">Referrer Traffic Split</h6>
        </div>
        <div class="card-body p-3">
          <?php if (empty($referrers)): ?>
            <p class="text-muted small text-center my-4">No referrer records found for this period.</p>
          <?php else: ?>
            <div class="list-group list-group-flush small">
              <?php foreach ($referrers as $r): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                  <span class="text-truncate" style="max-width: 180px;"><i class="bi bi-link-45deg me-1"></i><?= e($r['referrer_host']) ?></span>
                  <span class="badge bg-light text-dark border"><?= number_format($r['count']) ?> events</span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Variant Breakdown Table -->
  <?php if (!empty($variantsSales)): ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-transparent border-0 pt-3 px-3">
        <h6 class="card-title fw-bold mb-0">Variant Breakdown (Sizes / Colors Sold)</h6>
        <small class="text-muted">Data directly from customer orders. Helps identify which sizes/colors to reorder.</small>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" style="font-size: 0.84rem;">
            <thead class="table-light">
              <tr>
                <th class="ps-3">Variant Specification</th>
                <th>SKU</th>
                <th class="text-end">Units Sold</th>
                <th class="text-end">Revenue</th>
                <th class="text-end">Orders Count</th>
                <th class="text-end">Remaining Stock</th>
                <th class="pe-3 text-end">Last Sale Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($variantsSales as $v): ?>
                <tr>
                  <td class="ps-3 fw-semibold text-dark"><?= e($v['variant_label'] ?: 'Default') ?></td>
                  <td class="text-muted"><?= e($v['variant_sku'] ?: '&ndash;') ?></td>
                  <td class="text-end fw-bold"><?= number_format($v['units']) ?></td>
                  <td class="text-end text-success fw-bold"><?= price($v['revenue']) ?></td>
                  <td class="text-end"><?= number_format($v['orders_count']) ?></td>
                  <td class="text-end <?= ((int)($v['variant_stock'] ?? 0) <= 3) ? 'text-danger fw-bold' : '' ?>">
                    <?= isset($v['variant_stock']) ? number_format($v['variant_stock']) : '&ndash;' ?>
                  </td>
                  <td class="pe-3 text-end text-muted">
                    <?= !empty($v['last_sale']) ? date('M j, Y', strtotime($v['last_sale'])) : '&ndash;' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Recent Orders & Stock Ledger 2-Column Section -->
  <div class="row g-4 mb-4">
    <!-- Recent 20 Orders -->
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3">
          <h6 class="card-title fw-bold mb-0">Recent Orders Containing this Item</h6>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.8rem;">
              <thead class="table-light">
                <tr>
                  <th class="ps-3">Order #</th>
                  <th>Customer</th>
                  <th>Variant</th>
                  <th class="text-center">Qty</th>
                  <th class="text-end">Line Total</th>
                  <th class="pe-3 text-end">Date</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($recentOrders)): ?>
                  <tr><td colspan="6" class="text-center text-muted py-3">No orders found.</td></tr>
                <?php else: ?>
                  <?php foreach ($recentOrders as $ro): ?>
                    <tr>
                      <td class="ps-3">
                        <a href="<?= e(admin_url('order_view.php?id=' . $ro['id'])) ?>" class="fw-semibold text-primary text-decoration-none">
                          <?= e($ro['order_number']) ?>
                        </a>
                      </td>
                      <td><?= e($ro['customer_name']) ?></td>
                      <td class="text-muted"><?= e($ro['variant_label'] ?: '&ndash;') ?></td>
                      <td class="text-center"><?= (int)$ro['quantity'] ?></td>
                      <td class="text-end fw-bold"><?= price($ro['line_total']) ?></td>
                      <td class="pe-3 text-end text-muted"><?= date('M j, g:i a', strtotime($ro['created_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Stock Movement Mini-Ledger -->
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3">
          <h6 class="card-title fw-bold mb-0">Inventory Movement Mini-Ledger</h6>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.78rem;">
              <thead class="table-light">
                <tr>
                  <th class="ps-3">Reason</th>
                  <th class="text-center">Change</th>
                  <th class="text-center">Balance</th>
                  <th class="pe-3 text-end">Timestamp</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($movements)): ?>
                  <tr><td colspan="4" class="text-center text-muted py-3">No inventory movements recorded yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($movements as $m): ?>
                    <tr>
                      <td class="ps-3">
                        <span class="badge bg-light text-dark border text-capitalize"><?= str_replace('_', ' ', e($m['reason'])) ?></span>
                        <?php if (!empty($m['note'])): ?>
                          <div class="text-muted" style="font-size: 0.7rem;"><?= e($m['note']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td class="text-center fw-bold <?= $m['change_qty'] > 0 ? 'text-success' : 'text-danger' ?>">
                        <?= $m['change_qty'] > 0 ? '+' . $m['change_qty'] : $m['change_qty'] ?>
                      </td>
                      <td class="text-center fw-semibold"><?= (int)$m['stock_after'] ?></td>
                      <td class="pe-3 text-end text-muted"><?= date('M j, H:i', strtotime($m['created_at'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const ctx = document.getElementById('productDailyChart');
  if (ctx) {
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
          {
            label: 'Views',
            data: <?= json_encode($chartViews) ?>,
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
            fill: true,
            tension: 0.3
          },
          {
            label: 'Cart Adds',
            data: <?= json_encode($chartCart) ?>,
            borderColor: '#ffc107',
            backgroundColor: 'transparent',
            tension: 0.3
          },
          {
            label: 'Units Sold',
            data: <?= json_encode($chartUnits) ?>,
            borderColor: '#198754',
            backgroundColor: 'transparent',
            tension: 0.3
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false
      }
    });
  }
});
</script>

<?php
include __DIR__ . '/includes/footer.php';
