<?php
/**
 * FileCache — Lightweight, PSR-compatible High-Performance File-Based Cache.
 *
 * Designed for no-framework PHP on shared hosting or 1-2 vCPU VPS (Zero Redis/Memcached dependencies).
 * Features atomic writes via tmp+rename to prevent race conditions and torn reads under concurrency.
 *
 * =========================================================================
 * CACHE INVALIDATION CHECKLIST:
 * =========================================================================
 * 1. Product created/updated/deleted:
 *    -> FileCache::deletePattern('products:*');
 *    -> FileCache::deletePattern('home:*');
 *    -> FileCache::deletePattern('shop:*');
 *
 * 2. Category created/updated/deleted:
 *    -> FileCache::delete('categories:tree');
 *    -> FileCache::deletePattern('categories:*');
 *    -> FileCache::deletePattern('shop:*');
 *
 * 3. CMS page saved/updated:
 *    -> FileCache::delete("pages:{$slug}");
 *    -> FileCache::deletePattern('pages:*');
 *
 * 4. Settings updated (Admin Settings):
 *    -> FileCache::delete('settings:all');
 *
 * 5. Banners updated:
 *    -> FileCache::deletePattern('banners:*');
 *
 * 6. Order marked completed:
 *    -> DO NOT invalidate products cache (sold_count is eventually consistent on next TTL).
 *
 * CRITICAL RULE:
 * Never cache or read-stale anything inside an active database transaction.
 * All writes must be atomic (tempnam + chmod + rename).
 * =========================================================================
 */

final class FileCache
{
    /** Version prefix prepended to all keys. Bump to bust all cache instantly. */
    public const VERSION = 'v2:';

    /** Root directory for cache files */
    private static ?string $storageDir = null;

    /**
     * Get the absolute path to storage/cache directory.
     */
    public static function getStorageDir(): string
    {
        if (self::$storageDir === null) {
            $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
            self::$storageDir = $root . '/storage/cache';
            if (!is_dir(self::$storageDir)) {
                @mkdir(self::$storageDir, 0775, true);
            }
        }
        return self::$storageDir;
    }

    /**
     * Normalize key with version prefix.
     */
    public static function normalizeKey(string $key): string
    {
        if (str_starts_with($key, self::VERSION)) {
            return $key;
        }
        return self::VERSION . ltrim($key, ':');
    }

    /**
     * Compute path to cache file using PSR-compatible 2-char sub-sharding:
     * storage/cache/<sha1(key)[0:2]>/<sha1(key)>.cache
     */
    public static function getFilePath(string $normalizedKey): string
    {
        $hash = sha1($normalizedKey);
        $subDir = self::getStorageDir() . '/' . substr($hash, 0, 2);
        if (!is_dir($subDir)) {
            @mkdir($subDir, 0775, true);
        }
        return $subDir . '/' . $hash . '.cache';
    }

    /**
     * Fetch an item from cache. Returns null if expired or missing.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Never read cache inside active transaction to preserve read-your-own-writes
        if (function_exists('db')) {
            try {
                if (db()->inTransaction()) {
                    return $default;
                }
            } catch (Throwable) {}
        }

        $normKey = self::normalizeKey($key);
        $filePath = self::getFilePath($normKey);

        if (!file_exists($filePath)) {
            return $default;
        }

        $content = @file_get_contents($filePath);
        if ($content === false || strlen($content) < 10) {
            return $default;
        }

        $payload = @unserialize($content);
        if (!is_array($payload) || !isset($payload['exp'], $payload['data'], $payload['key'])) {
            @unlink($filePath);
            return $default;
        }

        // Validate key to protect against hash collisions
        if ($payload['key'] !== $normKey) {
            return $default;
        }

        // Check expiration (0 means forever)
        if ($payload['exp'] > 0 && $payload['exp'] < time()) {
            @unlink($filePath);
            return $default;
        }

        return $payload['data'];
    }

    /**
     * Store an item in cache atomically.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache (must be serializable)
     * @param int $ttl Time to live in seconds (default 300 = 5 min)
     * @return bool
     */
    public static function set(string $key, mixed $value, int $ttl = 300): bool
    {
        // Do not cache anything inside a transaction
        if (function_exists('db')) {
            try {
                if (db()->inTransaction()) {
                    return false;
                }
            } catch (Throwable) {}
        }

        $normKey = self::normalizeKey($key);
        $filePath = self::getFilePath($normKey);
        $dir = dirname($filePath);

        $payload = [
            'key'  => $normKey,
            'exp'  => $ttl > 0 ? (time() + $ttl) : 0,
            'data' => $value,
        ];

        $serialized = serialize($payload);

        // Atomic write: write to temp file on same filesystem, then rename()
        $tmpFile = @tempnam($dir, 'tmp_c_');
        if ($tmpFile === false) {
            return false;
        }

        if (@file_put_contents($tmpFile, $serialized, LOCK_EX) === false) {
            @unlink($tmpFile);
            return false;
        }

        @chmod($tmpFile, 0664);

        if (!@rename($tmpFile, $filePath)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }

    /**
     * Delete an item from cache.
     */
    public static function delete(string $key): bool
    {
        $normKey = self::normalizeKey($key);
        $filePath = self::getFilePath($normKey);
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }
        return true;
    }

    /**
     * Delete cache keys matching a wildcard pattern (e.g., 'shop:*' or 'v1:products:*').
     */
    public static function deletePattern(string $pattern): int
    {
        $dir = self::getStorageDir();
        if (!is_dir($dir)) {
            return 0;
        }

        // Clean prefix
        if (str_starts_with($pattern, self::VERSION)) {
            $prefix = $pattern;
        } else {
            $prefix = self::VERSION . ltrim($pattern, ':');
        }

        $regexPattern = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($prefix, '/')) . '$/i';
        $deleted = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'cache') {
                $content = @file_get_contents($item->getPathname());
                if ($content !== false) {
                    $payload = @unserialize($content);
                    if (is_array($payload) && isset($payload['key'])) {
                        if (preg_match($regexPattern, $payload['key'])) {
                            @unlink($item->getPathname());
                            $deleted++;
                        }
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @param string $key
     * @param int $ttl Seconds
     * @param callable $fn Callback to generate fresh data
     * @return mixed
     */
    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $fresh = $fn();
        self::set($key, $fresh, $ttl);
        return $fresh;
    }

    /**
     * Wipe all cached items completely.
     */
    public static function flush(): bool
    {
        $dir = self::getStorageDir();
        if (!is_dir($dir)) {
            return true;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return true;
    }
}
