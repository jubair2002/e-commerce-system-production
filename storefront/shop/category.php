<?php
/**
 * Category Listing Page (Cozastore Edition).
 * Cozastore category layout with breadcrumbs, category pills, filter & search drawers,
 * product grid, and circular pagination.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    header('Location: ' . route('shop'));
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
    <div class="bg0 p-t-100 p-b-140">
        <div class="container text-center">
            <i class="zmdi zmdi-alert-circle-o fs-50 cl6 d-block m-b-20" style="font-size: 60px;"></i>
            <h2 class="ltext-103 cl2 p-b-15">Category Not Found</h2>
            <p class="stext-115 cl6 p-b-30">The category you're looking for does not exist or has been moved.</p>
            <a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-101 bg1 bor1 hov-btn1 p-lr-15 trans-04 m-auto" style="max-width: 220px;">
                Browse All Products
            </a>
        </div>
    </div>
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

$total = 0;
$pages = 1;
$products = [];

$cacheKey = ($page <= 3) 
    ? 'shop:cat:' . $slug . ':p' . $page . ':' . md5(serialize($params) . '_' . $sort . '_' . $perPage)
    : null;

if ($cacheKey !== null) {
    $cachedData = FileCache::get($cacheKey);
    if (is_array($cachedData)) {
        $total    = $cachedData['total'] ?? 0;
        $pages    = $cachedData['pages'] ?? 1;
        $products = $cachedData['products'] ?? [];
    }
}

if (empty($products) && $total === 0) {
    try {
        $joinVps = str_contains($whereSql, 'vps.');
        $countJoins = $joinVps ? ' LEFT JOIN v_product_stock vps ON vps.product_id = p.id' : '';

        $countSql = "SELECT COUNT(p.id) FROM products p {$countJoins} {$whereSql}";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));

        $selectSql = "
            SELECT p.id, p.category_id, p.name, p.slug, p.sku, p.price, p.sale_price, p.stock, p.image,
                   p.status, p.featured, p.product_type, p.low_stock_threshold, p.allow_backorder,
                   p.track_inventory, p.rating_avg, p.rating_count, p.sold_count,
                   :cname AS category_name, :cslug AS category_slug,
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
        $products = $stmt->fetchAll() ?: [];
        if (!empty($products)) {
            products_attach_card_data($products);
        }

        if ($cacheKey !== null) {
            FileCache::set($cacheKey, [
                'total'    => $total,
                'pages'    => $pages,
                'products' => $products,
            ], 300); // 5 min TTL
        }
    } catch (Throwable $e) {
        $products = [];
        $total = 0;
        $pages = 1;
    }
}

$categoriesList = shop_active_categories();
$baseQuery = $_GET;

include __DIR__ . '/../../includes/partials/header.php';
?>

<!-- Breadcrumb -->
<div class="container m-t-20 m-b-20">
	<div class="bread-crumb flex-w p-l-0 p-r-15 p-t-15 p-lr-0-lg">
		<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
			Home
			<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
		</a>

		<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
			Shop
			<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
		</a>

		<span class="stext-109 cl4">
			<?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>
		</span>
	</div>
</div>

<!-- =================== COZASTORE CATEGORY CATALOG =================== -->
<div class="bg0 p-b-140">
	<div class="container">

		<!-- Category Title & Meta -->
		<div class="p-b-30">
			<h2 class="ltext-103 cl5">
				<?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>
			</h2>
			<p class="stext-102 cl6 p-t-6">
				Showing <?= count($products) ?> of <?= $total ?> product<?= $total === 1 ? '' : 's' ?>
			</p>
		</div>

		<!-- Category Filter Pills Bar & Controls -->
		<div class="flex-w flex-sb-m p-b-40">
			<div class="flex-w flex-l-m filter-tope-group m-tb-10">
				<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="stext-106 cl6 hov1 bor3 trans-04 m-r-32 m-tb-5">
					All Products
				</a>

				<?php if (!empty($categoriesList)): ?>
					<?php foreach ($categoriesList as $cl): ?>
						<a href="<?= htmlspecialchars(route('category', ['slug' => $cl['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="stext-106 cl6 hov1 bor3 trans-04 m-r-32 m-tb-5 <?= ($cl['slug'] === $slug) ? 'how-active1' : '' ?>">
							<?= htmlspecialchars($cl['name'], ENT_QUOTES, 'UTF-8') ?>
						</a>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="flex-w flex-c-m m-tb-10">
				<div class="flex-c-m stext-106 cl6 size-104 bor4 pointer hov-btn3 trans-04 m-r-8 m-tb-4 js-show-filter">
					<i class="icon-filter cl2 m-r-6 fs-15 trans-04 zmdi zmdi-filter-list"></i>
					<i class="icon-close-filter cl2 m-r-6 fs-15 trans-04 zmdi zmdi-close dis-none"></i>
					Filter
				</div>

				<div class="flex-c-m stext-106 cl6 size-105 bor4 pointer hov-btn3 trans-04 m-tb-4 js-show-search">
					<i class="icon-search cl2 m-r-6 fs-15 trans-04 zmdi zmdi-search"></i>
					<i class="icon-close-search cl2 m-r-6 fs-15 trans-04 zmdi zmdi-close dis-none"></i>
					Search
				</div>
			</div>
			
			<!-- Search Drawer -->
			<div class="dis-none panel-search w-full p-t-10 p-b-15">
				<form action="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" method="GET" class="bor8 dis-flex p-l-15">
					<input type="hidden" name="category" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
					<button type="submit" class="size-113 flex-c-m fs-16 cl2 hov-cl1 trans-04">
						<i class="zmdi zmdi-search"></i>
					</button>
					<input class="mtext-107 cl2 size-114 plh2 p-r-15" type="text" name="q" placeholder="Search in <?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>...">
				</form>	
			</div>

			<!-- Filter Drawer -->
			<div class="dis-none panel-filter w-full p-t-10">
				<div class="wrap-filter flex-w bg6 w-full p-lr-40 p-t-27 p-lr-15-sm">
					<!-- Sort By Column -->
					<div class="filter-col1 p-r-15 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Sort By
						</div>
						<?php
						$qSort = $baseQuery; unset($qSort['sort'], $qSort['page']);
						$buildSortUrl = fn($s) => '?' . http_build_query(array_merge($qSort, ['sort' => $s]));
						?>
						<ul>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildSortUrl('newest'), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $sort === 'newest' ? 'filter-link-active' : '' ?>">
									Newness
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildSortUrl('best_selling'), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $sort === 'best_selling' ? 'filter-link-active' : '' ?>">
									Popularity
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildSortUrl('price_asc'), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $sort === 'price_asc' ? 'filter-link-active' : '' ?>">
									Price: Low to High
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildSortUrl('price_desc'), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $sort === 'price_desc' ? 'filter-link-active' : '' ?>">
									Price: High to Low
								</a>
							</li>
						</ul>
					</div>

					<!-- Price Filter Column -->
					<div class="filter-col2 p-r-15 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Price
						</div>
						<?php
						$qPrice = $baseQuery; unset($qPrice['min_price'], $qPrice['max_price'], $qPrice['page']);
						$buildPriceUrl = fn($min, $max = null) => '?' . http_build_query(array_merge($qPrice, array_filter(['min_price' => $min, 'max_price' => $max], fn($v) => $v !== null)));
						?>
						<ul>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars('?' . http_build_query($qPrice), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= ($minPrice === null && $maxPrice === null) ? 'filter-link-active' : '' ?>">
									All Prices
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildPriceUrl(0, 500), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= ($minPrice == 0 && $maxPrice == 500) ? 'filter-link-active' : '' ?>">
									<?= price(0) ?> - <?= price(500) ?>
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildPriceUrl(500, 1500), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= ($minPrice == 500 && $maxPrice == 1500) ? 'filter-link-active' : '' ?>">
									<?= price(500) ?> - <?= price(1500) ?>
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildPriceUrl(1500), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= ($minPrice == 1500 && $maxPrice === null) ? 'filter-link-active' : '' ?>">
									<?= price(1500) ?>+
								</a>
							</li>
						</ul>
					</div>

					<!-- Availability Column -->
					<div class="filter-col3 p-r-15 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Availability
						</div>
						<ul>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars('?' . http_build_query(array_merge($baseQuery, ['in_stock' => $inStockOnly === '1' ? null : '1'])), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $inStockOnly === '1' ? 'filter-link-active' : '' ?>">
									<i class="zmdi <?= $inStockOnly === '1' ? 'zmdi-check-square' : 'zmdi-square-o' ?> m-r-4"></i> In Stock Only
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars('?' . http_build_query(array_merge($baseQuery, ['on_sale' => $onSaleOnly === '1' ? null : '1'])), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $onSaleOnly === '1' ? 'filter-link-active' : '' ?>">
									<i class="zmdi <?= $onSaleOnly === '1' ? 'zmdi-check-square' : 'zmdi-square-o' ?> m-r-4"></i> On Sale Only
								</a>
							</li>
						</ul>
					</div>

					<!-- Other Categories Column -->
					<div class="filter-col4 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							All Categories
						</div>
						<div class="flex-w p-t-4 m-r--5">
							<?php foreach ($categoriesList as $cl): ?>
								<a href="<?= htmlspecialchars(route('category', ['slug' => $cl['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-107 cl6 size-301 bor7 p-lr-15 hov-tag1 trans-04 m-r-5 m-b-5 <?= $cl['slug'] === $slug ? 'bg1 cl0' : '' ?>">
									<?= htmlspecialchars($cl['name'], ENT_QUOTES, 'UTF-8') ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Product Grid -->
		<?php if (!empty($products)): ?>
			<div class="row isotope-grid">
				<?php foreach ($products as $p): ?>
					<?php include __DIR__ . '/../../includes/partials/product_card.php'; ?>
				<?php endforeach; ?>
			</div>

			<!-- Cozastore Numbered Circular Pagination -->
			<?= render_coza_pagination($page, $pages, $_GET) ?>
		<?php else: ?>
			<div class="row">
				<div class="col-12 text-center p-tb-80 empty-products-state">
					<div class="empty-state-icon m-b-20">
						<i class="zmdi zmdi-shopping-basket cl6" style="font-size: 64px;"></i>
					</div>
					<h4 class="mtext-105 cl2 p-b-10 font-weight-bold">No products in this category</h4>
					<p class="stext-115 cl6 p-b-25" style="max-width: 520px; margin: 0 auto 25px auto;">
						We are currently restocking this category. Check back soon or explore our full collection.
					</p>
					<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-101 bg1 bor1 hov-btn1 p-lr-15 trans-04 m-auto" style="max-width: 220px; border-radius: 25px;">
						Browse Full Shop
					</a>
				</div>
			</div>
		<?php endif; ?>

	</div>
</div>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
