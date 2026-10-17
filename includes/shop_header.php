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
$headerCustomer = function_exists('customer_current') ? customer_current() : null;
$customerFirstName = '';
if ($headerCustomer) {
    $parts = explode(' ', trim($headerCustomer['name']));
    $customerFirstName = $parts[0] ?: 'Account';
}
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

  <!-- Preload core storefront styles -->
  <link rel="preload" href="<?= e(url('assets/css/shop.css')) ?>?v=<?= filemtime(__DIR__ . '/../assets/css/shop.css') ?>" as="style">

  <!-- Self-Hosted Typography & Icons -->
  <link rel="stylesheet" href="<?= e(url('assets/vendor/fonts/inter.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">

  <!-- Storefront Editorial Stylesheet -->
  <link rel="stylesheet" href="<?= e(url('assets/css/shop.css')) ?>?v=<?= filemtime(__DIR__ . '/../assets/css/shop.css') ?>">

  <!-- Dynamic Theme Accent Override -->
  <style>:root { --accent: <?= e($themeAccent) ?>; }</style>

  <script>
    window.SHOP_BASE_URL = "<?= e(BASE_URL) ?>";
    window.SHOP_CSRF_TOKEN = "<?= e(csrf_token()) ?>";
  </script>
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

        <!-- Customer Account Menu -->
        <?php if ($headerCustomer): ?>
          <div class="dropdown header-account-dropdown">
            <button class="action-btn dropdown-toggle d-flex align-items-center gap-1" type="button" id="customerAccountDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="border:none;background:none;">
              <i class="bi bi-person"></i>
              <span class="d-none d-md-inline small fw-semibold"><?= e($customerFirstName) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="customerAccountDropdown">
              <li class="px-3 py-2 border-bottom">
                <div class="small fw-bold text-truncate"><?= e($headerCustomer['name']) ?></div>
                <div class="small text-muted text-truncate" style="font-size: 0.75rem;"><?= e($headerCustomer['email']) ?></div>
              </li>
              <li><a class="dropdown-item" href="<?= e(shop_url('account/')) ?>"><i class="bi bi-person-circle"></i> My Account</a></li>
              <li><a class="dropdown-item" href="<?= e(shop_url('account/orders.php')) ?>"><i class="bi bi-box-seam"></i> Orders</a></li>
              <li><a class="dropdown-item" href="<?= e(shop_url('account/wishlist.php')) ?>"><i class="bi bi-heart"></i> Wishlist</a></li>
              <li><a class="dropdown-item" href="<?= e(shop_url('account/addresses.php')) ?>"><i class="bi bi-geo-alt"></i> Addresses</a></li>
              <li><hr class="dropdown-divider my-1"></li>
              <li>
                <form method="post" action="<?= e(shop_url('logout.php')) ?>" class="m-0 p-0">
                  <?= csrf_field() ?>
                  <button type="submit" class="dropdown-item text-danger border-0 bg-transparent w-100 text-start">
                    <i class="bi bi-box-arrow-right text-danger"></i> Sign Out
                  </button>
                </form>
              </li>
            </ul>
          </div>
        <?php else: ?>
          <a href="<?= e(shop_url('login.php')) ?>" class="action-btn d-none d-sm-flex align-items-center gap-1 text-decoration-none text-reset" aria-label="Sign In">
            <i class="bi bi-person"></i>
            <span class="small fw-semibold d-none d-md-inline">Sign In</span>
          </a>
        <?php endif; ?>

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
  <div class="offcanvas-body p-0 d-flex flex-column">
    <ul class="list-group list-group-flush flex-grow-1">
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

    <div class="p-3 border-top bg-light">
      <?php if ($headerCustomer): ?>
        <div class="d-flex align-items-center gap-2 mb-3">
          <div class="rounded-circle bg-white border d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
            <i class="bi bi-person text-secondary"></i>
          </div>
          <div class="overflow-hidden">
            <div class="fw-bold small text-truncate"><?= e($headerCustomer['name']) ?></div>
            <div class="text-muted small text-truncate" style="font-size: 0.75rem;"><?= e($headerCustomer['email']) ?></div>
          </div>
        </div>
        <div class="d-flex flex-column gap-2 mb-3 ps-1">
          <a href="<?= e(shop_url('account/')) ?>" class="small text-reset text-decoration-none"><i class="bi bi-person-circle me-2"></i>My Account</a>
          <a href="<?= e(shop_url('account/orders.php')) ?>" class="small text-reset text-decoration-none"><i class="bi bi-box-seam me-2"></i>Orders</a>
          <a href="<?= e(shop_url('account/wishlist.php')) ?>" class="small text-reset text-decoration-none"><i class="bi bi-heart me-2"></i>Wishlist</a>
          <a href="<?= e(shop_url('account/addresses.php')) ?>" class="small text-reset text-decoration-none"><i class="bi bi-geo-alt me-2"></i>Addresses</a>
        </div>
        <form method="post" action="<?= e(shop_url('logout.php')) ?>">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-box-arrow-right me-1"></i> Sign Out
          </button>
        </form>
      <?php else: ?>
        <div class="d-grid gap-2">
          <a href="<?= e(shop_url('login.php')) ?>" class="btn btn-editorial-primary btn-sm">Sign In</a>
          <a href="<?= e(shop_url('register.php')) ?>" class="btn btn-outline-secondary btn-sm">Create Account</a>
        </div>
      <?php endif; ?>
    </div>
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
