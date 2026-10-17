<?php
/**
 * Live search suggestions API.
 * Returns product and category matches as JSON, and logs queries to search_log.
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

// Release session lock early for high concurrency
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));

if ($q === '' || mb_strlen($q) < 2) {
    // Return empty results with popular active categories as suggestions
    $popularCats = db()->query(
        "SELECT c.id, c.name, c.slug, COUNT(p.id) as p_count
         FROM categories c
         JOIN products p ON p.category_id = c.id AND p.status = 'active'
         WHERE c.status = 'active'
         GROUP BY c.id
         ORDER BY p_count DESC, c.name ASC
         LIMIT 5"
    )->fetchAll();

    echo json_encode([
        'query'         => $q,
        'products'      => [],
        'categories'    => array_map(function ($c) {
            return [
                'id'    => (int)$c['id'],
                'name'  => $c['name'],
                'slug'  => $c['slug'],
                'url'   => shop_url('category.php?slug=' . urlencode($c['slug'])),
            ];
        }, $popularCats),
        'total_results' => 0,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (function_exists('app_finish_response')) app_finish_response();
    exit;
}

$pdo = db();
$like = '%' . $q . '%';

// 1. Search active products
$stmtP = $pdo->prepare(
    "SELECT p.id, p.name, p.slug, p.sku, p.price, p.sale_price, p.image,
            c.name AS category_name, c.slug AS category_slug
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.status = 'active'
       AND (p.name LIKE :q1 OR p.sku LIKE :q2 OR p.short_description LIKE :q3)
     ORDER BY
       CASE WHEN p.name LIKE :exact THEN 1
            WHEN p.name LIKE :start THEN 2
            ELSE 3 END,
       p.created_at DESC
     LIMIT 6"
);
$stmtP->execute([
    ':q1'    => $like,
    ':q2'    => $like,
    ':q3'    => $like,
    ':exact' => $q,
    ':start' => $q . '%',
]);
$rawProducts = $stmtP->fetchAll();

$products = [];
foreach ($rawProducts as $p) {
    $onSale = product_is_on_sale($p);
    $products[] = [
        'id'            => (int)$p['id'],
        'name'          => $p['name'],
        'slug'          => $p['slug'],
        'sku'           => $p['sku'] ?? '',
        'category'      => $p['category_name'] ?? '',
        'price'         => (float)$p['price'],
        'price_fmt'     => price((float)$p['price']),
        'sale_price'    => $onSale ? (float)$p['sale_price'] : null,
        'sale_price_fmt'=> $onSale ? price((float)$p['sale_price']) : null,
        'on_sale'       => $onSale,
        'image'         => product_image_url($p['image']),
        'url'           => shop_url('product.php?slug=' . urlencode($p['slug'])),
    ];
}

// 2. Search categories
$stmtC = $pdo->prepare(
    "SELECT id, name, slug FROM categories
     WHERE status = 'active' AND name LIKE :q
     ORDER BY name ASC
     LIMIT 4"
);
$stmtC->execute([':q' => $like]);
$rawCats = $stmtC->fetchAll();

$categories = [];
foreach ($rawCats as $c) {
    $categories[] = [
        'id'   => (int)$c['id'],
        'name' => $c['name'],
        'slug' => $c['slug'],
        'url'  => shop_url('category.php?slug=' . urlencode($c['slug'])),
    ];
}

$totalFound = count($products) + count($categories);

// 3. Log to search_log
try {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $visitorHash = md5($clientIp . $userAgent);

    $stmtLog = $pdo->prepare(
        "INSERT INTO search_log (term, results, visitor_hash, created_at)
         VALUES (:term, :res, :vhash, NOW())"
    );
    $stmtLog->execute([
        ':term'  => mb_substr($q, 0, 120),
        ':res'   => $totalFound,
        ':vhash' => $visitorHash,
    ]);
} catch (Throwable $e) {
    // Non-critical logging failure
}

echo json_encode([
    'query'         => $q,
    'products'      => $products,
    'categories'    => $categories,
    'total_results' => $totalFound,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (function_exists('app_finish_response')) app_finish_response();
exit;
