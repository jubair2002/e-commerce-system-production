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

// Security: strip null bytes and directory traversal sequences
$requested = str_replace(["\0", '../', '..\\'], '', (string)$requested);
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

if (!$filePath) {
    http_response_code(404);
    exit('File not found.');
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

if (
    (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
    (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime)
) {
    http_response_code(304);
    exit;
}

readfile($filePath);
exit;
