<?php
require_once __DIR__ . '/shop_bootstrap.php';

$pageTitle = $pageTitle ?? '';
$siteName  = setting('site_name', APP_NAME);
$fullTitle = $pageTitle !== '' ? "$pageTitle · $siteName" : $siteName;
$cats      = shop_active_categories();
$current   = basename($_SERVER['PHP_SELF'] ?? '');

// Theme Accent Token from Settings (validate hex)
$themeAccent = setting('theme_accent_color', setting('theme_accent', '#111111'));
if (!preg_match('/^#[a-fA-F0-9]{3,8}$/', $themeAccent)) {
    $themeAccent = '#111111';
}

// Store Favicon
$storeFavicon = setting('store_favicon');

// Announcement Bar Settings
$announcementText    = trim((string)setting('announcement_bar_text', ''));
$announcementEnabled = (int)setting('announcement_bar_enabled', '0') === 1 && $announcementText !== '';

$cartCnt = cart_count();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($fullTitle) ?></title>
  <meta name="description" content="<?= e(setting('seo_default_description', setting('site_about', 'Quality essentials delivered to your door.'))) ?>">
  <?php if (!empty($storeFavicon)): ?>
    <link rel="icon" href="<?= e(UPLOADS_URL . 'branding/' . $storeFavicon) ?>">
  <?php endif; ?>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Bootstrap 5.3 Grid Utilities & Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <!-- Storefront Editorial Stylesheet -->
  <link rel="stylesheet" href="<?= e(url('assets/css/shop.css')) ?>">

  <!-- Dynamic Theme Accent Override -->
  <style>:root { --accent: <?= e($themeAccent) ?>; }</style>

  <script>window.SHOP_BASE_URL = "<?= e(BASE_URL) ?>";</script>
</head>
<body>


<!-- Announcement Bar -->
<?php if ($announcementEnabled): ?>
  <div class="announcement-bar" id="announcementBar" role="region" aria-label="Store Announcement">
    <div class="announcement-inner">
      <span><?= e($announcementText) ?></span>
    </div>
    <button type="button" class="announcement-close" id="announcementClose" aria-label="Dismiss announcement">
      <i class="bi bi-x"></i>
    </button>
  </div>
<?php endif; ?>

<!-- Site Header -->
<header class="site-header" role="banner">
  <div class="container">
    <div class="header-inner">

      <!-- Left: Brand / Logo -->
      <a href="<?= e(shop_url()) ?>" class="header-brand" aria-label="<?= e($siteName) ?>">
        <?php $storeLogo = setting('store_logo'); ?>
        <?php if (!empty($storeLogo) && (file_exists(UPLOADS_PATH . '/branding/' . $storeLogo) || file_exists(UPLOADS_PATH . '/store/' . $storeLogo))): ?>
          <img src="<?= e(file_exists(UPLOADS_PATH . '/branding/' . $storeLogo) ? UPLOADS_URL . 'branding/' . $storeLogo : UPLOADS_URL . 'store/' . $storeLogo) ?>" alt="<?= e($siteName) ?>" style="max-height: 40px;">
        <?php else: ?>
          <span><?= e($siteName) ?></span>
        <?php endif; ?>
      </a>

      <!-- Center: Navigation -->
      <nav class="d-none d-lg-block" role="navigation" aria-label="Main Navigation">
        <ul class="header-nav">
          <li>
            <a href="<?= e(shop_url()) ?>" class="nav-link <?= $current === 'index.php' ? 'active' : '' ?>">Home</a>
          </li>
          <li>
            <a href="<?= e(shop_url('shop.php')) ?>" class="nav-link <?= $current === 'shop.php' ? 'active' : '' ?>">Shop</a>
          </li>
          <?php if (!empty($cats)): ?>
            <li class="dropdown">
              <a href="#" class="nav-link dropdown-toggle <?= $current === 'category.php' ? 'active' : '' ?>" data-bs-toggle="dropdown" aria-expanded="false">
                Categories
              </a>
              <ul class="dropdown-menu shadow-sm border-0 py-2" style="border: 1px solid var(--line) !important; border-radius: var(--radius-md);">
                <?php foreach ($cats as $c): ?>
                  <li>
                    <a class="dropdown-item py-2 px-3 small" href="<?= e(shop_url('category.php?slug=' . urlencode($c['slug']))) ?>">
                      <?= e($c['name']) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php endif; ?>
          <li>
            <a href="<?= e(shop_url('track.php')) ?>" class="nav-link <?= $current === 'track.php' ? 'active' : '' ?>">Track Order</a>
          </li>
          <li>
            <a href="<?= e(shop_url('index.php#about')) ?>" class="nav-link">About</a>
          </li>
        </ul>
      </nav>

      <!-- Right: Search, Cart, Mobile Toggle -->
      <div class="header-actions">

        <!-- Search Trigger & Popover -->
        <div class="search-wrapper">
          <button type="button" class="action-btn" data-toggle-search aria-label="Search store">
            <i class="bi bi-search"></i>
          </button>
          <div class="search-expand-bar" id="searchExpandBar">
            <form action="<?= e(shop_url('shop.php')) ?>" method="get" class="search-input-group">
              <i class="bi bi-search"></i>
              <input type="text"
                     name="q"
                     id="headerSearchInput"
                     class="search-field"
                     placeholder="Search products..."
                     autocomplete="off"
                     value="<?= e($_GET['q'] ?? '') ?>">
            </form>
            <div class="search-suggestions-dropdown" id="searchSuggestionsDropdown"></div>
          </div>
        </div>

        <!-- Cart Trigger -->
        <button type="button" class="action-btn" data-cart-drawer-trigger aria-label="View shopping cart">
          <i class="bi bi-bag"></i>
          <span class="cart-badge-count" style="<?= $cartCnt > 0 ? '' : 'display:none;' ?>"><?= $cartCnt ?></span>
        </button>

        <!-- Mobile Nav Hamburger -->
        <button type="button" class="action-btn mobile-nav-toggle" data-bs-toggle="offcanvas" data-bs-target="#mobileNavOffcanvas" aria-controls="mobileNavOffcanvas" aria-label="Open menu">
          <i class="bi bi-list"></i>
        </button>

      </div>

    </div>
  </div>
