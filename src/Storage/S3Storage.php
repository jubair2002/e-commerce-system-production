<?php

namespace App\Storage;

use Aws\S3\S3Client;
use Aws\S3\ObjectUploader;
use Aws\Exception\AwsException;
use RuntimeException;
use Throwable;

class S3Storage implements StorageInterface
{
    private S3Client $client;
    private string $bucket;
    private string $prefix;
    private ?string $publicBaseUrl;
    private string $delivery;
    private ?string $lastError = null;

    public function __construct(array $config)
    {
        $this->bucket = (string)($config['bucket'] ?? '');
        if (empty($this->bucket)) {
            throw new RuntimeException("S3 bucket name is required for S3Storage.");
        }

        $this->delivery = (string)($config['delivery'] ?? 'public');
        $this->prefix = trim((string)($config['prefix'] ?? ''), '/');
        if ($this->prefix !== '') {
            $this->prefix .= '/';
        }

        $this->publicBaseUrl = !empty($config['public_base_url']) ? rtrim((string)$config['public_base_url'], '/') : null;

        $clientOptions = [
            'version' => 'latest',
            'region'  => (string)($config['region'] ?? 'us-east-1'),
        ];

        // Custom endpoint for MinIO / LocalStack / alternative S3-compatible providers
        if (!empty($config['endpoint'])) {
            $clientOptions['endpoint'] = (string)$config['endpoint'];
        }
        if (isset($config['use_path_style_endpoint'])) {
            $clientOptions['use_path_style_endpoint'] = (bool)$config['use_path_style_endpoint'];
        }

        // Authentication: If static credentials are provided, use them;
        // otherwise use EC2 IAM Instance Profile directly (bypassing dummy environment variables)
        $key    = trim((string)($config['key'] ?? ''));
        $secret = trim((string)($config['secret'] ?? ''));
        $isPlaceholder = str_starts_with($key, 'your-aws-') || str_starts_with($key, 'AKIA_PLACEHOLDER');

        if ($key !== '' && $secret !== '' && !$isPlaceholder) {
            $clientOptions['credentials'] = [
                'key'    => $key,
                'secret' => $secret,
            ];
            if (!empty($config['token'])) {
                $clientOptions['credentials']['token'] = (string)$config['token'];
            }
        } else {
            // When static key/secret are not provided in settings, use the AWS default provider chain.
            // This safely supports:
            // 1. AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY from Docker / system environment / .env
            // 2. AWS ECS Container Task Role (AWS_CONTAINER_CREDENTIALS_RELATIVE_URI)
            // 3. AWS EC2 Instance Profile (IMDSv1 & IMDSv2)
            // 4. Standard ~/.aws/credentials file
            try {
                $clientOptions['credentials'] = \Aws\Credentials\CredentialProvider::defaultProvider();
            } catch (Throwable $e) {
                // Fallback to instance profile if default provider chain initialization fails
                $clientOptions['credentials'] = \Aws\Credentials\CredentialProvider::instanceProfile();
            }
        }

        $this->client = new S3Client($clientOptions);
    }

