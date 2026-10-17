<?php
/**
 * Shop / Catalog Listing (Prompt 04).
 * Desktop filter sidebar + mobile bottom-sheet drawer.
 * Live filtering by category, price, availability, on-sale, and variant options.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$pageTitle = 'All Products';
$siteName  = setting('site_name', APP_NAME);

// Query Filters
$search      = trim((string)($_GET['q'] ?? ''));
$catSlug     = trim((string)($_GET['category'] ?? ''));
$featured    = trim((string)($_GET['featured'] ?? ''));
$inStockOnly = trim((string)($_GET['in_stock'] ?? ''));
$onSaleOnly  = trim((string)($_GET['on_sale'] ?? ''));
$sort        = trim((string)($_GET['sort'] ?? setting('shop_default_sort', 'newest')));
$minPrice    = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$maxPrice    = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float)$_GET['max_price'] : null;

// Selected Option Values (e.g. Size, Color)
$selectedOptValues = [];
if (!empty($_GET['opt_val'])) {
    if (is_array($_GET['opt_val'])) {
        $selectedOptValues = array_filter(array_map('intval', $_GET['opt_val']));
    } else {
        $selectedOptValues = array_filter(array_map('intval', explode(',', (string)$_GET['opt_val'])));
    }
}

$perPage = max(6, (int)setting('shop_products_per_page', '12'));
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where  = ["p.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = '(p.name LIKE :q1 OR p.sku LIKE :q2 OR p.short_description LIKE :q3)';
    $params[':q1'] = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
}
if ($catSlug !== '') {
    $where[] = 'c.slug = :cs';
    $params[':cs'] = $catSlug;
}
if ($featured === '1') {
    $where[] = 'p.featured = 1';
}
if ($inStockOnly === '1') {
    $where[] = '(p.track_inventory = 0 OR p.allow_backorder = 1 OR COALESCE(vps.total_stock, p.stock) > 0)';
}
if ($onSaleOnly === '1') {
    $where[] = '(p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price)';
}
if ($minPrice !== null && $minPrice > 0) {
    $where[] = 'COALESCE(p.sale_price, p.price) >= :min_p';
    $params[':min_p'] = $minPrice;
}
if ($maxPrice !== null && $maxPrice > 0) {
    $where[] = 'COALESCE(p.sale_price, p.price) <= :max_p';
    $params[':max_p'] = $maxPrice;
}
if (!empty($selectedOptValues)) {
    $optIn = implode(',', $selectedOptValues);
    $where[] = "p.id IN (
        SELECT pv.product_id
        FROM product_variants pv
        JOIN product_variant_values pvv ON pvv.variant_id = pv.id
        WHERE pvv.option_value_id IN ($optIn) AND pv.status = 'active'
    )";
}

$orderSql = match ($sort) {
    'price_asc'    => 'COALESCE(p.sale_price, p.price) ASC',
    'price_desc'   => 'COALESCE(p.sale_price, p.price) DESC',
    'best_selling' => 'p.sold_count DESC, p.created_at DESC',
    default        => 'p.created_at DESC',
};

$whereSql = 'WHERE ' . implode(' AND ', $where);

$pdo = db();
$total = 0;
$pages = 1;
$products = [];

try {
    $countSql = "
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN v_product_stock vps ON vps.product_id = p.id
        $whereSql
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $selectSql = "
        SELECT p.*, c.name AS category_name, c.slug AS category_slug,
               COALESCE(vps.total_stock, p.stock) AS calculated_stock
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN v_product_stock vps ON vps.product_id = p.id
        $whereSql
        ORDER BY $orderSql
        LIMIT :lim OFFSET :off
    ";
    $stmt = $pdo->prepare($selectSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();
    if (!empty($products)) {
        products_attach_card_data($products);
    }
} catch (Throwable $e) {
    $products = [];
    $total = 0;
}

// Sidebar Data
$categoriesList = shop_active_categories();
$filterOptions  = storefront_filter_options();

// Active Filters List for Chips
$activeChips = [];
$baseQuery = $_GET;

if ($search !== '') {
    $qParams = $baseQuery; unset($qParams['q'], $qParams['page']);
    $activeChips[] = ['label' => 'Search: "' . $search . '"', 'url' => '?' . http_build_query($qParams)];
}
if ($catSlug !== '') {
    $qParams = $baseQuery; unset($qParams['category'], $qParams['page']);
    $catName = '';
    foreach ($categoriesList as $cl) {
        if ($cl['slug'] === $catSlug) { $catName = $cl['name']; break; }
    }
    $activeChips[] = ['label' => 'Category: ' . ($catName ?: $catSlug), 'url' => '?' . http_build_query($qParams)];
}
if ($inStockOnly === '1') {
    $qParams = $baseQuery; unset($qParams['in_stock'], $qParams['page']);
    $activeChips[] = ['label' => 'In Stock', 'url' => '?' . http_build_query($qParams)];
}
if ($onSaleOnly === '1') {
    $qParams = $baseQuery; unset($qParams['on_sale'], $qParams['page']);
    $activeChips[] = ['label' => 'On Sale', 'url' => '?' . http_build_query($qParams)];
}
if ($minPrice !== null || $maxPrice !== null) {
    $qParams = $baseQuery; unset($qParams['min_price'], $qParams['max_price'], $qParams['page']);
    $priceLabel = 'Price: ' . ($minPrice ?: '0') . ' – ' . ($maxPrice ?: 'Any');
    $activeChips[] = ['label' => $priceLabel, 'url' => '?' . http_build_query($qParams)];
}
foreach ($selectedOptValues as $vId) {
    $valLabel = '';
    foreach ($filterOptions as $fo) {
        foreach ($fo['values'] as $v) {
            if ($v['id'] === $vId) {
                $valLabel = $fo['name'] . ': ' . $v['value'];
                break 2;
            }
        }
    }
    if ($valLabel) {
        $qParams = $baseQuery;
        $filtered = array_diff($selectedOptValues, [$vId]);
        if (!empty($filtered)) {
            $qParams['opt_val'] = $filtered;
        } else {
            unset($qParams['opt_val']);
        }
        unset($qParams['page']);
        $activeChips[] = ['label' => $valLabel, 'url' => '?' . http_build_query($qParams)];
    }
}

include __DIR__ . '/../../includes/partials/header.php';
?>

<section class="section">
  <div class="container">

    <!-- Top Toolbar -->
    <div class="catalog-toolbar">
      <div>
        <p class="result-count-label">
          Showing <?= count($products) ?> of <?= $total ?> product<?= $total === 1 ? '' : 's' ?>
        </p>
      </div>
      <div class="toolbar-controls">
        <!-- Mobile Filter Button Trigger -->
        <button type="button" class="btn btn-editorial-secondary btn-sm mobile-filter-btn" data-filter-drawer-trigger>
          <?= icon('filter', 'me-1') ?> Filters <?= count($activeChips) > 0 ? '(' . count($activeChips) . ')' : '' ?>
        </button>

        <!-- Sort Select -->
        <form method="get" id="sortForm" class="d-inline-flex align-items-center">
          <?php foreach ($_GET as $k => $v): if ($k === 'sort' || $k === 'page') continue; ?>
            <?php if (is_array($v)): foreach ($v as $subV): ?>
              <input type="hidden" name="<?= e($k) ?>[]" value="<?= e($subV) ?>">
            <?php endforeach; else: ?>
              <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
            <?php endif; ?>
          <?php endforeach; ?>
          <select name="sort" class="sort-select" onchange="document.getElementById('sortForm').submit();" aria-label="Sort products">
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
            <option value="best_selling" <?= $sort === 'best_selling' ? 'selected' : '' ?>>Best Selling</option>
            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
          </select>
        </form>
      </div>
    </div>

    <!-- Active Filter Chips -->
    <?php if (!empty($activeChips)): ?>
      <div class="active-filter-chips">
        <span class="small text-muted me-1">Active filters:</span>
        <?php foreach ($activeChips as $chip): ?>
          <span class="filter-chip">
            <?= e($chip['label']) ?>
            <a href="<?= e($chip['url']) ?>" aria-label="Remove filter <?= e($chip['label']) ?>"><?= icon('x') ?></a>
          </span>
        <?php endforeach; ?>
        <a href="<?= e(route('shop')) ?>" class="clear-all-link">Clear all</a>
      </div>
    <?php endif; ?>

    <!-- Catalog Layout (Sidebar + Grid) -->
    <div class="catalog-layout">

      <!-- Desktop Filter Sidebar -->
      <aside class="filter-sidebar" aria-label="Product filters">
        <form method="get" action="<?= e(route('shop')) ?>">
          <?php if ($search !== ''): ?>
            <input type="hidden" name="q" value="<?= e($search) ?>">
          <?php endif; ?>
          <?php if ($sort !== 'newest'): ?>
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
          <?php endif; ?>

          <!-- Category Filter -->
          <?php if (!empty($categoriesList)): ?>
            <div class="filter-block">
              <div class="filter-title">Categories</div>
              <ul class="filter-list">
                <li>
                  <label class="filter-checkbox-label">
                    <span>
                      <input type="radio" name="category" value="" <?= $catSlug === '' ? 'checked' : '' ?> onchange="this.form.submit()">
                      All Categories
                    </span>
                  </label>
                </li>
                <?php foreach ($categoriesList as $cl): ?>
                  <li>
                    <label class="filter-checkbox-label">
                      <span>
                        <input type="radio" name="category" value="<?= e($cl['slug']) ?>" <?= $catSlug === $cl['slug'] ? 'checked' : '' ?> onchange="this.form.submit()">
                        <?= e($cl['name']) ?>
                      </span>
                    </label>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <!-- Availability & Sale Checkboxes -->
          <div class="filter-block">
            <div class="filter-title">Availability</div>
            <ul class="filter-list">
              <li>
                <label class="filter-checkbox-label">
                  <span>
                    <input type="checkbox" name="in_stock" value="1" <?= $inStockOnly === '1' ? 'checked' : '' ?> onchange="this.form.submit()">
                    In Stock Only
                  </span>
                </label>
              </li>
              <li>
                <label class="filter-checkbox-label">
                  <span>
                    <input type="checkbox" name="on_sale" value="1" <?= $onSaleOnly === '1' ? 'checked' : '' ?> onchange="this.form.submit()">
                    On Sale Only
                  </span>
                </label>
              </li>
            </ul>
          </div>

          <!-- Price Range Filter -->
          <div class="filter-block">
            <div class="filter-title">Price Range</div>
            <div class="price-inputs-row mb-2">
              <input type="number" name="min_price" class="price-input" placeholder="Min" value="<?= $minPrice !== null ? e($minPrice) : '' ?>" min="0" step="1">
              <span class="text-muted">–</span>
              <input type="number" name="max_price" class="price-input" placeholder="Max" value="<?= $maxPrice !== null ? e($maxPrice) : '' ?>" min="0" step="1">
            </div>
            <button type="submit" class="btn btn-editorial-secondary btn-sm w-100">Apply Price</button>
          </div>

          <!-- Option Filters (Size, Color) -->
          <?php if (!empty($filterOptions)): ?>
            <?php foreach ($filterOptions as $opt): ?>
              <div class="filter-block">
                <div class="filter-title"><?= e($opt['name']) ?></div>
                <ul class="filter-list">
                  <?php foreach ($opt['values'] as $val): ?>
                    <?php $isChecked = in_array($val['id'], $selectedOptValues, true); ?>
                    <li>
                      <label class="filter-checkbox-label">
                        <span class="d-flex align-items-center gap-2">
                          <input type="checkbox" name="opt_val[]" value="<?= (int)$val['id'] ?>" <?= $isChecked ? 'checked' : '' ?> onchange="this.form.submit()">
                          <?php if (!empty($val['swatch_hex'])): ?>
                            <span class="swatch-dot" style="background-color: <?= e($val['swatch_hex']) ?>"></span>
                          <?php endif; ?>
                          <?= e($val['value']) ?>
                        </span>
                      </label>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

        </form>
      </aside>

      <!-- Main Product Grid -->
      <main class="catalog-main">
        <?php if (!empty($products)): ?>
          <div class="row g-4 row-cols-2 row-cols-md-2 row-cols-lg-3">
            <?php foreach ($products as $p): ?>
              <div class="col">
                <?php include __DIR__ . '/../../includes/partials/product_card.php'; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Numbered Pagination -->
          <?php if ($pages > 1): $qs = $_GET; ?>
            <nav aria-label="Page navigation">
              <ul class="editorial-pagination">
                <?php if ($page > 1): $qs['page'] = $page - 1; ?>
                  <li><a href="?<?= e(http_build_query($qs)) ?>" aria-label="Previous page"><?= icon('chevron-left') ?></a></li>
                <?php endif; ?>

                <?php for ($pn = 1; $pn <= $pages; $pn++): $qs['page'] = $pn; ?>
                  <li class="<?= $pn === $page ? 'active' : '' ?>">
                    <?php if ($pn === $page): ?>
                      <span aria-current="page"><?= $pn ?></span>
                    <?php else: ?>
                      <a href="?<?= e(http_build_query($qs)) ?>"><?= $pn ?></a>
                    <?php endif; ?>
                  </li>
                <?php endfor; ?>

                <?php if ($page < $pages): $qs['page'] = $page + 1; ?>
                  <li><a href="?<?= e(http_build_query($qs)) ?>" aria-label="Next page"><?= icon('chevron-right') ?></a></li>
                <?php endif; ?>
              </ul>
            </nav>
          <?php endif; ?>

        <?php else: ?>
          <!-- Friendly Empty State -->
          <div class="empty-state-card">
            <div class="empty-state-icon"><?= icon('search') ?></div>
            <h2>No products found</h2>
            <p>We couldn't find any items matching your selected criteria. Try adjusting your filters or search terms.</p>
            <a href="<?= e(route('shop')) ?>" class="btn btn-editorial-primary">
              Clear All Filters
            </a>
          </div>
        <?php endif; ?>
      </main>

    </div>

  </div>
</section>

<!-- Mobile Filter Bottom-Sheet Drawer -->
<div class="mobile-filter-drawer" id="mobileFilterDrawer" role="dialog" aria-modal="true" aria-label="Filters">
  <div class="filter-drawer-header">
    <h5 class="fw-semibold m-0"><?= icon('filter', 'me-1') ?> Filter Products</h5>
    <button type="button" class="btn-close" data-filter-drawer-close aria-label="Close filters"></button>
  </div>
  <div class="filter-drawer-body">
    <form method="get" action="<?= e(route('shop')) ?>">
      <?php if ($search !== ''): ?>
        <input type="hidden" name="q" value="<?= e($search) ?>">
      <?php endif; ?>
      <?php if ($sort !== 'newest'): ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
      <?php endif; ?>

      <!-- Categories -->
      <?php if (!empty($categoriesList)): ?>
        <div class="filter-block">
          <div class="filter-title">Category</div>
          <select name="category" class="form-select mb-2">
            <option value="">All Categories</option>
            <?php foreach ($categoriesList as $cl): ?>
              <option value="<?= e($cl['slug']) ?>" <?= $catSlug === $cl['slug'] ? 'selected' : '' ?>>
                <?= e($cl['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <!-- Availability -->
      <div class="filter-block">
        <div class="filter-title">Availability</div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="in_stock" value="1" id="mInStock" <?= $inStockOnly === '1' ? 'checked' : '' ?>>
          <label class="form-check-label small" for="mInStock">In Stock Only</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="on_sale" value="1" id="mOnSale" <?= $onSaleOnly === '1' ? 'checked' : '' ?>>
          <label class="form-check-label small" for="mOnSale">On Sale Only</label>
        </div>
      </div>

      <!-- Price -->
      <div class="filter-block">
        <div class="filter-title">Price Range</div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <input type="number" name="min_price" class="form-control form-control-sm" placeholder="Min" value="<?= $minPrice !== null ? e($minPrice) : '' ?>">
          </div>
          <div class="col-6">
            <input type="number" name="max_price" class="form-control form-control-sm" placeholder="Max" value="<?= $maxPrice !== null ? e($maxPrice) : '' ?>">
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 pt-2">
        <button type="submit" class="btn btn-editorial-primary w-100">Apply Filters</button>
        <a href="<?= e(route('shop')) ?>" class="btn btn-editorial-secondary w-100">Reset</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
