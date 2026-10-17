<?php
require_once __DIR__ . '/../shop_bootstrap.php';

$pageTitle = $pageTitle ?? '';
$siteName  = setting('site_name', APP_NAME);
$fullTitle = $pageTitle !== '' ? "$pageTitle · $siteName" : $siteName;
$cats      = shop_active_categories();

// Theme Accent Token from Settings (validate hex)
$themeAccent = setting('theme_accent_color', setting('theme_accent', '#111827'));
if (!preg_match('/^#[a-fA-F0-9]{3,8}$/', $themeAccent)) {
    $themeAccent = '#111827';
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

$cssFile = __DIR__ . '/../../assets/css/shop.css';
$cssVersion = file_exists($cssFile) ? filemtime($cssFile) : time();
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

  <!-- Theme Flash Prevention -->
  <script>
    (function() {
      try {
        var t = localStorage.getItem('jinispotro_theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', t);
      } catch (e) {}
    })();
  </script>

  <!-- Preload Typography & Core Styles -->
  <link rel="preload" href="<?= e(url('assets/css/shop.css')) ?>?v=<?= $cssVersion ?>" as="style">
  <link rel="stylesheet" href="<?= e(url('assets/vendor/fonts/inter.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/css/shop.css')) ?>?v=<?= $cssVersion ?>">

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
      <?= icon('x') ?>
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
            <a href="<?= e(route('home')) ?>" class="nav-link <?= route_is('home') ? 'is-active' : '' ?>">Home</a>
          </li>
          <li>
            <a href="<?= e(route('shop')) ?>" class="nav-link <?= route_is('shop') ? 'is-active' : '' ?>">Shop</a>
          </li>
          <?php if (!empty($cats)): ?>
            <li class="shop-dropdown">
              <button type="button" class="nav-link d-inline-flex align-items-center gap-1" data-dropdown-toggle aria-expanded="false">
                <span>Categories</span>
                <?= icon('chevron-down') ?>
              </button>
              <div class="dropdown-menu">
                <?php foreach ($cats as $c): ?>
                  <a class="dropdown-item" href="<?= e(route('category', ['slug' => $c['slug']])) ?>">
                    <?= e($c['name']) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </li>
          <?php endif; ?>
          <li>
            <a href="<?= e(route('track')) ?>" class="nav-link <?= route_is('track') ? 'is-active' : '' ?>">Track Order</a>
          </li>
        </ul>
      </nav>

      <!-- Right: Actions -->
      <div class="header-actions">

        <!-- Search Trigger & Popover -->
        <div class="search-wrapper" style="position: relative;">
          <button type="button" class="header-btn" data-toggle-search aria-label="Search store">
            <?= icon('search') ?>
          </button>
          <div class="search-expand-bar" id="searchExpandBar">
            <form action="<?= e(route('shop')) ?>" method="get" class="search-input-group d-flex align-items-center gap-2">
              <?= icon('search') ?>
              <input type="text"
                     name="q"
                     id="headerSearchInput"
                     class="search-field form-control"
                     placeholder="Search products..."
                     autocomplete="off"
                     value="<?= e($_GET['q'] ?? '') ?>">
            </form>
            <div class="search-suggestions-dropdown" id="searchSuggestionsDropdown"></div>
          </div>
        </div>

        <!-- Wishlist -->
        <a href="<?= e(route('account_wishlist')) ?>" class="header-btn d-none d-md-inline-flex" aria-label="Wishlist" title="Wishlist">
          <?= icon('heart') ?>
        </a>

        <!-- Dark/Light Theme Toggle -->
        <button type="button" class="header-btn" data-theme-toggle aria-label="Toggle theme">
          <span class="theme-icon-sun" style="display:none;"><?= icon('sun') ?></span>
          <span class="theme-icon-moon"><?= icon('moon') ?></span>
        </button>

        <!-- Customer Account Menu / Sign In -->
        <?php if ($headerCustomer): ?>
          <div class="shop-dropdown">
            <button class="header-account-btn" type="button" data-dropdown-toggle aria-expanded="false" aria-label="Account menu">
              <span class="header-account-avatar"><?= icon('user') ?></span>
              <span class="small fw-semibold d-none d-md-inline"><?= e($customerFirstName) ?></span>
              <?= icon('chevron-down') ?>
            </button>
            <div class="dropdown-menu">
              <div class="px-3 py-2 border-bottom">
                <div class="small fw-bold text-truncate"><?= e($headerCustomer['name']) ?></div>
                <div class="small text-muted text-truncate"><?= e($headerCustomer['email']) ?></div>
              </div>
              <a class="dropdown-item" href="<?= e(route('account')) ?>"><?= icon('user') ?> My Account</a>
              <a class="dropdown-item" href="<?= e(route('account_orders')) ?>"><?= icon('bag') ?> Orders</a>
              <a class="dropdown-item" href="<?= e(route('account_wishlist')) ?>"><?= icon('heart') ?> Wishlist</a>
              <a class="dropdown-item" href="<?= e(route('account_addresses')) ?>"><?= icon('map-pin') ?> Addresses</a>
              <div class="dropdown-divider"></div>
              <form method="post" action="<?= e(route('logout')) ?>" class="m-0 p-0">
                <?= csrf_field() ?>
                <button type="submit" class="dropdown-item text-danger border-0 bg-transparent w-100 text-start">
                  <?= icon('logout') ?> Sign Out
                </button>
              </form>
            </div>
          </div>
        <?php else: ?>
          <a href="<?= e(route('login')) ?>" class="header-auth-btn" aria-label="Sign In">
            <?= icon('user') ?>
            <span class="small fw-semibold">Sign In</span>
          </a>
        <?php endif; ?>

        <!-- Cart Trigger -->
        <button type="button" class="header-btn" data-cart-drawer-trigger data-drawer-open="miniCartDrawer" aria-label="View shopping cart">
          <?= icon('bag') ?>
          <span class="badge-count cart-badge-count" style="<?= $cartCnt > 0 ? '' : 'display:none;' ?>"><?= $cartCnt ?></span>
        </button>

        <!-- Mobile Menu Toggle -->
        <button type="button" class="header-btn d-lg-none" data-drawer-open="mobileNavDrawer" aria-label="Open menu">
          <?= icon('menu') ?>
        </button>

      </div>

    </div>
  </div>
</header>

<!-- Mobile Navigation Drawer -->
<div class="shop-drawer drawer-left" id="mobileNavDrawer" aria-hidden="true">
  <div class="drawer-header">
    <h3 class="drawer-title"><?= e($siteName) ?></h3>
    <button type="button" class="drawer-close" data-drawer-close aria-label="Close menu">
      <?= icon('x') ?>
    </button>
  </div>
  <div class="drawer-body p-0">
    <div class="d-flex flex-column">
      <a href="<?= e(route('home')) ?>" class="p-3 border-bottom text-ink fw-medium">Home</a>
      <a href="<?= e(route('shop')) ?>" class="p-3 border-bottom text-ink fw-medium">All Products</a>
      <?php if (!empty($cats)): ?>
        <div class="p-3 border-bottom">
          <div class="text-muted fw-semibold small text-uppercase mb-2">Categories</div>
          <div class="d-flex flex-column gap-2 ps-2">
            <?php foreach ($cats as $c): ?>
              <a href="<?= e(route('category', ['slug' => $c['slug']])) ?>" class="small text-ink-2">
                <?= e($c['name']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <a href="<?= e(route('track')) ?>" class="p-3 border-bottom text-ink fw-medium">Track Order</a>
      <a href="<?= e(route('page', ['slug' => 'about'])) ?>" class="p-3 border-bottom text-ink fw-medium">About Us</a>
      <a href="<?= e(route('contact')) ?>" class="p-3 border-bottom text-ink fw-medium">Contact Us</a>
    </div>

    <div class="p-4 mt-4 bg-soft">
      <?php if ($headerCustomer): ?>
        <div class="d-flex align-items-center gap-2 mb-3">
          <div class="trust-icon"><?= icon('user') ?></div>
          <div>
            <div class="fw-bold small"><?= e($headerCustomer['name']) ?></div>
            <div class="text-muted small"><?= e($headerCustomer['email']) ?></div>
          </div>
        </div>
        <div class="d-flex flex-column gap-2 mb-3">
          <a href="<?= e(route('account')) ?>" class="small text-ink-2"><?= icon('user') ?> My Account</a>
          <a href="<?= e(route('account_orders')) ?>" class="small text-ink-2"><?= icon('bag') ?> Orders</a>
          <a href="<?= e(route('account_wishlist')) ?>" class="small text-ink-2"><?= icon('heart') ?> Wishlist</a>
        </div>
        <form method="post" action="<?= e(route('logout')) ?>">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-secondary btn-sm w-100 text-danger">
            <?= icon('logout') ?> Sign Out
          </button>
        </form>
      <?php else: ?>
        <div class="d-flex flex-column gap-2">
          <a href="<?= e(route('login')) ?>" class="btn btn-primary btn-sm">Sign In</a>
          <a href="<?= e(route('register')) ?>" class="btn btn-secondary btn-sm">Create Account</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/mini_cart.php'; ?>

<!-- Main Content Anchor -->
<main class="site-main" id="mainContent">
