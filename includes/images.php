<?php
/**
 * Image Pipeline Helper (Phase D1).
 * Generates responsive WebP/JPEG cached variants and generates optimized <picture> tags.
 */

if (!defined('UPLOADS_CACHE_PATH')) {
    define('UPLOADS_CACHE_PATH', UPLOADS_PATH . '/cache');
    define('UPLOADS_CACHE_URL', UPLOADS_URL . 'cache/');
}

/**
 * Ensure image cache directory exists and is secured against script execution.
 */
function image_pipeline_init_cache(): void
{
    if (!is_dir(UPLOADS_CACHE_PATH)) {
        @mkdir(UPLOADS_CACHE_PATH, 0755, true);
    }
    $ht = UPLOADS_CACHE_PATH . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|phar)\$\">\nDeny from all\n</FilesMatch>\nOptions -Indexes\n");
    }
}

/**
 * Generate or retrieve a resized/converted variant of an image.
 */
function image_get_variant(string $relPath, int $targetWidth, string $format = 'webp'): ?string
{
    $pipelineEnabled = (int)setting('image_pipeline_enabled', '1') === 1;
    if (!$pipelineEnabled || !extension_loaded('gd')) {
        return null;
    }

    $sourceFull = UPLOADS_PATH . '/' . ltrim($relPath, '/');
    if (!file_exists($sourceFull) || !is_file($sourceFull)) {
        return null;
    }

    image_pipeline_init_cache();

    $hash = md5($relPath . '_' . filemtime($sourceFull) . '_' . $targetWidth . '_' . $format);
    $cacheFilename = $hash . '.' . $format;
    $cacheFull = UPLOADS_CACHE_PATH . '/' . $cacheFilename;

    if (file_exists($cacheFull)) {
        return UPLOADS_CACHE_URL . $cacheFilename;
    }

    // Generate variant using GD
    $info = @getimagesize($sourceFull);
    if (!$info) {
        return null;
    }

    $origW = $info[0];
    $origH = $info[1];
    $mime = $info['mime'];

    if ($origW <= 0 || $origH <= 0) {
        return null;
    }

    $targetW = min($targetWidth, $origW);
    $targetH = (int)round(($origH / $origW) * $targetW);

    $srcImg = null;
    switch ($mime) {
        case 'image/jpeg':
            $srcImg = @imagecreatefromjpeg($sourceFull);
            break;
        case 'image/png':
            $srcImg = @imagecreatefrompng($sourceFull);
            break;
        case 'image/webp':
            $srcImg = @imagecreatefromwebp($sourceFull);
            break;
    }

    if (!$srcImg) {
        return null;
    }

    $dstImg = imagecreatetruecolor($targetW, $targetH);
    if ($format === 'webp' || $mime === 'image/png') {
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
    }

    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);

    $saved = false;
    if ($format === 'webp' && function_exists('imagewebp')) {
        $saved = @imagewebp($dstImg, $cacheFull, 82);
    } elseif ($format === 'jpg' || $format === 'jpeg') {
        $saved = @imagejpeg($dstImg, $cacheFull, 82);
    }

    imagedestroy($srcImg);
    imagedestroy($dstImg);

    return $saved ? (UPLOADS_CACHE_URL . $cacheFilename) : null;
}

/**
 * Generate a responsive <picture> or <img> tag.
 *
 * @param string $src Relative file path (e.g. 'products/abc.jpg') or absolute URL
 * @param string $alt Alt text
 * @param string $sizes Responsive sizes attribute, e.g. '(max-width: 768px) 100vw, 320px'
 * @param array $opts ['loading' => 'lazy'|'eager', 'fetchpriority' => 'high'|'auto', 'width' => int, 'height' => int, 'class' => string]
 */
function img_tag(string $src, string $alt = '', string $sizes = '100vw', array $opts = []): string
{
    $loading = $opts['loading'] ?? 'lazy';
    $fetchpriority = $opts['fetchpriority'] ?? null;
    $width = $opts['width'] ?? null;
    $height = $opts['height'] ?? null;
    $class = $opts['class'] ?? '';

    $fullUrl = str_starts_with($src, 'http') || str_starts_with($src, 'data:') ? $src : (UPLOADS_URL . ltrim($src, '/'));
    
    $attr = 'src="' . e($fullUrl) . '" alt="' . e($alt) . '"';
    if ($class) $attr .= ' class="' . e($class) . '"';
    if ($width) $attr .= ' width="' . (int)$width . '"';
    if ($height) $attr .= ' height="' . (int)$height . '"';
    if ($loading) $attr .= ' loading="' . e($loading) . '"';
    if ($fetchpriority) $attr .= ' fetchpriority="' . e($fetchpriority) . '"';

    // If it's a relative uploads path, generate webp variant if possible
    if (!str_starts_with($src, 'http') && !str_starts_with($src, 'data:')) {
        $variantWebp = image_get_variant($src, $width ? (int)$width : 640, 'webp');
        if ($variantWebp) {
            return '<picture>' .
                   '<source type="image/webp" srcset="' . e($variantWebp) . '" sizes="' . e($sizes) . '">' .
                   '<img ' . $attr . '>' .
                   '</picture>';
        }
    }

    return '<img ' . $attr . '>';
}

/**
 * Purge all files in image cache directory.
 */
function image_pipeline_clear_cache(): int
{
    if (!is_dir(UPLOADS_CACHE_PATH)) {
        return 0;
    }
    $count = 0;
    foreach (glob(UPLOADS_CACHE_PATH . '/*.*') as $file) {
        if (is_file($file) && basename($file) !== '.htaccess') {
            @unlink($file);
            $count++;
        }
    }
    return $count;
}
