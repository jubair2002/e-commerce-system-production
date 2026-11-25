<?php
/**
 * Dynamic Uploads Delivery Controller
 * Safely serves media files from UPLOADS_PATH with HTTP caching.
 * Enables zero-overhead external uploads storage in production and development.
 */
require_once __DIR__ . '/config/config.php';

// Release session lock immediately if opened by config.php to avoid stalling concurrent image requests
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Clean any pre-existing output buffering to prevent corrupting binary media bytes
while (ob_get_level()) {
    ob_end_clean();
}

$requested = $_GET['file'] ?? '';
if (empty($requested)) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    // Strip query string if any
    if (strpos($uri, '?') !== false) {
        $uri = substr($uri, 0, strpos($uri, '?'));
    }
    // Match any /uploads/ or /assets/uploads/ pattern even with subfolder installs
    if (preg_match('#(?:^|/)(?:assets/)?uploads/(.+)$#', $uri, $matches)) {
        $requested = $matches[1];
    }
}

// Security: strip null bytes and directory traversal sequences recursively
$requested = str_replace("\0", '', (string)$requested);
$requested = str_replace('\\', '/', $requested);
while (strpos($requested, '../') !== false) {
    $requested = str_replace('../', '', $requested);
}
$requested = ltrim($requested, '/');

if (empty($requested)) {
    http_response_code(400);
    exit('Invalid file request.');
}

// Disallow direct execution or downloading of server-side code files
if (preg_match('/\.(php[0-9]?|phtml|phar|sh|pl|cgi|py|exe|bat|htaccess|env)$/i', $requested)) {
    http_response_code(403);
    exit('Access denied.');
}

// Build list of candidate file paths to inspect
$candidatePaths = [
    // 1. Primary custom UPLOADS_PATH
    UPLOADS_PATH . '/' . $requested,
    // 2. In-repo assets/uploads
    ROOT_PATH . '/assets/uploads/' . $requested,
];

// Profile picture path flexibility (checks both root and avatars/ folder)
if (str_starts_with($requested, 'avatars/')) {
    $bare = substr($requested, 8);
    $candidatePaths[] = UPLOADS_PATH . '/' . $bare;
    $candidatePaths[] = ROOT_PATH . '/assets/uploads/' . $bare;
} else {
    $candidatePaths[] = UPLOADS_PATH . '/avatars/' . $requested;
    $candidatePaths[] = ROOT_PATH . '/assets/uploads/avatars/' . $requested;
}

// Product image path flexibility (checks both root and products/ folder)
if (str_starts_with($requested, 'products/')) {
    $bare = substr($requested, 9);
    $candidatePaths[] = UPLOADS_PATH . '/' . $bare;
    $candidatePaths[] = ROOT_PATH . '/assets/uploads/' . $bare;
} else {
    $candidatePaths[] = UPLOADS_PATH . '/products/' . $requested;
    $candidatePaths[] = ROOT_PATH . '/assets/uploads/products/' . $requested;
}

// Branding / banner / category flexibility
foreach (['banners', 'branding', 'categories', 'store'] as $folder) {
    if (str_starts_with($requested, $folder . '/')) {
        $bare = substr($requested, strlen($folder) + 1);
        $candidatePaths[] = UPLOADS_PATH . '/' . $bare;
        $candidatePaths[] = ROOT_PATH . '/assets/uploads/' . $bare;
    }
}

$filePath = null;
foreach ($candidatePaths as $candidate) {
    if (!empty($candidate) && file_exists($candidate) && is_file($candidate)) {
        $filePath = $candidate;
        break;
    }
}

// Canonical path verification to prevent path traversal outside allowed asset directories
if ($filePath) {
    $realPath = realpath($filePath);
    $allowedRoots = array_filter([
        realpath(UPLOADS_PATH),
        realpath(ROOT_PATH . '/assets/uploads'),
        realpath(ROOT_PATH . '/assets'),
    ]);

    $isAllowed = false;
    if ($realPath !== false) {
        foreach ($allowedRoots as $root) {
            if ($root !== false && (str_starts_with($realPath, $root . DIRECTORY_SEPARATOR) || $realPath === $root)) {
                $isAllowed = true;
                break;
            }
        }
    }

    if (!$isAllowed) {
        http_response_code(403);
        exit('Access denied.');
    }
}

// Graceful fallback for responsive image variants (e.g. *_thumb.webp, *_card.webp)
if (!$filePath) {
    $variantPattern = '/_(thumb|thumbnail|card|detail)\.webp$/i';
    $prefixPattern  = '#(^|/)(?:thumb|thumbnail|card|detail)_(.+)#i';

    $baseCandidateName = null;
    if (preg_match($variantPattern, $requested)) {
        $baseCandidateName = preg_replace($variantPattern, '', $requested);
    } elseif (preg_match($prefixPattern, $requested, $pm)) {
        $dir = dirname($requested);
        $baseCandidateName = ($dir !== '.' ? $dir . '/' : '') . $pm[2];
        $baseCandidateName = ltrim($baseCandidateName, './');
    }

    if (!empty($baseCandidateName)) {
        $possibleExts = ['.webp', '.jpg', '.jpeg', '.png'];
        $baseCandidates = [];
        $baseWithoutExt = preg_replace('/\.[a-z0-9]+$/i', '', $baseCandidateName);

        foreach ($possibleExts as $ext) {
            $baseCandidates[] = UPLOADS_PATH . '/' . $baseWithoutExt . $ext;
            $baseCandidates[] = ROOT_PATH . '/assets/uploads/' . $baseWithoutExt . $ext;
            $baseCandidates[] = UPLOADS_PATH . '/products/' . $baseWithoutExt . $ext;
            $baseCandidates[] = ROOT_PATH . '/assets/uploads/products/' . $baseWithoutExt . $ext;
        }

        foreach ($baseCandidates as $bc) {
            if (file_exists($bc) && is_file($bc)) {
                $filePath = $bc;
                break;
            }
        }
    }
}

if (!$filePath) {
    // Deliver subtle, high-performance placeholder SVG instead of broken image box
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200"><rect width="200" height="200" fill="#f1f5f9"/><path d="M70 85 L130 85 L125 140 L75 140 Z" fill="none" stroke="#cbd5e1" stroke-width="2.5" stroke-linejoin="round"/><path d="M85 85 C85 70 115 70 115 85" fill="none" stroke="#cbd5e1" stroke-width="2.5"/></svg>';
    exit;
}

// MIME type detection
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
    'ico'  => 'image/x-icon',
    'pdf'  => 'application/pdf',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
];
$contentType = $mimeTypes[$ext] ?? (function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream');

// HTTP Caching (304 Not Modified support for sub-millisecond responses)
$mtime = filemtime($filePath);
$fsize = filesize($filePath);
$etag  = '"' . md5($filePath . $mtime . $fsize) . '"';

header('Content-Type: ' . $contentType);
header('Content-Length: ' . $fsize);
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Content-Type-Options: nosniff');
if ($ext === 'svg' || $contentType === 'image/svg+xml') {
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
}

if (
    (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
    (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime)
) {
    http_response_code(304);
    exit;
}

readfile($filePath);
exit;
