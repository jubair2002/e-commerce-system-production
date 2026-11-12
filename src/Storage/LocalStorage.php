<?php

namespace App\Storage;

use RuntimeException;
use InvalidArgumentException;

class LocalStorage implements StorageInterface
{
    private string $basePath;
    private string $baseUrl;
    private string $appKey;

    public function __construct(string $basePath, string $baseUrl, string $appKey = '')
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $this->baseUrl  = rtrim($baseUrl, '/') . '/';
        $this->appKey   = $appKey;

        if (!is_dir($this->basePath)) {
            @mkdir($this->basePath, 0775, true);
        }
    }

    public function getProviderName(): string
    {
        return 'local';
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function resolvePath(string $key): string
    {
        // Prevent directory traversal
        $clean = str_replace("\0", '', $key);
        while (str_contains($clean, '../') || str_contains($clean, '..\\')) {
            $clean = str_replace(['../', '..\\'], '', $clean);
        }
        $clean = ltrim($clean, '/\\');
        return $this->basePath . '/' . $clean;
    }

    public function put(string $key, $sourcePathOrStream, array $opts = []): bool
    {
        $destPath = $this->resolvePath($key);
        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }

        if (is_resource($sourcePathOrStream)) {
            $destStream = @fopen($destPath, 'wb');
            if (!$destStream) {
                return false;
            }
            $res = stream_copy_to_stream($sourcePathOrStream, $destStream);
            fclose($destStream);
            @chmod($destPath, 0664);
            return $res !== false;
        }

        if (is_string($sourcePathOrStream)) {
            // Check if it's an existing file on disk
            if (strlen($sourcePathOrStream) < 4096 && @file_exists($sourcePathOrStream) && @is_file($sourcePathOrStream)) {
                $copied = @copy($sourcePathOrStream, $destPath);
                if ($copied) {
                    @chmod($destPath, 0664);
                    return true;
                }
                // Fallback to stream copy
                $in = @fopen($sourcePathOrStream, 'rb');
                $out = @fopen($destPath, 'wb');
                if ($in && $out) {
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    fclose($out);
                    @chmod($destPath, 0664);
                    return true;
                }
                if ($in) fclose($in);
                if ($out) fclose($out);
                return false;
            }

            // Treat as raw binary string content
            $res = @file_put_contents($destPath, $sourcePathOrStream, LOCK_EX);
            if ($res !== false) {
                @chmod($destPath, 0664);
                return true;
            }
        }

        return false;
    }

    public function getStream(string $key)
    {
        $path = $this->resolvePath($key);
        if (!file_exists($path) || !is_file($path)) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        return $stream ?: null;
    }

    public function delete(string $key): bool
    {
        $path = $this->resolvePath($key);
        if (file_exists($path) && is_file($path)) {
            return @unlink($path);
        }
        return true;
    }

    public function exists(string $key): bool
    {
        $path = $this->resolvePath($key);
        return file_exists($path) && is_file($path);
    }

    public function copy(string $src, string $dst): bool
    {
        $srcPath = $this->resolvePath($src);
        $dstPath = $this->resolvePath($dst);

        if (!file_exists($srcPath) || !is_file($srcPath)) {
            return false;
        }

        $dir = dirname($dstPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $res = @copy($srcPath, $dstPath);
        if ($res) {
            @chmod($dstPath, 0664);
        }
        return (bool)$res;
    }

    public function publicUrl(string $key): string
    {
        $clean = ltrim(str_replace('\\', '/', $key), '/');
        return $this->baseUrl . $clean;
    }

    public function signedUrl(string $key, int $ttlSeconds = 3600): string
    {
        $expires = time() + max(60, $ttlSeconds);
        $clean = ltrim(str_replace('\\', '/', $key), '/');
        $signature = hash_hmac('sha256', "{$clean}:{$expires}", $this->appKey ?: 'local_storage_secret');

        $url = $this->baseUrl . $clean;
        $separator = str_contains($url, '?') ? '&' : '?';
        return "{$url}{$separator}expires={$expires}&signature={$signature}";
    }

    public function presignedUpload(string $key, string $contentType, int $ttlSeconds = 3600): array
    {
        $expires = time() + max(60, $ttlSeconds);
        $clean = ltrim(str_replace('\\', '/', $key), '/');
        $signature = hash_hmac('sha256', "upload:{$clean}:{$expires}:{$contentType}", $this->appKey ?: 'local_storage_secret');

        return [
            'url'     => rtrim($this->baseUrl, '/') . "/../api/storage_upload.php?key=" . urlencode($clean) . "&expires={$expires}&signature={$signature}",
            'method'  => 'PUT',
            'headers' => [
                'Content-Type' => $contentType,
            ],
        ];
    }
}
