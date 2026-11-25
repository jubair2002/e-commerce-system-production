<?php
/**
 * Global application configuration.
 * All secrets and environment-specific settings are loaded from .env.
 */
require_once __DIR__ . '/env.php';

// Composer Autoloader
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// ----- Database -----
define('DB_HOST',    (string)env('DB_HOST', '127.0.0.1'));
define('DB_PORT',    (string)env('DB_PORT', '3306'));
define('DB_NAME',    (string)env('DB_NAME', 'php_admin_panel'));
define('DB_USER',    (string)env('DB_USER', 'root'));
define('DB_PASS',    (string)env('DB_PASS', ''));
define('DB_CHARSET', (string)env('DB_CHARSET', 'utf8mb4'));

// ----- App -----
define('APP_NAME', (string)env('APP_NAME', 'PHP Admin Panel'));
define('APP_ENV',  (string)env('APP_ENV', 'development')); // 'development' or 'production'

// Application Encryption Key (used for encrypting sensitive credentials like SMTP passwords).
define('APP_KEY', (string)env('APP_KEY', 'CHANGE_ME_TO_64_RANDOM_CHARS'));

/**
 * Detect whether the current request is HTTPS, including behind AWS ALB, ELB, CloudFront, Cloudflare, or reverse proxies.
 */
if (!function_exists('is_https')) {
    function is_https(): bool
    {
        $force = function_exists('env') ? env('FORCE_HTTPS') : null;
        if ($force === true || $force === 'true' || $force === '1' || $force === 1) {
            return true;
        }

        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO']
            ?? $_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO']
            ?? $_SERVER['HTTP_X_FORWARDED_PROTOCOL']
            ?? '';

        if ($forwardedProto !== '') {
            $protos = explode(',', strtolower($forwardedProto));
            if (trim($protos[0]) === 'https') {
                return true;
            }
        }

        if (isset($_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }

        if (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower((string)$_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
            return true;
        }

        if (isset($_SERVER['HTTP_CF_VISITOR'])) {
            $cf = json_decode((string)$_SERVER['HTTP_CF_VISITOR'], true);
            if (isset($cf['scheme']) && strtolower($cf['scheme']) === 'https') {
                return true;
            }
        }

        return false;
    }
}

// Optional: Force HTTPS redirect when FORCE_HTTPS=true in production
$forceHttps = function_exists('env') ? env('FORCE_HTTPS', false) : false;
if (($forceHttps === true || $forceHttps === 'true' || $forceHttps === '1') && !is_https() && PHP_SAPI !== 'cli') {
    $currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!in_array($currentHost, ['localhost', '127.0.0.1'], true) && !str_starts_with($currentHost, 'localhost:') && !str_starts_with($currentHost, '127.0.0.1:')) {
        $dest = 'https://' . $currentHost . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $dest, true, 301);
        exit;
    }
}

// Auto-detect project root URL or use .env override
$envBaseUrl = env('BASE_URL');
if (!empty($envBaseUrl)) {
    define('BASE_URL', rtrim((string)$envBaseUrl, '/') . '/');
} else {
    $rootPath = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
    $scriptFilename = isset($_SERVER['SCRIPT_FILENAME']) ? str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME']) ?: $_SERVER['SCRIPT_FILENAME']) : '';
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    $base = '';
    if (!empty($rootPath) && !empty($scriptFilename) && str_starts_with($scriptFilename, $rootPath)) {
        $relScript = substr($scriptFilename, strlen($rootPath));
        if ($relScript !== '' && str_ends_with($scriptName, $relScript)) {
            $base = substr($scriptName, 0, -strlen($relScript));
        }
    }

    if ($base === '') {
        $scriptDir = str_replace('\\', '/', dirname($scriptName));
        $scriptDir = rtrim($scriptDir, '/');
        if (preg_match('#/(admin|account|api|cron|ajax|storefront|shop|cart|auth|track|pages|errors|seo)(/.*)?$#', $scriptDir)) {
            $scriptDir = preg_replace('#/(admin|account|api|cron|ajax|storefront|shop|cart|auth|track|pages|errors|seo)(/.*)?$#', '', $scriptDir);
        }
        $base = $scriptDir;
    }

    define('BASE_URL', ($base === '' || $base === '/' || $base === '.') ? '/' : (rtrim($base, '/') . '/'));
}
define('ADMIN_URL', BASE_URL . 'admin/');

