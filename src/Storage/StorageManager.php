<?php

namespace App\Storage;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

class StorageManager
{
    private static ?self $instance = null;

    private StorageConfig $config;
    private ?StorageInterface $primaryDriver = null;
    private ?StorageInterface $fallbackDriver = null;
    private ?PDO $pdo = null;

    // Standard purpose limits in bytes
    public const MAX_SIZES = [
        'avatar'   => 2 * 1024 * 1024,   // 2 MB
        'branding' => 2 * 1024 * 1024,   // 2 MB
        'banner'   => 5 * 1024 * 1024,   // 5 MB
        'product'  => 10 * 1024 * 1024,  // 10 MB
        'document' => 10 * 1024 * 1024,  // 10 MB
        'default'  => 10 * 1024 * 1024,  // 10 MB
    ];

    // Allowed MIME types per purpose
    public const ALLOWED_MIMES = [
        'avatar'   => ['image/jpeg', 'image/png', 'image/webp'],
        'branding' => ['image/png', 'image/jpeg', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml'],
        'banner'   => ['image/jpeg', 'image/png', 'image/webp'],
        'product'  => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'document' => ['application/pdf', 'application/json', 'text/csv', 'text/plain'],
    ];

    public const MIME_EXTENSIONS = [
        'image/jpeg'               => 'jpg',
        'image/pjpeg'              => 'jpg',
        'image/png'                => 'png',
        'image/x-png'              => 'png',
        'image/webp'               => 'webp',
        'image/gif'                => 'gif',
        'image/svg+xml'            => 'svg',
        'image/x-icon'             => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'application/pdf'          => 'pdf',
        'application/json'         => 'json',
        'text/csv'                 => 'csv',
        'text/plain'               => 'txt',
    ];

    public function __construct(?StorageConfig $config = null, ?PDO $pdo = null)
    {
        $this->config = $config ?? new StorageConfig();
        $this->pdo    = $pdo;

        $this->initDrivers();
    }

    public static function getInstance(?PDO $pdo = null): self
    {
        if (self::$instance === null) {
            $pdoInstance = $pdo ?: (function_exists('db') ? db() : null);
            self::$instance = new self(null, $pdoInstance);
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    private function getPdo(): PDO
    {
        if ($this->pdo === null) {
            if (function_exists('db')) {
                $this->pdo = db();
            } else {
                throw new RuntimeException("Database PDO connection is required for StorageManager database operations.");
            }
        }
        return $this->pdo;
    }

    private function initDrivers(): void
    {
        $primaryName = $this->config->getPrimaryDriver();
        $this->primaryDriver = $this->createDriver($primaryName);

        $fallbackName = $this->config->getFallbackDriver();
        if ($fallbackName !== 'none' && $fallbackName !== $primaryName) {
            $this->fallbackDriver = $this->createDriver($fallbackName);
        }
    }

    private function createDriver(string $name): StorageInterface
    {
        if ($name === 'local') {
            $cfg = $this->config->getLocalConfig();
            return new LocalStorage($cfg['base_path'], $cfg['base_url'], $cfg['app_key']);
        }

        if ($name === 's3') {
            $cfg = $this->config->getS3Config();
            return new S3Storage($cfg);
        }

        throw new InvalidArgumentException("Unsupported storage driver: '{$name}'");
    }

    public function getPrimaryDriver(): StorageInterface
    {
        if (!$this->primaryDriver) {
            $this->initDrivers();
        }
        return $this->primaryDriver;
    }

    public function getFallbackDriver(): ?StorageInterface
    {
        return $this->fallbackDriver;
    }

    public function getDriver(string $name): StorageInterface
    {
        if ($this->primaryDriver && $this->primaryDriver->getProviderName() === $name) {
            return $this->primaryDriver;
        }
        if ($this->fallbackDriver && $this->fallbackDriver->getProviderName() === $name) {
            return $this->fallbackDriver;
        }
        return $this->createDriver($name);
    }

    /**
     * Generate secure storage key: {purpose}/{yyyy}/{mm}/{random-32-hex}.{ext}
     */
    public function generateStorageKey(string $purpose, string $ext): string
    {
        $purpose = preg_replace('/[^a-z0-9_-]/i', '', strtolower($purpose)) ?: 'misc';
        $year    = date('Y');
        $month   = date('m');
        $random  = bin2hex(random_bytes(16));
        $ext     = ltrim(strtolower($ext), '.');

        return "{$purpose}/{$year}/{$month}/{$random}.{$ext}";
    }

    /**
     * Store an uploaded file with rigorous validation, sanitization, and fallback write logic.
     *
     * @param string $purpose e.g. 'avatar', 'product', 'banner', 'branding', 'document'
     * @param array $file $_FILES element with 'tmp_name', 'name', 'size', 'error'
     * @param array $options ['owner_type' => string, 'owner_id' => int, 'visibility' => 'public'|'private', 'is_test' => bool]
     * @return array
     */
    public function storeUploadedFile(string $purpose, array $file, array $options = []): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            $msg = function_exists('upload_error_message') ? upload_error_message($error) : "Upload error code {$error}";
            throw new RuntimeException($msg);
        }

        $tmpName = $file['tmp_name'] ?? '';
        if (empty($tmpName) || !file_exists($tmpName)) {
            throw new RuntimeException("Uploaded temporary file not found.");
        }

        // Validate is_uploaded_file unless explicitly running in test/cli mode
        $isTest = !empty($options['is_test']);
        if (!$isTest && !is_uploaded_file($tmpName)) {
            throw new RuntimeException("Security violation: file was not uploaded via HTTP POST.");
        }

        $originalName = basename((string)($file['name'] ?? 'file'));
        $fileSize     = (int)($file['size'] ?? filesize($tmpName));

        // 1. Max Size Check
        $maxAllowed = self::MAX_SIZES[$purpose] ?? self::MAX_SIZES['default'];
        if ($fileSize > $maxAllowed) {
            $mb = round($maxAllowed / (1024 * 1024), 1);
            throw new RuntimeException("File exceeds maximum allowed size of {$mb} MB.");
        }

        // 2. Strict MIME Type Detection via fileinfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $tmpName);
        if (PHP_VERSION_ID < 80500) {
            @finfo_close($finfo);
        }

        $allowedList = self::ALLOWED_MIMES[$purpose] ?? self::ALLOWED_MIMES['product'];
        if (!in_array($detectedMime, $allowedList, true)) {
            throw new RuntimeException("Invalid file format detected: {$detectedMime}. Format not allowed for {$purpose}.");
        }

        // 3. For images, verify image integrity with getimagesize
        $width = null;
        $height = null;
        $isImage = str_starts_with($detectedMime, 'image/') && $detectedMime !== 'image/svg+xml';
        if ($isImage) {
            $imgInfo = ImageProcessor::inspect($tmpName);
            if (!$imgInfo) {
                throw new RuntimeException("Corrupted or invalid image file uploaded.");
            }
            $width  = $imgInfo['width'];
            $height = $imgInfo['height'];
        }

        // 4. Sanitize SVG files to block XSS payloads if branding SVG
        if ($detectedMime === 'image/svg+xml') {
            $svgContent = (string)file_get_contents($tmpName);
            if (preg_match('/<script|javascript:|onload=|onerror=|onclick=/i', $svgContent)) {
                throw new RuntimeException("Security violation: SVG file contains potentially malicious active script content.");
            }
        }

        // 5. Image Transformation & EXIF Stripping
        $sourceToStore = $tmpName;
        $tempCleanup   = null;
        $finalMime     = $detectedMime;

        if ($purpose === 'avatar' && $isImage) {
            // Square crop, resize to 400x400, re-encode to WebP
            $proc = ImageProcessor::processAvatar($tmpName, 400, 85);
            $sourceToStore = $proc['temp_path'];
            $tempCleanup   = $proc['temp_path'];
            $finalMime     = $proc['mime_type'];
            $width         = $proc['width'];
            $height        = $proc['height'];
            $fileSize      = $proc['size'];
        } elseif ($purpose === 'product' && $isImage && function_exists('imagewebp')) {
            // Re-encode to clean WebP and strip EXIF
            $proc = ImageProcessor::processProductImage($tmpName, 1800, 85);
            $sourceToStore = $proc['temp_path'];
            $tempCleanup   = $proc['temp_path'];
            $finalMime     = $proc['mime_type'];
            $width         = $proc['width'];
            $height        = $proc['height'];
            $fileSize      = $proc['size'];
        }

        // 6. Determine final safe extension
        $safeExt = self::MIME_EXTENSIONS[$finalMime] ?? 'bin';
        $storageKey = $this->generateStorageKey($purpose, $safeExt);
        $checksum = hash_file('sha256', $sourceToStore);
        $visibility = $options['visibility'] ?? 'public';

        // 7. Store using Primary Driver with automatic Fallback
        $primary = $this->getPrimaryDriver();
        $fallback = $this->getFallbackDriver();

        $activeProvider = $primary->getProviderName();
        $syncStatus = 'synced';
        $putSuccess = false;

        $opts = [
            'mime_type'  => $finalMime,
            'visibility' => $visibility,
            'metadata'   => [
                'original_name' => substr($originalName, 0, 100),
                'purpose'       => $purpose,
                'checksum'      => $checksum,
            ],
        ];

        try {
            $putSuccess = $primary->put($storageKey, $sourceToStore, $opts);
        } catch (Throwable $e) {
            error_log("[StorageManager] Primary driver '{$activeProvider}' failed: " . $e->getMessage());
            $putSuccess = false;
        }

        if (!$putSuccess) {
            if ($fallback) {
                $activeProvider = $fallback->getProviderName();
                $syncStatus = 'pending';
                try {
                    $putSuccess = $fallback->put($storageKey, $sourceToStore, $opts);
                } catch (Throwable $e) {
                    error_log("[StorageManager] Fallback driver '{$activeProvider}' failed: " . $e->getMessage());
                    $putSuccess = false;
                }
            }
        }

        // If storage failed on all drivers, clean up temp file and throw
        if ($tempCleanup && file_exists($tempCleanup)) {
            @unlink($tempCleanup);
        }

        if (!$putSuccess) {
            throw new RuntimeException("Storage failure: unable to write file to primary or fallback storage providers.");
        }

        // 8. Record in database `files` table
        $pdo = $this->getPdo();
        $bucket = ($activeProvider === 's3' && method_exists($this->getDriver('s3'), 'getBucket'))
            ? $this->getDriver('s3')->getBucket()
            : null;

        $ownerType = $options['owner_type'] ?? null;
        $ownerId   = !empty($options['owner_id']) ? (int)$options['owner_id'] : null;

        $stmt = $pdo->prepare(
            "INSERT INTO files (
                storage_key, provider, bucket, original_name, mime_type, size,
                checksum_sha256, width, height, visibility, purpose, owner_type, owner_id,
                status, sync_status, created_at, updated_at
            ) VALUES (
                :key, :provider, :bucket, :orig, :mime, :size,
                :sha, :w, :h, :vis, :purpose, :otype, :oid,
                'ready', :sync, NOW(), NOW()
            )"
        );

        $stmt->execute([
            ':key'      => $storageKey,
            ':provider' => $activeProvider,
            ':bucket'   => $bucket,
            ':orig'     => $originalName,
            ':mime'     => $finalMime,
            ':size'     => $fileSize,
            ':sha'      => $checksum,
            ':w'        => $width,
            ':h'        => $height,
            ':vis'      => $visibility,
            ':purpose'  => $purpose,
            ':otype'    => $ownerType,
            ':oid'      => $ownerId,
            ':sync'     => $syncStatus,
        ]);

        $fileId = (int)$pdo->lastInsertId();

        // 9. Generate responsive variants for products if applicable
        if ($purpose === 'product' && $isImage && $fileId) {
            $this->generateResponsiveVariants($fileId, $storageKey, $sourceToStore, $activeProvider);
        }

        $activeDriver = $this->getDriver($activeProvider);
        $publicUrl = ($visibility === 'public')
            ? $activeDriver->publicUrl($storageKey)
            : $activeDriver->signedUrl($storageKey, 3600);

        return [
            'id'          => $fileId,
            'storage_key' => $storageKey,
            'provider'    => $activeProvider,
            'url'         => $publicUrl,
            'original_name' => $originalName,
            'mime_type'   => $finalMime,
            'size'        => $fileSize,
            'width'       => $width,
            'height'      => $height,
            'checksum'    => $checksum,
            'sync_status' => $syncStatus,
        ];
    }

