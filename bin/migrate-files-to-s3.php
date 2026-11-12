<?php
/**
 * Idempotent File Migration CLI: Local Disk -> AWS S3
 *
 * Usage:
 *   php bin/migrate-files-to-s3.php [--dry-run] [--limit=100] [--purpose=product|avatar|banner|branding]
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Storage\StorageConfig;
use App\Storage\S3Storage;
use App\Storage\ImageProcessor;

$options = getopt('', ['dry-run', 'limit:', 'purpose:', 'batch-size:']);
$dryRun    = isset($options['dry-run']);
$limit     = isset($options['limit']) ? (int)$options['limit'] : 0;
$purpose   = isset($options['purpose']) ? strtolower(trim($options['purpose'])) : null;
$batchSize = isset($options['batch-size']) ? max(1, (int)$options['batch-size']) : 50;

echo "=== AWS S3 Media Migration Tool ===" . PHP_EOL;
echo "Mode:       " . ($dryRun ? "\033[33mDRY-RUN (No files uploaded, no DB changes)\033[0m" : "\033[32mLIVE EXECUTION\033[0m") . PHP_EOL;
if ($purpose) echo "Filter:     Purpose = {$purpose}" . PHP_EOL;
if ($limit)   echo "Limit:      Max {$limit} files" . PHP_EOL;
echo "Batch Size: {$batchSize}" . PHP_EOL;
echo str_repeat("-", 60) . PHP_EOL;

$pdo = db();
$config = new StorageConfig();
$s3Config = $config->getS3Config();

if (empty($s3Config['bucket']) && !$dryRun) {
    echo "\033[31mError:\033[0m S3_BUCKET is not configured. Please set S3_BUCKET in .env or via Admin Settings." . PHP_EOL;
    exit(1);
}

$s3 = null;
if (!$dryRun) {
    try {
        $s3 = new S3Storage($s3Config);
    } catch (Throwable $e) {
        echo "\033[31mError initializing S3 storage driver:\033[0m " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

$uploadsPath = defined('UPLOADS_PATH') ? UPLOADS_PATH : (dirname(__DIR__) . '/assets/uploads');
$inRepoPath  = dirname(__DIR__) . '/assets/uploads';

// Task queue for items to migrate
$queue = [];

// 1. Gather products
if (!$purpose || $purpose === 'product') {
    $stmt = $pdo->query("SELECT id, name, image, file_id FROM products WHERE image IS NOT NULL AND image != ''");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $queue[] = [
            'type'        => 'product_primary',
            'purpose'     => 'product',
            'table'       => 'products',
            'pk'          => $r['id'],
            'col_name'    => 'image',
            'col_fid'     => 'file_id',
            'filename'    => $r['image'],
            'existing_fid'=> $r['file_id'],
            'search_dirs' => [
                $uploadsPath . '/products',
                $uploadsPath,
                $inRepoPath . '/products',
                $inRepoPath,
            ],
        ];
    }

    $stmtG = $pdo->query("SELECT id, product_id, filename, file_id FROM product_images WHERE filename IS NOT NULL AND filename != ''");
    while ($r = $stmtG->fetch(PDO::FETCH_ASSOC)) {
        $queue[] = [
            'type'        => 'product_gallery',
            'purpose'     => 'product',
            'table'       => 'product_images',
            'pk'          => $r['id'],
            'col_name'    => 'filename',
            'col_fid'     => 'file_id',
            'filename'    => $r['filename'],
            'existing_fid'=> $r['file_id'],
            'search_dirs' => [
                $uploadsPath . '/products',
                $uploadsPath,
                $inRepoPath . '/products',
                $inRepoPath,
            ],
        ];
    }
}

// 2. Gather customer avatars
if (!$purpose || $purpose === 'avatar') {
    $stmt = $pdo->query("SELECT id, name, avatar, avatar_file_id FROM customers WHERE avatar IS NOT NULL AND avatar != '' AND deleted_at IS NULL");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $queue[] = [
            'type'        => 'customer_avatar',
            'purpose'     => 'avatar',
            'table'       => 'customers',
            'pk'          => $r['id'],
            'col_name'    => 'avatar',
            'col_fid'     => 'avatar_file_id',
            'filename'    => $r['avatar'],
            'existing_fid'=> $r['avatar_file_id'],
            'search_dirs' => [
                $uploadsPath . '/avatars',
                $uploadsPath,
                $inRepoPath . '/avatars',
                $inRepoPath,
            ],
        ];
    }

    $stmtU = $pdo->query("SELECT id, name, avatar, avatar_file_id FROM users WHERE avatar IS NOT NULL AND avatar != ''");
    while ($r = $stmtU->fetch(PDO::FETCH_ASSOC)) {
        $queue[] = [
            'type'        => 'user_avatar',
            'purpose'     => 'avatar',
            'table'       => 'users',
            'pk'          => $r['id'],
            'col_name'    => 'avatar',
            'col_fid'     => 'avatar_file_id',
            'filename'    => $r['avatar'],
            'existing_fid'=> $r['avatar_file_id'],
            'search_dirs' => [
                $uploadsPath . '/avatars',
                $uploadsPath,
                $inRepoPath . '/avatars',
                $inRepoPath,
            ],
        ];
    }
}

// 3. Gather storefront banners
if (!$purpose || $purpose === 'banner') {
    $stmt = $pdo->query("SELECT id, title, image, file_id FROM storefront_banners WHERE image IS NOT NULL AND image != ''");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $queue[] = [
            'type'        => 'banner',
            'purpose'     => 'banner',
            'table'       => 'storefront_banners',
            'pk'          => $r['id'],
            'col_name'    => 'image',
            'col_fid'     => 'file_id',
            'filename'    => $r['image'],
            'existing_fid'=> $r['file_id'],
            'search_dirs' => [
                $uploadsPath . '/banners',
                $uploadsPath,
                $inRepoPath . '/banners',
                $inRepoPath,
            ],
        ];
    }
}

// 4. Gather branding
if (!$purpose || $purpose === 'branding') {
    $stmt = $pdo->query("SELECT key_name, value FROM settings WHERE key_name IN ('store_logo', 'store_favicon') AND value IS NOT NULL AND value != ''");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $queue[] = [
            'type'        => 'branding',
            'purpose'     => 'branding',
            'table'       => 'settings',
            'pk'          => $r['key_name'],
            'col_name'    => 'value',
            'col_fid'     => null,
            'filename'    => $r['value'],
            'existing_fid'=> null,
            'search_dirs' => [
                $uploadsPath . '/branding',
                $uploadsPath . '/store',
                $uploadsPath,
                $inRepoPath . '/branding',
                $inRepoPath,
            ],
        ];
    }
}

$totalFound = count($queue);
echo "Discovered {$totalFound} database media reference(s) to inspect." . PHP_EOL;

if ($totalFound === 0) {
    echo "No media files currently found in database rows. Nothing to migrate." . PHP_EOL;
    exit(0);
}

$processed = 0;
$uploadedCount = 0;
$skippedCount = 0;
$missingCount = 0;
$errorCount = 0;

foreach ($queue as $idx => $item) {
    if ($limit > 0 && $processed >= $limit) {
        echo "Limit of {$limit} reached. Stopping batch." . PHP_EOL;
        break;
    }
    $processed++;
    $num = $idx + 1;

    $cleanFilename = ltrim($item['filename'], '/');

    // Check if already migrated to S3 in `files` table
    $stmtCheck = $pdo->prepare("SELECT id, storage_key, provider FROM files WHERE (original_name = :orig OR storage_key = :k) AND provider = 's3' AND deleted_at IS NULL LIMIT 1");
    $stmtCheck->execute([':orig' => basename($cleanFilename), ':k' => $cleanFilename]);
    $existingFile = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existingFile) {
        $skippedCount++;
        echo "[$num/$totalFound] \033[36m[SKIP]\033[0m {$item['type']} #{$item['pk']} ({$cleanFilename}) -> already migrated to S3 (File ID: {$existingFile['id']})" . PHP_EOL;
        // Ensure referencing table has file_id set if missing
        if (!empty($item['col_fid']) && empty($item['existing_fid']) && !$dryRun) {
            $pdo->prepare("UPDATE `{$item['table']}` SET `{$item['col_fid']}` = :fid WHERE id = :pk")
                ->execute([':fid' => $existingFile['id'], ':pk' => $item['pk']]);
        }
        continue;
    }

    // Locate source file on local disk
    $localSourcePath = null;
    foreach ($item['search_dirs'] as $dir) {
        $candidate = $dir . '/' . basename($cleanFilename);
        if (file_exists($candidate) && is_file($candidate)) {
            $localSourcePath = $candidate;
            break;
        }
    }

    if (!$localSourcePath) {
        $missingCount++;
        echo "[$num/$totalFound] \033[33m[MISSING ON DISK]\033[0m {$item['type']} #{$item['pk']}: {$cleanFilename}" . PHP_EOL;
        continue;
    }

    // Detect MIME and checksum
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $localSourcePath);
    finfo_close($finfo);

    $fileSize = filesize($localSourcePath);
    $checksum = hash_file('sha256', $localSourcePath);
    $ext = pathinfo($localSourcePath, PATHINFO_EXTENSION) ?: 'bin';
    $targetKey = "{$item['purpose']}/" . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . strtolower($ext);

    $imgInfo = ImageProcessor::inspect($localSourcePath);
    $width  = $imgInfo ? $imgInfo['width'] : null;
    $height = $imgInfo ? $imgInfo['height'] : null;

    if ($dryRun) {
        echo "[$num/$totalFound] \033[32m[DRY-RUN]\033[0m Would copy {$localSourcePath} (" . round($fileSize / 1024, 1) . " KB) -> s3://{$s3Config['bucket']}/{$targetKey}" . PHP_EOL;
        $uploadedCount++;
        continue;
    }

    // Perform S3 Upload & Verification
    try {
        $pdo->beginTransaction();

        $success = $s3->put($targetKey, $localSourcePath, [
            'mime_type'  => $mimeType,
            'visibility' => 'public',
            'metadata'   => [
                'original_name' => basename($cleanFilename),
                'checksum'      => $checksum,
                'migrated_from' => 'local_disk',
            ],
        ]);

        if (!$success || !$s3->exists($targetKey)) {
            throw new RuntimeException("S3 PutObject or existence check failed.");
        }

        // Insert into files table
        $stmtIns = $pdo->prepare(
            "INSERT INTO files (
                storage_key, provider, bucket, original_name, mime_type, size,
                checksum_sha256, width, height, visibility, purpose, owner_type, owner_id,
                status, sync_status, created_at, updated_at
            ) VALUES (
                :k, 's3', :bucket, :orig, :mime, :size,
                :sha, :w, :h, 'public', :purpose, :otype, :oid,
                'ready', 'synced', NOW(), NOW()
            )"
        );
        $stmtIns->execute([
            ':k'       => $targetKey,
            ':bucket'  => $s3Config['bucket'],
            ':orig'    => basename($cleanFilename),
            ':mime'    => $mimeType,
            ':size'    => $fileSize,
            ':sha'     => $checksum,
            ':w'       => $width,
            ':h'       => $height,
            ':purpose' => $item['purpose'],
            ':otype'   => $item['table'],
            ':oid'     => is_numeric($item['pk']) ? $item['pk'] : null,
        ]);
        $fileId = (int)$pdo->lastInsertId();

        // Update referencing table
        if (!empty($item['col_fid']) && is_numeric($item['pk'])) {
            $pdo->prepare("UPDATE `{$item['table']}` SET `{$item['col_fid']}` = :fid, `{$item['col_name']}` = :k WHERE id = :pk")
                ->execute([':fid' => $fileId, ':k' => $targetKey, ':pk' => $item['pk']]);
        } elseif ($item['table'] === 'settings') {
            update_setting($item['pk'], $targetKey);
        }

        $pdo->commit();
        $uploadedCount++;
        echo "[$num/$totalFound] \033[32m[OK]\033[0m Copied {$cleanFilename} -> s3://{$s3Config['bucket']}/{$targetKey} (ID: {$fileId})" . PHP_EOL;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorCount++;
        echo "[$num/$totalFound] \033[31m[ERROR]\033[0m Failed to migrate {$cleanFilename}: " . $e->getMessage() . PHP_EOL;
    }
}

echo str_repeat("-", 60) . PHP_EOL;
echo "Migration Summary:" . PHP_EOL;
echo "  Processed: {$processed}" . PHP_EOL;
echo "  Copied:    {$uploadedCount}" . PHP_EOL;
echo "  Skipped:   {$skippedCount}" . PHP_EOL;
echo "  Missing:   {$missingCount}" . PHP_EOL;
echo "  Errors:    {$errorCount}" . PHP_EOL;
echo "  Originals: \033[32mUNTOUCHED (100% preserved)\033[0m" . PHP_EOL;
exit($errorCount > 0 ? 1 : 0);

