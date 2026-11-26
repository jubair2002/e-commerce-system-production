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

// Activity Feed Formatter & Query
if (!function_exists('dash_format_activity')) {
    function dash_format_activity(array $act): array {
        $action = (string)($act['action'] ?? '');
        $rawDesc = (string)($act['description'] ?? '');
        $user = (string)($act['user_name'] ?: 'System');

        $icon = 'bi-activity';
        $badgeColor = '#4f46e5';
        $badgeBg = '#eef2ff';
        $actionLabel = 'Activity';
        $title = $rawDesc;
        $details = '';

        if ($action === 'login') {
            $icon = 'bi-box-arrow-in-right';
            $badgeColor = '#10b981';
            $badgeBg = '#ecfdf5';
            $actionLabel = 'Sign In';
            $title = 'Signed into admin panel';
        } elseif ($action === 'logout') {
            $icon = 'bi-box-arrow-right';
            $badgeColor = '#64748b';
            $badgeBg = '#f1f5f9';
            $actionLabel = 'Sign Out';
            $title = 'Signed out of session';
        } elseif (str_starts_with($action, 'settings')) {
            $icon = 'bi-sliders2';
            $badgeColor = '#3b82f6';
            $badgeBg = '#eff6ff';
            $actionLabel = 'Settings';
            if (preg_match('/tab:\s*([^;]+);\s*changed keys:\s*(.*)/i', $rawDesc, $m)) {
                $tab = ucfirst(trim($m[1]));
                $keys = array_filter(array_map('trim', explode(',', $m[2])));
                $title = "Updated {$tab} settings";
                $details = implode(', ', $keys);
            } elseif (str_contains($rawDesc, 'storage layer')) {
                $title = 'Updated storage settings';
                $details = 'Primary: S3, Fallback: local';
            } else {
                $title = !empty($rawDesc) ? $rawDesc : 'Settings updated';
            }
        } elseif (str_starts_with($action, 'product')) {
            $icon = 'bi-box-seam';
            $badgeColor = '#06b6d4';
            $badgeBg = '#ecfeff';
            $actionLabel = 'Catalog';
            if (preg_match('/Saved product\s+(.*?)\s*\(/i', $rawDesc, $m)) {
                $title = 'Saved product "' . trim($m[1]) . '"';
            } else {
                $title = !empty($rawDesc) ? $rawDesc : 'Product updated';
            }
        } elseif (str_starts_with($action, 'order')) {
            $icon = 'bi-bag-check';
            $badgeColor = '#f59e0b';
            $badgeBg = '#fffbeb';
            $actionLabel = 'Order';
            $title = !empty($rawDesc) ? $rawDesc : 'Order status updated';
        } elseif (str_starts_with($action, 'category')) {
            $icon = 'bi-tags';
            $badgeColor = '#0d9488';
            $badgeBg = '#ccfbf1';
            $actionLabel = 'Category';
            $title = !empty($rawDesc) ? $rawDesc : 'Category updated';
        } elseif (str_starts_with($action, 'coupon')) {
            $icon = 'bi-ticket-perforated';
            $badgeColor = '#db2777';
            $badgeBg = '#fce7f3';
            $actionLabel = 'Coupon';
            $title = !empty($rawDesc) ? $rawDesc : 'Coupon updated';
        } elseif (str_starts_with($action, 'review')) {
            $icon = 'bi-star';
            $badgeColor = '#d97706';
            $badgeBg = '#fef3c7';
            $actionLabel = 'Review';
            $title = !empty($rawDesc) ? $rawDesc : 'Review moderated';
        } elseif (str_starts_with($action, 'monitoring')) {
            $icon = 'bi-speedometer2';
            $badgeColor = '#8b5cf6';
            $badgeBg = '#f5f3ff';
            $actionLabel = 'System';
            $title = !empty($rawDesc) ? $rawDesc : 'System check performed';
        }

        return [
            'icon' => $icon,
            'badgeColor' => $badgeColor,
            'badgeBg' => $badgeBg,
            'actionLabel' => $actionLabel,
            'title' => $title,
            'details' => $details,
            'user' => $user,
        ];
    }
}

