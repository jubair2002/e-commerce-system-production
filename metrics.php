<?php
/**
 * Prometheus Metrics Exporter Endpoint for Jinispotro E-Commerce.
 * Exposes core application, e-commerce, and storage metrics in Prometheus text format.
 */
declare(strict_types=1);

// Optional security token check
$requiredToken = getenv('METRICS_TOKEN') ?: (defined('METRICS_TOKEN') ? METRICS_TOKEN : '');
if (!empty($requiredToken)) {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    if (str_starts_with($header, 'Bearer ')) {
        $token = substr($header, 7);
    } elseif (isset($_GET['token'])) {
        $token = (string)$_GET['token'];
    }
    if ($token !== $requiredToken) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "# Unauthorized: Invalid metrics token\n";
        exit;
    }
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$lines = [];

function emit_metric(string $name, $value, array $labels = [], string $help = '', string $type = 'gauge'): string
{
    $out = '';
    if ($help !== '') {
        $out .= "# HELP {$name} {$help}\n";
    }
    if ($type !== '') {
        $out .= "# TYPE {$name} {$type}\n";
    }
    $labelStr = '';
    if (!empty($labels)) {
        $pairs = [];
        foreach ($labels as $k => $v) {
            $pairs[] = sprintf('%s="%s"', $k, addcslashes((string)$v, "\"\n\\"));
        }
        $labelStr = '{' . implode(',', $pairs) . '}';
    }
    $out .= sprintf("%s%s %s\n", $name, $labelStr, is_numeric($value) ? $value : 0);
    return $out;
}

// 1. PHP Runtime Metrics
echo emit_metric('ecommerce_php_info', 1, ['version' => PHP_VERSION, 'sapi' => PHP_SAPI], 'PHP version information', 'gauge');
echo emit_metric('ecommerce_php_memory_bytes', memory_get_usage(true), [], 'PHP memory allocated in bytes', 'gauge');
echo emit_metric('ecommerce_php_memory_peak_bytes', memory_get_peak_usage(true), [], 'PHP memory peak allocated in bytes', 'gauge');

// 2. Database Connectivity & E-Commerce Metrics
try {
    $pdo = db();
    echo emit_metric('ecommerce_database_up', 1, [], 'MySQL database connection status (1 = connected, 0 = down)', 'gauge');

    // Orders by status
    $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status");
    $orderCounts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $orderCounts[$row['status']] = (int)$row['cnt'];
    }
    $knownStatuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];
    echo "# HELP ecommerce_orders_total Total count of orders by status.\n# TYPE ecommerce_orders_total gauge\n";
    foreach ($knownStatuses as $st) {
        $c = $orderCounts[$st] ?? 0;
        echo emit_metric('ecommerce_orders_total', $c, ['status' => $st], '', '');
    }

    // Revenue
    $revStmt = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'completed'");
    $revenue = (float)$revStmt->fetchColumn();
    echo emit_metric('ecommerce_revenue_total', $revenue, ['currency' => 'BDT'], 'Total revenue from completed orders', 'counter');

    // Customers
    $custStmt = $pdo->query("SELECT COUNT(*) FROM customers");
    $customers = (int)$custStmt->fetchColumn();
    echo emit_metric('ecommerce_customers_total', $customers, [], 'Total registered customer accounts', 'gauge');

    // Products
    $prodStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
    $products = (int)$prodStmt->fetchColumn();
    echo emit_metric('ecommerce_products_active', $products, [], 'Total active products in catalog', 'gauge');

    // Low / Out of stock products
    $stockStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE stock <= 5 AND status = 'active'");
    $lowStock = (int)$stockStmt->fetchColumn();
    echo emit_metric('ecommerce_products_low_stock', $lowStock, [], 'Products with stock quantity <= 5', 'gauge');

    // Email queue metrics
    $emailStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM email_queue GROUP BY status");
    $emailCounts = [];
    while ($row = $emailStmt->fetch(PDO::FETCH_ASSOC)) {
        $emailCounts[$row['status']] = (int)$row['cnt'];
    }
    echo "# HELP ecommerce_email_queue_total Email queue entries by status.\n# TYPE ecommerce_email_queue_total gauge\n";
    foreach (['pending', 'sending', 'sent', 'failed'] as $st) {
        $c = $emailCounts[$st] ?? 0;
        echo emit_metric('ecommerce_email_queue_total', $c, ['status' => $st], '', '');
    }

    // Storage Layer metrics
    $checkFilesTable = $pdo->query("SHOW TABLES LIKE 'files'")->fetchColumn();
    if ($checkFilesTable) {
        $storageStmt = $pdo->query("SELECT provider, COUNT(*) AS cnt FROM files WHERE status != 'deleted' GROUP BY provider");
        $fileCounts = [];
        while ($row = $storageStmt->fetch(PDO::FETCH_ASSOC)) {
            $fileCounts[$row['provider']] = (int)$row['cnt'];
        }
        echo "# HELP ecommerce_storage_files_total Stored media files by provider.\n# TYPE ecommerce_storage_files_total gauge\n";
        foreach (['local', 's3'] as $prov) {
            $c = $fileCounts[$prov] ?? 0;
            echo emit_metric('ecommerce_storage_files_total', $c, ['provider' => $prov], '', '');
        }

        $pendingSyncStmt = $pdo->query("SELECT COUNT(*) FROM files WHERE sync_status = 'pending' AND status != 'deleted'");
        $pendingSync = (int)$pendingSyncStmt->fetchColumn();
        echo emit_metric('ecommerce_storage_sync_pending', $pendingSync, [], 'Files pending synchronization to primary storage', 'gauge');
    }

} catch (Throwable $e) {
    echo emit_metric('ecommerce_database_up', 0, [], 'MySQL database connection status (1 = connected, 0 = down)', 'gauge');
    echo "# ERROR: " . $e->getMessage() . "\n";
}
