<?php
/**
 * Asynchronous Storage Sync Worker
 * Copies files saved on fallback storage to primary storage when sync_status = 'pending'.
 *
 * Usage:
 *   CLI: php bin/sync-storage.php [--limit=50]
 *   Cron: *\/5 * * * * /usr/bin/php /home/ubuntu/e-commerce-system-production/bin/sync-storage.php >/dev/null 2>&1
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Storage\StorageManager;

$options = getopt('', ['limit:']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 50;

$pdo = db();
$storage = StorageManager::getInstance();

$primary = $storage->getPrimaryDriver();
$primaryName = $primary->getProviderName();

// Find pending sync rows where provider != primaryName
$stmt = $pdo->prepare(
    "SELECT * FROM files
     WHERE sync_status = 'pending'
       AND status = 'ready'
       AND deleted_at IS NULL
     ORDER BY id ASC
     LIMIT :limit"
);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$pendingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendingRows)) {
    // Nothing to sync
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting sync for " . count($pendingRows) . " pending file(s) to primary provider '{$primaryName}'..." . PHP_EOL;

$synced = 0;
$failed = 0;

foreach ($pendingRows as $row) {
    $fileId = (int)$row['id'];
    $key    = $row['storage_key'];
    $sourceProviderName = $row['provider'];

    if ($sourceProviderName === $primaryName) {
        // Already on primary
        $pdo->prepare("UPDATE files SET sync_status = 'synced', updated_at = NOW() WHERE id = :id")
            ->execute([':id' => $fileId]);
        $synced++;
        continue;
    }

    try {
        $sourceDriver = $storage->getDriver($sourceProviderName);
        $stream = $sourceDriver->getStream($key);

        if (!$stream) {
            throw new RuntimeException("Could not open read stream from source provider '{$sourceProviderName}' for key '{$key}'.");
        }

        // Copy to primary
        $opts = [
            'mime_type'  => $row['mime_type'],
            'visibility' => $row['visibility'],
            'metadata'   => [
                'original_name' => $row['original_name'],
                'purpose'       => $row['purpose'],
                'checksum'      => $row['checksum_sha256'],
                'synced_at'     => date('c'),
            ],
        ];

        $uploaded = $primary->put($key, $stream, $opts);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$uploaded || !$primary->exists($key)) {
            throw new RuntimeException("Write to primary provider '{$primaryName}' failed or verification failed.");
        }

        // If product image, also sync any responsive variants
        if ($row['purpose'] === 'product') {
            $pathInfo = pathinfo($key);
            $dir = $pathInfo['dirname'];
            $fn  = $pathInfo['filename'];
            foreach (['thumb', 'card', 'detail'] as $sizeLabel) {
                $variantKey = "{$dir}/{$fn}_{$sizeLabel}.webp";
                if ($sourceDriver->exists($variantKey)) {
                    $vStream = $sourceDriver->getStream($variantKey);
                    if ($vStream) {
                        $primary->put($variantKey, $vStream, ['mime_type' => 'image/webp', 'visibility' => 'public']);
                        if (is_resource($vStream)) fclose($vStream);
                    }
                }
            }
        }

        // Mark as synced in database
        $pdo->prepare(
            "UPDATE files
             SET provider = :p, bucket = :b, sync_status = 'synced', updated_at = NOW()
             WHERE id = :id"
        )->execute([
            ':p'  => $primaryName,
            ':b'  => ($primaryName === 's3' && method_exists($primary, 'getBucket')) ? $primary->getBucket() : null,
            ':id' => $fileId,
        ]);

        echo "  [OK] Synced file #{$fileId} ({$key}) from {$sourceProviderName} -> {$primaryName}" . PHP_EOL;
        $synced++;
    } catch (Throwable $e) {
        $failed++;
        echo "  [FAIL] Failed to sync file #{$fileId} ({$key}): " . $e->getMessage() . PHP_EOL;
        error_log("[sync-storage] File #{$fileId} error: " . $e->getMessage());
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Sync finished: {$synced} synced, {$failed} failed." . PHP_EOL;
exit($failed > 0 ? 1 : 0);

