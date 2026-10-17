<?php
/**
 * Cron Job: Incremental Sales Summary Rebuilder.
 *
 * Recomputes product_sales_summary for products in orders whose status or data
 * changed since the previous run. Never re-aggregates the entire historical table.
 *
 * Usage:
 *   php cron/rebuild_sales_summary.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Access denied: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$start = microtime(true);
$nowStr = date('Y-m-d H:i:s');
$lastRun = setting('sales_summary_last_run', date('Y-m-d H:i:s', strtotime('-1 day')));

echo "[" . date('Y-m-d H:i:s') . "] Rebuilding sales summary for orders updated since {$lastRun}...\n";

try {
    // 1. Identify product IDs affected by orders updated or placed since last run
    $stmtAffected = $pdo->prepare(
        "SELECT DISTINCT oi.product_id
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE (o.updated_at >= :lr1 OR o.created_at >= :lr2)
           AND oi.product_id IS NOT NULL"
    );
    $stmtAffected->execute([':lr1' => $lastRun, ':lr2' => $lastRun]);
    $affectedProductIds = $stmtAffected->fetchAll(PDO::FETCH_COLUMN);

    if (empty($affectedProductIds)) {
        echo "No orders modified since last run. Nothing to recompute.\n";
        update_setting('sales_summary_last_run', $nowStr);
        exit(0);
    }

    $count = count($affectedProductIds);
    echo "Found {$count} affected product(s). Recomputing summaries...\n";

    $pdo->beginTransaction();

    // Process in batches of 200 products
    $chunks = array_chunk($affectedProductIds, 200);
    $totalUpdated = 0;

    foreach ($chunks as $chunkIds) {
        $inClause = implode(',', array_map('intval', $chunkIds));

        // Delete existing summary rows for these affected products to refresh cleanly
        $pdo->exec("DELETE FROM product_sales_summary WHERE product_id IN ($inClause)");

        // Insert fresh aggregations for these products
        $stmtRebuild = $pdo->prepare(
            "INSERT INTO product_sales_summary (product_id, variant_id, variant_label, units, revenue, orders_count, last_sale)
             SELECT 
                 oi.product_id,
                 COALESCE(oi.variant_id, 0) AS variant_id,
                 MAX(oi.variant_label) AS variant_label,
                 COALESCE(SUM(oi.quantity), 0) AS units,
                 COALESCE(SUM(oi.line_total), 0.00) AS revenue,
                 COUNT(DISTINCT oi.order_id) AS orders_count,
                 MAX(o.created_at) AS last_sale
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
             WHERE oi.product_id IN ($inClause)
             GROUP BY oi.product_id, COALESCE(oi.variant_id, 0)"
        );
        $stmtRebuild->execute();
        $totalUpdated += $stmtRebuild->rowCount();
    }

    $pdo->commit();

    update_setting('sales_summary_last_run', $nowStr);

    $elapsed = round(microtime(true) - $start, 3);
    echo "[" . date('Y-m-d H:i:s') . "] Successfully recomputed {$totalUpdated} summary row(s) for {$count} products in {$elapsed}s.\n";
    exit(0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