// Absolute site URL (used in emails and background tasks)
$isHttps = is_https();
$envSiteUrl = env('SITE_URL');
if (!empty($envSiteUrl)) {
    $siteUrl = rtrim((string)$envSiteUrl, '/') . '/';
    if ($isHttps && str_starts_with(strtolower($siteUrl), 'http://')) {
        $siteUrl = 'https://' . substr($siteUrl, 7);
    }
    define('SITE_URL', $siteUrl);
} else {
    $scheme  = $isHttps ? 'https://' : 'http://';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SITE_URL', rtrim($scheme . $host . BASE_URL, '/') . '/');
}

// ----- Paths -----
define('ROOT_PATH',     dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
$envUploadsPath = env('UPLOADS_PATH');
$envUploadsUrl  = env('UPLOADS_URL');
define('UPLOADS_PATH',  !empty($envUploadsPath) ? rtrim($envUploadsPath, '/') : ROOT_PATH . '/assets/uploads');

if (!empty($envUploadsUrl)) {
    $rawUrl = (string)$envUploadsUrl;
    if (preg_match('#^https?://#i', $rawUrl)) {
        if ($isHttps && str_starts_with(strtolower($rawUrl), 'http://')) {
            $rawUrl = 'https://' . substr($rawUrl, 7);
        }
        define('UPLOADS_URL', rtrim($rawUrl, '/') . '/');
    } else {
        $trimmed = trim($rawUrl, '/');
        define('UPLOADS_URL', BASE_URL . ($trimmed !== '' ? $trimmed . '/' : ''));
    }
} else {
    define('UPLOADS_URL', BASE_URL . 'assets/uploads/');
}

// Automatically ensure uploads subdirectories and storage symlink exist if custom UPLOADS_PATH is used
if (UPLOADS_PATH !== ROOT_PATH . '/assets/uploads' && is_dir(UPLOADS_PATH)) {
    foreach (['products', 'avatars', 'banners', 'branding', 'categories', 'cache'] as $subDir) {
        $targetSub = UPLOADS_PATH . '/' . $subDir;
        if (!is_dir($targetSub)) {
            @mkdir($targetSub, 0775, true);
        }
    }
    $symlinkTarget = ROOT_PATH . '/uploads';
    if (!file_exists($symlinkTarget) && function_exists('symlink')) {
        @symlink(UPLOADS_PATH, $symlinkTarget);
    }
}

// ----- Error handling -----
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ----- Session -----
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    // Session fixation protection: test_sess only permitted during testing environment
    if (APP_ENV === 'testing' && !empty($_GET['test_sess']) && preg_match('/^[a-zA-Z0-9,-]+$/', $_GET['test_sess'])) {
        session_id($_GET['test_sess']);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    if (is_https()) {
        ini_set('session.cookie_secure', '1');
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Global HTTP security headers
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Default timezone - guarded for when settings table is not yet created
$appTimezone = 'Asia/Dhaka';
if (function_exists('setting')) {
    try {
        $stz = setting('timezone', 'Asia/Dhaka');
        if (!empty($stz)) {
            $appTimezone = $stz;
        }
    } catch (Throwable $e) {}
}
date_default_timezone_set($appTimezone);

/** Convenience helper for StorageManager */
if (!function_exists('storage')) {
    function storage(): \App\Storage\StorageManager
    {
        return \App\Storage\StorageManager::getInstance();
    }
}

