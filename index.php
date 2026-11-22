<?php
// Handle direct media delivery if requested through built-in PHP server fallback
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requestUri !== null && preg_match('#(?:^|/)(?:assets/)?uploads/(.+)$#', $requestUri, $matches)) {
    $_GET['file'] = $matches[1];
    require __DIR__ . '/uploads.php';
    exit;
}

/**
 * Storefront Home Page (Cozastore Edition).
 * Driven by dynamic database queries, admin settings, and S3 StorageManager.
 */
require_once __DIR__ . '/includes/shop_bootstrap.php';

$pageTitle = 'Home';
$siteBrand = trim((string)setting('site_name', ''));
if ($siteBrand === '' || $siteBrand === 'PHP Admin Panel' || $siteBrand === 'Store') {
    $siteBrand = 'Jinish Potro';
}
$siteName  = $siteBrand;

// Section Toggles from Settings
$showHero        = (int)setting('home_show_hero', '1') === 1;
$showCategories  = (int)setting('home_show_categories', '1') === 1;
$showNewArrivals = (int)setting('home_show_new_arrivals', '1') === 1;
$showBestSellers = (int)setting('home_show_best_sellers', '1') === 1;
$showFeatured    = (int)setting('home_show_featured', '1') === 1;
$showReviews     = (int)setting('home_show_reviews', '1') === 1;

$heroBanners = [];
if ($showHero) {
    $heroBanners = storefront_banners_get('hero');
}

$categoryBanners = [];
if ($showCategories) {
    $categoryBanners = storefront_banners_get('category_row');
}

$pdo = db();

// 1. Categories (cached 5 min for faster load)
$categories = [];
try {
    $categories = FileCache::remember('home:categories:all', 300, function () use ($pdo) {
        return $pdo->query(
            "SELECT c.id, c.name, c.slug, c.description, c.image,
                    (SELECT COUNT(*) FROM products p 
                     WHERE (p.category_id = c.id OR (p.category_id IS NULL AND LOWER(p.tags) LIKE CONCAT('%', LOWER(c.slug), '%'))) 
                       AND p.status = 'active') AS product_count
             FROM categories c
             WHERE c.status = 'active'
             ORDER BY product_count DESC, c.name ASC"
        )->fetchAll() ?: [];
    });
} catch (Throwable $e) { $categories = []; }

$productCols = "p.id, p.category_id, p.name, p.slug, p.sku, p.price, p.sale_price, p.stock, p.image,
                p.status, p.featured, p.product_type, p.low_stock_threshold, p.allow_backorder,
                p.track_inventory, p.rating_avg, p.rating_count, p.sold_count";

// 2. New Arrivals (cached 5 min)
$newArrivalsCount = max(4, min(40, (int)setting('home_featured_count', '8')));
$newArrivals = [];
if ($showNewArrivals) {
    try {
        $newArrivals = FileCache::remember("home:newarrivals:{$newArrivalsCount}", 300, function () use ($pdo, $productCols, $newArrivalsCount) {
            $stmt = $pdo->prepare(
                "SELECT {$productCols}, c.name AS category_name, c.slug AS category_slug
                 FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.status = 'active'
                 ORDER BY p.created_at DESC, p.id DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':lim', $newArrivalsCount, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];
            if (!empty($rows)) products_attach_card_data($rows);
            return $rows;
        });
    } catch (Throwable $e) { $newArrivals = []; }
}

// 3. Best Sellers (first 8 from precomputed product_sales_summary, cached 5 min)
$bestSellers = [];
if ($showBestSellers) {
    try {
        $bestSellers = FileCache::remember('home:bestsellers:8', 300, function () use ($pdo, $productCols) {
            $rows = $pdo->query(
                "SELECT {$productCols}, c.name AS category_name, c.slug AS category_slug,
                        COALESCE(SUM(pss.units), 0) AS total_units_sold
                 FROM products p
                 JOIN product_sales_summary pss ON pss.product_id = p.id
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.status = 'active'
                 GROUP BY p.id
                 ORDER BY total_units_sold DESC, p.created_at DESC
                 LIMIT 8"
            )->fetchAll() ?: [];
            if (!empty($rows)) products_attach_card_data($rows);
            return $rows;
        });
    } catch (Throwable $e) { $bestSellers = []; }
}

