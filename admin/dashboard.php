<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pageTitle = 'Dashboard';

// E-commerce stats (gracefully handle missing tables — pre-migration).
$ecommerceEnabled = false;
$totalProducts    = 0;
$activeProducts   = 0;
$outOfStock       = 0;
$totalCategories  = 0;
$featuredCount    = 0;
$inventoryValue   = 0.0;
$lowStock         = [];
$recentProducts   = [];

// Order stats
$totalOrders     = 0;
$pendingOrders   = 0;
$totalRevenue    = 0.0;
$todayRevenue    = 0.0;
$recentOrders    = [];
$ordersEnabled   = false;

// 7-day analytics trend
$revLabels   = [];
$revValues   = [];
$orderValues = [];

try {
    $totalProducts    = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $activeProducts   = (int) db()->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
    $outOfStock       = (int) db()->query('SELECT COUNT(*) FROM products WHERE stock <= 0')->fetchColumn();
    $totalCategories  = (int) db()->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    $featuredCount    = (int) db()->query('SELECT COUNT(*) FROM products WHERE featured = 1')->fetchColumn();
    $inventoryValue   = (float) db()->query(
        'SELECT COALESCE(SUM(stock * COALESCE(sale_price, price)), 0) FROM products'
    )->fetchColumn();

    $lowStock = db()->query(
        'SELECT id, name, sku, stock, image FROM products
         WHERE stock > 0 AND stock <= 10 ORDER BY stock ASC LIMIT 6'
    )->fetchAll();

    $recentProducts = db()->query(
        'SELECT p.id, p.name, p.price, p.sale_price, p.stock, p.image, p.created_at,
                c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         ORDER BY p.created_at DESC LIMIT 6'
    )->fetchAll();

    $ecommerceEnabled = true;
} catch (Throwable $e) {}

try {
    $totalOrders   = (int) db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $pendingOrders = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
    $totalRevenue  = (float) db()->query(
        "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status NOT IN ('cancelled')"
    )->fetchColumn();
    $todayRevenue  = (float) db()->query(
        "SELECT COALESCE(SUM(total), 0) FROM orders
         WHERE status NOT IN ('cancelled') AND DATE(created_at) = CURDATE()"
    )->fetchColumn();
    $recentOrders  = db()->query(
        "SELECT id, order_number, customer_name, total, status, created_at
         FROM orders ORDER BY created_at DESC LIMIT 6"
    )->fetchAll();

    // Chart: 7-day revenue & order trends
    $revRows = db()->query(
        "SELECT DATE(created_at) AS d, COALESCE(SUM(total), 0) AS total_rev, COUNT(*) AS total_orders
         FROM orders
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
           AND status != 'cancelled'
         GROUP BY DATE(created_at)"
    )->fetchAll();
    $byDateRev = [];
    $byDateOrders = [];
    foreach ($revRows as $r) {
        $byDateRev[$r['d']] = (float)$r['total_rev'];
        $byDateOrders[$r['d']] = (int)$r['total_orders'];
    }
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $revLabels[] = date('M j', strtotime($d));
        $revValues[] = round($byDateRev[$d] ?? 0.0, 2);
        $orderValues[] = (int)($byDateOrders[$d] ?? 0);
    }

    $ordersEnabled = true;
} catch (Throwable $e) {}

// Customer metrics
$totalCustomers  = 0;
$newCustomers7d  = 0;
$repeatCustomers = 0;
try {
    $totalCustomers = (int) db()->query("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL")->fetchColumn();
    $newCustomers7d = (int) db()->query(
        "SELECT COUNT(*) FROM customers 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
           AND deleted_at IS NULL"
    )->fetchColumn();

    $repeatCustomers = (int) db()->query(
        "SELECT COUNT(*) FROM (
            SELECT customer_id FROM orders 
            WHERE customer_id IS NOT NULL AND status != 'cancelled'
            GROUP BY customer_id 
            HAVING COUNT(*) >= 2
         ) t"
    )->fetchColumn();
} catch (Throwable $e) {}

