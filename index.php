<?php
// Handle direct media delivery if requested through built-in PHP server fallback
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requestUri !== null && preg_match('#(?:^|/)(?:assets/)?uploads/(.+)$#', $requestUri, $matches)) {
    $_GET['file'] = $matches[1];
    require __DIR__ . '/uploads.php';
    exit;
}

/**
 * Storefront Home Page (Phase C).
 * Modern, calm, editorial design driven by admin settings.
 */
require_once __DIR__ . '/includes/shop_bootstrap.php';

$pageTitle = 'Home';
$siteName  = setting('site_name', APP_NAME);

// Section Toggles from Settings
$showHero        = (int)setting('home_show_hero', '1') === 1;
$showCategories  = (int)setting('home_show_categories', '1') === 1;
$showNewArrivals = (int)setting('home_show_new_arrivals', '1') === 1;
$showBestSellers = (int)setting('home_show_best_sellers', '1') === 1;
$showFeatured    = (int)setting('home_show_featured', '1') === 1;
$showReviews     = (int)setting('home_show_reviews', '1') === 1;

$heroBanner = null;
if ($showHero) {
    $heroBanners = storefront_banners_get('hero');
    $heroBanner  = $heroBanners[0] ?? null;
}

$pdo = db();

// 1. Categories
$categories = [];
if ($showCategories) {
    try {
        $categories = $pdo->query(
            "SELECT c.id, c.name, c.slug, c.image,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = 'active') AS product_count
             FROM categories c
             WHERE c.status = 'active'
             ORDER BY product_count DESC, c.name ASC
             LIMIT 4"
        )->fetchAll();
    } catch (Throwable $e) { $categories = []; }
}

$productCols = "p.id, p.category_id, p.name, p.slug, p.sku, p.price, p.sale_price, p.stock, p.image,
                p.status, p.featured, p.product_type, p.low_stock_threshold, p.allow_backorder,
                p.track_inventory, p.rating_avg, p.rating_count, p.sold_count";

