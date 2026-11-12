<?php
/**
 * Storage Orphan & Soft-Delete Cleanup Worker
 * Purges expired soft-deleted files and cleans up unreferenced media rows.
 *
 * Usage:
 *   CLI: php bin/cleanup-orphans.php [--dry-run] [--days=30]
 *   Cron: 0 3 * * * /usr/bin/php /home/ubuntu/e-commerce-system-production/bin/cleanup-orphans.php >/dev/null 2>&1
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Storage\StorageManager;

$options = getopt('', ['dry-run', 'days:']);
$dryRun = isset($options['dry-run']);
$retentionDays = isset($options['days']) ? max(1, (int)$options['days']) : 30;

echo "=== Storage Cleanup & Retention Worker ===" . PHP_EOL;
echo "Mode:      " . ($dryRun ? "\033[33mDRY-RUN\033[0m" : "\033[32mLIVE PURGE\033[0m") . PHP_EOL;
echo "Retention: {$retentionDays} days" . PHP_EOL;
echo str_repeat("-", 50) . PHP_EOL;

$pdo = db();
$storage = StorageManager::getInstance();

// 1. Purge files soft-deleted older than retention period
$stmtDeleted = $pdo->prepare(
    "SELECT * FROM files
     WHERE deleted_at IS NOT NULL
       AND deleted_at < DATE_SUB(NOW(), INTERVAL :days DAY)"
);
$stmtDeleted->execute([':days' => $retentionDays]);
$expiredFiles = $stmtDeleted->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($expiredFiles) . " expired soft-deleted file(s) to purge from storage." . PHP_EOL;

$purgedCount = 0;
foreach ($expiredFiles as $file) {
    $fileId = (int)$file['id'];
    $key    = $file['storage_key'];
    $provider = $file['provider'];

    if ($dryRun) {
        echo "  [DRY-RUN] Would purge #{$fileId}: {$provider}://{$key}" . PHP_EOL;
        $purgedCount++;
        continue;
    }

    try {
        $driver = $storage->getDriver($provider);
        $driver->delete($key);

        // If product, delete responsive variants
        if ($file['purpose'] === 'product') {
            $pathInfo = pathinfo($key);
            $dir = $pathInfo['dirname'];
            $fn  = $pathInfo['filename'];
            foreach (['thumb', 'card', 'detail'] as $sizeLabel) {
                $driver->delete("{$dir}/{$fn}_{$sizeLabel}.webp");
            }
        }

        // Remove row permanently
        $pdo->prepare("DELETE FROM files WHERE id = :id")->execute([':id' => $fileId]);
        echo "  [PURGED] File #{$fileId} ({$key}) removed from storage and database." . PHP_EOL;
        $purgedCount++;
    } catch (Throwable $e) {
        echo "  [ERROR] Could not purge file #{$fileId}: " . $e->getMessage() . PHP_EOL;
    }
}

// 2. Identify unreferenced orphan files (files not linked to any active table)
echo "Scanning for orphaned unreferenced media files older than 7 days..." . PHP_EOL;
$stmtOrphans = $pdo->query(
    "SELECT f.id, f.storage_key, f.purpose
     FROM files f
     WHERE f.status != 'deleted'
       AND f.deleted_at IS NULL
       AND f.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
       AND NOT EXISTS (SELECT 1 FROM products p WHERE p.file_id = f.id)
       AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.file_id = f.id)
       AND NOT EXISTS (SELECT 1 FROM product_variants pv WHERE pv.file_id = f.id)
       AND NOT EXISTS (SELECT 1 FROM customers c WHERE c.avatar_file_id = f.id)
       AND NOT EXISTS (SELECT 1 FROM users u WHERE u.avatar_file_id = f.id)
       AND NOT EXISTS (SELECT 1 FROM storefront_banners b WHERE b.file_id = f.id)
       AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.file_id = f.id)"
);
$orphanFiles = $stmtOrphans->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($orphanFiles) . " orphaned file(s)." . PHP_EOL;

$markedCount = 0;
foreach ($orphanFiles as $orphan) {
    $oId = (int)$orphan['id'];
    if ($dryRun) {
        echo "  [DRY-RUN] Would mark orphan file #{$oId} as deleted." . PHP_EOL;
        $markedCount++;
    } else {
        $pdo->prepare("UPDATE files SET status = 'deleted', deleted_at = NOW() WHERE id = :id")->execute([':id' => $oId]);
        echo "  [ORPHAN] Marked file #{$oId} ({$orphan['storage_key']}) as deleted." . PHP_EOL;
        $markedCount++;
    }
}

echo str_repeat("-", 50) . PHP_EOL;
echo "Cleanup complete: {$purgedCount} purged, {$markedCount} orphaned files soft-deleted." . PHP_EOL;