// Subscribers & Messages
$totalSubscribers = 0;
$newMessagesCount = 0;
try {
    $totalSubscribers = (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status IN ('active', 'subscribed')")->fetchColumn();
    $newMessagesCount = (int) db()->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn();
} catch (Throwable $e) {}

// Recent Activity
$recentActivity = [];
try {
    $recentActivity = db()->query(
        "SELECT a.*, u.name AS user_name
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.created_at DESC LIMIT 6"
    )->fetchAll();
} catch (Throwable $e) {}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ==========================================================
   Dashboard Unified Layout & Geometric Consistency
   ========================================================== */

/* Welcome Banner */
.dash-welcome-banner {
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
  border-radius: 14px;
  padding: 1.5rem 1.75rem;
  color: #ffffff;
  position: relative;
  overflow: hidden;
  box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.12);
  margin-bottom: 1.5rem;
}
.dash-welcome-banner::after {
  content: '';
  position: absolute;
  top: -40px;
  right: -40px;
  width: 220px;
  height: 220px;
  background: radial-gradient(circle, rgba(56, 189, 248, 0.15) 0%, rgba(56, 189, 248, 0) 70%);
  pointer-events: none;
}
.dash-live-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.15);
  padding: 3px 10px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #e2e8f0;
}
.dash-live-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.35);
}

/* Card Foundation */
.dash-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 4px 12px rgba(15, 23, 42, 0.02);
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  overflow: hidden;
}
.dash-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
  border-color: #cbd5e1;
}

/* Primary KPI Cards (Consistent Height & Internal Grid) */
.dash-kpi-card {
  height: 154px;
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.dash-kpi-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}
.dash-kpi-title {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #64748b;
  margin-bottom: 4px;
}
.dash-kpi-value {
  font-size: 1.65rem;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.15;
  margin: 0;
}
.dash-kpi-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  flex-shrink: 0;
}
.dash-kpi-footer {
  padding-top: 0.65rem;
  border-top: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  line-height: 1.3;
}

/* Secondary Compact KPI Cards (Exact Equal 4-Column Grid) */
.dash-sub-card {
  height: 84px;
  padding: 0.85rem 1.15rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.dash-sub-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.15rem;
  flex-shrink: 0;
}

/* Matched Card Headers */
.dash-card-header {
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
}
.dash-card-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Equalized Pair Heights */
.dash-upper-card {
  height: 410px;
  display: flex;
  flex-direction: column;
}
.dash-upper-body {
  flex: 1 1 auto;
  padding: 1.25rem;
  position: relative;
  overflow: hidden;
}
.dash-lower-card {
  height: 450px;
  display: flex;
  flex-direction: column;
}
.dash-lower-body {
  flex: 1 1 auto;
  padding: 0;
  overflow-y: auto;
  position: relative;
}

