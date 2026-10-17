<?php
/**
 * Local development server router for PHP built-in server.
 * Mimics Apache .htaccess URL rewriting rules and pretty URLs.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// 1. Static files or existing non-directory files
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // let PHP CLI server serve the file directly
}

// Uploads delivery: serve from UPLOADS_PATH if requested via /uploads/ or /assets/uploads/
if (preg_match('#(?:^|/)(?:assets/)?uploads/(.+)$#', $uri, $m)) {
    $_GET['file'] = $m[1];
    require __DIR__ . '/uploads.php';
    exit;
}

// 2. Exact rewrites
$rewrites = [
    '/' => 'index.php',
    '/shop' => 'storefront/shop/index.php',
    '/cart' => 'storefront/cart/index.php',
    '/checkout' => 'storefront/cart/checkout.php',
    '/order/confirmation' => 'storefront/cart/confirmation.php',
    '/track' => 'storefront/track/index.php',
    '/login' => 'storefront/auth/login.php',
    '/register' => 'storefront/auth/register.php',
    '/logout' => 'storefront/auth/logout.php',
    '/forgot-password' => 'storefront/auth/forgot-password.php',
    '/reset-password' => 'storefront/auth/reset-password.php',
    '/verify-email' => 'storefront/auth/verify-email.php',
    '/resend-verification' => 'storefront/auth/resend-verification.php',
    '/contact' => 'storefront/pages/contact.php',
    '/newsletter' => 'storefront/pages/newsletter.php',
    '/sitemap.xml' => 'storefront/seo/sitemap.php',
    '/account' => 'account/index.php',
    '/account/orders' => 'account/orders.php',
    '/account/order' => 'account/order.php',
    '/account/invoice' => 'account/invoice.php',
    '/account/addresses' => 'account/addresses.php',
    '/account/profile' => 'account/profile.php',
    '/account/security' => 'account/security.php',
    '/account/wishlist' => 'account/wishlist.php',
    '/account/export' => 'account/export.php',
    '/account/delete' => 'account/delete.php',
];

$trimmed = rtrim($uri, '/');
if ($trimmed === '') $trimmed = '/';

if (!empty($_GET['test_sess']) && preg_match('/^[a-zA-Z0-9,-]+$/', $_GET['test_sess'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_id($_GET['test_sess']);
    }
}

if (isset($rewrites[$trimmed])) {
    require __DIR__ . '/' . $rewrites[$trimmed];
    exit;
}

// 3. Dynamic patterns: /category/{slug}, /product/{slug}, /page/{slug}
if (preg_match('#^/category/([^/]+)/?$#', $uri, $m)) {
    $_GET['slug'] = urldecode($m[1]);
    require __DIR__ . '/storefront/shop/category.php';
    exit;
}
if (preg_match('#^/product/([^/]+)/?$#', $uri, $m)) {
    $_GET['slug'] = urldecode($m[1]);
    require __DIR__ . '/storefront/shop/product.php';
    exit;
}
if (preg_match('#^/page/([^/]+)/?$#', $uri, $m)) {
    $_GET['slug'] = urldecode($m[1]);
    require __DIR__ . '/storefront/pages/view.php';
    exit;
}

// 4. Legacy redirects
if (preg_match('#^/(shop|cart|auth|track|pages|errors|seo)(/.*)?$#', $uri, $m)) {
    $target = __DIR__ . '/storefront' . $uri;
    if (file_exists($target) && !is_dir($target)) {
        require $target;
        exit;
    }
}

// 5. If index.php or existing php script in subfolder
if (file_exists($file . '/index.php')) {
    require $file . '/index.php';
    exit;
}
if (file_exists($file) && is_file($file)) {
    require $file;
    exit;
}

// 6. 404
http_response_code(404);
require __DIR__ . '/storefront/errors/404.php';
exit;
