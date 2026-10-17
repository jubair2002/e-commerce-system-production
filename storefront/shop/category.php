<?php
/**
 * Category Listing Page (Prompt 04).
 * Breadcrumb, Category Title, Filter Sidebar, Sort, and Clean Cards.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    header('Location: ' . shop_url('shop.php'));
    exit;
}

$pdo = db();

try {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = :slug AND status = 'active' LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    $category = $stmt->fetch();
} catch (Throwable $e) {
    $category = false;
}

if (!$category) {
    http_response_code(404);
    $pageTitle = 'Category Not Found';
    include __DIR__ . '/../../includes/partials/header.php';
    ?>
    <section class="section">
      <div class="container">
        <div class="empty-state-card">
          <div class="empty-state-icon"><i class="bi bi-tag"></i></div>
          <h2>Category Not Found</h2>
          <p>The category you're looking for does not exist or has been moved.</p>
          <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary">
            Browse All Products
          </a>
        </div>
      </div>
    </section>
    <?php
    include __DIR__ . '/../../includes/partials/footer.php';
    exit;
}

$pageTitle = $category['name'];

// Sorting & Filters
$sort        = trim((string)($_GET['sort'] ?? setting('shop_default_sort', 'newest')));
$inStockOnly = trim((string)($_GET['in_stock'] ?? ''));
$onSaleOnly  = trim((string)($_GET['on_sale'] ?? ''));
$minPrice    = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$maxPrice    = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float)$_GET['max_price'] : null;

$perPage = max(6, (int)setting('shop_products_per_page', '12'));
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where  = ["p.status = 'active'", 'p.category_id = :cid'];
$params = [':cid' => (int)$category['id']];

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

$orderSql = match ($sort) {
    'price_asc'    => 'COALESCE(p.sale_price, p.price) ASC',
    'price_desc'   => 'COALESCE(p.sale_price, p.price) DESC',
    'best_selling' => 'p.sold_count DESC, p.created_at DESC',
    default        => 'p.created_at DESC',
};

$whereSql = 'WHERE ' . implode(' AND ', $where);

try {
    $countSql = "
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        LEFT JOIN v_product_stock vps ON vps.product_id = p.id
        $whereSql
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    $selectSql = "
        SELECT p.*, :cname AS category_name, :cslug AS category_slug,
               COALESCE(vps.total_stock, p.stock) AS calculated_stock
        FROM products p
        LEFT JOIN v_product_stock vps ON vps.product_id = p.id
        $whereSql
        ORDER BY $orderSql
        LIMIT :lim OFFSET :off
    ";
    $stmt = $pdo->prepare($selectSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':cname', $category['name']);
    $stmt->bindValue(':cslug', $category['slug']);
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
    $pages = 1;
}

$categoriesList = shop_active_categories();

include __DIR__ . '/../../includes/partials/header.php';
?>

<section class="section">
  <div class="container">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
      <ol class="breadcrumb small" style="background:none; padding:0;">
        <li class="breadcrumb-item"><a href="<?= e(shop_url()) ?>" class="text-muted">Home</a></li>
        <li class="breadcrumb-item"><a href="<?= e(shop_url('shop.php')) ?>" class="text-muted">Shop</a></li>
        <li class="breadcrumb-item active text-dark" aria-current="page"><?= e($category['name']) ?></li>
      </ol>
    </nav>

    <!-- Category Header -->
    <div class="section-header mb-4">
      <h1 class="section-title"><?= e($category['name']) ?></h1>
      <p class="section-sub text-muted">Showing <?= count($products) ?> of <?= $total ?> product<?= $total === 1 ? '' : 's' ?></p>
    </div>

    <!-- Top Toolbar -->
    <div class="catalog-toolbar">
      <div></div>
      <div class="toolbar-controls">
        <form method="get" id="sortFormCategory" class="d-inline-flex align-items-center">
          <input type="hidden" name="slug" value="<?= e($slug) ?>">
          <?php if ($inStockOnly): ?><input type="hidden" name="in_stock" value="1"><?php endif; ?>
          <?php if ($onSaleOnly): ?><input type="hidden" name="on_sale" value="1"><?php endif; ?>
          <?php if ($minPrice !== null): ?><input type="hidden" name="min_price" value="<?= e($minPrice) ?>"><?php endif; ?>
          <?php if ($maxPrice !== null): ?><input type="hidden" name="max_price" value="<?= e($maxPrice) ?>"><?php endif; ?>
          <select name="sort" class="sort-select" onchange="document.getElementById('sortFormCategory').submit();" aria-label="Sort products">
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
            <option value="best_selling" <?= $sort === 'best_selling' ? 'selected' : '' ?>>Best Selling</option>
            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
          </select>
        </form>
      </div>
    </div>

    <!-- Catalog Layout -->
    <div class="catalog-layout">

      <!-- Desktop Sidebar -->
      <aside class="filter-sidebar" aria-label="Filters">
        <form method="get" action="<?= e(shop_url('category.php')) ?>">
          <input type="hidden" name="slug" value="<?= e($slug) ?>">

          <!-- Categories List -->
          <div class="filter-block">
            <div class="filter-title">All Categories</div>
            <ul class="filter-list">
              <li>
                <a href="<?= e(shop_url('shop.php')) ?>" class="text-reset small">All Products</a>
              </li>
              <?php foreach ($categoriesList as $cl): ?>
                <li>
                  <a href="<?= e(shop_url('category.php?slug=' . urlencode($cl['slug']))) ?>"
                     class="small <?= $cl['slug'] === $slug ? 'fw-bold text-dark' : 'text-muted' ?>">
                    <?= e($cl['name']) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <!-- Availability -->
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

          <!-- Price -->
          <div class="filter-block">
            <div class="filter-title">Price Range</div>
            <div class="price-inputs-row mb-2">
              <input type="number" name="min_price" class="price-input" placeholder="Min" value="<?= $minPrice !== null ? e($minPrice) : '' ?>">
              <span class="text-muted">–</span>
              <input type="number" name="max_price" class="price-input" placeholder="Max" value="<?= $maxPrice !== null ? e($maxPrice) : '' ?>">
            </div>
            <button type="submit" class="btn btn-editorial-secondary btn-sm w-100">Apply</button>
          </div>
        </form>
      </aside>

      <!-- Main Products Grid -->
      <main class="catalog-main">
        <?php if (!empty($products)): ?>
          <div class="row g-4 row-cols-2 row-cols-md-2 row-cols-lg-3">
            <?php foreach ($products as $p): ?>
              <div class="col">
                <?php include __DIR__ . '/../../includes/partials/product_card.php'; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Pagination -->
          <?php if ($pages > 1): $qs = $_GET; ?>
            <nav aria-label="Page navigation">
              <ul class="editorial-pagination">
                <?php for ($pn = 1; $pn <= $pages; $pn++): $qs['page'] = $pn; ?>
                  <li class="<?= $pn === $page ? 'active' : '' ?>">
                    <?php if ($pn === $page): ?>
                      <span aria-current="page"><?= $pn ?></span>
                    <?php else: ?>
                      <a href="?<?= e(http_build_query($qs)) ?>"><?= $pn ?></a>
                    <?php endif; ?>
                  </li>
                <?php endfor; ?>
              </ul>
            </nav>
          <?php endif; ?>

        <?php else: ?>
          <div class="empty-state-card">
            <div class="empty-state-icon"><i class="bi bi-box-seam"></i></div>
            <h2>No products in this category</h2>
            <p>We are currently restocking this category. Check back soon or explore our full collection.</p>
            <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary">
              Browse All Products
            </a>
          </div>
        <?php endif; ?>
      </main>

    </div>

  </div>
</section>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
