<?php
/**
 * Admin Analytics Dashboard (Prompt 09).
 * Products, sales, funnel, customers, and traffic intelligence.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

if (!has_role('admin', 'editor')) {
    flash('danger', 'You do not have permission to access Analytics.');
    redirect(admin_url('dashboard.php'));
}

$pageTitle = 'Analytics Dashboard';
$pdo = db();

// Handle on-demand Roll-up refresh
if (isset($_POST['action']) && $_POST['action'] === 'refresh_rollup') {
    require_csrf();
    $res = analytics_rollup();
    flash('success', "Roll-ups refreshed! Updated {$res['site_stats_rows']} site records, {$res['product_stats_rows']} product records.");
    redirect(admin_url('analytics.php?' . http_build_query($_GET)));
}

// Date Range Parameters
$rangePreset = trim((string)($_GET['range'] ?? '30d'));
$compare     = !empty($_GET['compare']);
$catId       = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$groupBy     = in_array($_GET['group_by'] ?? '', ['day', 'week', 'month'], true) ? $_GET['group_by'] : 'day';
$searchQ     = trim((string)($_GET['q'] ?? ''));
$page        = max(1, (int)($_GET['p'] ?? 1));
$limit       = max(5, min(100, (int)($_GET['limit'] ?? 15)));

// Calculate dates based on preset
$today = date('Y-m-d');
switch ($rangePreset) {
    case 'today':
        $from = $today;
        $to   = $today;
        break;
    case '7d':
        $from = date('Y-m-d', strtotime('-6 days'));
        $to   = $today;
        break;
    case '90d':
        $from = date('Y-m-d', strtotime('-89 days'));
        $to   = $today;
        break;
    case 'month':
        $from = date('Y-m-01');
        $to   = $today;
        break;
    case 'custom':
        $from = trim((string)($_GET['from'] ?? date('Y-m-d', strtotime('-29 days'))));
        $to   = trim((string)($_GET['to'] ?? $today));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-29 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $today;
        break;
    case '30d':
    default:
        $rangePreset = '30d';
        $from = date('Y-m-d', strtotime('-29 days'));
        $to   = $today;
        break;
}

if ($from > $to) {
    $tmp = $from; $from = $to; $to = $tmp;
}

$filters = [
    'category_id' => $catId,
    'group_by'    => $groupBy,
    'search'      => $searchQ,
    'page'        => $page,
    'limit'       => $limit,
];

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportFilters = $filters;
    $exportFilters['limit'] = 1000;
    $exportFilters['page']  = 1;
    $exportData = analytics_query('products_table', $from, $to, $exportFilters);

    $filename = "analytics_products_{$from}_to_{$to}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel compatibility
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, [
        'Product ID', 'Product Name', 'SKU', 'Category', 'Stock Left',
        'Views', 'Unique Visitors', 'Add to Cart', 'View->Cart %', 'Cart->Buy %',
        'Orders', 'Units Sold', 'Revenue (BDT)', 'Avg Price', 'Days of Stock Left', 'Status'
    ]);

    foreach ($exportData['items'] as $item) {
        fputcsv($out, [
            $item['id'],
            $item['name'],
            $item['sku'],
            $item['category_name'] ?: 'Uncategorized',
            $item['stock'],
            $item['views'],
            $item['unique_visitors'],
            $item['add_to_cart'],
            $item['view_to_cart_pct'] . '%',
            $item['cart_to_buy_pct'] . '%',
            $item['orders_count'],
            $item['units_sold'],
            number_format($item['revenue'], 2, '.', ''),
            number_format($item['avg_price'], 2, '.', ''),
            $item['days_stock'] >= 999 ? 'Abundant' : $item['days_stock'],
            $item['badge']['label'] ?? 'Active',
        ]);
    }
    fclose($out);
    exit;
}

// Fetch analytics metrics
$kpis         = analytics_query('kpis', $from, $to, $filters);
$trend        = analytics_query('trend', $from, $to, $filters);
$funnel       = analytics_query('funnel', $from, $to, $filters);
$topProducts  = analytics_query('top_products', $from, $to, $filters);
$categories   = analytics_query('categories', $from, $to, $filters);
$ordersStatus = analytics_query('orders_status', $from, $to);
$heatmap      = analytics_query('heatmap', $from, $to);
$traffic      = analytics_query('traffic', $from, $to);
$device       = analytics_query('device', $from, $to);
$insights     = analytics_query('insights', $from, $to, $filters);
$productsTab  = analytics_query('products_table', $from, $to, $filters);

// Previous period comparison if enabled
$deltas = [];
if ($compare) {
    $daysDiff = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1);
    $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . " -{$daysDiff} days +1 day"));
    $prevKpis = analytics_query('kpis', $prevFrom, $prevTo, $filters);

    foreach ($kpis as $key => $val) {
        $prevVal = $prevKpis[$key] ?? 0;
        if ($prevVal == 0) {
            $pct = $val > 0 ? 100.0 : 0.0;
        } else {
            $pct = round((($val - $prevVal) / $prevVal) * 100, 1);
        }
        $deltas[$key] = [
            'pct'   => $pct,
            'is_up' => ($val >= $prevVal),
        ];
    }
}

// Categories list for filter dropdown
$allCategories = [];
try {
    $allCategories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) {}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-0">

  <!-- Top Filters & Control Header -->
  <div class="card mb-4 border-0 shadow-sm">
    <div class="card-body p-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        
        <!-- Date Presets & Custom Pickers -->
        <form method="get" class="d-flex flex-wrap align-items-center gap-2 m-0" id="filterForm">
          <input type="hidden" name="range" id="rangeInput" value="<?= e($rangePreset) ?>">

          <!-- Preset buttons -->
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary <?= $rangePreset === 'today' ? 'active' : '' ?>" onclick="setRange('today')">Today</button>
            <button type="button" class="btn btn-outline-secondary <?= $rangePreset === '7d' ? 'active' : '' ?>" onclick="setRange('7d')">7 d</button>
            <button type="button" class="btn btn-outline-secondary <?= $rangePreset === '30d' ? 'active' : '' ?>" onclick="setRange('30d')">30 d</button>
            <button type="button" class="btn btn-outline-secondary <?= $rangePreset === '90d' ? 'active' : '' ?>" onclick="setRange('90d')">90 d</button>
            <button type="button" class="btn btn-outline-secondary <?= $rangePreset === 'month' ? 'active' : '' ?>" onclick="setRange('month')">This Month</button>
            <button type="button" class="btn btn-outline-secondary <?= $rangePreset === 'custom' ? 'active' : '' ?>" onclick="setRange('custom')">Custom</button>
          </div>

          <!-- Custom range dates -->
          <div class="d-flex align-items-center gap-1 <?= $rangePreset !== 'custom' ? 'd-none' : '' ?>" id="customDateInputs">
            <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" style="width: 135px;">
            <span class="text-muted small">&ndash;</span>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" style="width: 135px;">
          </div>

          <!-- Category filter -->
          <select name="category_id" class="form-select form-select-sm" style="width: 160px;" onchange="this.form.submit()">
            <option value="0">All Categories</option>
            <?php foreach ($allCategories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <!-- Compare to previous period checkbox -->
          <div class="form-check form-switch ms-1 mb-0">
            <input class="form-check-input" type="checkbox" name="compare" value="1" id="compareCheck" <?= $compare ? 'checked' : '' ?> onchange="this.form.submit()">
            <label class="form-check-label small" for="compareCheck">Compare</label>
          </div>

          <button type="submit" class="btn btn-primary btn-sm px-3">Apply</button>
        </form>

        <!-- Action tools: CSV Export & Refresh Roll-up -->
        <div class="d-flex align-items-center gap-2">
          <a href="<?= e(admin_url('analytics.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))) ?>" class="btn btn-outline-dark btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-download"></i><span>Export CSV</span>
          </a>

          <form method="post" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="refresh_rollup">
            <button type="submit" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1" title="Recompute daily roll-up aggregations">
              <i class="bi bi-arrow-repeat"></i><span>Refresh Roll-ups</span>
            </button>
          </form>
        </div>

      </div>
    </div>
  </div>

  <!-- Insights Panel -->
  <?php if (!empty($insights)): ?>
    <div class="row g-3 mb-4">
      <?php foreach ($insights as $ins): ?>
        <div class="col-md-6 col-lg-3">
          <div class="card h-100 border-start border-4 border-<?= e($ins['type']) ?> shadow-sm">
            <div class="card-body p-3">
              <div class="d-flex align-items-center gap-2 mb-1 text-<?= e($ins['type']) ?>">
                <i class="bi <?= e($ins['icon']) ?>"></i>
                <h6 class="card-subtitle fw-bold mb-0" style="font-size: 0.85rem;"><?= e($ins['title']) ?></h6>
              </div>
              <p class="card-text text-muted small mb-2" style="font-size: 0.8rem;">
                <?= $ins['message'] ?>
              </p>
              <?php if (!empty($ins['link'])): ?>
                <a href="<?= e($ins['link']) ?>" class="btn btn-link btn-sm p-0 text-decoration-none" style="font-size: 0.78rem;">
                  View Details &rarr;
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- A. KPI Metrics Grid -->
  <div class="row g-3 mb-4">
    <?php
    $kpiCards = [
        ['label' => 'Total Revenue',      'val' => price($kpis['revenue']),          'key' => 'revenue',          'icon' => 'bi-cash-stack',       'color' => 'success'],
        ['label' => 'Orders',             'val' => number_format($kpis['orders']),    'key' => 'orders',           'icon' => 'bi-receipt',          'color' => 'primary'],
        ['label' => 'Units Sold',         'val' => number_format($kpis['units_sold']),'key' => 'units_sold',      'icon' => 'bi-box-seam',         'color' => 'info'],
        ['label' => 'Average Order Value','val' => price($kpis['aov']),              'key' => 'aov',              'icon' => 'bi-calculator',       'color' => 'secondary'],
        ['label' => 'Unique Visitors',    'val' => number_format($kpis['visitors']),  'key' => 'visitors',         'icon' => 'bi-people',           'color' => 'dark'],
        ['label' => 'Product Views',      'val' => number_format($kpis['product_views']), 'key' => 'product_views', 'icon' => 'bi-eye',            'color' => 'primary'],
        ['label' => 'Add-to-Cart Rate',   'val' => $kpis['add_to_cart_rate'] . '%',   'key' => 'add_to_cart_rate', 'icon' => 'bi-cart-plus',       'color' => 'warning'],
        ['label' => 'Conversion Rate',    'val' => $kpis['conversion_rate'] . '%',    'key' => 'conversion_rate',  'icon' => 'bi-graph-up',        'color' => 'success'],
        ['label' => 'Cancel Rate',        'val' => $kpis['cancel_rate'] . '%',        'key' => 'cancel_rate',      'icon' => 'bi-x-circle',         'color' => 'danger'],
        ['label' => 'COD Pending Cash',   'val' => price($kpis['cod_pending']),       'key' => 'cod_pending',      'icon' => 'bi-wallet2',          'color' => 'warning'],
    ];

    foreach ($kpiCards as $card):
      $delta = $deltas[$card['key']] ?? null;
    ?>
      <div class="col-sm-6 col-md-4 col-xl" style="min-width: 180px;">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body p-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="text-muted small fw-semibold"><?= e($card['label']) ?></span>
              <div class="badge bg-<?= e($card['color']) ?> bg-opacity-10 text-<?= e($card['color']) ?> p-2 rounded-circle">
                <i class="bi <?= e($card['icon']) ?>"></i>
              </div>
            </div>
            <div class="fs-4 fw-bold text-dark mb-1"><?= e($card['val']) ?></div>
            <?php if ($delta !== null): ?>
              <div class="small <?= $delta['is_up'] ? 'text-success' : 'text-danger' ?>">
                <i class="bi bi-arrow-<?= $delta['is_up'] ? 'up' : 'down' ?>-short"></i>
                <?= abs($delta['pct']) ?>% vs prev
              </div>
            <?php else: ?>
              <div class="small text-muted" style="font-size: 0.75rem;">Selected period</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- B. Charts Section 1: Revenue & Funnel -->
  <div class="row g-4 mb-4">
    <!-- Revenue & Orders Trend -->
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3 d-flex align-items-center justify-content-between">
          <h6 class="card-title fw-bold mb-0">Revenue &amp; Orders Trend</h6>
          <div class="btn-group btn-group-sm">
            <a href="<?= e(admin_url('analytics.php?' . http_build_query(array_merge($_GET, ['group_by' => 'day'])))) ?>" class="btn btn-outline-secondary <?= $groupBy === 'day' ? 'active' : '' ?>">Day</a>
            <a href="<?= e(admin_url('analytics.php?' . http_build_query(array_merge($_GET, ['group_by' => 'week'])))) ?>" class="btn btn-outline-secondary <?= $groupBy === 'week' ? 'active' : '' ?>">Week</a>
            <a href="<?= e(admin_url('analytics.php?' . http_build_query(array_merge($_GET, ['group_by' => 'month'])))) ?>" class="btn btn-outline-secondary <?= $groupBy === 'month' ? 'active' : '' ?>">Month</a>
          </div>
        </div>
        <div class="card-body p-3">
          <div style="height: 300px;">
            <canvas id="trendChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Conversion Funnel -->
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3">
          <h6 class="card-title fw-bold mb-0">Conversion Funnel</h6>
        </div>
        <div class="card-body p-3 d-flex flex-column justify-content-around">
          <?php 
          $funnelColors = ['primary', 'info', 'warning', 'success'];
          $maxCount = max(1, (int)($funnel[0]['count'] ?? 1));
          foreach ($funnel as $idx => $stg):
            $pctWidth = max(5, round(($stg['count'] / $maxCount) * 100));
            $c = $funnelColors[$idx % count($funnelColors)];
          ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between small fw-semibold mb-1">
                <span><?= e($stg['name']) ?></span>
                <span class="text-dark fw-bold"><?= number_format($stg['count']) ?></span>
              </div>
              <div class="progress" style="height: 14px;">
                <div class="progress-bar bg-<?= $c ?>" role="progressbar" style="width: <?= $pctWidth ?>%;"></div>
              </div>
              <?php if ($idx > 0 && $stg['drop_rate'] > 0): ?>
                <div class="text-danger small mt-1" style="font-size: 0.72rem;">
                  &darr; <?= $stg['drop_rate'] ?>% drop-off from previous step
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- B. Charts Section 2: Top Products & Category Share -->
  <div class="row g-4 mb-4">
    <!-- Top Products -->
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3 d-flex align-items-center justify-content-between">
          <h6 class="card-title fw-bold mb-0">Top 10 Products by Revenue</h6>
          <span class="badge text-bg-light border text-muted">Excludes Cancelled</span>
        </div>
        <div class="card-body p-3">
          <div style="height: 280px;">
            <canvas id="topProductsChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Category Share & Device Split -->
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3">
          <h6 class="card-title fw-bold mb-0">Category Share</h6>
        </div>
        <div class="card-body p-3">
          <div style="height: 220px;">
            <canvas id="categoriesChart"></canvas>
          </div>
          <!-- Device Breakdown -->
          <div class="d-flex justify-content-around text-center mt-3 pt-3 border-top small">
            <div>
              <div class="text-muted"><i class="bi bi-laptop me-1"></i> Desktop</div>
              <div class="fw-bold fs-6"><?= number_format($device['desktop'] ?? 0) ?></div>
            </div>
            <div>
              <div class="text-muted"><i class="bi bi-phone me-1"></i> Mobile</div>
              <div class="fw-bold fs-6"><?= number_format($device['mobile'] ?? 0) ?></div>
            </div>
            <div>
              <div class="text-muted"><i class="bi bi-tablet me-1"></i> Tablet</div>
              <div class="fw-bold fs-6"><?= number_format($device['tablet'] ?? 0) ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- B. Charts Section 3: Orders Heatmap & Traffic Sources -->
  <div class="row g-4 mb-4">
    <!-- Orders by Hour of Day Heatmap -->
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3">
          <h6 class="card-title fw-bold mb-0">Ordering Heatmap (Day of Week &times; Hour)</h6>
          <small class="text-muted">Highlights peak buying hours to optimize flash sales and campaigns.</small>
        </div>
        <div class="card-body p-3">
          <div class="table-responsive">
            <table class="table table-sm table-bordered text-center mb-0" style="font-size: 0.72rem;">
              <thead class="table-light">
                <tr>
                  <th style="width: 50px;">Day</th>
                  <?php for ($h = 0; $h < 24; $h++): ?>
                    <th style="width: 3.5%;"><?= sprintf('%02d', $h) ?></th>
                  <?php endfor; ?>
                </tr>
              </thead>
              <tbody>
                <?php
                $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                $maxCell = 1;
                for ($d = 0; $d < 7; $d++) {
                    for ($h = 0; $h < 24; $h++) {
                        if (($heatmap[$d][$h] ?? 0) > $maxCell) {
                            $maxCell = $heatmap[$d][$h];
                        }
                    }
                }
                for ($d = 0; $d < 7; $d++):
                ?>
                  <tr>
                    <td class="fw-bold bg-light"><?= $dayNames[$d] ?></td>
                    <?php for ($h = 0; $h < 24; $h++):
                      $cnt = $heatmap[$d][$h] ?? 0;
                      $intensity = $cnt > 0 ? min(1, $cnt / $maxCell) : 0;
                      $bgStyle = $intensity > 0 ? "background-color: rgba(13, 110, 253, " . max(0.15, $intensity) . "); color: " . ($intensity > 0.5 ? '#fff' : '#000') . ";" : "";
                    ?>
                      <td style="<?= $bgStyle ?>" title="<?= $dayNames[$d] ?> <?= sprintf('%02d:00', $h) ?>: <?= $cnt ?> orders">
                        <?= $cnt > 0 ? $cnt : '' ?>
                      </td>
                    <?php endfor; ?>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Traffic Sources -->
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-transparent border-0 pt-3 px-3">
          <h6 class="card-title fw-bold mb-0">Traffic Sources</h6>
        </div>
        <div class="card-body p-3">
          <?php if (empty($traffic)): ?>
            <p class="text-muted small text-center my-4">No external referrer data recorded yet.</p>
          <?php else: ?>
            <div class="list-group list-group-flush small">
              <?php foreach ($traffic as $tr): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                  <span class="text-truncate" style="max-width: 180px;" title="<?= e($tr['source']) ?>">
                    <i class="bi bi-globe2 me-1 text-muted"></i> <?= e($tr['source']) ?>
                  </span>
                  <span class="badge bg-light text-dark border">
                    <?= number_format($tr['visitors']) ?> visitors
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- C. Product Analytics Table -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-0 pt-3 px-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
      <h6 class="card-title fw-bold mb-0">Product Performance</h6>

      <!-- Search inside table -->
      <form method="get" class="d-flex align-items-center gap-2 m-0">
        <input type="hidden" name="range" value="<?= e($rangePreset) ?>">
        <input type="hidden" name="from" value="<?= e($from) ?>">
        <input type="hidden" name="to" value="<?= e($to) ?>">
        <input type="hidden" name="category_id" value="<?= e($catId) ?>">
        <div class="input-group input-group-sm" style="width: 220px;">
          <input type="text" name="q" class="form-control" placeholder="Search product or SKU..." value="<?= e($searchQ) ?>">
          <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
        </div>
      </form>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 0.84rem;">
          <thead class="table-light">
            <tr>
              <th class="ps-3">Product</th>
              <th>Category</th>
              <th class="text-end">Views</th>
              <th class="text-end">Add to Cart</th>
              <th class="text-end">View&rarr;Cart</th>
              <th class="text-end">Orders</th>
              <th class="text-end">Units</th>
              <th class="text-end">Revenue</th>
              <th class="text-end">Stock</th>
              <th class="text-end">Stock Days</th>
              <th>Status</th>
              <th class="text-center pe-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($productsTab['items'])): ?>
              <tr>
                <td colspan="12" class="text-center py-4 text-muted">
                  No products matched the selected filters.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($productsTab['items'] as $item): ?>
                <tr>
                  <td class="ps-3">
                    <div class="d-flex align-items-center gap-2">
                      <?php 
                      $pImg = product_image_url($item['image'] ?? null);
                      $hasImg = !empty($item['image']) && !str_starts_with($pImg, 'data:image/svg+xml');
                      ?>
                      <?php if ($hasImg): ?>
                        <img src="<?= e($pImg) ?>" alt="" style="width: 36px; height: 36px; object-fit: cover;" class="rounded border">
                      <?php else: ?>
                        <div class="rounded border bg-light d-flex align-items-center justify-content-center text-muted" style="width: 36px; height: 36px;">
                          <i class="bi bi-box"></i>
                        </div>
                      <?php endif; ?>
                      <div>
                        <a href="<?= e(admin_url('analytics_product.php?id=' . $item['id'])) ?>" class="fw-semibold text-dark text-decoration-none">
                          <?= e($item['name']) ?>
                        </a>
                        <div class="text-muted" style="font-size: 0.72rem;">SKU: <?= e($item['sku'] ?: '&ndash;') ?></div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge bg-light text-muted border"><?= e($item['category_name'] ?: 'Uncategorized') ?></span></td>
                  <td class="text-end"><?= number_format($item['views']) ?></td>
                  <td class="text-end"><?= number_format($item['add_to_cart']) ?></td>
                  <td class="text-end fw-semibold <?= $item['view_to_cart_pct'] >= 10 ? 'text-success' : 'text-muted' ?>">
                    <?= $item['view_to_cart_pct'] ?>%
                  </td>
                  <td class="text-end"><?= number_format($item['orders_count']) ?></td>
                  <td class="text-end"><?= number_format($item['units_sold']) ?></td>
                  <td class="text-end fw-bold text-dark"><?= price($item['revenue']) ?></td>
                  <td class="text-end <?= $item['stock'] <= 5 ? 'text-danger fw-bold' : '' ?>">
                    <?= number_format($item['stock']) ?>
                  </td>
                  <td class="text-end">
                    <?php if ($item['days_stock'] >= 999): ?>
                      <span class="text-muted" title="Stock velocity low">&infin;</span>
                    <?php elseif ($item['days_stock'] <= 5): ?>
                      <span class="badge bg-danger"><?= $item['days_stock'] ?> d</span>
                    <?php else: ?>
                      <span class="text-dark"><?= $item['days_stock'] ?> d</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= e($item['badge']['class']) ?>" style="font-size: 0.72rem;">
                      <?= e($item['badge']['label']) ?>
                    </span>
                  </td>
                  <td class="text-center pe-3">
                    <a href="<?= e(admin_url('analytics_product.php?id=' . $item['id'])) ?>" class="btn btn-outline-primary btn-sm py-0 px-2" title="Product Analytics Deep Dive">
                      Deep Dive &rarr;
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($productsTab['pages'] > 1): ?>
        <div class="p-3 d-flex flex-wrap justify-content-between align-items-center gap-2 border-top">
          <div class="small text-muted d-flex align-items-center gap-2">
            <span>Showing <?= count($productsTab['items']) ?> of <?= number_format($productsTab['total']) ?> products</span>
            <span class="text-muted">·</span>
            <label class="d-flex align-items-center gap-1 mb-0">
              <span class="text-muted">Per page:</span>
              <select class="form-select form-select-sm" style="width: auto; display: inline-block;" onchange="const url = new URL(window.location.href); url.searchParams.set('limit', this.value); url.searchParams.set('p', 1); window.location.href = url.toString();">
                <option value="15" <?= $limit === 15 ? 'selected' : '' ?>>15</option>
                <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
              </select>
            </label>
          </div>
          <?= render_admin_pagination($page, $productsTab['pages'], $_GET, 15, 'p') ?>
        </div>
      <?php endif; ?>

    </div>
  </div>

</div>

<!-- Chart.js initialization script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  function setRange(range) {
    document.getElementById('rangeInput').value = range;
    if (range === 'custom') {
      document.getElementById('customDateInputs').classList.remove('d-none');
    } else {
      document.getElementById('filterForm').submit();
    }
  }
  window.setRange = setRange;

  // 1. Revenue & Orders Trend Chart
  const trendCtx = document.getElementById('trendChart');
  if (trendCtx) {
    new Chart(trendCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($trend['labels']) ?>,
        datasets: [
          {
            label: 'Revenue (BDT)',
            type: 'line',
            data: <?= json_encode($trend['revenue']) ?>,
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
            fill: true,
            tension: 0.3,
            yAxisID: 'yRev'
          },
          {
            label: 'Orders',
            type: 'bar',
            data: <?= json_encode($trend['orders']) ?>,
            backgroundColor: 'rgba(108, 117, 125, 0.5)',
            borderRadius: 4,
            yAxisID: 'yOrd'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          yRev: {
            type: 'linear',
            position: 'left',
            ticks: {
              callback: function(v) { return '৳' + v.toLocaleString(); }
            }
          },
          yOrd: {
            type: 'linear',
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: { precision: 0 }
          }
        }
      }
    });
  }

  // 2. Top Products Horizontal Bar Chart
  const topCtx = document.getElementById('topProductsChart');
  if (topCtx) {
    const topLabels = <?= json_encode(array_column($topProducts['by_revenue'], 'name')) ?>;
    const topRevs   = <?= json_encode(array_column($topProducts['by_revenue'], 'revenue')) ?>;
    new Chart(topCtx, {
      type: 'bar',
      data: {
        labels: topLabels,
        datasets: [{
          label: 'Revenue (BDT)',
          data: topRevs,
          backgroundColor: '#198754',
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: {
            ticks: {
              callback: function(v) { return '৳' + v.toLocaleString(); }
            }
          }
        }
      }
    });
  }

  // 3. Category Share Donut Chart
  const catCtx = document.getElementById('categoriesChart');
  if (catCtx) {
    const catLabels = <?= json_encode(array_column($categories, 'category_name')) ?>;
    const catRevs   = <?= json_encode(array_column($categories, 'revenue')) ?>;
    new Chart(catCtx, {
      type: 'doughnut',
      data: {
        labels: catLabels,
        datasets: [{
          data: catRevs,
          backgroundColor: [
            '#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545',
            '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0'
          ]
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12 } }
        }
      }
    });
  }
});
</script>

<?php
include __DIR__ . '/includes/footer.php';