</header>

<!-- Mobile Navigation Offcanvas -->
<div class="offcanvas offcanvas-start border-0" tabindex="-1" id="mobileNavOffcanvas" aria-labelledby="mobileNavLabel" style="max-width: 300px;">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title fw-bold" id="mobileNavLabel"><?= e($siteName) ?></h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body p-0">
    <ul class="list-group list-group-flush">
      <li class="list-group-item py-3"><a href="<?= e(shop_url()) ?>" class="text-reset d-block">Home</a></li>
      <li class="list-group-item py-3"><a href="<?= e(shop_url('shop.php')) ?>" class="text-reset d-block">All Products</a></li>
      <?php if (!empty($cats)): ?>
        <li class="list-group-item py-3">
          <div class="fw-semibold text-muted small text-uppercase mb-2">Categories</div>
          <div class="d-flex flex-column gap-2 ps-2">
            <?php foreach ($cats as $c): ?>
              <a href="<?= e(shop_url('category.php?slug=' . urlencode($c['slug']))) ?>" class="text-reset small">
                <?= e($c['name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </li>
      <?php endif; ?>
      <li class="list-group-item py-3"><a href="<?= e(shop_url('track.php')) ?>" class="text-reset d-block">Track Order</a></li>
      <li class="list-group-item py-3"><a href="<?= e(shop_url('index.php#about')) ?>" class="text-reset d-block">About Us</a></li>
    </ul>
  </div>
</div>

<!-- Mini-Cart Drawer Backdrop -->
<div class="mini-cart-backdrop" id="miniCartBackdrop"></div>

<!-- Mini-Cart Slide-out Drawer -->
<div class="mini-cart-drawer" id="miniCartDrawer" role="dialog" aria-modal="true" aria-labelledby="miniCartTitle">
  <div class="drawer-header">
    <h3 class="drawer-title" id="miniCartTitle">
      <i class="bi bi-bag"></i> Your Cart
    </h3>
    <button type="button" class="drawer-close-btn" data-cart-drawer-close aria-label="Close cart">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <div class="drawer-body" id="miniCartBody">
    <div class="text-center py-5">
      <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
    </div>
  </div>
  <div class="drawer-footer">
    <div class="drawer-subtotal-row">
      <span>Subtotal</span>
      <span class="drawer-subtotal-amount" id="miniCartSubtotal"><?= e(price(0)) ?></span>
    </div>
    <div class="drawer-actions">
      <a href="<?= e(shop_url('checkout.php')) ?>" class="btn btn-editorial-primary btn-full">
        Proceed to Checkout
      </a>
      <a href="<?= e(shop_url('cart.php')) ?>" class="btn btn-editorial-secondary btn-full">
        View Full Cart
      </a>
    </div>
    <p class="drawer-shipping-note">Taxes and shipping calculated at checkout.</p>
  </div>
</div>

<main class="site-main" id="mainContent">