// 4. Featured Products (first 8, cached 5 min)
$featured = [];
if ($showFeatured) {
    try {
        $featured = FileCache::remember('home:featured:8', 300, function () use ($pdo, $productCols) {
            $rows = $pdo->query(
                "SELECT {$productCols}, c.name AS category_name, c.slug AS category_slug
                 FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.status = 'active' AND p.featured = 1
                 ORDER BY p.created_at DESC
                 LIMIT 8"
            )->fetchAll() ?: [];
            if (!empty($rows)) products_attach_card_data($rows);
            return $rows;
        });
    } catch (Throwable $e) { $featured = []; }
}

// 5. Customer Reviews (cached 5 min)
$homeReviews = [];
if ($showReviews) {
    try {
        $homeReviews = FileCache::remember('home:reviews:4', 300, function () use ($pdo) {
            return $pdo->query(
                "SELECT r.id, r.product_id, r.author_name, r.rating, r.title, r.body, r.created_at,
                        p.name AS product_name, p.slug AS product_slug, p.image AS product_image
                 FROM product_reviews r
                 JOIN products p ON p.id = r.product_id
                 WHERE r.status = 'approved' AND r.rating >= 4
                 ORDER BY r.id DESC
                 LIMIT 4"
            )->fetchAll() ?: [];
        });
    } catch (Throwable $e) { $homeReviews = []; }
}

// Build New Arrivals products map tagged with category filter classes
$newArrivalProducts = [];
$productTags = [];

if ($showNewArrivals && !empty($newArrivals)) {
    foreach ($newArrivals as $p) {
        $pid = (int)$p['id'];
        $newArrivalProducts[$pid] = $p;
        if (!empty($p['category_slug'])) {
            $productTags[$pid][$p['category_slug']] = $p['category_slug'];
        }
    }
}

include __DIR__ . '/includes/partials/header.php';
?>

<!-- =================== HERO SLIDER =================== -->
<?php if ($showHero): ?>
<section class="section-slide">
	<div class="wrap-slick1">
		<div class="slick1">
			<?php if (!empty($heroBanners)): ?>
				<?php foreach ($heroBanners as $idx => $b):
					$bgImg    = !empty($b['image']) ? banner_image_url($b['image']) : '';
					$bStyle   = $bgImg ? "background-image: url('" . htmlspecialchars($bgImg, ENT_QUOTES, 'UTF-8') . "');" : "background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);";
					$bTitle   = !empty($b['title']) ? $b['title'] : "Welcome to {$siteBrand}";
					$bDesc    = !empty($b['subtitle']) ? $b['subtitle'] : setting('store_tagline', 'Curated collections crafted with intention.');
					$bBtnText = !empty($b['button_text']) ? $b['button_text'] : 'Shop Now';
					$bBtnUrl  = !empty($b['button_url']) ? $b['button_url'] : route('shop');
					$animations = [
						['fadeInDown', 'fadeInUp', 'zoomIn'],
						['rollIn', 'lightSpeedIn', 'slideInUp'],
						['rotateInDownLeft', 'rotateInUpRight', 'rotateIn']
					];
					$anim = $animations[$idx % 3];
				?>
				<div class="item-slick1" style="<?= $bStyle ?>">
					<div class="container h-full">
						<div class="flex-col-l-m h-full p-t-70 p-b-30 respon5">
							<div class="layer-slick1 animated visible-false" data-appear="<?= $anim[0] ?>" data-delay="0">
								<span class="stext-101 respon2" style="color: <?= $bgImg ? '#ffffff' : '#64748b' ?>; font-size: 14px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase;">
									<?= htmlspecialchars($bDesc, ENT_QUOTES, 'UTF-8') ?>
								</span>
							</div>
								
							<div class="layer-slick1 animated visible-false" data-appear="<?= $anim[1] ?>" data-delay="800">
								<h2 class="ltext-201 respon1" style="color: <?= $bgImg ? '#ffffff' : '#111827' ?>; font-size: 52px; font-weight: 800; letter-spacing: -1px; padding-top: 14px; padding-bottom: 32px; line-height: 1.15;">
									<?= htmlspecialchars($bTitle, ENT_QUOTES, 'UTF-8') ?>
								</h2>
							</div>
								
							<div class="layer-slick1 animated visible-false" data-appear="<?= $anim[2] ?>" data-delay="1600">
								<a href="<?= htmlspecialchars($bBtnUrl, ENT_QUOTES, 'UTF-8') ?>" class="hero-shop-btn flex-c-m stext-101 trans-04">
									<?= htmlspecialchars($bBtnText, ENT_QUOTES, 'UTF-8') ?>
								</a>
							</div>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			<?php else: ?>
				<!-- Clean Brand Hero Banner (Modern Obsidian Light Palette) -->
				<div class="item-slick1" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%); border-bottom: 1px solid #eef0f3;">
					<div class="container h-full">
						<div class="flex-col-l-m h-full p-t-70 p-b-30 respon5">
							<div class="layer-slick1 animated visible-false" data-appear="fadeInDown" data-delay="0">
								<span class="stext-101 respon2" style="color: #64748b; font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase;">
									Quality Essentials
								</span>
							</div>
								
							<div class="layer-slick1 animated visible-false" data-appear="fadeInUp" data-delay="800">
								<h2 class="ltext-201 respon1" style="color: #111827; font-size: 54px; font-weight: 800; letter-spacing: -1.5px; padding-top: 14px; padding-bottom: 32px; line-height: 1.15;">
									<?= htmlspecialchars($siteBrand, ENT_QUOTES, 'UTF-8') ?>
								</h2>
							</div>
								
							<div class="layer-slick1 animated visible-false" data-appear="zoomIn" data-delay="1600">
								<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="hero-shop-btn flex-c-m stext-101 trans-04">
									Shop Now
								</a>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<!-- =================== CATEGORY BANNER CARDS =================== -->
