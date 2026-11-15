<?php
/**
 * Asynchronous Storage Sync Worker
 * Copies files saved on local storage to AWS S3 cloud storage.
 *
 * Usage:
 *   CLI: php bin/sync-storage.php [--limit=50] [--all]
 *   Cron: *\/5 * * * * www-data php /var/www/html/bin/sync-storage.php >> /var/log/storage-sync.log 2>&1
 */

// Load all application functions, cryptographic helpers, and database singleton
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crypto.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Storage\StorageManager;

$options = getopt('', ['limit:', 'all']);
$limit   = isset($options['limit']) ? max(1, (int)$options['limit']) : 50;
$syncAll = isset($options['all']);

$pdo = db();
$storage = StorageManager::getInstance();

// Determine target cloud provider:
// If S3 driver is available (configured either as Primary or Fallback in DB/env), sync to S3.
$s3Driver = null;
try {
    $s3Driver = $storage->getDriver('s3');
} catch (Throwable $e) {
    // S3 driver not available or not configured
}

$primaryDriver = $storage->getPrimaryDriver();
$primaryName   = $primaryDriver->getProviderName();

// Target driver is S3 if available, otherwise primary driver
$targetDriver = $s3Driver ?: $primaryDriver;
$targetName   = $targetDriver->getProviderName();

// Build query for files to sync
if ($syncAll) {
    // Sync all ready files currently on local storage to S3
    $stmt = $pdo->prepare(
        "SELECT * FROM files
         WHERE provider = 'local'
           AND status = 'ready'
           AND deleted_at IS NULL
         ORDER BY id ASC
         LIMIT :limit"
    );
} else {
    // Sync pending files (or any file where provider != targetName and sync_status = 'pending')
    $stmt = $pdo->prepare(
        "SELECT * FROM files
         WHERE sync_status = 'pending'
           AND status = 'ready'
           AND deleted_at IS NULL
         ORDER BY id ASC
         LIMIT :limit"
    );
}

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$pendingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendingRows)) {
    // Nothing to sync
    echo "[" . date('Y-m-d H:i:s') . "] Storage sync check: 0 pending files. Up to date." . PHP_EOL;
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting storage sync for " . count($pendingRows) . " file(s) to '{$targetName}'..." . PHP_EOL;

$synced = 0;
$failed = 0;

foreach ($pendingRows as $row) {
    $fileId = (int)$row['id'];
    $key    = $row['storage_key'];
    $sourceProviderName = $row['provider'];

    // If source and target are the same and not syncing all, just mark synced
    if ($sourceProviderName === $targetName && !$syncAll) {
        $pdo->prepare("UPDATE files SET sync_status = 'synced', updated_at = NOW() WHERE id = :id")
            ->execute([':id' => $fileId]);
        $synced++;
        continue;
    }

    try {
        $sourceDriver = $storage->getDriver($sourceProviderName);
        $stream = $sourceDriver->getStream($key);

        if (!$stream) {
            throw new RuntimeException("Could not open read stream from '{$sourceProviderName}' for key '{$key}'.");
        }

        $opts = [
            'mime_type'  => $row['mime_type'],
            'visibility' => $row['visibility'] ?? 'public',
            'metadata'   => [
                'original_name' => $row['original_name'],
                'purpose'       => $row['purpose'],
                'checksum'      => $row['checksum_sha256'],
                'synced_at'     => date('c'),
            ],
        ];

        $uploaded = $targetDriver->put($key, $stream, $opts);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$uploaded) {
            $err = method_exists($targetDriver, 'getLastError') ? $targetDriver->getLastError() : 'Put failed';
            throw new RuntimeException("Upload to '{$targetName}' failed: {$err}");
        }

        // If product image, also sync any responsive WebP variants
        if ($row['purpose'] === 'product') {
            $pathInfo = pathinfo($key);
            $dir = $pathInfo['dirname'];
            $fn  = $pathInfo['filename'];
            foreach (['thumb', 'card', 'detail'] as $sizeLabel) {
                $variantKey = "{$dir}/{$fn}_{$sizeLabel}.webp";
                if ($sourceDriver->exists($variantKey)) {
                    $vStream = $sourceDriver->getStream($variantKey);
                    if ($vStream) {
                        $targetDriver->put($variantKey, $vStream, ['mime_type' => 'image/webp', 'visibility' => 'public']);
                        if (is_resource($vStream)) {
                            fclose($vStream);
                        }
                    }
                }
            }
        }

        // Mark as synced in database
        $bucket = ($targetName === 's3' && method_exists($targetDriver, 'getBucket')) ? $targetDriver->getBucket() : null;
        $pdo->prepare(
            "UPDATE files
             SET provider = :p, bucket = :b, sync_status = 'synced', updated_at = NOW()
             WHERE id = :id"
        )->execute([
            ':p'  => $targetName,
            ':b'  => $bucket,
            ':id' => $fileId,
        ]);

        echo "  [OK] Synced file #{$fileId} ({$key}) from {$sourceProviderName} -> {$targetName}" . PHP_EOL;
        $synced++;
    } catch (Throwable $e) {
        $failed++;
        echo "  [FAIL] Failed to sync file #{$fileId} ({$key}): " . $e->getMessage() . PHP_EOL;
        error_log("[sync-storage] File #{$fileId} error: " . $e->getMessage());
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Storage sync finished: {$synced} synced, {$failed} failed." . PHP_EOL;
exit($failed > 0 ? 1 : 0);