    public function getProviderName(): string
    {
        return 's3';
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getClient(): S3Client
    {
        return $this->client;
    }

    private function resolveKey(string $key): string
    {
        $clean = str_replace(["\0", '../', '..\\'], '', $key);
        $clean = ltrim($clean, '/\\');
        return $this->prefix . $clean;
    }

    public function put(string $key, $sourcePathOrStream, array $opts = []): bool
    {
        $fullKey = $this->resolveKey($key);
        $mimeType = $opts['mime_type'] ?? 'application/octet-stream';
        $cacheControl = $opts['cache_control'] ?? 'public, max-age=31536000, immutable';
        $metadata = $opts['metadata'] ?? [];

        $source = null;
        $size = null;

        if (is_resource($sourcePathOrStream)) {
            $source = $sourcePathOrStream;
            $stat = @fstat($source);
            if ($stat && isset($stat['size']) && $stat['size'] >= 0) {
                $size = (int)$stat['size'];
            }
            if (@stream_get_meta_data($source)['seekable'] ?? false) {
                @rewind($source);
            }
        } elseif (is_string($sourcePathOrStream)) {
            if (strlen($sourcePathOrStream) < 4096 && @file_exists($sourcePathOrStream) && @is_file($sourcePathOrStream)) {
                $source = @fopen($sourcePathOrStream, 'rb');
                $size = @filesize($sourcePathOrStream);
            } else {
                // String content in memory
                $source = fopen('php://temp', 'r+');
                fwrite($source, $sourcePathOrStream);
                rewind($source);
                $size = strlen($sourcePathOrStream);
            }
        }

        if (!$source) {
            return false;
        }

        try {
            // Use multipart uploader for large objects (> 5 MB) or unknown stream length
            if ($size === null || $size > 5 * 1024 * 1024) {
                $uploader = new ObjectUploader(
                    $this->client,
                    $this->bucket,
                    $fullKey,
                    $source,
                    null, // Do not send x-amz-acl header; modern S3 buckets have ACLs disabled
                    [
                        'before_upload' => function (\Aws\Command $command) use ($mimeType, $cacheControl, $metadata) {
                            $command['ContentType'] = $mimeType;
                            $command['CacheControl'] = $cacheControl;
                            if (!empty($metadata)) {
                                $command['Metadata'] = $metadata;
                            }
                        },
                    ]
                );
                $result = $uploader->upload();
                return ($result['@metadata']['statusCode'] ?? 0) === 200;
            }

            // Standard PutObject for smaller files
            $params = [
                'Bucket'       => $this->bucket,
                'Key'          => $fullKey,
                'Body'         => $source,
                'ContentType'  => $mimeType,
                'CacheControl' => $cacheControl,
            ];
            if (!empty($metadata)) {
                $params['Metadata'] = $metadata;
            }

            $res = $this->client->putObject($params);
            return ($res['@metadata']['statusCode'] ?? 0) === 200;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log("[S3Storage::put] Upload error for key '{$fullKey}': " . $e->getMessage());
            return false;
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getStream(string $key)
    {
        $fullKey = $this->resolveKey($key);
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $fullKey,
            ]);
            $body = $result['Body'] ?? null;
            if ($body && method_exists($body, 'detach')) {
                return $body->detach();
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function delete(string $key): bool
    {
        $fullKey = $this->resolveKey($key);
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $fullKey,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log("[S3Storage::delete] Delete error for key '{$fullKey}': " . $e->getMessage());
            return false;
        }
    }

    public function exists(string $key): bool
    {
        $fullKey = $this->resolveKey($key);
        try {
            return $this->client->doesObjectExistV2($this->bucket, $fullKey);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function copy(string $src, string $dst): bool
    {
        $fullSrc = $this->resolveKey($src);
        $fullDst = $this->resolveKey($dst);

        try {
            $this->client->copyObject([
                'Bucket'     => $this->bucket,
                'Key'        => $fullDst,
                'CopySource' => rawurlencode($this->bucket . '/' . $fullSrc),
            ]);
            return true;
        } catch (Throwable $e) {
            error_log("[S3Storage::copy] Copy error from '{$fullSrc}' to '{$fullDst}': " . $e->getMessage());
            return false;
        }
    }

    public function publicUrl(string $key): string
    {
        $fullKey = $this->resolveKey($key);
        if ($this->publicBaseUrl) {
            return $this->publicBaseUrl . '/' . ltrim($fullKey, '/');
        }
        if ($this->delivery === 'presigned') {
            return $this->signedUrl($key, 86400); // 24 hours
        }
        return $this->client->getObjectUrl($this->bucket, $fullKey);
    }

    public function signedUrl(string $key, int $ttlSeconds = 3600): string
    {
        $fullKey = $this->resolveKey($key);
        $ttl = max(60, min(604800, $ttlSeconds)); // AWS allows up to 7 days
        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $fullKey,
        ]);
        $request = $this->client->createPresignedRequest($cmd, "+{$ttl} seconds");
        return (string)$request->getUri();
    }

    public function presignedUpload(string $key, string $contentType, int $ttlSeconds = 3600): array
    {
        $fullKey = $this->resolveKey($key);
        $ttl = max(60, min(604800, $ttlSeconds));
        $cmd = $this->client->getCommand('PutObject', [
            'Bucket'      => $this->bucket,
            'Key'         => $fullKey,
            'ContentType' => $contentType,
        ]);
        $request = $this->client->createPresignedRequest($cmd, "+{$ttl} seconds");

        return [
            'url'     => (string)$request->getUri(),
            'method'  => 'PUT',
            'headers' => [
                'Content-Type' => $contentType,
            ],
        ];
    }
}

