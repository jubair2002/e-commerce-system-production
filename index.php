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

$heroBanners = [];
if ($showHero) {
    $heroBanners = storefront_banners_get('hero');
}
$heroPreloadUrl = !empty($heroBanners[0]['image']) ? banner_image_url($heroBanners[0]['image']) : null;

$pdo = db();

// 1. Categories (cached 5 min for faster load)
$categories = [];
if ($showCategories) {
    try {
        $categories = FileCache::remember('home:categories:4', 300, function () use ($pdo) {
            return $pdo->query(
                "SELECT c.id, c.name, c.slug, c.image,
                        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = 'active') AS product_count
                 FROM categories c
                 WHERE c.status = 'active'
                 ORDER BY product_count DESC, c.name ASC
                 LIMIT 4"
            )->fetchAll() ?: [];
        });
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

// 6. Hero Marquee Ticker Products (deduplicated, single appearance per product)
$heroTickerProducts = array_merge($featured, $newArrivals, $bestSellers);
$tickerSeen = [];
$tickerUnique = [];
foreach ($heroTickerProducts as $tp) {
    if (!isset($tickerSeen[$tp['id']])) {
        $tickerSeen[$tp['id']] = true;
        $tickerUnique[] = $tp;
        if (count($tickerUnique) >= 16) break;
    }
}
$tickerTrack = $tickerUnique;

include __DIR__ . '/includes/partials/header.php';
?>

<!-- =================== PREMIER EDITORIAL HERO BANNER SYSTEM =================== -->
<?php if ($showHero): ?>
  <style>
  .hero-editorial-viewport {
    position: relative;
    max-width: 1440px;
    margin: 0.75rem auto 2.5rem;
    padding: 0 1rem;
  }
  @media (max-width: 768px) {
    .hero-editorial-viewport {
      padding: 0 0.5rem;
      margin: 0.25rem auto 2rem;
    }
  }
  .hero-editorial-card {
    position: relative;
    border-radius: 28px;
    overflow: hidden;
    min-height: 560px;
    height: clamp(540px, 68vh, 660px);
    background-color: #080d1a;
    box-shadow: 0 24px 64px -16px rgba(11, 17, 32, 0.35), 0 4px 16px -2px rgba(11, 17, 32, 0.15);
    display: flex;
    align-items: center;
  }
  @media (max-width: 768px) {
    .hero-editorial-card {
      border-radius: 20px;
      min-height: 500px;
      height: 70vh;
    }
  }

  /* Slide Background & Media */
  .hero-slide {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.85s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.85s;
    z-index: 1;
    overflow: hidden;
  }
  .hero-slide.is-active {
    opacity: 1;
    visibility: visible;
    z-index: 2;
  }
  .hero-slide-media {
    position: absolute;
    inset: -20px;
    background-position: center center;
    background-size: cover;
    background-repeat: no-repeat;
    transform: scale(1.0);
    transition: transform 6s cubic-bezier(0.25, 1, 0.5, 1);
    will-change: transform;
  }
  .hero-slide.is-active .hero-slide-media {
    transform: scale(1.06);
  }

  /* Cinematic Scrim & Lighting */
  .hero-slide-scrim {
    position: absolute;
    inset: 0;
    background:
      linear-gradient(90deg, rgba(8, 13, 26, 0.88) 0%, rgba(8, 13, 26, 0.65) 45%, rgba(8, 13, 26, 0.25) 85%, rgba(8, 13, 26, 0.15) 100%),
      linear-gradient(180deg, rgba(8, 13, 26, 0.2) 0%, rgba(8, 13, 26, 0.3) 50%, rgba(8, 13, 26, 0.85) 100%);
    pointer-events: none;
    z-index: 2;
  }
  @media (max-width: 768px) {
    .hero-slide-scrim {
      background:
        linear-gradient(180deg, rgba(8, 13, 26, 0.4) 0%, rgba(8, 13, 26, 0.75) 45%, rgba(8, 13, 26, 0.94) 100%);
    }
  }

  /* Editorial Content Layout */
  .hero-slide-inner {
    position: relative;
    z-index: 3;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    padding: 3.5rem 4rem 5.5rem;
  }
  @media (max-width: 768px) {
    .hero-slide-inner {
      padding: 2.5rem 1.5rem 5rem;
      align-items: flex-end;
    }
  }
  .hero-slide-body {
    max-width: 720px;
  }

  /* Kicker Capsule Badge */
  .hero-kicker-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.14);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.22);
    color: #f1f5f9;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    margin-bottom: 1.25rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    opacity: 0;
    transform: translateY(16px);
    transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .hero-kicker-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #38bdf8;
    box-shadow: 0 0 10px #38bdf8;
    display: inline-block;
    animation: heroDotPulse 2s infinite ease-in-out;
  }
  @keyframes heroDotPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.4; transform: scale(0.85); }
  }

  /* Headline & Description */
  .hero-headline {
    color: #ffffff !important;
    font-size: clamp(2.2rem, 4.4vw, 3.8rem);
    font-weight: 800;
    line-height: 1.08;
    letter-spacing: -0.035em;
    margin-bottom: 1.2rem;
    text-shadow: 0 2px 14px rgba(0, 0, 0, 0.45);
    text-wrap: balance;
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.1s, transform 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.1s;
  }
  .hero-description {
    color: rgba(255, 255, 255, 0.88) !important;
    font-size: clamp(1rem, 1.2vw, 1.18rem);
    line-height: 1.65;
    max-width: 600px;
    margin-bottom: 2rem;
    text-shadow: 0 1px 8px rgba(0, 0, 0, 0.4);
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.2s, transform 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.2s;
  }

  /* Actions Group */
  .hero-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.3s, transform 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.3s;
  }
  .hero-btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 13px 28px;
    border-radius: 9999px;
    background: #ffffff;
    color: #0b1120 !important;
    font-weight: 700;
    font-size: 0.95rem;
    text-decoration: none !important;
    box-shadow: 0 10px 24px -4px rgba(255, 255, 255, 0.3);
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .hero-btn-primary:hover {
    background: #f1f5f9;
    color: #000000 !important;
    transform: translateY(-2px);
    box-shadow: 0 14px 28px -4px rgba(255, 255, 255, 0.4);
  }
  .hero-btn-primary svg {
    transition: transform 0.25s ease;
  }
  .hero-btn-primary:hover svg {
    transform: translateX(4px);
  }
  .hero-btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 13px 24px;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid rgba(255, 255, 255, 0.28);
    color: #ffffff !important;
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none !important;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .hero-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.22);
    border-color: rgba(255, 255, 255, 0.5);
    color: #ffffff !important;
    transform: translateY(-2px);
  }

  /* Active Slide Trigger Animations */
  .hero-slide.is-active .hero-kicker-badge,
  .hero-slide.is-active .hero-headline,
  .hero-slide.is-active .hero-description,
  .hero-slide.is-active .hero-actions {
    opacity: 1;
    transform: translateY(0);
  }

  /* Story-Style Segmented Progress Navigation (Centered & Minimalist) */
  .hero-bottom-bar {
    position: absolute;
    bottom: 24px;
    left: 0;
    right: 0;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
  }
  @media (max-width: 768px) {
    .hero-bottom-bar {
      bottom: 16px;
    }
  }
  .hero-progress-segments {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    max-width: 300px;
    padding: 0 1rem;
    pointer-events: auto;
  }
  .hero-progress-bar {
    position: relative;
    height: 4px;
    flex: 1;
    background: rgba(255, 255, 255, 0.28);
    border-radius: 9999px;
    overflow: hidden;
    cursor: pointer;
    transition: background 0.2s, height 0.2s;
  }
  .hero-progress-bar:hover {
    height: 6px;
    background: rgba(255, 255, 255, 0.45);
  }
  .hero-progress-fill {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    width: 0%;
    background: #ffffff;
    border-radius: 9999px;
    box-shadow: 0 0 10px rgba(255, 255, 255, 0.85);
  }
  .hero-progress-bar.is-completed .hero-progress-fill {
    width: 100% !important;
  }
  </style>

  <?php if (!empty($heroBanners)): ?>
    <section class="hero-editorial-viewport" id="heroEditorialSlider" aria-label="Store Showcase">
      <div class="hero-editorial-card">
        <?php foreach ($heroBanners as $idx => $b):
          $bgUrl = !empty($b['image']) ? banner_image_url($b['image']) : '';
          $bTitle = !empty($b['title']) ? $b['title'] : "Made by {$siteName}. Inspired by you.";
          $bDesc = !empty($b['subtitle']) ? $b['subtitle'] : setting('store_tagline', 'Curated designer apparel, technical gear, and everyday essentials crafted with intention.');
          $bBtnText = !empty($b['button_text']) ? $b['button_text'] : 'Shop all products';
          $bBtnUrl = !empty($b['button_url']) ? $b['button_url'] : route('shop');
          $kickerTag = $idx === 0 ? 'Featured Editorial' : ($idx === 1 ? 'Technical Innovation' : 'Artisanal Craft');
        ?>
          <div class="hero-slide <?= $idx === 0 ? 'is-active' : '' ?>" data-slide-index="<?= $idx ?>">
            <div class="hero-slide-media" style="<?= $bgUrl ? "background-image: url('" . e($bgUrl) . "');" : '' ?>"></div>
            <div class="hero-slide-scrim"></div>
            <div class="hero-slide-inner">
              <div class="hero-slide-body">
                <div class="hero-kicker-badge">
                  <span class="hero-kicker-dot"></span>
                  <span><?= e($kickerTag) ?></span>
                </div>
                <h1 class="hero-headline"><?= e($bTitle) ?></h1>
                <?php if ($bDesc !== ''): ?>
                  <p class="hero-description"><?= e($bDesc) ?></p>
                <?php endif; ?>
                <div class="hero-actions">
                  <a href="<?= e($bBtnUrl) ?>" class="hero-btn-primary">
                    <span><?= e($bBtnText) ?></span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                  </a>
                  <a href="<?= e(route('shop')) ?>" class="hero-btn-secondary">
                    <span>Explore Lookbook</span>
                  </a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>

        <!-- Bottom Segmented Story Progress Navigation (Clean & Minimalist) -->
        <?php if (count($heroBanners) > 1): ?>
          <div class="hero-bottom-bar">
            <div class="hero-progress-segments" id="heroProgressSegments" role="tablist" aria-label="Banner slides">
              <?php foreach ($heroBanners as $idx => $b): ?>
                <div class="hero-progress-bar <?= $idx === 0 ? 'is-active' : '' ?>" data-slide-target="<?= $idx ?>" role="tab" aria-label="Slide <?= $idx + 1 ?>">
                  <div class="hero-progress-fill"></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if (count($heroBanners) > 1): ?>
      <script>
      (function() {
        const root = document.getElementById('heroEditorialSlider');
        if (!root) return;

        const slides = root.querySelectorAll('.hero-slide');
        const progressBars = root.querySelectorAll('.hero-progress-bar');

        if (slides.length <= 1) return;

        let currentIndex = 0;
        const totalSlides = slides.length;
        const DURATION = 3000; // 3000ms = 3.0s auto swap
        let startTime = null;
        let animationFrameId = null;
        let isPaused = false;
        let elapsedBeforePause = 0;

        function setSlide(newIndex) {
          if (newIndex === currentIndex && slides[currentIndex].classList.contains('is-active')) {
            return;
          }

          // Reset progress fills
          progressBars.forEach((bar, idx) => {
            const fill = bar.querySelector('.hero-progress-fill');
            if (idx < newIndex) {
              bar.classList.add('is-completed');
              bar.classList.remove('is-active');
              if (fill) fill.style.width = '100%';
            } else if (idx === newIndex) {
              bar.classList.remove('is-completed');
              bar.classList.add('is-active');
              if (fill) fill.style.width = '0%';
            } else {
              bar.classList.remove('is-completed');
              bar.classList.remove('is-active');
              if (fill) fill.style.width = '0%';
            }
          });

          // Transition slide
          slides[currentIndex].classList.remove('is-active');
          currentIndex = (newIndex + totalSlides) % totalSlides;
          slides[currentIndex].classList.add('is-active');

          resetAndStartTimer();
        }

        function animateProgress(timestamp) {
          if (isPaused) return;

          if (!startTime) startTime = timestamp - elapsedBeforePause;
          const elapsed = timestamp - startTime;
          const progress = Math.min(elapsed / DURATION, 1.0);

          const currentBar = progressBars[currentIndex];
          if (currentBar) {
            const fill = currentBar.querySelector('.hero-progress-fill');
            if (fill) {
              fill.style.width = (progress * 100) + '%';
            }
          }

          if (progress < 1.0) {
            animationFrameId = requestAnimationFrame(animateProgress);
          } else {
            // Move to next slide after 3 seconds
            const nextIndex = (currentIndex + 1) % totalSlides;
            setSlide(nextIndex);
          }
        }

        function resetAndStartTimer() {
          if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
            animationFrameId = null;
          }
          startTime = null;
          elapsedBeforePause = 0;
          if (!isPaused) {
            animationFrameId = requestAnimationFrame(animateProgress);
          }
        }

        function pauseTimer() {
          if (isPaused) return;
          isPaused = true;
          if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
            animationFrameId = null;
          }
          if (startTime) {
            elapsedBeforePause = performance.now() - startTime;
          }
        }

        function resumeTimer() {
          if (!isPaused) return;
          isPaused = false;
          startTime = null;
          animationFrameId = requestAnimationFrame(animateProgress);
        }

        // Initialize progress bar fills
        progressBars.forEach((bar, idx) => {
          const fill = bar.querySelector('.hero-progress-fill');
          if (idx < currentIndex) {
            bar.classList.add('is-completed');
            if (fill) fill.style.width = '100%';
          } else if (idx === currentIndex) {
            bar.classList.add('is-active');
            if (fill) fill.style.width = '0%';
          }
        });

        // Click on progress bar segments to jump directly
        progressBars.forEach((bar) => {
          bar.addEventListener('click', function(e) {
            e.preventDefault();
            const target = parseInt(this.getAttribute('data-slide-target'), 10);
            if (!isNaN(target)) {
              setSlide(target);
            }
          });
        });

        // Hover pause
        root.addEventListener('mouseenter', pauseTimer);
        root.addEventListener('mouseleave', resumeTimer);

        // Keyboard navigation
        root.setAttribute('tabindex', '0');
        root.addEventListener('keydown', function(e) {
          if (e.key === 'ArrowLeft') {
            const target = (currentIndex - 1 + totalSlides) % totalSlides;
            setSlide(target);
          } else if (e.key === 'ArrowRight') {
            const target = (currentIndex + 1) % totalSlides;
            setSlide(target);
          }
        });

        // Touch Swipe Support
        let touchStartX = 0;
        let touchEndX = 0;
        root.addEventListener('touchstart', function(e) {
          touchStartX = e.changedTouches[0].screenX;
          pauseTimer();
        }, { passive: true });
        root.addEventListener('touchend', function(e) {
          touchEndX = e.changedTouches[0].screenX;
          const diff = touchStartX - touchEndX;
          if (Math.abs(diff) > 40) {
            if (diff > 0) {
              setSlide((currentIndex + 1) % totalSlides); // Swiped left -> next
            } else {
              setSlide((currentIndex - 1 + totalSlides) % totalSlides); // Swiped right -> prev
            }
          }
          resumeTimer();
        }, { passive: true });

        // Start 3-second cycle
        resetAndStartTimer();
      })();
      </script>
    <?php endif; ?>
  <?php else: ?>
    <!-- Fallback default typography hero if no banners in DB -->
    <section class="hero-webflow mb-4" aria-label="Store Welcome">
      <div class="hero-webflow-glow"></div>
      <div class="container">
        <h1 class="hero-webflow-title"><?= e("Made by {$siteName}. Inspired by you.") ?></h1>
        <p class="hero-webflow-desc"><?= e(setting('store_tagline', 'Curated designer apparel, technical gear, and everyday essentials crafted with intention.')) ?></p>
        <a href="<?= e(route('shop')) ?>" class="hero-webflow-btn">
          Shop all products <?= icon('arrow-right') ?>
        </a>
      </div>
    </section>
  <?php endif; ?>

  <!-- Product Showcase Carousel with Clickable Controls -->
  <?php if (!empty($tickerTrack)): ?>
    <div class="container mb-4">
      <div class="hero-carousel-wrapper position-relative">
        <button type="button" class="hero-carousel-nav hero-carousel-prev" id="heroCarouselPrev" aria-label="Previous products" title="Previous products">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <div class="hero-marquee-wrap" id="heroProductCarousel" tabindex="0" role="region" aria-label="Featured Products Carousel">
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
        <button type="button" class="hero-carousel-nav hero-carousel-next" id="heroCarouselNext" aria-label="Next products" title="Next products">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
        </button>
      </div>
    </div>
  <?php endif; ?>
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


