<?php
/**
 * Cron Job: Nightly Analytics Roll-up (Yesterday Only).
 *
 * Incrementally aggregates raw analytics_events into product_stats_daily and site_stats_daily
 * for yesterday only, avoiding catastrophic full-table re-aggregations.
 * Safe to re-run (idempotent ON DUPLICATE KEY UPDATE).
 *
 * Usage:
 *   php cron/aggregate_analytics.php
 *   php cron/aggregate_analytics.php --date=2026-10-16
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Access denied: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/analytics.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$options = getopt('', ['date:']);
$targetDate = !empty($options['date']) ? trim($options['date']) : date('Y-m-d', strtotime('-1 day'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    echo "Error: Invalid date format. Use YYYY-MM-DD.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting Nightly Analytics Aggregation for date: {$targetDate}...\n";
$start = microtime(true);

try {
    // 1. Roll-up product_stats_daily for target date
    $stmtProd = $pdo->prepare(
        "INSERT INTO product_stats_daily (stat_date, product_id, views, unique_views, add_to_cart, orders, units_sold, revenue)
         SELECT 
             :stat_date AS stat_date,
             p.id AS product_id,
             COALESCE(ev.views, 0) AS views,
             COALESCE(ev.unique_views, 0) AS unique_views,
             COALESCE(ev.add_to_cart, 0) AS add_to_cart,
             COALESCE(ord.orders_count, 0) AS orders,
             COALESCE(ord.units_sold, 0) AS units_sold,
             COALESCE(ord.revenue, 0.00) AS revenue
         FROM products p
         LEFT JOIN (
             SELECT 
                 product_id,
                 SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS views,
                 COUNT(DISTINCT CASE WHEN event_type = 'product_view' THEN visitor_hash END) AS unique_views,
                 SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_cart
             FROM analytics_events
             WHERE DATE(created_at) = :stat_date_ev AND product_id IS NOT NULL
             GROUP BY product_id
         ) ev ON ev.product_id = p.id
         LEFT JOIN (
             SELECT 
                 oi.product_id,
                 COUNT(DISTINCT o.id) AS orders_count,
                 SUM(oi.quantity) AS units_sold,
                 SUM(oi.line_total) AS revenue
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE DATE(o.created_at) = :stat_date_ord AND o.status <> 'cancelled'
             GROUP BY oi.product_id
         ) ord ON ord.product_id = p.id
         WHERE COALESCE(ev.views, 0) > 0 OR COALESCE(ord.units_sold, 0) > 0
         ON DUPLICATE KEY UPDATE
             views        = VALUES(views),
             unique_views = VALUES(unique_views),
             add_to_cart  = VALUES(add_to_cart),
             orders       = VALUES(orders),
             units_sold   = VALUES(units_sold),
             revenue      = VALUES(revenue)"
    );

    $stmtProd->execute([
        ':stat_date'     => $targetDate,
        ':stat_date_ev'  => $targetDate,
        ':stat_date_ord' => $targetDate,
    ]);
    $prodCount = $stmtProd->rowCount();

    // 2. Roll-up site_stats_daily for target date
    $stmtSite = $pdo->prepare(
        "INSERT INTO site_stats_daily (stat_date, visitors, product_views, add_to_cart, checkout_starts, orders, cancelled_orders, revenue, avg_order_value)
         SELECT 
             :stat_date AS stat_date,
             COALESCE(ev.visitors, 0) AS visitors,
             COALESCE(ev.product_views, 0) AS product_views,
             COALESCE(ev.add_to_cart, 0) AS add_to_cart,
             COALESCE(ev.checkout_starts, 0) AS checkout_starts,
             COALESCE(ord.orders_count, 0) AS orders,
             COALESCE(ord.cancelled_orders, 0) AS cancelled_orders,
             COALESCE(ord.revenue, 0.00) AS revenue,
             CASE WHEN COALESCE(ord.orders_count, 0) > 0 
                  THEN ROUND(COALESCE(ord.revenue, 0.00) / ord.orders_count, 2)
                  ELSE 0.00 END AS avg_order_value
         FROM (SELECT 1) dummy
         LEFT JOIN (
             SELECT 
                 COUNT(DISTINCT visitor_hash) AS visitors,
                 SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS product_views,
                 SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_cart,
                 SUM(CASE WHEN event_type = 'checkout_start' THEN 1 ELSE 0 END) AS checkout_starts
             FROM analytics_events
             WHERE DATE(created_at) = :stat_date_ev
         ) ev ON 1=1
         LEFT JOIN (
             SELECT 
                 COUNT(DISTINCT CASE WHEN status <> 'cancelled' THEN id END) AS orders_count,
                 COUNT(DISTINCT CASE WHEN status = 'cancelled' THEN id END) AS cancelled_orders,
                 SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0.00 END) AS revenue
             FROM orders
             WHERE DATE(created_at) = :stat_date_ord
         ) ord ON 1=1
         ON DUPLICATE KEY UPDATE
             visitors         = VALUES(visitors),
             product_views    = VALUES(product_views),
             add_to_cart      = VALUES(add_to_cart),
             checkout_starts  = VALUES(checkout_starts),
             orders           = VALUES(orders),
             cancelled_orders = VALUES(cancelled_orders),
             revenue          = VALUES(revenue),
             avg_order_value  = VALUES(avg_order_value)"
    );

    $stmtSite->execute([
        ':stat_date'     => $targetDate,
        ':stat_date_ev'  => $targetDate,
        ':stat_date_ord' => $targetDate,
    ]);
    $siteCount = $stmtSite->rowCount();

    $elapsed = round(microtime(true) - $start, 3);
    echo "  product_stats_daily rows: {$prodCount}\n";
    echo "  site_stats_daily rows: {$siteCount}\n";
    echo "[" . date('Y-m-d H:i:s') . "] Completed analytics rollup in {$elapsed}s.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
