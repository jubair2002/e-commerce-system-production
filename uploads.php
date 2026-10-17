<?php
/**
 * Dynamic Uploads Delivery Controller
 * Safely serves media files from UPLOADS_PATH with HTTP caching.
 * Enables zero-overhead external uploads storage in production and development.
 */
require_once __DIR__ . '/config/config.php';

$requested = $_GET['file'] ?? '';
if (empty($requested)) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#^/(?:assets/)?uploads/(.+)$#', $uri, $matches)) {
        $requested = $matches[1];
    }
}

// Security: strip null bytes and directory traversal sequences
$requested = str_replace(["\0", '../', '..\\'], '', $requested);
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

// 1. Check primary UPLOADS_PATH
$filePath = UPLOADS_PATH . '/' . $requested;

// 2. Fallback to default in-repo assets/uploads if not found in custom UPLOADS_PATH
if (!file_exists($filePath) || !is_file($filePath)) {
    $fallback = ROOT_PATH . '/assets/uploads/' . $requested;
    if (file_exists($fallback) && is_file($fallback)) {
        $filePath = $fallback;
    } else {
        http_response_code(404);
        exit('File not found.');
    }
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
];
$contentType = $mimeTypes[$ext] ?? mime_content_type($filePath) ?: 'application/octet-stream';

// HTTP Caching (304 Not Modified support for sub-millisecond responses)
$mtime = filemtime($filePath);
$etag  = '"' . md5($filePath . $mtime . filesize($filePath)) . '"';

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($filePath));
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