<?php if ($showCategories && (!empty($categoryBanners) || !empty($categories))): ?>
<div class="sec-banner bg0 p-t-80 p-b-50">
	<div class="container">
		<div class="row">
			<?php if (!empty($categoryBanners)): ?>
				<?php 
				$bannerCount = count($categoryBanners);
				$colClass = $bannerCount >= 4 ? 'col-md-6 col-xl-3' : ($bannerCount === 2 ? 'col-md-6' : 'col-md-6 col-xl-4');
				foreach ($categoryBanners as $b): 
					$bImg    = !empty($b['image']) ? banner_image_url($b['image']) : '';
					$bTitle  = $b['title'];
					$bSub    = $b['subtitle'] ?? '';
					$bBtnTxt = !empty($b['button_text']) ? $b['button_text'] : 'Shop Now';
					$bUrl    = !empty($b['button_url']) ? $b['button_url'] : route('shop');

					$displaySub = $bSub;
					if (!empty($categories)) {
						foreach ($categories as $cat) {
							if (strcasecmp($cat['name'], $bTitle) === 0 || (!empty($bUrl) && str_contains($bUrl, 'slug=' . $cat['slug']))) {
								if ($displaySub === '' || preg_match('/^\d+\s*products?$/i', trim($displaySub))) {
									$cCnt = (int)($cat['product_count'] ?? 0);
									$displaySub = $cCnt === 1 ? '1 Product' : "{$cCnt} Products";
								}
								break;
							}
						}
					}
				?>
				<div class="<?= $colClass ?> p-b-30 m-lr-auto">
					<!-- Block1 -->
					<div class="block1 wrap-pic-w">
						<?php if ($bImg): ?>
							<img src="<?= htmlspecialchars($bImg, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($bTitle, ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; aspect-ratio: 4/3; object-fit: cover;">
						<?php else: ?>
							<div class="block1-fallback-box" style="width: 100%; aspect-ratio: 4/3; background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); display: flex; align-items: center; justify-content: center;">
								<span style="font-size: 16px; font-weight: 700; color: #94a3b8; letter-spacing: 2px; text-transform: uppercase;"><?= htmlspecialchars($bTitle, ENT_QUOTES, 'UTF-8') ?></span>
							</div>
						<?php endif; ?>

						<a href="<?= htmlspecialchars($bUrl, ENT_QUOTES, 'UTF-8') ?>" class="block1-txt ab-t-l s-full flex-col-l-sb p-lr-38 p-tb-34 trans-03 respon3">
							<div class="block1-txt-child1 flex-col-l">
								<span class="block1-name ltext-102 trans-04 p-b-8">
									<?= htmlspecialchars($bTitle, ENT_QUOTES, 'UTF-8') ?>
								</span>

								<?php if ($displaySub !== ''): ?>
								<span class="block1-info stext-102 trans-04">
									<?= htmlspecialchars($displaySub, ENT_QUOTES, 'UTF-8') ?>
								</span>
								<?php endif; ?>
							</div>

							<div class="block1-txt-child2 p-b-4 trans-05">
								<div class="block1-link stext-101 cl0 trans-09">
									<?= htmlspecialchars($bBtnTxt, ENT_QUOTES, 'UTF-8') ?>
								</div>
							</div>
						</a>
					</div>
				</div>
				<?php endforeach; ?>
			<?php else: ?>
				<?php 
				// Fallback to active categories from DB
				$bannerCats = array_slice($categories, 0, 3);
				foreach ($bannerCats as $cat): 
					$catImg = category_image_url($cat['image'] ?? null);
					$pCount = (int)($cat['product_count'] ?? 0);
				?>
				<div class="col-md-6 col-xl-4 p-b-30 m-lr-auto">
					<!-- Block1 -->
					<div class="block1 wrap-pic-w">
						<img src="<?= htmlspecialchars($catImg, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; aspect-ratio: 4/3; object-fit: cover;">

						<a href="<?= htmlspecialchars(route('category', ['slug' => $cat['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="block1-txt ab-t-l s-full flex-col-l-sb p-lr-38 p-tb-34 trans-03 respon3">
							<div class="block1-txt-child1 flex-col-l">
								<span class="block1-name ltext-102 trans-04 p-b-8">
									<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
								</span>

								<span class="block1-info stext-102 trans-04">
									<?= $pCount === 1 ? '1 Product' : "{$pCount} Products" ?>
								</span>
							</div>

							<div class="block1-txt-child2 p-b-4 trans-05">
								<div class="block1-link stext-101 cl0 trans-09">
									Shop Now
								</div>
							</div>
						</a>
					</div>
				</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =================== NEW ARRIVALS SECTION =================== -->
