<?php
/**
 * Dynamic XML Sitemap generator listing home, shop, active categories, and active products.
 */
require_once __DIR__ . '/includes/shop_bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: application/xml; charset=utf-8');
}

$baseUrl = rtrim(BASE_URL, '/') . '/';

// Fetch active categories
$cats = [];
try {
    $cats = db()->query(
        "SELECT slug, updated_at FROM categories WHERE status = 'active' ORDER BY name ASC"
    )->fetchAll();
} catch (Throwable $e) {
    $cats = [];
}

// Fetch active products
$products = [];
try {
    $products = db()->query(
        "SELECT slug, updated_at FROM products WHERE status = 'active' ORDER BY id DESC"
    )->fetchAll();
} catch (Throwable $e) {
    $products = [];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <!-- Core Storefront Pages -->
  <url>
    <loc><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></loc>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc><?= htmlspecialchars($baseUrl . 'shop.php', ENT_QUOTES, 'UTF-8') ?></loc>
    <changefreq>daily</changefreq>
    <priority>0.9</priority>
  </url>
  <url>
    <loc><?= htmlspecialchars($baseUrl . 'track.php', ENT_QUOTES, 'UTF-8') ?></loc>
    <changefreq>monthly</changefreq>
    <priority>0.5</priority>
  </url>

  <!-- Active Categories -->
  <?php foreach ($cats as $c): ?>
    <url>
      <loc><?= htmlspecialchars($baseUrl . 'category.php?slug=' . urlencode($c['slug']), ENT_QUOTES, 'UTF-8') ?></loc>
      <lastmod><?= date('c', !empty($c['updated_at']) ? strtotime($c['updated_at']) : time()) ?></lastmod>
      <changefreq>weekly</changefreq>
      <priority>0.8</priority>
    </url>
  <?php endforeach; ?>

  <!-- Active Products -->
  <?php foreach ($products as $p): ?>
    <url>
      <loc><?= htmlspecialchars($baseUrl . 'product.php?slug=' . urlencode($p['slug']), ENT_QUOTES, 'UTF-8') ?></loc>
      <lastmod><?= date('c', !empty($p['updated_at']) ? strtotime($p['updated_at']) : time()) ?></lastmod>
      <changefreq>daily</changefreq>
      <priority>0.8</priority>
    </url>
  <?php endforeach; ?>
</urlset>