// 2. New Arrivals (cached 5 min)
$newArrivals = [];
if ($showNewArrivals) {
    try {
        $newArrivals = FileCache::remember('home:newarrivals:8', 300, function () use ($pdo, $productCols) {
            $rows = $pdo->query(
                "SELECT {$productCols}, c.name AS category_name, c.slug AS category_slug
                 FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.status = 'active'
                 ORDER BY p.created_at DESC
                 LIMIT 8"
            )->fetchAll() ?: [];
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

// 6. Hero Marquee Ticker Products (deduplicated)
$heroTickerProducts = array_merge($featured, $newArrivals, $bestSellers);
$tickerSeen = [];
$tickerUnique = [];
foreach ($heroTickerProducts as $tp) {
    if (!isset($tickerSeen[$tp['id']])) {
        $tickerSeen[$tp['id']] = true;
        $tickerUnique[] = $tp;
        if (count($tickerUnique) >= 8) break;
    }
}
$tickerTrack = !empty($tickerUnique) ? array_merge($tickerUnique, $tickerUnique) : [];

include __DIR__ . '/includes/partials/header.php';
?>

<!-- =================== WEBFLOW SHOP HERO =================== -->
<?php if ($showHero): ?>
  <section class="hero-webflow" aria-label="Store Welcome">
    <div class="hero-webflow-glow"></div>
    <div class="container">
      <?php
      $heroTitle = ($heroBanner && !empty($heroBanner['title']))
          ? $heroBanner['title']
          : "Made by {$siteName}. Inspired by you.";
      $heroDesc = ($heroBanner && !empty($heroBanner['subtitle']))
          ? $heroBanner['subtitle']
          : setting('store_tagline', 'Curated designer apparel, technical gear, and everyday essentials crafted with intention.');
      $heroBtnText = ($heroBanner && !empty($heroBanner['button_text'])) ? $heroBanner['button_text'] : 'Shop all products';
      $heroBtnUrl = ($heroBanner && !empty($heroBanner['button_url'])) ? $heroBanner['button_url'] : route('shop');
      ?>
      <h1 class="hero-webflow-title"><?= e($heroTitle) ?></h1>
      <p class="hero-webflow-desc"><?= e($heroDesc) ?></p>
      <a href="<?= e($heroBtnUrl) ?>" class="hero-webflow-btn">
        <?= e($heroBtnText) ?> <?= icon('arrow-right') ?>
      </a>
    </div>

    <!-- Continuous Marquee Product Ticker -->
    <?php if (!empty($tickerTrack)): ?>
      <div class="hero-marquee-wrap" aria-hidden="true">
        <div class="hero-marquee-track">
          <?php foreach ($tickerTrack as $tp): ?>
            <a href="<?= e(route('product', ['slug' => $tp['slug']])) ?>" class="hero-marquee-item">
              <img src="<?= e(product_image_url($tp['image'], 'thumbnail', (int)$tp['id'])) ?>" alt="<?= e($tp['name']) ?>" class="hero-marquee-img" loading="lazy">
              <div class="hero-marquee-name"><?= e($tp['name']) ?></div>
              <div class="hero-marquee-price"><?= e(price((float)($tp['sale_price'] ?: $tp['price']))) ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<!-- =================== CATEGORY PILLS BAR =================== -->
<?php if ($showCategories && !empty($categories)): ?>
  <div class="container mb-4">
    <div class="category-pills-wrap">
      <a href="<?= e(route('shop')) ?>" class="category-pill is-active">
        All Products
      </a>
      <?php foreach ($categories as $cat): ?>
        <a href="<?= e(route('category', ['slug' => $cat['slug']])) ?>" class="category-pill">
          <?= e($cat['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<!-- =================== FEATURED BENTO GRID =================== -->
<?php if ($showFeatured && !empty($featured)): ?>
  <section class="section-py pt-2" aria-label="Featured Highlights">
    <div class="container">
      <div class="section-header-wrap">
        <div>
          <h2 class="section-header-title">Featured</h2>
        </div>
        <a href="<?= e(route('shop', ['featured' => '1'])) ?>" class="section-header-link">
          View all <?= icon('arrow-right') ?>
        </a>
      </div>

      <div class="bento-grid">
        <?php foreach ($featured as $idx => $p): ?>
          <div class="<?= $idx === 0 ? 'bento-span-2' : '' ?>">
            <?php include __DIR__ . '/includes/partials/product_card.php'; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =================== NEW ARRIVALS =================== -->
<?php if ($showNewArrivals && !empty($newArrivals)): ?>
  <section class="section-py pt-4" aria-label="New Arrivals">
    <div class="container">
      <div class="section-header-wrap">
        <div>
          <h2 class="section-header-title">New Arrivals</h2>
        </div>
        <a href="<?= e(route('shop', ['sort' => 'newest'])) ?>" class="section-header-link">
          View all <?= icon('arrow-right') ?>
        </a>
      </div>

      <div class="product-grid">
        <?php foreach ($newArrivals as $p): ?>
          <?php include __DIR__ . '/includes/partials/product_card.php'; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =================== BEST SELLERS =================== -->
<?php if ($showBestSellers && !empty($bestSellers)): ?>
  <section class="section-py pt-4" aria-label="Best Sellers">
    <div class="container">
      <div class="section-header-wrap">
        <div>
          <h2 class="section-header-title">Best Sellers</h2>
        </div>
        <a href="<?= e(route('shop', ['sort' => 'best_selling'])) ?>" class="section-header-link">
          View all <?= icon('arrow-right') ?>
        </a>
      </div>

      <div class="product-grid">
        <?php foreach ($bestSellers as $p): ?>
          <?php include __DIR__ . '/includes/partials/product_card.php'; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =================== TRUST BAR =================== -->
<section class="trust-bar my-6" aria-label="Customer Commitments">
  <div class="container">
    <div class="row g-4">
      <div class="col-6 col-lg-3">
        <div class="trust-item">
          <div class="trust-icon"><?= icon('shield') ?></div>
          <div>
            <div class="trust-title"><?= e(setting('trust_item_1_title', 'Nationwide COD')) ?></div>
            <div class="trust-desc"><?= e(setting('trust_item_1_desc', 'Cash on delivery across all 64 districts')) ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="trust-item">
          <div class="trust-icon"><?= icon('truck') ?></div>
          <div>
            <div class="trust-title"><?= e(setting('trust_item_2_title', 'Fast Dispatch')) ?></div>
            <div class="trust-desc"><?= e(setting('trust_item_2_desc', '24h in Dhaka, 48-72h outside')) ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="trust-item">
          <div class="trust-icon"><?= icon('check-circle') ?></div>
          <div>
            <div class="trust-title"><?= e(setting('trust_item_3_title', '100% Authentic')) ?></div>
            <div class="trust-desc"><?= e(setting('trust_item_3_desc', 'Guaranteed genuine products direct from makers')) ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="trust-item">
          <div class="trust-icon"><?= icon('rotate-ccw') ?></div>
          <div>
            <div class="trust-title"><?= e(setting('trust_item_4_title', 'Dedicated Support')) ?></div>
            <div class="trust-desc"><?= e(setting('trust_item_4_desc', 'Friendly phone & WhatsApp assistance')) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- =================== CUSTOMER REVIEWS STRIP =================== -->
<?php if ($showReviews && !empty($homeReviews)): ?>
  <section class="section-py pt-0" aria-label="Customer Reviews">
    <div class="container">
      <div class="section-header-wrap">
        <div>
          <h2 class="section-header-title">Loved by Our Community</h2>
        </div>
      </div>

      <div class="row g-4">
        <?php foreach ($homeReviews as $hr): ?>
          <div class="col-12 col-md-6 col-lg-3">
            <div class="card card-raised h-100 p-5" style="border-radius: var(--radius-lg); background-color: var(--surface-card);">
              <div class="rating-stars mb-3" aria-hidden="true" style="color: #f59e0b;">
                <?php for ($i = 1; $i <= (int)$hr['rating']; $i++): ?>
                  <?= icon('star') ?>
                <?php endfor; ?>
              </div>
              <h4 class="fs-base mb-2 fw-semibold" style="letter-spacing: -0.01em;">"<?= e($hr['title']) ?>"</h4>
              <p class="small text-muted mb-4" style="line-height: 1.55;">
                <?= e(mb_substr($hr['body'], 0, 110)) ?><?= mb_strlen($hr['body']) > 110 ? '...' : '' ?>
              </p>
              <div class="mt-auto d-flex align-items-center justify-content-between border-top pt-3">
                <span class="small fw-semibold text-ink"><?= e($hr['author_name']) ?></span>
                <a href="<?= e(route('product', ['slug' => $hr['product_slug']])) ?>" class="small text-muted text-truncate" style="max-width: 120px;" title="<?= e($hr['product_name']) ?>">
                  <?= e($hr['product_name']) ?>
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


