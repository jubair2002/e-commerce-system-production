<?php
/**
 * Hourly Analytics Roll-up CLI Cron (Prompt 09).
 *
 * Tasks:
 *   1. Aggregate raw events & orders for yesterday and today into product_stats_daily and site_stats_daily.
 *   2. Purge raw analytics_events older than analytics_retention_days (default 180 days).
 *
 * Usage:
 *   php cron/analytics_rollup.php
 *   php cron/analytics_rollup.php --date=2026-10-17
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

$options = getopt('', ['date:']);
$targetDate = !empty($options['date']) ? trim($options['date']) : null;

if ($targetDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    echo "Error: Invalid date format. Use YYYY-MM-DD.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting Analytics Roll-up Cron...\n";
$start = microtime(true);

try {
    $result = analytics_rollup($targetDate);
    $elapsed = round((microtime(true) - $start) * 1000, 2);

    echo "  Processed dates: " . implode(', ', $result['dates']) . "\n";
    echo "  Product stats rows updated: {$result['product_stats_rows']}\n";
    echo "  Site stats rows updated: {$result['site_stats_rows']}\n";
    echo "  Old events purged: {$result['purged_events']}\n";
    echo "Done in {$elapsed} ms.\n";
    exit(0);
} catch (Throwable $e) {
    echo "Error during analytics roll-up: " . $e->getMessage() . "\n";
    exit(1);
}
