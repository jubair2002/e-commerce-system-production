<?php

namespace App\Storage;

use RuntimeException;

class StorageConfig
{
    private string $primary;
    private string $fallback;
    private array $localConfig;
    private array $s3Config;

    public function __construct(?array $overrides = null)
    {
        // 1. Determine primary and fallback drivers
        // Database settings override .env if present
        $dbPrimary  = function_exists('setting') ? setting('storage_primary') : null;
        $dbFallback = function_exists('setting') ? setting('storage_fallback') : null;

        $envPrimary  = function_exists('env') ? env('STORAGE_PRIMARY', 'local') : 'local';
        $envFallback = function_exists('env') ? env('STORAGE_FALLBACK', 'none') : 'none';

        $this->primary  = strtolower((string)($overrides['primary'] ?? $dbPrimary ?: $envPrimary));
        $this->fallback = strtolower((string)($overrides['fallback'] ?? $dbFallback ?: $envFallback));

        // 2. Local Storage Configuration
        $basePath = function_exists('env') ? env('LOCAL_STORAGE_PATH') : null;
        if (empty($basePath) && defined('UPLOADS_PATH')) {
            $basePath = UPLOADS_PATH;
        }
        if (empty($basePath)) {
            $basePath = dirname(__DIR__, 2) . '/assets/uploads';
        }

        $baseUrl = function_exists('env') ? env('LOCAL_PUBLIC_BASE_URL') : null;
        if (empty($baseUrl) && defined('UPLOADS_URL')) {
            $baseUrl = UPLOADS_URL;
        }
        if (empty($baseUrl)) {
            $baseUrl = '/assets/uploads/';
        }

        $appKey = defined('APP_KEY') ? APP_KEY : (function_exists('env') ? (string)env('APP_KEY', '') : '');

        $this->localConfig = [
            'base_path' => $basePath,
            'base_url'  => $baseUrl,
            'app_key'   => $appKey,
        ];

        // 3. AWS S3 Configuration
        $dbRegion    = function_exists('setting') ? setting('storage_aws_region') : null;
        $dbBucket    = function_exists('setting') ? setting('storage_s3_bucket') : null;
        $dbKey       = function_exists('setting') ? setting('storage_aws_access_key_id') : null;
        $dbSecretEnc = function_exists('setting') ? setting('storage_aws_secret_access_key') : null;
        $dbSecret    = null;
        if ($dbSecretEnc !== null && $dbSecretEnc !== '') {
            $dbSecret = function_exists('app_decrypt') ? (string)app_decrypt($dbSecretEnc) : '';
        } elseif ($dbSecretEnc === '') {
            $dbSecret = '';
        }
        $dbPublicBaseUrl = function_exists('setting') ? setting('storage_s3_public_base_url') : null;
        $dbPrefix        = function_exists('setting') ? setting('storage_s3_key_prefix') : null;

        $resolvedKey = (string)($overrides['s3']['key'] ?? ($dbKey !== null ? $dbKey : (function_exists('env') ? env('AWS_ACCESS_KEY_ID', '') : '')));
        $resolvedSecret = (string)($overrides['s3']['secret'] ?? ($dbSecret !== null ? $dbSecret : (function_exists('env') ? env('AWS_SECRET_ACCESS_KEY', '') : '')));

        // Strip placeholders
        if (str_starts_with($resolvedKey, 'your-aws-') || str_starts_with($resolvedKey, 'AKIA_PLACEHOLDER')) {
            $resolvedKey = '';
            $resolvedSecret = '';
        }

        $this->s3Config = [
            'region'                   => (string)($overrides['s3']['region'] ?? ($dbRegion ?: (function_exists('env') ? env('AWS_REGION', 'us-east-1') : 'us-east-1'))),
            'bucket'                   => (string)($overrides['s3']['bucket'] ?? ($dbBucket ?: (function_exists('env') ? env('S3_BUCKET', '') : ''))),
            'key'                      => $resolvedKey,
            'secret'                   => $resolvedSecret,
            'public_base_url'          => (string)($overrides['s3']['public_base_url'] ?? ($dbPublicBaseUrl ?: (function_exists('env') ? env('S3_PUBLIC_BASE_URL', '') : ''))),
            'prefix'                   => (string)($overrides['s3']['prefix'] ?? ($dbPrefix ?: (function_exists('env') ? env('S3_KEY_PREFIX', '') : ''))),
            'endpoint'                 => function_exists('env') ? env('S3_ENDPOINT', null) : null,
            'use_path_style_endpoint'  => function_exists('env') ? (bool)env('S3_USE_PATH_STYLE_ENDPOINT', false) : false,
        ];

        // Validate
        $this->validate();
    }

    private function validate(): void
    {
        $validDrivers = ['local', 's3'];
        if (!in_array($this->primary, $validDrivers, true)) {
            throw new RuntimeException("Invalid STORAGE_PRIMARY driver '{$this->primary}'. Must be 'local' or 's3'.");
        }

        $validFallbacks = ['none', 'local', 's3'];
        if (!in_array($this->fallback, $validFallbacks, true)) {
            throw new RuntimeException("Invalid STORAGE_FALLBACK driver '{$this->fallback}'. Must be 'none', 'local', or 's3'.");
        }

        if ($this->primary === $this->fallback && $this->fallback !== 'none') {
            throw new RuntimeException("STORAGE_PRIMARY and STORAGE_FALLBACK cannot both be set to '{$this->primary}'.");
        }

        if ($this->primary === 's3' || $this->fallback === 's3') {
            if (empty($this->s3Config['bucket'])) {
                // If S3 is primary, bucket is strictly required. If S3 is fallback, warn or throw.
                if ($this->primary === 's3') {
                    throw new RuntimeException("S3_BUCKET is required when STORAGE_PRIMARY is set to 's3'.");
                }
            }
        }
    }

    public function getPrimaryDriver(): string
    {
        return $this->primary;
    }

    public function getFallbackDriver(): string
    {
        return $this->fallback;
    }

    public function getLocalConfig(): array
    {
        return $this->localConfig;
    }

    public function getS3Config(): array
    {
        return $this->s3Config;
    }
}

