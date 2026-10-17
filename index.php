<?php
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
$showBrandStory  = (int)setting('home_show_brand_story', '1') === 1;

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

// 2. New Arrivals
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

// 3. Best Sellers
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

// 4. Featured Products
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

// 5. Customer Reviews
$homeReviews = [];
if ($showReviews) {
    try {
        $homeReviews = $pdo->query(
            "SELECT r.*, p.name AS product_name, p.slug AS product_slug, p.image AS product_image
             FROM product_reviews r
             JOIN products p ON p.id = r.product_id
             WHERE r.status = 'approved' AND r.rating >= 4
             ORDER BY r.id DESC
             LIMIT 4"
        )->fetchAll();
    } catch (Throwable $e) { $homeReviews = []; }
}

// Batch attach hover images and swatches to eliminate N+1 queries
if (!empty($newArrivals)) products_attach_card_data($newArrivals);
if (!empty($bestSellers)) products_attach_card_data($bestSellers);
if (!empty($featured))    products_attach_card_data($featured);

include __DIR__ . '/includes/partials/header.php';
?>

<!-- =================== HERO SECTION =================== -->
<?php if ($showHero): ?>
  <div class="container">
    <?php if ($heroBanner && !empty($heroBanner['image'])): ?>
      <section class="hero-section hero-editorial" aria-label="Featured Promotion">
        <div class="hero-slide" style="background-image: linear-gradient(to right, rgba(0,0,0,0.65) 0%, rgba(0,0,0,0.2) 60%), url('<?= e(UPLOADS_URL . 'banners/' . $heroBanner['image']) ?>'); background-size: cover; background-position: center; border-radius: var(--radius-lg);">
          <div class="hero-content text-white">
            <span class="badge badge-new mb-3">Curated Collection</span>
            <h1 class="hero-headline display-serif text-white"><?= e($heroBanner['title']) ?></h1>
            <?php if (!empty($heroBanner['subtitle'])): ?>
              <p class="hero-text text-white opacity-75"><?= e($heroBanner['subtitle']) ?></p>
            <?php endif; ?>
            <?php if (!empty($heroBanner['button_text']) && !empty($heroBanner['button_url'])): ?>
              <a href="<?= e($heroBanner['button_url']) ?>" class="btn btn-primary btn-lg">
                <?= e($heroBanner['button_text']) ?> <?= icon('arrow-right') ?>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </section>
    <?php else: ?>
      <!-- Minimalist Fallback Hero -->
      <section class="hero-section p-6 p-md-8 text-center" aria-label="Store Welcome">
        <div class="hero-content mx-auto" style="max-width: 680px;">
          <span class="badge badge-new mb-3">Everyday Essentials</span>
          <h1 class="hero-headline display-serif mb-3">Thoughtfully made. Delivered nationwide.</h1>
          <p class="hero-text mb-6">
            Curated essentials designed with enduring materials, honest pricing, and transparent Cash on Delivery.
          </p>
          <div class="d-flex justify-content-center gap-3">
            <a href="<?= e(route('shop')) ?>" class="btn btn-primary btn-lg">
              Explore Shop <?= icon('arrow-right') ?>
            </a>
            <a href="<?= e(route('shop', ['featured' => '1'])) ?>" class="btn btn-secondary btn-lg">
              Featured Items
            </a>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- =================== TRUST BAR =================== -->
<section class="trust-bar" aria-label="Customer Commitments">
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

<!-- =================== CATEGORIES TILES =================== -->
<?php if ($showCategories && !empty($categories)): ?>
  <section class="section-py" aria-label="Categories">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-6">
        <div>
          <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Curated Collections</span>
          <h2 class="display-serif mt-1">Shop by Category</h2>
        </div>
        <a href="<?= e(route('shop')) ?>" class="btn btn-secondary btn-sm">
          Browse All <?= icon('arrow-right') ?>
        </a>
      </div>

      <div class="row g-4">
        <?php foreach ($categories as $cat): ?>
          <div class="col-6 col-lg-3">
            <a href="<?= e(route('category', ['slug' => $cat['slug']])) ?>" class="category-tile">
              <?php if (!empty($cat['image']) && file_exists(UPLOADS_PATH . '/categories/' . $cat['image'])): ?>
                <img src="<?= e(UPLOADS_URL . 'categories/' . $cat['image']) ?>" alt="<?= e($cat['name']) ?>" loading="lazy">
              <?php else: ?>
                <div class="w-100 h-100" style="background: linear-gradient(135deg, var(--bg-soft) 0%, var(--line) 100%);"></div>
              <?php endif; ?>
              <div class="category-tile-overlay"></div>
              <div class="category-tile-title">
                <?= e($cat['name']) ?>
                <div class="small fw-normal opacity-75" style="font-size: 11px;"><?= (int)$cat['product_count'] ?> products</div>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =================== NEW ARRIVALS =================== -->
