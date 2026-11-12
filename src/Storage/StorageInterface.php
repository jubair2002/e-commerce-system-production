<?php

namespace App\Storage;

interface StorageInterface
{
    /**
     * Store a file from a local filesystem path, a string of binary content, or an open stream.
     *
     * @param string $key Object key / path
     * @param mixed $sourcePathOrStream Absolute local file path, stream resource, or binary string
     * @param array $opts Additional options (e.g. 'mime_type', 'visibility', 'metadata', 'cache_control')
     * @return bool
     */
    public function put(string $key, $sourcePathOrStream, array $opts = []): bool;

    /**
     * Get a readable PHP stream resource for the object.
     *
     * @param string $key
     * @return resource|null Returns stream resource or null if object not found / inaccessible
     */
    public function getStream(string $key);

    /**
     * Delete an object by key.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Check if an object exists by key.
     *
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool;

    /**
     * Copy an object from source key to destination key.
     *
     * @param string $src
     * @param string $dst
     * @return bool
     */
    public function copy(string $src, string $dst): bool;

    /**
     * Get the public URL for the object.
     *
     * @param string $key
     * @return string
     */
    public function publicUrl(string $key): string;

    /**
     * Get a temporary signed/presigned download URL for private objects.
     *
     * @param string $key
     * @param int $ttlSeconds Time to live in seconds (default 3600)
     * @return string
     */
    public function signedUrl(string $key, int $ttlSeconds = 3600): string;

    /**
     * Generate presigned upload URL and required headers for direct client-side upload.
     *
     * @param string $key
     * @param string $contentType
     * @param int $ttlSeconds
     * @return array Array with 'url', 'method', and 'headers'
     */
    public function presignedUpload(string $key, string $contentType, int $ttlSeconds = 3600): array;

    /**
     * Get the provider identifier name ('local' or 's3').
     *
     * @return string
     */
    public function getProviderName(): string;
}