/* Table and Timeline Elements */
.dash-avatar-circle {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.status-pill {
  font-size: 11px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 9999px;
  letter-spacing: 0.02em;
  display: inline-block;
}
.chart-toggle-pill {
  font-size: 11px;
  font-weight: 700;
  padding: 4px 12px;
  border-radius: 9999px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #64748b;
  cursor: pointer;
  transition: all 0.15s ease;
}
.chart-toggle-pill.active {
  background: #4f46e5;
  color: #ffffff;
  border-color: #4f46e5;
  box-shadow: 0 2px 6px rgba(79, 70, 229, 0.35);
}
</style>

<!-- 1. Hero Welcome & Shortcuts -->
<div class="dash-welcome-banner d-flex flex-wrap align-items-center justify-content-between gap-3">
  <div>
    <div class="d-flex align-items-center gap-2 mb-2">
      <span class="dash-live-badge">
        <span class="dash-live-dot"></span> Live Store Operations
      </span>
      <span class="text-white-50 small">&middot; <?= date('l, F j, Y') ?></span>
    </div>
    <h2 class="h3 fw-bold mb-1 text-white">Welcome back, <?= e(current_user()['name']) ?> 👋</h2>
    <p class="text-white-50 small mb-0">Here is your executive store performance overview and operational health.</p>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <a href="<?= e(admin_url('product_form.php')) ?>" class="btn btn-primary px-3 shadow-sm rounded-pill fw-medium">
      <i class="bi bi-plus-lg me-1"></i> Add Product
    </a>
    <a href="<?= e(admin_url('orders.php')) ?>" class="btn btn-light px-3 shadow-sm rounded-pill text-dark fw-medium">
      <i class="bi bi-receipt me-1"></i> Orders
    </a>
    <a href="<?= e(url()) ?>" target="_blank" class="btn btn-outline-light px-3 rounded-pill fw-medium">
      <i class="bi bi-box-arrow-up-right me-1"></i> Storefront
    </a>
  </div>
</div>

<!-- 2. Row 1: Primary 4 KPI Cards (Uniform Sizes & Heights) -->
<div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-xl-4 mb-3">
  <!-- Card 1: Total Revenue -->
  <div class="col">
    <div class="dash-card dash-kpi-card">
      <div class="dash-kpi-header">
        <div>
          <div class="dash-kpi-title">Total Revenue</div>
          <h3 class="dash-kpi-value"><?= e(price($totalRevenue)) ?></h3>
        </div>
        <div class="dash-kpi-icon" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">
          <i class="bi bi-cash-stack"></i>
        </div>
      </div>
      <div class="dash-kpi-footer">
        <span class="text-muted">Today's Sales</span>
        <span class="fw-bold text-success">+<?= e(price($todayRevenue)) ?></span>
      </div>
    </div>
  </div>

  <!-- Card 2: Total Orders -->
  <div class="col">
    <a href="<?= e(admin_url('orders.php')) ?>" class="text-decoration-none">
      <div class="dash-card dash-kpi-card">
        <div class="dash-kpi-header">
          <div>
            <div class="dash-kpi-title">Total Orders</div>
            <h3 class="dash-kpi-value"><?= number_format($totalOrders) ?></h3>
          </div>
          <div class="dash-kpi-icon" style="background: rgba(79, 70, 229, 0.12); color: #4f46e5;">
            <i class="bi bi-bag-check"></i>
          </div>
        </div>
        <div class="dash-kpi-footer">
          <span class="text-muted">Pending Orders</span>
          <span class="badge rounded-pill bg-warning text-dark px-2 py-1"><?= number_format($pendingOrders) ?> pending</span>
        </div>
      </div>
    </a>
  </div>

  <!-- Card 3: Active Catalog -->
  <div class="col">
    <a href="<?= e(admin_url('products.php')) ?>" class="text-decoration-none">
      <div class="dash-card dash-kpi-card">
        <div class="dash-kpi-header">
          <div>
            <div class="dash-kpi-title">Active Catalog</div>
            <h3 class="dash-kpi-value"><?= number_format($activeProducts) ?></h3>
          </div>
          <div class="dash-kpi-icon" style="background: rgba(14, 165, 233, 0.12); color: #0ea5e9;">
            <i class="bi bi-box-seam"></i>
          </div>
        </div>
        <div class="dash-kpi-footer">
          <span class="text-muted">In Categories</span>
          <span class="fw-semibold text-secondary"><?= number_format($totalCategories) ?> categories</span>
        </div>
      </div>
    </a>
  </div>

  <!-- Card 4: Total Customers -->
  <div class="col">
    <a href="<?= e(admin_url('customers.php')) ?>" class="text-decoration-none">
      <div class="dash-card dash-kpi-card">
        <div class="dash-kpi-header">
          <div>
            <div class="dash-kpi-title">Total Customers</div>
            <h3 class="dash-kpi-value"><?= number_format($totalCustomers ?: 1) ?></h3>
          </div>
          <div class="dash-kpi-icon" style="background: rgba(168, 85, 247, 0.12); color: #a855f7;">
            <i class="bi bi-people"></i>
          </div>
        </div>
        <div class="dash-kpi-footer">
          <span class="text-muted">Recent Buyers</span>
          <span class="badge rounded-pill bg-light text-dark border px-2 py-1">+<?= number_format($newCustomers7d) ?> (7 days)</span>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- 3. Row 2: Secondary 4 Metric Cards (Consistent Height & 4-Column Grid) -->
<div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-xl-4 mb-4">
  <!-- Sub Card 1: Inventory Valuation -->
  <div class="col">
    <a href="<?= e(admin_url('inventory.php')) ?>" class="text-decoration-none text-dark">
      <div class="dash-card dash-sub-card">
        <div>
          <div class="dash-kpi-title">Inventory Value</div>
          <div class="fw-bold fs-6 text-dark"><?= e(price($inventoryValue)) ?></div>
        </div>
        <div class="dash-sub-icon bg-light text-primary border">
          <i class="bi bi-wallet2"></i>
        </div>
      </div>
    </a>
  </div>

  <!-- Sub Card 2: Stock Health -->
  <div class="col">
    <a href="<?= e(admin_url('inventory.php?filter=low')) ?>" class="text-decoration-none text-dark">
      <div class="dash-card dash-sub-card">
        <div>
          <div class="dash-kpi-title">Low Stock Alert</div>
          <div class="fw-bold fs-6 text-dark">
            <span class="<?= count($lowStock) > 0 ? 'text-danger' : 'text-success' ?>"><?= count($lowStock) ?> items</span>
            <span class="text-muted small fw-normal">(<?= number_format($outOfStock) ?> out)</span>
          </div>
        </div>
        <div class="dash-sub-icon <?= count($lowStock) > 0 ? 'bg-danger bg-opacity-10 text-danger border border-danger-subtle' : 'bg-light text-muted border' ?>">
          <i class="bi bi-exclamation-triangle"></i>
        </div>
      </div>
    </a>
  </div>

  <!-- Sub Card 3: Newsletter Subscribers -->
  <div class="col">
    <a href="<?= e(admin_url('subscribers.php')) ?>" class="text-decoration-none text-dark">
      <div class="dash-card dash-sub-card">
        <div>
          <div class="dash-kpi-title">Subscribers</div>
          <div class="fw-bold fs-6 text-dark"><?= number_format($totalSubscribers) ?> audience</div>
        </div>
        <div class="dash-sub-icon bg-light text-success border">
          <i class="bi bi-envelope-check"></i>
        </div>
      </div>
    </a>
  </div>

  <!-- Sub Card 4: Customer Retention -->
  <div class="col">
    <a href="<?= e(admin_url('customers.php?orders=has_orders')) ?>" class="text-decoration-none text-dark">
      <div class="dash-card dash-sub-card">
        <div>
          <div class="dash-kpi-title">Repeat Buyers</div>
          <div class="fw-bold fs-6 text-dark">
            <?= $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 1) : 0 ?>% retention
          </div>
        </div>
        <div class="dash-sub-icon bg-light text-info border">
          <i class="bi bi-arrow-repeat"></i>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- 4. Row 3: Upper Matched Pair (Chart & Low Stock Alerts: Exact 410px Height) -->
