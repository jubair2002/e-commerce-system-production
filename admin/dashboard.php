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
/* ==========================================================
   Dashboard Horizon UI Geometric Styling & Alignment
   ========================================================== */

/* Welcome Banner */
.dash-welcome-banner {
  background: var(--horizon-brand-gradient);
  border-radius: 20px;
  padding: 1.75rem 2rem;
  color: #ffffff;
  position: relative;
  overflow: hidden;
  box-shadow: 0px 18px 40px rgba(67, 24, 255, 0.25);
  margin-bottom: 1.75rem;
}
.dash-welcome-banner::after {
  content: '';
  position: absolute;
  top: -40px;
  right: -40px;
  width: 240px;
  height: 240px;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.22) 0%, rgba(255, 255, 255, 0) 70%);
  pointer-events: none;
}
.dash-live-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.18);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  border: 1px solid rgba(255, 255, 255, 0.25);
  padding: 4px 12px;
  border-radius: 30px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #ffffff;
}
.dash-live-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #05CD99;
  box-shadow: 0 0 0 3px rgba(5, 205, 153, 0.45);
}

/* Card Foundation */
.dash-card {
  background: var(--horizon-card-bg);
  border: none;
  border-radius: 20px;
  box-shadow: var(--horizon-shadow-card);
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  overflow: hidden;
}
[data-bs-theme="dark"] .dash-card {
  background: #111C44;
  border: 1px solid rgba(255, 255, 255, 0.06);
}
.dash-card:hover {
  transform: translateY(-2px);
  box-shadow: 0px 22px 45px rgba(112, 144, 176, 0.18);
}
[data-bs-theme="dark"] .dash-card:hover {
  box-shadow: 0px 10px 25px rgba(0, 0, 0, 0.35);
}