    /**
     * Generate responsive WebP variants for a stored product image.
     */
    private function generateResponsiveVariants(int $parentFileId, string $parentKey, string $sourcePath, string $provider): void
    {
        $sizes = [
            'thumb'  => 300,
            'card'   => 600,
            'detail' => 1200,
        ];

        $driver = $this->getDriver($provider);
        $pathInfo = pathinfo($parentKey);
        $dir = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];

        foreach ($sizes as $label => $targetW) {
            $variant = ImageProcessor::generateVariant($sourcePath, $targetW, 82);
            if ($variant) {
                $variantKey = "{$dir}/{$filename}_{$label}.webp";
                $driver->put($variantKey, $variant['temp_path'], [
                    'mime_type'  => 'image/webp',
                    'visibility' => 'public',
                ]);
                @unlink($variant['temp_path']);
            }
        }
    }

    /**
     * Delete a file: soft-delete row in database, then delete from storage.
     */
    public function deleteFile(int $fileId, bool $purgeStorageImmediately = true): bool
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        // Soft delete row
        $stmtDel = $pdo->prepare("UPDATE files SET status = 'deleted', deleted_at = NOW() WHERE id = :id");
        $stmtDel->execute([':id' => $fileId]);

        if ($purgeStorageImmediately) {
            try {
                $driver = $this->getDriver($row['provider']);
                $driver->delete($row['storage_key']);

                // Delete responsive variants if product
                if ($row['purpose'] === 'product') {
                    $pathInfo = pathinfo($row['storage_key']);
                    $dir = $pathInfo['dirname'];
                    $fn  = $pathInfo['filename'];
                    foreach (['thumb', 'card', 'detail'] as $sizeLabel) {
                        $driver->delete("{$dir}/{$fn}_{$sizeLabel}.webp");
                    }
                }
            } catch (Throwable $e) {
                error_log("[StorageManager::deleteFile] Error purging object from storage: " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Resolve public or signed URL for a file row, ID, or legacy path.
     */
    public function getUrl($fileOrId, array $opts = []): string
    {
        if (empty($fileOrId)) {
            return $opts['fallback'] ?? '';
        }

        // 1. If integer ID provided
        if (is_numeric($fileOrId)) {
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare("SELECT * FROM files WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => (int)$fileOrId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $this->formatUrlFromRow($row, $opts);
            }
            return $opts['fallback'] ?? '';
        }

        // 2. If array row provided
        if (is_array($fileOrId) && isset($fileOrId['storage_key'], $fileOrId['provider'])) {
            return $this->formatUrlFromRow($fileOrId, $opts);
        }

        // 3. If string provided (could be storage key or legacy filename)
        if (is_string($fileOrId)) {
            $key = trim($fileOrId);
            if (str_starts_with($key, 'http://') || str_starts_with($key, 'https://') || str_starts_with($key, 'data:')) {
                return $key;
            }

            // Check if key exists in `files` table
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare("SELECT * FROM files WHERE storage_key = :k OR original_name = :orig LIMIT 1");
            $stmt->execute([':k' => $key, ':orig' => basename($key)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $this->formatUrlFromRow($row, $opts);
            }

            // Backwards compatibility: deliver via Primary driver public URL or local URL
            return $this->getPrimaryDriver()->publicUrl($key);
        }

        return $opts['fallback'] ?? '';
    }

    private function formatUrlFromRow(array $row, array $opts = []): string
    {
        $driver = $this->getDriver($row['provider']);
        $key = $row['storage_key'];

        // If specific responsive size requested for product image
        if (!empty($opts['size']) && in_array($opts['size'], ['thumb', 'thumbnail', 'card', 'detail'], true)) {
            $sizeLabel = ($opts['size'] === 'thumbnail') ? 'thumb' : $opts['size'];
            $pathInfo = pathinfo($key);
            $variantKey = "{$pathInfo['dirname']}/{$pathInfo['filename']}_{$sizeLabel}.webp";
            // Check if variant exists
            if ($driver->exists($variantKey)) {
                $key = $variantKey;
            }
        }

        if (($row['visibility'] ?? 'public') === 'private') {
            $ttl = (int)($opts['ttl'] ?? 3600);
            return $driver->signedUrl($key, $ttl);
        }

        return $driver->publicUrl($key);
    }
}