<?php if ($showNewArrivals): ?>
<section class="bg0 p-t-23 p-b-140">
	<div class="container">
		<div class="p-b-10">
			<h3 class="ltext-103 cl5">
				<?= htmlspecialchars(setting('home_new_arrivals_title', 'New Arrivals'), ENT_QUOTES, 'UTF-8') ?>
			</h3>
		</div>

		<div class="flex-w flex-sb-m p-b-52">
			<div class="flex-w flex-l-m filter-tope-group m-tb-10">
				<button class="stext-106 cl6 hov1 bor3 trans-04 m-r-32 m-tb-5 how-active1" data-filter="*">
					All
				</button>

				<?php foreach ($categories as $cat): ?>
				<button class="stext-106 cl6 hov1 bor3 trans-04 m-r-32 m-tb-5" data-filter=".<?= htmlspecialchars($cat['slug'], ENT_QUOTES, 'UTF-8') ?>">
					<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
				</button>
				<?php endforeach; ?>
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
			
			<!-- Search product -->
			<div class="dis-none panel-search w-full p-t-10 p-b-15">
				<form action="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" method="GET" class="bor8 dis-flex p-l-15">
					<button type="submit" class="size-113 flex-c-m fs-16 cl2 hov-cl1 trans-04">
						<i class="zmdi zmdi-search"></i>
					</button>

					<input class="mtext-107 cl2 size-114 plh2 p-r-15" type="text" name="q" placeholder="Search products...">
				</form>	
			</div>

			<!-- Filter Drawer -->
			<div class="dis-none panel-filter w-full p-t-10">
				<div class="wrap-filter flex-w bg6 w-full p-lr-40 p-t-27 p-lr-15-sm">
					<div class="filter-col1 p-r-15 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Sort By
						</div>
						<ul>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars(route('shop', ['sort' => 'newest']), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04">Newness</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars(route('shop', ['sort' => 'best_selling']), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04">Popularity</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars(route('shop', ['sort' => 'price_asc']), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04">Price: Low to High</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars(route('shop', ['sort' => 'price_desc']), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04">Price: High to Low</a>
							</li>
						</ul>
					</div>

					<div class="filter-col2 p-r-15 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Price Range
						</div>
						<ul>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04">All Prices</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars(route('shop', ['min_price' => 0, 'max_price' => 500]), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04">Under 500</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars(route('shop', ['min_price' => 500, 'max_price' => 1500]), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04">500 - 1,500</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars(route('shop', ['min_price' => 1500]), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04">1,500+</a>
							</li>
						</ul>
					</div>

					<div class="filter-col3 p-r-15 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Categories
						</div>
						<ul>
							<?php foreach ($categories as $cat): ?>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars(route('category', ['slug' => $cat['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04">
									<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
								</a>
							</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</div>
		</div>

		<?php if (!empty($newArrivalProducts)): ?>
			<div class="row isotope-grid">
				<?php foreach ($newArrivalProducts as $pid => $p): 
					$productCardExtraClass = implode(' ', $productTags[$pid] ?? []);
					include __DIR__ . '/includes/partials/product_card.php';
				endforeach; ?>
			</div>

			<!-- View All Products -->
			<div class="flex-c-m flex-w w-full p-t-45">
				<a href="<?= htmlspecialchars(route('shop', ['sort' => 'newest']), ENT_QUOTES, 'UTF-8') ?>" class="view-all-products-btn flex-c-m stext-101 trans-04">
					View All Products <i class="zmdi zmdi-arrow-right m-l-8 fs-16"></i>
				</a>
			</div>
		<?php else: ?>
			<div class="row">
				<div class="col-12 text-center p-tb-80 empty-products-state">
					<div class="empty-state-icon m-b-20">
						<i class="zmdi zmdi-shopping-basket cl6" style="font-size: 64px;"></i>
					</div>
					<h4 class="mtext-105 cl2 p-b-10 font-weight-bold">No new arrivals yet</h4>
					<p class="stext-115 cl6 p-b-25" style="max-width: 520px; margin: 0 auto 25px auto;">
						We are preparing our latest collection drops. Check back soon or explore our full catalog.
					</p>
					<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-101 bg1 bor1 hov-btn1 p-lr-15 trans-04 m-auto" style="max-width: 220px; border-radius: 25px;">
						Browse Shop
					</a>
				</div>
			</div>
		<?php endif; ?>
	</div>
</section>
<?php endif; ?>

<!-- =================== CUSTOMER REVIEWS STRIP =================== -->
<?php if ($showReviews && !empty($homeReviews)): ?>
<section class="sec-reviews bg0 p-b-100" aria-label="Customer Reviews">
  <div class="container">
    <div class="p-b-32 text-center">
      <h3 class="ltext-103 cl5">
        Loved by Our Community
      </h3>
      <p class="stext-102 cl6 p-t-6">Real experiences from verified shoppers</p>
    </div>

    <div class="row">
      <?php foreach ($homeReviews as $hr): ?>
        <div class="col-sm-6 col-lg-3 p-b-30">
          <div class="bor10 bg0 p-lr-25 p-tb-25 h-full flex-col-sb" style="border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
            <div>
              <div class="cl1 fs-14 m-b-10">
                <?php for ($i = 1; $i <= (int)$hr['rating']; $i++): ?>
                  <i class="zmdi zmdi-star" style="color: #f59e0b;"></i>
                <?php endfor; ?>
              </div>
              <h4 class="stext-101 cl2 font-weight-bold m-b-8">
                "<?= htmlspecialchars($hr['title'], ENT_QUOTES, 'UTF-8') ?>"
              </h4>
              <p class="stext-102 cl6 m-b-16" style="line-height: 1.6;">
                <?= htmlspecialchars(mb_substr($hr['body'], 0, 110), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($hr['body']) > 110 ? '...' : '' ?>
              </p>
            </div>
            <div class="flex-w flex-sb-m p-t-15 bor12">
              <span class="stext-111 cl2 font-weight-bold"><?= htmlspecialchars($hr['author_name'], ENT_QUOTES, 'UTF-8') ?></span>
              <a href="<?= htmlspecialchars(route('product', ['slug' => $hr['product_slug']]), ENT_QUOTES, 'UTF-8') ?>" class="stext-111 cl8 hov-cl1 trans-04 text-truncate" style="max-width: 110px;" title="<?= htmlspecialchars($hr['product_name'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($hr['product_name'], ENT_QUOTES, 'UTF-8') ?>
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/partials/footer.php'; ?>