/* Primary KPI Cards (Horizon UI Stat Cards) */
.dash-kpi-card {
  height: 154px;
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.dash-kpi-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.dash-kpi-title {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--horizon-text-muted);
  margin-bottom: 4px;
}
.dash-kpi-value {
  font-size: 1.7rem;
  font-weight: 800;
  color: var(--horizon-text-title);
  line-height: 1.15;
  margin: 0;
}
.dash-kpi-icon {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.35rem;
  flex-shrink: 0;
}
.dash-kpi-footer {
  padding-top: 0.65rem;
  border-top: 1px solid var(--horizon-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  line-height: 1.3;
}
[data-bs-theme="dark"] .dash-kpi-footer {
  border-top-color: rgba(255, 255, 255, 0.06);
}

/* Secondary Compact KPI Cards */
.dash-sub-card {
  height: 88px;
  padding: 1rem 1.25rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.dash-sub-icon {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
  flex-shrink: 0;
}

/* Matched Card Headers */
.dash-card-header {
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--horizon-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: transparent;
}
[data-bs-theme="dark"] .dash-card-header {
  border-bottom-color: rgba(255, 255, 255, 0.06);
}
.dash-card-title {
  font-size: 1rem;
  font-weight: 700;
  color: var(--horizon-text-title);
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
  padding: 1.5rem;
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
  overflow-x: hidden !important;
  position: relative;
}

/* Custom sleek scrollbar for dashboard scrollable bodies */
.dash-lower-body::-webkit-scrollbar {
  width: 5px;
}
.dash-lower-body::-webkit-scrollbar-track {
  background: transparent;
}
.dash-lower-body::-webkit-scrollbar-thumb {
  background: rgba(163, 174, 208, 0.3);
  border-radius: 9999px;
}
.dash-lower-body::-webkit-scrollbar-thumb:hover {
  background: rgba(163, 174, 208, 0.5);
}

/* Store Activity Feed Styling */
.dash-activity-timeline {
  display: flex;
  flex-direction: column;
}
.dash-activity-item {
  border: 1px solid var(--horizon-border);
  background: var(--horizon-card-bg);
  border-radius: 14px;
  padding: 0.75rem 0.85rem;
  transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
  margin-bottom: 0.5rem;
}
[data-bs-theme="dark"] .dash-activity-item {
  background: #1B254B;
  border-color: rgba(255, 255, 255, 0.06);
}
.dash-activity-item:last-child {
  margin-bottom: 0;
}
.dash-activity-item:hover {
  background: var(--horizon-brand-light);
  border-color: var(--horizon-brand);
  transform: translateX(2px);
  box-shadow: 0 4px 12px rgba(67, 24, 255, 0.08);
}
.dash-activity-icon-box {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  flex-shrink: 0;
}
.dash-activity-badge {
  font-size: 9.5px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 20px;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  display: inline-block;
  line-height: 1.2;
}
.dash-activity-title {
  color: var(--horizon-text-body);
  font-weight: 600;
  font-size: 12.5px;
  line-height: 1.4;
  word-break: break-word;
  overflow-wrap: anywhere;
}
[data-bs-theme="dark"] .dash-activity-title {
  color: #FFFFFF;
}
.dash-activity-meta {
  background: var(--horizon-bg);
  border: 1px solid var(--horizon-border);
  border-radius: 6px;
  padding: 3px 8px;
  color: var(--horizon-text-muted);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 10.5px !important;
  word-break: break-word;
  overflow-wrap: anywhere;
}
[data-bs-theme="dark"] .dash-activity-meta {
  background: #111C44;
  border-color: rgba(255, 255, 255, 0.08);
}

/* Table and Timeline Elements */
.dash-avatar-circle {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--horizon-brand-gradient);
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(67, 24, 255, 0.25);
}
.status-pill {
  font-size: 11px;
  font-weight: 700;
  padding: 4px 12px;
  border-radius: 30px;
  letter-spacing: 0.02em;
  display: inline-block;
}
.chart-toggle-pill {
  font-size: 11.5px;
  font-weight: 700;
  padding: 5px 14px;
  border-radius: 30px;
  border: 1px solid var(--horizon-border);
  background: var(--horizon-bg);
  color: var(--horizon-text-muted);
  cursor: pointer;
  transition: all 0.15s ease;
}
[data-bs-theme="dark"] .chart-toggle-pill {
  background: #1B254B;
  border-color: rgba(255, 255, 255, 0.08);
}
.chart-toggle-pill.active {
  background: var(--horizon-brand);
  color: #ffffff;
  border-color: var(--horizon-brand);
  box-shadow: 0 4px 12px rgba(67, 24, 255, 0.35);
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
    <h2 class="h3 fw-bold mb-1 text-white">Welcome back, <?= e(current_user()['name'] ?? ($_SESSION['user_name'] ?? 'Admin')) ?> 👋</h2>
    <p class="text-white-50 small mb-0">Here is your executive store performance overview and operational health.</p>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <a href="<?= e(admin_url('product_form.php')) ?>" class="btn btn-light px-3 shadow-sm rounded-pill fw-bold" style="color: #4318FF;">
      <i class="bi bi-plus-lg me-1"></i> Add Product
    </a>
    <a href="<?= e(admin_url('orders.php')) ?>" class="btn btn-light px-3 shadow-sm rounded-pill text-dark fw-bold">
      <i class="bi bi-receipt me-1"></i> Orders
    </a>
    <a href="<?= e(url()) ?>" target="_blank" class="btn btn-outline-light px-3 rounded-pill fw-bold">
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
        <div class="dash-kpi-icon" style="background: rgba(5, 205, 153, 0.12); color: #05CD99;">
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
          <div class="dash-kpi-icon" style="background: rgba(67, 24, 255, 0.12); color: #4318FF;">
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
          <div class="dash-kpi-icon" style="background: rgba(57, 101, 255, 0.12); color: #3965FF;">
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
          <div class="dash-kpi-icon" style="background: rgba(117, 81, 255, 0.12); color: #7551FF;">
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
        <a href="<?= e(admin_url('activity.php')) ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 fw-semibold" style="font-size: 11px;">
          All Logs
        </a>
      </div>
      <div class="dash-lower-body">
        <?php if (!empty($recentActivity)): ?>
          <div class="dash-activity-timeline p-3">
            <?php foreach ($recentActivity as $act): ?>
              <?php $p = dash_format_activity($act); ?>
              <div class="dash-activity-item d-flex align-items-start gap-2.5">
                <div class="dash-activity-icon-box" style="background-color: <?= $p['badgeBg'] ?>; color: <?= $p['badgeColor'] ?>;">
                  <i class="bi <?= $p['icon'] ?>"></i>
                </div>
                <div class="flex-grow-1" style="min-width: 0; overflow-wrap: anywhere; word-break: break-word;">
                  <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                    <div class="d-flex align-items-center gap-1.5" style="min-width: 0;">
                      <span class="fw-bold text-dark text-truncate" style="font-size: 12.5px;"><?= e($p['user']) ?></span>
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
                    <div class="dash-activity-meta mt-1.5" title="<?= e($p['details']) ?>">
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
                borderColor: '#4318FF',
                backgroundColor: 'rgba(67, 24, 255, 0.08)',
                fill: true,
                tension: 0.35,
                borderWidth: 2.5,
                pointBackgroundColor: '#4318FF',
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
                    backgroundColor: '#111C44',
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
                    ticks: { font: { size: 11, family: 'Plus Jakarta Sans' } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(224, 229, 242, 0.6)' },
                    ticks: {
                        font: { size: 11, family: 'Plus Jakarta Sans' },
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
            chart.data.datasets[0].borderColor = '#4318FF';
            chart.data.datasets[0].backgroundColor = 'rgba(67, 24, 255, 0.08)';
            chart.data.datasets[0].pointBackgroundColor = '#4318FF';
            chart.update();
        });

        btnOrders.addEventListener('click', function() {
            currentMode = 'orders';
            btnOrders.classList.add('active');
            btnRev.classList.remove('active');
            chart.data.datasets[0].label = 'Orders (#)';
            chart.data.datasets[0].data = ordersData;
            chart.data.datasets[0].borderColor = '#05CD99';
            chart.data.datasets[0].backgroundColor = 'rgba(5, 205, 153, 0.08)';
            chart.data.datasets[0].pointBackgroundColor = '#05CD99';
            chart.update();
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
