<?php
/**
 * Global application configuration.
 * All secrets and environment-specific settings are loaded from .env.
 */
require_once __DIR__ . '/env.php';

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

    define('BASE_URL', ($base === '' || $base === '/') ? '/' : (rtrim($base, '/') . '/'));
}
define('ADMIN_URL', BASE_URL . 'admin/');

// Absolute site URL (used in emails and background tasks)
$envSiteUrl = env('SITE_URL');
if (!empty($envSiteUrl)) {
    define('SITE_URL', rtrim((string)$envSiteUrl, '/') . '/');
} else {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme  = $isHttps ? 'https://' : 'http://';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SITE_URL', rtrim($scheme . $host . BASE_URL, '/') . '/');
}

// ----- Paths -----
define('ROOT_PATH',     dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('UPLOADS_PATH',  ROOT_PATH . '/assets/uploads');
define('UPLOADS_URL',   BASE_URL . 'assets/uploads/');

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
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
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

