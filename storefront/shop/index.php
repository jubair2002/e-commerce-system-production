<?php
/**
 * Shop / Catalog Listing (Cozastore Edition).
 * Cozastore product catalog markup with interactive drawer filters, search panel,
 * isotope grid, and responsive circular pagination.
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

$cacheKey = ($page <= 3 && $search === '') 
    ? 'shop:list:p' . $page . ':' . md5(serialize($params) . '_' . $sort . '_' . $perPage)
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
        $joinCat = str_contains($whereSql, 'c.');

        $countJoins = '';
        if ($joinCat) $countJoins .= ' LEFT JOIN categories c ON c.id = p.category_id';
        if ($joinVps) $countJoins .= ' LEFT JOIN v_product_stock vps ON vps.product_id = p.id';

        $countSql = "SELECT COUNT(p.id) FROM products p {$countJoins} {$whereSql}";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));

        if ($search !== '' && $page === 1 && function_exists('analytics_log_search')) {
            analytics_log_search($search, $total);
        }

        $selectSql = "
            SELECT p.id, p.category_id, p.name, p.slug, p.sku, p.price, p.sale_price, p.stock, p.image,
                   p.status, p.featured, p.product_type, p.low_stock_threshold, p.allow_backorder,
                   p.track_inventory, p.rating_avg, p.rating_count, p.sold_count,
                   c.name AS category_name, c.slug AS category_slug,
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
    }
}

// Filter Sidebar / Drawer Data
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

<!-- =================== COZASTORE CATALOG CONTENT =================== -->
<div class="bg0 m-t-23 p-b-140">
	<div class="container">

		<!-- Category Filter Pills Bar & Action Controls -->
		<div class="flex-w flex-sb-m p-b-40">
			<div class="flex-w flex-l-m filter-tope-group m-tb-10">
				<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="stext-106 cl6 hov1 bor3 trans-04 m-r-32 m-tb-5 <?= empty($catSlug) ? 'how-active1' : '' ?>">
					All Products
				</a>

				<?php if (!empty($categoriesList)): ?>
					<?php foreach ($categoriesList as $cl): ?>
						<a href="<?= htmlspecialchars(route('category', ['slug' => $cl['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="stext-106 cl6 hov1 bor3 trans-04 m-r-32 m-tb-5 <?= ($cl['slug'] === $catSlug) ? 'how-active1' : '' ?>">
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
			
			<!-- Search product panel -->
			<div class="dis-none panel-search w-full p-t-10 p-b-15">
				<form action="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" method="GET" class="bor8 dis-flex p-l-15">
					<?php if ($catSlug !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($catSlug, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
					<?php if ($sort !== 'newest'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
					<button type="submit" class="size-113 flex-c-m fs-16 cl2 hov-cl1 trans-04">
						<i class="zmdi zmdi-search"></i>
					</button>
					<input class="mtext-107 cl2 size-114 plh2 p-r-15" type="text" name="q" placeholder="Search catalog..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
				</form>	
			</div>

			<!-- Filter Drawer panel -->
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
						<?php
						$qStock = $baseQuery; unset($qStock['in_stock'], $qStock['on_sale'], $qStock['page']);
						?>
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

					<!-- Categories Column -->
					<div class="filter-col4 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Categories
						</div>
						<div class="flex-w p-t-4 m-r--5">
							<?php foreach ($categoriesList as $cl): ?>
								<a href="<?= htmlspecialchars(route('category', ['slug' => $cl['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-107 cl6 size-301 bor7 p-lr-15 hov-tag1 trans-04 m-r-5 m-b-5 <?= $cl['slug'] === $catSlug ? 'bg1 cl0' : '' ?>">
									<?= htmlspecialchars($cl['name'], ENT_QUOTES, 'UTF-8') ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Active Filters Chips Bar -->
		<?php if (!empty($activeChips)): ?>
			<div class="flex-w flex-m p-b-30">
				<span class="stext-109 cl6 m-r-10">Active Filters:</span>
				<?php foreach ($activeChips as $chip): ?>
					<a href="<?= htmlspecialchars($chip['url'], ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-107 cl2 size-301 bor7 p-lr-12 hov-tag1 trans-04 m-r-8 m-b-5 bg6" style="border-radius: 20px;">
						<?= htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8') ?>
						<i class="zmdi zmdi-close m-l-6 fs-12"></i>
					</a>
				<?php endforeach; ?>
				<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl1 hov-cl2 trans-04 m-l-10 m-b-5">
					Clear All
				</a>
			</div>
		<?php endif; ?>

		<!-- Product Grid -->
		<div class="row isotope-grid">
			<?php if (!empty($products)): ?>
				<?php foreach ($products as $p): ?>
					<?php include __DIR__ . '/../../includes/partials/product_card.php'; ?>
				<?php endforeach; ?>
			<?php else: ?>
				<div class="col-12 text-center p-tb-80">
					<i class="zmdi zmdi-search-for cl6 fs-50 d-block m-b-15" style="font-size: 56px;"></i>
					<h4 class="mtext-105 cl2 p-b-10">No products found</h4>
					<p class="stext-115 cl6 p-b-25">We couldn't find any products matching your active filters.</p>
					<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-101 bg1 bor1 hov-btn1 p-lr-15 trans-04 m-auto" style="max-width: 200px;">
						Clear Filters
					</a>
				</div>
			<?php endif; ?>
		</div>

		<!-- Cozastore Numbered Circular Pagination -->
		<?= render_coza_pagination($page, $pages, $_GET) ?>

	</div>
</div>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