$recentActivity = [];
try {
    $recentActivity = db()->query(
        "SELECT a.*, u.name AS user_name
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.created_at DESC LIMIT 10"
    )->fetchAll();
} catch (Throwable $e) {}

include __DIR__ . '/includes/header.php';
?>

<style>
/* Equalized Pair Heights for AdminLTE Dashboard */
.dash-upper-card {
  height: 390px;
  display: flex;
  flex-direction: column;
}
.dash-upper-body {
  flex: 1 1 auto;
  padding: 1rem;
  position: relative;
  overflow: hidden;
}
.dash-lower-card {
  height: 420px;
  display: flex;
  flex-direction: column;
}
.dash-lower-body {
  flex: 1 1 auto;
  padding: 0;
  overflow-y: auto;
  overflow-x: hidden !important;
  position: relative;
}
.dash-lower-body::-webkit-scrollbar {
  width: 5px;
}
.dash-lower-body::-webkit-scrollbar-thumb {
  background: rgba(0, 0, 0, 0.15);
  border-radius: 4px;
}
[data-bs-theme="dark"] .dash-lower-body::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
}

.dash-activity-timeline {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.dash-activity-item {
  border: 1px solid var(--lte-border-color);
  background: var(--lte-card-bg);
  border-radius: 0.25rem;
  padding: 0.55rem 0.75rem;
  transition: background 0.15s ease;
}
.dash-activity-item:hover {
  background: rgba(13, 110, 253, 0.05);
}
.dash-activity-icon-box {
  width: 32px;
  height: 32px;
  border-radius: 0.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.dash-activity-badge {
  font-size: 9px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 4px;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  display: inline-block;
  line-height: 1.2;
}
.dash-activity-title {
  font-weight: 600;
  font-size: 12.5px;
  line-height: 1.35;
  color: var(--lte-text-body);
}
.dash-activity-meta {
  background: rgba(0, 0, 0, 0.03);
  border: 1px solid var(--lte-border-color);
  border-radius: 4px;
  padding: 2px 6px;
  color: var(--lte-text-muted);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 10.5px !important;
}
[data-bs-theme="dark"] .dash-activity-meta {
  background: rgba(255, 255, 255, 0.03);
}

.dash-avatar-circle {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #0d6efd;
  color: #ffffff;
  font-size: 10px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
</style>

<!-- 1. Header Welcome Callout -->
<div class="card card-outline card-primary mb-3">
  <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
      <h4 class="mb-1 fw-bold fs-5">Welcome back, <?= e(current_user()['name'] ?? ($_SESSION['user_name'] ?? 'Admin')) ?>!</h4>
      <p class="text-muted small mb-0">Operational summary &amp; performance overview for <?= date('l, F j, Y') ?>.</p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <a href="<?= e(admin_url('product_form.php')) ?>" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> Add Product
      </a>
      <a href="<?= e(admin_url('orders.php')) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-receipt me-1"></i> Orders
      </a>
      <a href="<?= e(url()) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-box-arrow-up-right me-1"></i> View Store
      </a>
    </div>
  </div>
</div>

<!-- 2. Row 1: AdminLTE Iconic Small Boxes -->
<div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-xl-4 mb-3">
  <!-- Small Box 1: Revenue -->
  <div class="col">
    <div class="small-box bg-success">
      <div class="inner">
        <h3><?= e(price($totalRevenue)) ?></h3>
        <p>Total Revenue</p>
      </div>
      <div class="small-box-icon">
        <i class="bi bi-currency-dollar"></i>
      </div>
      <a href="<?= e(admin_url('orders.php')) ?>" class="small-box-footer">
        Today: +<?= e(price($todayRevenue)) ?> <i class="bi bi-arrow-right-circle ms-1"></i>
      </a>
    </div>
  </div>

  <!-- Small Box 2: Total Orders -->
  <div class="col">
    <div class="small-box bg-primary">
      <div class="inner">
        <h3><?= number_format($totalOrders) ?></h3>
        <p>Total Orders</p>
      </div>
      <div class="small-box-icon">
        <i class="bi bi-receipt"></i>
      </div>
      <a href="<?= e(admin_url('orders.php')) ?>" class="small-box-footer">
        <?= number_format($pendingOrders) ?> Pending <i class="bi bi-arrow-right-circle ms-1"></i>
      </a>
    </div>
  </div>

  <!-- Small Box 3: Active Catalog -->
  <div class="col">
    <div class="small-box bg-info">
      <div class="inner">
        <h3><?= number_format($activeProducts) ?></h3>
        <p>Active Products</p>
      </div>
      <div class="small-box-icon">
        <i class="bi bi-box-seam"></i>
      </div>
      <a href="<?= e(admin_url('products.php')) ?>" class="small-box-footer">
        <?= number_format($totalCategories) ?> Categories <i class="bi bi-arrow-right-circle ms-1"></i>
      </a>
    </div>
  </div>

  <!-- Small Box 4: Total Customers -->
  <div class="col">
    <div class="small-box bg-warning">
      <div class="inner">
        <h3><?= number_format($totalCustomers ?: 1) ?></h3>
        <p>Registered Customers</p>
      </div>
      <div class="small-box-icon">
        <i class="bi bi-people"></i>
      </div>
      <a href="<?= e(admin_url('customers.php')) ?>" class="small-box-footer">
        +<?= number_format($newCustomers7d) ?> New (7d) <i class="bi bi-arrow-right-circle ms-1"></i>
      </a>
    </div>
  </div>
</div>

<!-- 3. Row 2: AdminLTE Info Boxes -->
<div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-xl-4 mb-3">
  <!-- Info Box 1: Inventory Valuation -->
  <div class="col">
    <a href="<?= e(admin_url('inventory.php')) ?>" class="text-decoration-none text-body">
      <div class="info-box">
        <span class="info-box-icon bg-primary shadow-sm"><i class="bi bi-wallet2"></i></span>
        <div class="info-box-content">
          <span class="info-box-text">Inventory Value</span>
          <span class="info-box-number"><?= e(price($inventoryValue)) ?></span>
        </div>
      </div>
    </a>
  </div>

  <!-- Info Box 2: Stock Health -->
  <div class="col">
    <a href="<?= e(admin_url('inventory.php?filter=low')) ?>" class="text-decoration-none text-body">
      <div class="info-box">
        <span class="info-box-icon <?= count($lowStock) > 0 ? 'bg-danger' : 'bg-success' ?> shadow-sm">
          <i class="bi bi-exclamation-triangle"></i>
        </span>
        <div class="info-box-content">
          <span class="info-box-text">Low Stock Alert</span>
          <span class="info-box-number">
            <span class="<?= count($lowStock) > 0 ? 'text-danger' : 'text-success' ?>"><?= count($lowStock) ?> items</span>
            <small class="text-muted fw-normal">(<?= number_format($outOfStock) ?> out)</small>
          </span>
        </div>
      </div>
    </a>
  </div>

  <!-- Info Box 3: Newsletter Subscribers -->
  <div class="col">
    <a href="<?= e(admin_url('subscribers.php')) ?>" class="text-decoration-none text-body">
      <div class="info-box">
        <span class="info-box-icon bg-success shadow-sm"><i class="bi bi-envelope-check"></i></span>
        <div class="info-box-content">
          <span class="info-box-text">Subscribers</span>
          <span class="info-box-number"><?= number_format($totalSubscribers) ?> audience</span>
        </div>
      </div>
    </a>
  </div>

  <!-- Info Box 4: Customer Retention -->
  <div class="col">
    <a href="<?= e(admin_url('customers.php?orders=has_orders')) ?>" class="text-decoration-none text-body">
      <div class="info-box">
        <span class="info-box-icon bg-secondary shadow-sm"><i class="bi bi-arrow-repeat"></i></span>
        <div class="info-box-content">
          <span class="info-box-text">Repeat Buyers</span>
          <span class="info-box-number"><?= $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 1) : 0 ?>% retention</span>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- 4. Row 3: Performance Chart & Stock Watchlist -->
<div class="row g-3 mb-3">
  <!-- Left: Performance Analytics Chart -->
  <div class="col-xl-8">
    <div class="card card-outline card-primary dash-upper-card">
      <div class="card-header">
        <h5 class="card-title">
          <i class="bi bi-graph-up text-primary"></i> Store Performance (Last 7 Days)
        </h5>
        <div class="card-tools btn-group btn-group-sm">
          <button type="button" class="btn btn-primary active" id="btnChartRev">Revenue ($)</button>
          <button type="button" class="btn btn-outline-primary" id="btnChartOrders">Orders (#)</button>
        </div>
      </div>
      <div class="dash-upper-body">
        <div style="position: relative; width: 100%; height: 100%;">
          <canvas id="performanceChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Stock Watchlist -->
  <div class="col-xl-4">
    <div class="card card-outline card-warning dash-upper-card">
      <div class="card-header">
        <h5 class="card-title">
          <i class="bi bi-exclamation-triangle text-warning"></i> Stock Watchlist
        </h5>
        <div class="card-tools">
          <a href="<?= e(admin_url('inventory.php?filter=low')) ?>" class="btn btn-xs btn-outline-secondary btn-sm">
            Inventory
          </a>
        </div>
      </div>
      <div class="dash-upper-body" style="overflow-y: auto;">
        <?php if (!empty($lowStock)): ?>
          <div class="d-flex flex-column gap-2">
            <?php foreach ($lowStock as $ls): ?>
              <div class="p-2 rounded border bg-body-tertiary d-flex align-items-center gap-2">
                <img src="<?= e(product_image_url($ls['image'], 'thumbnail', (int)$ls['id'])) ?>"
                     class="rounded border" width="34" height="34" style="object-fit: cover;" alt="">
                <div class="flex-grow-1 min-w-0">
                  <a href="<?= e(admin_url('product_form.php?id=' . (int)$ls['id'])) ?>" class="fw-semibold text-body text-truncate d-block small text-decoration-none">
                    <?= e($ls['name']) ?>
                  </a>
                  <span class="text-muted" style="font-size: 11px;">SKU: <?= e($ls['sku'] ?: '-') ?></span>
                </div>
                <div class="text-end">
                  <span class="badge bg-danger">
                    <?= (int)$ls['stock'] ?> left
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="h-100 d-flex flex-column align-items-center justify-content-center text-center py-4 text-muted">
            <i class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"></i>
            <span class="small fw-semibold text-body">Stock Levels Healthy</span>
            <span class="small text-muted mt-1">All products currently satisfy minimum inventory thresholds.</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- 5. Row 4: Recent Orders & Store Activity Stream -->
<div class="row g-3">
  <!-- Left: Recent Orders Table -->
  <div class="col-xl-8">
    <div class="card card-outline card-info dash-lower-card">
      <div class="card-header">
        <h5 class="card-title">
          <i class="bi bi-receipt text-info"></i> Recent Customer Orders
        </h5>
        <div class="card-tools">
          <a href="<?= e(admin_url('orders.php')) ?>" class="btn btn-outline-secondary btn-sm">
            View All
          </a>
        </div>
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
                        <span class="fw-medium text-body small text-truncate" style="max-width: 140px;"><?= e($cName) ?></span>
                      </div>
                    </td>
                    <td class="fw-bold text-body small">
                      <?= e(price((float)$ro['total'])) ?>
                    </td>
                    <td>
                      <span class="badge <?= e($statusBadges[$ro['status']] ?? 'bg-secondary text-white') ?>">
                        <?= e(ucfirst($ro['status'])) ?>
                      </span>
                    </td>
                    <td class="small text-muted">
                      <?= date('M j, g:i a', strtotime($ro['created_at'])) ?>
                    </td>
                    <td class="text-end pe-3">
                      <a href="<?= e(admin_url('order_view.php?id=' . (int)$ro['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="View Order Details">
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

  <!-- Right: Recent Store Activity -->
  <div class="col-xl-4">
    <div class="card card-outline card-secondary dash-lower-card">
      <div class="card-header">
        <h5 class="card-title">
          <i class="bi bi-clock-history text-secondary"></i> Store Activity Feed
        </h5>
        <div class="card-tools">
          <a href="<?= e(admin_url('activity.php')) ?>" class="btn btn-outline-secondary btn-sm">
            All Logs
          </a>
        </div>
      </div>
      <div class="dash-lower-body">
        <?php if (!empty($recentActivity)): ?>
          <div class="dash-activity-timeline p-2">
            <?php foreach ($recentActivity as $act): ?>
              <?php $p = dash_format_activity($act); ?>
              <div class="dash-activity-item d-flex align-items-start gap-2">
                <div class="dash-activity-icon-box" style="background-color: <?= $p['badgeBg'] ?>; color: <?= $p['badgeColor'] ?>;">
                  <i class="bi <?= $p['icon'] ?>"></i>
                </div>
                <div class="flex-grow-1" style="min-width: 0; overflow-wrap: anywhere; word-break: break-word;">
                  <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                    <div class="d-flex align-items-center gap-1" style="min-width: 0;">
                      <span class="fw-bold text-body text-truncate" style="font-size: 12px;"><?= e($p['user']) ?></span>
                      <span class="dash-activity-badge text-truncate" style="background-color: <?= $p['badgeBg'] ?>; color: <?= $p['badgeColor'] ?>;">
                        <?= e($p['actionLabel']) ?>
                      </span>
                    </div>
                    <span class="text-muted text-nowrap flex-shrink-0" style="font-size: 10.5px;">
                      <?= e(format_date($act['created_at'])) ?>
                    </span>
                  </div>
                  <div class="dash-activity-title">
                    <?= e($p['title']) ?>
                  </div>
                  <?php if (!empty($p['details'])): ?>
                    <div class="dash-activity-meta mt-1" title="<?= e($p['details']) ?>">
                      <i class="bi bi-sliders me-1 opacity-75"></i><?= e($p['details']) ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="h-100 d-flex flex-column align-items-center justify-content-center text-center py-5 text-muted">
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
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.08)',
                fill: true,
                tension: 0.3,
                borderWidth: 2,
                pointBackgroundColor: '#0d6efd',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 1.5,
                pointRadius: 3.5,
                pointHoverRadius: 5.5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    padding: 8,
                    cornerRadius: 4,
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
                    ticks: { font: { size: 11, family: 'Source Sans 3' } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0, 0, 0, 0.06)' },
                    ticks: {
                        font: { size: 11, family: 'Source Sans 3' },
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
            btnRev.classList.add('active', 'btn-primary');
            btnRev.classList.remove('btn-outline-primary');
            btnOrders.classList.remove('active', 'btn-primary');
            btnOrders.classList.add('btn-outline-primary');
            chart.data.datasets[0].label = 'Revenue ($)';
            chart.data.datasets[0].data = revData;
            chart.data.datasets[0].borderColor = '#0d6efd';
            chart.data.datasets[0].backgroundColor = 'rgba(13, 110, 253, 0.08)';
            chart.data.datasets[0].pointBackgroundColor = '#0d6efd';
            chart.update();
        });

        btnOrders.addEventListener('click', function() {
            currentMode = 'orders';
            btnOrders.classList.add('active', 'btn-primary');
            btnOrders.classList.remove('btn-outline-primary');
            btnRev.classList.remove('active', 'btn-primary');
            btnRev.classList.add('btn-outline-primary');
            chart.data.datasets[0].label = 'Orders (#)';
            chart.data.datasets[0].data = ordersData;
            chart.data.datasets[0].borderColor = '#198754';
            chart.data.datasets[0].backgroundColor = 'rgba(25, 135, 84, 0.08)';
            chart.data.datasets[0].pointBackgroundColor = '#198754';
            chart.update();
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