<div class="row g-4 mb-4">
  <!-- Left: Performance Analytics Chart (Height: 410px) -->
  <div class="col-xl-8">
    <div class="dash-card dash-upper-card">
      <div class="dash-card-header">
        <div>
          <h5 class="dash-card-title">
            <i class="bi bi-graph-up text-primary"></i> Store Performance (Last 7 Days)
          </h5>
          <span class="text-muted small">Daily revenue volume and customer orders trend.</span>
        </div>
        <div class="d-flex align-items-center gap-1">
          <button type="button" class="chart-toggle-pill active" id="btnChartRev">Revenue ($)</button>
          <button type="button" class="chart-toggle-pill" id="btnChartOrders">Orders (#)</button>
        </div>
      </div>
      <div class="dash-upper-body">
        <div style="position: relative; width: 100%; height: 100%;">
          <canvas id="performanceChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Low Stock Alert Watch (Matched Height: 410px) -->
  <div class="col-xl-4">
    <div class="dash-card dash-upper-card">
      <div class="dash-card-header">
        <h5 class="dash-card-title">
          <i class="bi bi-exclamation-triangle text-warning"></i> Stock Watchlist
        </h5>
        <a href="<?= e(admin_url('inventory.php?filter=low')) ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
          Inventory
        </a>
      </div>
      <div class="dash-upper-body" style="overflow-y: auto;">
        <?php if (!empty($lowStock)): ?>
          <div class="d-flex flex-column gap-2">
            <?php foreach ($lowStock as $ls): ?>
              <div class="p-2 rounded-3 border bg-light bg-opacity-50 d-flex align-items-center gap-2">
                <img src="<?= e(product_image_url($ls['image'], 'thumbnail', (int)$ls['id'])) ?>"
                     class="rounded border" width="38" height="38" style="object-fit: cover;" alt="">
                <div class="flex-grow-1 min-w-0">
                  <a href="<?= e(admin_url('product_form.php?id=' . (int)$ls['id'])) ?>" class="fw-semibold text-dark text-truncate d-block small text-decoration-none">
                    <?= e($ls['name']) ?>
                  </a>
                  <span class="text-muted" style="font-size: 11px;">SKU: <?= e($ls['sku'] ?: '-') ?></span>
                </div>
                <div class="text-end">
                  <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle fw-bold" style="font-size: 11px;">
                    <?= (int)$ls['stock'] ?> left
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="h-100 d-flex flex-column align-items-center justify-content-center text-center py-4 text-muted">
            <i class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"></i>
            <span class="small fw-semibold text-dark">Stock Levels Healthy</span>
            <span class="small text-muted mt-1">All products currently satisfy minimum inventory thresholds.</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- 5. Row 4: Lower Matched Pair (Recent Orders & Activity Stream: Exact 450px Height) -->
<div class="row g-4">
  <!-- Left: Recent Orders Table (Height: 450px) -->
  <div class="col-xl-8">
    <div class="dash-card dash-lower-card">
      <div class="dash-card-header">
        <div>
          <h5 class="dash-card-title">
            <i class="bi bi-receipt text-primary"></i> Recent Customer Orders
          </h5>
          <span class="text-muted small">Latest purchases processed on the storefront.</span>
        </div>
        <a href="<?= e(admin_url('orders.php')) ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
          View All
        </a>
      </div>
      <div class="dash-lower-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr class="small text-muted text-uppercase" style="font-size: 11px;">
                <th class="ps-3">Order #</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Status</th>
                <th>Date Placed</th>
                <th class="text-end pe-3">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentOrders)): ?>
                <tr>
                  <td colspan="6" class="text-center py-5 text-muted">
                    <i class="bi bi-bag-x fs-1 d-block mb-2 text-muted"></i>
                    No orders placed yet. Customer orders will appear here in real time.
                  </td>
                </tr>
              <?php else: ?>
                <?php
                $statusBadges = [
                    'pending'    => 'bg-warning text-dark',
                    'processing' => 'bg-info text-dark',
                    'shipped'    => 'bg-primary text-white',
                    'completed'  => 'bg-success text-white',
                    'cancelled'  => 'bg-secondary text-white',
                ];
                foreach ($recentOrders as $ro):
                  $cName = trim($ro['customer_name'] ?: 'Guest Customer');
                  $nameParts = explode(' ', $cName);
                  $initials = strtoupper(substr($nameParts[0], 0, 1) . (!empty($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
                ?>
                  <tr>
                    <td class="ps-3">
                      <a href="<?= e(admin_url('order_view.php?id=' . (int)$ro['id'])) ?>" class="fw-bold text-decoration-none font-monospace small">
                        <?= e($ro['order_number']) ?>
                      </a>
                    </td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <span class="dash-avatar-circle"><?= e($initials) ?></span>
                        <span class="fw-medium text-dark small text-truncate" style="max-width: 140px;"><?= e($cName) ?></span>
                      </div>
                    </td>
                    <td class="fw-bold text-dark small">
                      <?= e(price((float)$ro['total'])) ?>
                    </td>
                    <td>
                      <span class="status-pill <?= e($statusBadges[$ro['status']] ?? 'bg-light text-dark border') ?>">
                        <?= e(ucfirst($ro['status'])) ?>
                      </span>
                    </td>
                    <td class="small text-muted">
                      <?= date('M j, g:i a', strtotime($ro['created_at'])) ?>
                    </td>
                    <td class="text-end pe-3">
                      <a href="<?= e(admin_url('order_view.php?id=' . (int)$ro['id'])) ?>" class="btn btn-sm btn-light border" title="View Order Details">
                        <i class="bi bi-chevron-right"></i>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Recent Store Activity (Matched Height: 450px) -->
  <div class="col-xl-4">
    <div class="dash-card dash-lower-card">
      <div class="dash-card-header">
        <h5 class="dash-card-title">
          <i class="bi bi-clock-history text-primary"></i> Store Activity Feed
        </h5>
        <a href="<?= e(admin_url('activity.php')) ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
          All Logs
        </a>
      </div>
      <div class="dash-lower-body p-3">
        <?php if (!empty($recentActivity)): ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($recentActivity as $act): ?>
              <div class="d-flex align-items-start gap-2 pb-2 border-bottom">
                <div class="mt-1">
                  <span class="badge bg-light text-secondary border rounded-circle p-1" style="width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center;">
                    <i class="bi bi-dot" style="font-size: 1.4rem;"></i>
                  </span>
                </div>
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex align-items-baseline justify-content-between gap-1">
                    <span class="small fw-semibold text-dark"><?= e($act['user_name'] ?: 'System') ?></span>
                    <span class="text-muted" style="font-size: 10px;"><?= e(format_date($act['created_at'])) ?></span>
                  </div>
                  <p class="mb-0 text-muted small text-truncate" style="font-size: 12px;">
                    <?= e($act['description']) ?>
                  </p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="h-100 d-flex flex-column align-items-center justify-content-center text-center py-4 text-muted">
            <i class="bi bi-journal-text fs-2 d-block mb-2 text-muted"></i>
            <span class="small">No recent activity logged.</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('performanceChart');
    if (!ctx) return;

    const labels = <?= json_encode($revLabels) ?>;
    const revData = <?= json_encode($revValues) ?>;
    const ordersData = <?= json_encode($orderValues) ?>;

    let currentMode = 'revenue';

    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue ($)',
                data: revData,
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.08)',
                fill: true,
                tension: 0.35,
                borderWidth: 2.5,
                pointBackgroundColor: '#4f46e5',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return (currentMode === 'revenue' ? '$' : '') + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(226, 232, 240, 0.6)' },
                    ticks: {
                        font: { size: 11 },
                        callback: function(val) {
                            return currentMode === 'revenue' ? ('$' + val) : val;
                        }
                    }
                }
            }
        }
    });

    const btnRev = document.getElementById('btnChartRev');
    const btnOrders = document.getElementById('btnChartOrders');

    if (btnRev && btnOrders) {
        btnRev.addEventListener('click', function() {
            currentMode = 'revenue';
            btnRev.classList.add('active');
            btnOrders.classList.remove('active');
            chart.data.datasets[0].label = 'Revenue ($)';
            chart.data.datasets[0].data = revData;
            chart.data.datasets[0].borderColor = '#4f46e5';
            chart.data.datasets[0].backgroundColor = 'rgba(79, 70, 229, 0.08)';
            chart.data.datasets[0].pointBackgroundColor = '#4f46e5';
            chart.update();
        });

        btnOrders.addEventListener('click', function() {
            currentMode = 'orders';
            btnOrders.classList.add('active');
            btnRev.classList.remove('active');
            chart.data.datasets[0].label = 'Orders (#)';
            chart.data.datasets[0].data = ordersData;
            chart.data.datasets[0].borderColor = '#10b981';
            chart.data.datasets[0].backgroundColor = 'rgba(16, 185, 129, 0.08)';
            chart.data.datasets[0].pointBackgroundColor = '#10b981';
            chart.update();
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