<?php if ($showNewArrivals && !empty($newArrivals)): ?>
  <section class="section-py pt-0" aria-label="New Arrivals">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-6">
        <div>
          <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Just Landed</span>
          <h2 class="display-serif mt-1">New Arrivals</h2>
        </div>
        <a href="<?= e(route('shop', ['sort' => 'newest'])) ?>" class="btn btn-secondary btn-sm">
          See All New <?= icon('arrow-right') ?>
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
  <section class="section-py pt-0" aria-label="Best Sellers">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-6">
        <div>
          <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Customer Favorites</span>
          <h2 class="display-serif mt-1">Best Sellers</h2>
        </div>
        <a href="<?= e(route('shop', ['sort' => 'best_selling'])) ?>" class="btn btn-secondary btn-sm">
          View All <?= icon('arrow-right') ?>
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

<!-- =================== FEATURED PRODUCTS =================== -->
<?php if ($showFeatured && !empty($featured)): ?>
  <section class="section-py pt-0" aria-label="Featured Highlights">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-6">
        <div>
          <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Hand Picked</span>
          <h2 class="display-serif mt-1">Featured Highlights</h2>
        </div>
        <a href="<?= e(route('shop', ['featured' => '1'])) ?>" class="btn btn-secondary btn-sm">
          View All Featured <?= icon('arrow-right') ?>
        </a>
      </div>

      <div class="product-grid">
        <?php foreach ($featured as $p): ?>
          <?php include __DIR__ . '/includes/partials/product_card.php'; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- =================== CUSTOMER REVIEWS STRIP =================== -->
<?php if ($showReviews && !empty($homeReviews)): ?>
  <section class="section-py pt-0" aria-label="Customer Reviews">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-6">
        <div>
          <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Real Feedback</span>
          <h2 class="display-serif mt-1">Loved by Our Customers</h2>
        </div>
      </div>

      <div class="row g-4">
        <?php foreach ($homeReviews as $hr): ?>
          <div class="col-12 col-md-6 col-lg-3">
            <div class="card card-raised h-100">
              <div class="rating-stars mb-2" aria-hidden="true">
                <?php for ($i = 1; $i <= (int)$hr['rating']; $i++): ?>
                  <?= icon('star') ?>
                <?php endfor; ?>
              </div>
              <h4 class="fs-base mb-2 fw-semibold">"<?= e($hr['title']) ?>"</h4>
              <p class="small text-muted mb-4" style="line-height: 1.5;">
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

<!-- =================== BRAND STORY SECTION =================== -->
<?php if ($showBrandStory): ?>
  <section class="section-py bg-soft border-top" aria-label="Brand Philosophy">
    <div class="container">
      <div class="row align-items-center g-6">
        <div class="col-lg-6">
          <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Our Philosophy</span>
          <h2 class="display-serif mt-1 mb-4"><?= e(setting('brand_story_title', 'Crafted with Intention')) ?></h2>
          <p class="text-ink-2 mb-6" style="line-height: 1.8;">
            <?= nl2br(e(setting('brand_story_text', 'We believe everyday objects should be both functional and beautiful. Every piece in our collection is curated with care and designed to last.'))) ?>
          </p>
          <div class="d-flex gap-3">
            <a href="<?= e(route('shop')) ?>" class="btn btn-primary">
              Explore Collection
            </a>
            <a href="<?= e(route('track')) ?>" class="btn btn-secondary">
              Track Existing Order
            </a>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card p-6" style="background: var(--surface);">
            <h3 class="mb-4">Why shop with us?</h3>
            <ul class="d-flex flex-column gap-3 small text-ink-2 mb-0">
              <li class="d-flex align-items-start gap-2">
                <span class="text-ok"><?= icon('check-circle') ?></span>
                <span><strong>Zero prepayment required:</strong> Order with confidence using Cash on Delivery across all 64 districts.</span>
              </li>
              <li class="d-flex align-items-start gap-2">
                <span class="text-ok"><?= icon('check-circle') ?></span>
                <span><strong>Real-time availability:</strong> Every size and color variation reflects live inventory so we never oversell.</span>
              </li>
              <li class="d-flex align-items-start gap-2">
                <span class="text-ok"><?= icon('check-circle') ?></span>
                <span><strong>Instant order tracking:</strong> Check parcel dispatch and delivery milestones 24/7 with just your order ID.</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php include __DIR__ . '/includes/partials/footer.php'; ?>
