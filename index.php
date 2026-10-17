<?php
/**
 * Storefront Home Page (Prompt 04).
 * Clean, editorial, restrained design.
 * Toggled sections via home_show_* admin settings.
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

$heroBanner = null;
if ($showHero) {
    $heroBanners = storefront_banners_get('hero');
    $heroBanner  = $heroBanners[0] ?? null;
}

$pdo = db();

// Categories
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

// New Arrivals
$newArrivals = [];
if ($showNewArrivals) {
    try {
        $newArrivals = $pdo->query(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.status = 'active'
             ORDER BY p.created_at DESC
             LIMIT 8"
        )->fetchAll();
    } catch (Throwable $e) { $newArrivals = []; }
}

// Best Sellers
$bestSellers = [];
if ($showBestSellers) {
    try {
        $bestSellers = $pdo->query(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.status = 'active'
             ORDER BY p.sold_count DESC, p.created_at DESC
             LIMIT 8"
        )->fetchAll();
    } catch (Throwable $e) { $bestSellers = []; }
}

// Featured Products
$featured = [];
if ($showFeatured) {
    try {
        $featured = $pdo->query(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.status = 'active' AND p.featured = 1
             ORDER BY p.created_at DESC
             LIMIT 8"
        )->fetchAll();
    } catch (Throwable $e) { $featured = []; }
}

// Batch attach hover images and swatches to eliminate N+1 queries
if (!empty($newArrivals)) products_attach_card_data($newArrivals);
if (!empty($bestSellers)) products_attach_card_data($bestSellers);
if (!empty($featured))    products_attach_card_data($featured);

include __DIR__ . '/includes/shop_header.php';
?>

<!-- =================== HERO SECTION =================== -->
<?php if ($showHero): ?>
  <?php if ($heroBanner && !empty($heroBanner['image'])): ?>
    <section class="hero-editorial">
      <div class="hero-banner-wrap">
        <img src="<?= e(UPLOADS_URL . 'banners/' . $heroBanner['image']) ?>"
             alt="<?= e($heroBanner['title']) ?>"
             class="hero-banner-img"
             loading="eager">
        <div class="hero-overlay"></div>
        <div class="container">
          <div class="hero-content hero-content-light">
            <span class="section-eyebrow text-white-50">Curated Collection</span>
            <h1 class="hero-headline"><?= e($heroBanner['title']) ?></h1>
            <?php if (!empty($heroBanner['subtitle'])): ?>
              <p class="hero-sub"><?= e($heroBanner['subtitle']) ?></p>
            <?php endif; ?>
            <?php if (!empty($heroBanner['button_text']) && !empty($heroBanner['button_url'])): ?>
              <a href="<?= e($heroBanner['button_url']) ?>" class="btn btn-editorial-primary px-4 py-3">
                <?= e($heroBanner['button_text']) ?> <i class="bi bi-arrow-right ms-1"></i>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  <?php else: ?>
    <!-- Fallback Editorial Minimal Text Hero -->
    <section class="hero-editorial py-5">
      <div class="container">
        <div class="hero-fallback">
          <span class="section-eyebrow">Minimal &amp; Timeless</span>
          <h1>Quality essentials, crafted for modern life.</h1>
          <p class="lead text-muted mb-4">
            Thoughtfully designed products made with enduring materials, honest pricing, and fast nationwide delivery.
          </p>
          <div class="d-flex justify-content-center gap-3">
            <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary">
              Shop All Products <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <a href="<?= e(shop_url('shop.php?featured=1')) ?>" class="btn btn-editorial-secondary">
              View Featured
            </a>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>
<?php endif; ?>

<!-- =================== TRUST ROW =================== -->
<section class="trust-strip">
  <div class="container">
    <div class="row g-4 justify-content-between">
      <div class="col-md-4">
        <div class="trust-item">
          <div class="trust-icon"><i class="bi bi-cash-stack"></i></div>
          <div>
            <div class="trust-label">Cash on Delivery</div>
            <p class="trust-sub">Inspect and pay at your doorstep nationwide.</p>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="trust-item">
          <div class="trust-icon"><i class="bi bi-truck"></i></div>
          <div>
            <div class="trust-label">Fast Tracked Delivery</div>
            <p class="trust-sub">Estimated delivery: <?= e(shipping_delivery_estimate()) ?>.</p>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="trust-item">
          <div class="trust-icon"><i class="bi bi-arrow-counterclockwise"></i></div>
          <div>
            <div class="trust-label">Easy Returns &amp; Support</div>
            <p class="trust-sub">Dedicated customer support for all orders.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- =================== CATEGORIES TILES =================== -->
<?php if ($showCategories && !empty($categories)): ?>
  <section class="section">
    <div class="container">
      <div class="section-header d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div>
          <span class="section-eyebrow">Explore</span>
          <h2 class="section-title">Shop by Category</h2>
        </div>
        <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-secondary btn-sm">
          Browse All <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>

      <div class="category-tiles-grid">
        <?php foreach ($categories as $cat): ?>
          <a href="<?= e(shop_url('category.php?slug=' . urlencode($cat['slug']))) ?>" class="category-tile">
            <?php
            $catImg = !empty($cat['image'])
              ? UPLOADS_URL . 'categories/' . $cat['image']
              : 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=600&q=80';
            ?>
            <img src="<?= e($catImg) ?>" alt="<?= e($cat['name']) ?>" loading="lazy">
            <div class="category-tile-overlay"></div>
            <div class="category-tile-info">
              <div class="category-tile-name"><?= e($cat['name']) ?></div>
              <div class="category-tile-count"><?= (int)$cat['product_count'] ?> Products</div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =================== NEW ARRIVALS =================== -->
<?php if ($showNewArrivals && !empty($newArrivals)): ?>
  <section class="section pt-0">
    <div class="container">
      <div class="section-header d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div>
          <span class="section-eyebrow">Just Landed</span>
          <h2 class="section-title">New Arrivals</h2>
          <p class="section-sub">Fresh drops added to the catalog this week.</p>
        </div>
        <a href="<?= e(shop_url('shop.php?sort=newest')) ?>" class="btn btn-editorial-secondary btn-sm">
          See All New <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>

      <div class="row g-4 row-cols-2 row-cols-md-3 row-cols-lg-4">
        <?php foreach ($newArrivals as $p): ?>
          <div class="col">
            <?php include __DIR__ . '/_product_card.php'; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =================== BEST SELLERS =================== -->
<?php if ($showBestSellers && !empty($bestSellers)): ?>
  <section class="section pt-0">
    <div class="container">
      <div class="section-header d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div>
          <span class="section-eyebrow">Customer Favorites</span>
          <h2 class="section-title">Best Sellers</h2>
          <p class="section-sub">Our most ordered and highest rated essentials.</p>
        </div>
        <a href="<?= e(shop_url('shop.php?sort=best_selling')) ?>" class="btn btn-editorial-secondary btn-sm">
          View All <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>

      <div class="row g-4 row-cols-2 row-cols-md-3 row-cols-lg-4">
        <?php foreach ($bestSellers as $p): ?>
          <div class="col">
            <?php include __DIR__ . '/_product_card.php'; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =================== FEATURED PRODUCTS =================== -->
<?php if ($showFeatured && !empty($featured)): ?>
  <section class="section pt-0">
    <div class="container">
      <div class="section-header d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div>
          <span class="section-eyebrow">Hand Picked</span>
          <h2 class="section-title">Featured Highlights</h2>
        </div>
        <a href="<?= e(shop_url('shop.php?featured=1')) ?>" class="btn btn-editorial-secondary btn-sm">
          View All Featured <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>

      <div class="row g-4 row-cols-2 row-cols-md-3 row-cols-lg-4">
        <?php foreach ($featured as $p): ?>
          <div class="col">
            <?php include __DIR__ . '/_product_card.php'; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =================== ABOUT STRIP =================== -->
<section class="section bg-light border-top" id="about">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <span class="section-eyebrow">Our Philosophy</span>
        <h2 class="section-title">Designed for longevity, not landfill.</h2>
        <p class="text-muted mb-4" style="line-height: 1.7;">
          At <?= e($siteName) ?>, we believe shopping should be deliberate and transparent. We work with responsible makers to deliver durable, timeless products with zero unnecessary markups and straightforward Cash on Delivery.
        </p>
        <div class="d-flex gap-3">
          <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary">
            Explore Collection
          </a>
          <a href="<?= e(shop_url('track.php')) ?>" class="btn btn-editorial-secondary">
            Track Existing Order
          </a>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="p-4 p-md-5 bg-white border rounded">
          <h4 class="fw-semibold mb-3">Straightforward Shopping</h4>
          <ul class="list-unstyled mb-0 d-flex flex-column gap-3 small text-muted">
            <li class="d-flex align-items-start gap-2">
              <i class="bi bi-check-circle-fill text-success mt-1"></i>
              <span><strong>No prepayment required:</strong> Order with confidence using Cash on Delivery across the country.</span>
            </li>
            <li class="d-flex align-items-start gap-2">
              <i class="bi bi-check-circle-fill text-success mt-1"></i>
              <span><strong>Real-time availability:</strong> Every size and color variation reflects live inventory so we never oversell.</span>
            </li>
            <li class="d-flex align-items-start gap-2">
              <i class="bi bi-check-circle-fill text-success mt-1"></i>
              <span><strong>Instant order tracking:</strong> Check live parcel and dispatch milestones with just your phone number.</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
