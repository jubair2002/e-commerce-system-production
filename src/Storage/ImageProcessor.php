<?php

namespace App\Storage;

use RuntimeException;

class ImageProcessor
{
    /**
     * Inspect image dimensions and verify it is a valid image.
     */
    public static function inspect(string $filePath): ?array
    {
        if (!file_exists($filePath) || !is_file($filePath)) {
            return null;
        }

        $info = @getimagesize($filePath);
        if (!$info || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }

        return [
            'width'     => (int)$info[0],
            'height'    => (int)$info[1],
            'mime_type' => (string)$info['mime'],
        ];
    }

    /**
     * Create a GD image resource from file based on detected MIME.
     */
    private static function createImageFromFile(string $filePath, string $mime)
    {
        switch ($mime) {
            case 'image/jpeg':
            case 'image/pjpeg':
                $img = @imagecreatefromjpeg($filePath);
                if ($img && function_exists('exif_read_data')) {
                    $img = self::autoRotate($img, $filePath);
                }
                return $img;
            case 'image/png':
            case 'image/x-png':
                return @imagecreatefrompng($filePath);
            case 'image/webp':
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null;
            case 'image/gif':
                return @imagecreatefromgif($filePath);
            default:
                return null;
        }
    }

    /**
     * Auto-rotate JPEG based on EXIF orientation tag.
     */
    private static function autoRotate($image, string $filePath)
    {
        $exif = @exif_read_data($filePath);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $image = imagerotate($image, 180, 0);
                    break;
                case 6:
                    $image = imagerotate($image, -90, 0);
                    break;
                case 8:
                    $image = imagerotate($image, 90, 0);
                    break;
            }
        }
        return $image;
    }

    /**
     * Process avatar: center crop to square, resize to target size, re-encode to WebP (stripping EXIF).
     *
     * @param string $sourcePath
     * @param int $targetSize e.g. 400
     * @param int $quality 0-100 (default 85)
     * @return array ['temp_path' => string, 'width' => int, 'height' => int, 'mime_type' => 'image/webp', 'size' => int]
     */
    public static function processAvatar(string $sourcePath, int $targetSize = 400, int $quality = 85): array
    {
        $info = self::inspect($sourcePath);
        if (!$info) {
            throw new RuntimeException("Invalid or unreadable image file for avatar processing.");
        }

        $srcImg = self::createImageFromFile($sourcePath, $info['mime_type']);
        if (!$srcImg) {
            throw new RuntimeException("Could not decode image with GD.");
        }

        $origW = imagesx($srcImg);
        $origH = imagesy($srcImg);

        // Center square crop
        $minSide = min($origW, $origH);
        $srcX = (int)(($origW - $minSide) / 2);
        $srcY = (int)(($origH - $minSide) / 2);

        $destImg = imagecreatetruecolor($targetSize, $targetSize);
        imagealphablending($destImg, false);
        imagesavealpha($destImg, true);

        imagecopyresampled($destImg, $srcImg, 0, 0, $srcX, $srcY, $targetSize, $targetSize, $minSide, $minSide);

        $tempFile = tempnam(sys_get_temp_dir(), 'avatar_') . '.webp';
        $saved = imagewebp($destImg, $tempFile, $quality);

        if (PHP_VERSION_ID < 80500) {
            @imagedestroy($srcImg);
            @imagedestroy($destImg);
        }

        if (!$saved || !file_exists($tempFile)) {
            throw new RuntimeException("Failed to encode avatar as WebP.");
        }

        return [
            'temp_path' => $tempFile,
            'width'     => $targetSize,
            'height'    => $targetSize,
            'mime_type' => 'image/webp',
            'size'      => (int)filesize($tempFile),
        ];
    }

    /**
     * Process product image or general image: optionally resize if max width exceeded, re-encode to WebP.
     *
     * @param string $sourcePath
     * @param int $maxWidth
     * @param int $quality
     * @return array
     */
    public static function processProductImage(string $sourcePath, int $maxWidth = 2000, int $quality = 85): array
    {
        $info = self::inspect($sourcePath);
        if (!$info) {
            throw new RuntimeException("Invalid or unreadable image file.");
        }

        $srcImg = self::createImageFromFile($sourcePath, $info['mime_type']);
        if (!$srcImg) {
            throw new RuntimeException("Could not decode image with GD.");
        }

        $origW = imagesx($srcImg);
        $origH = imagesy($srcImg);

        $targetW = min($maxWidth, $origW);
        $targetH = (int)round(($origH / $origW) * $targetW);

        $destImg = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($destImg, false);
        imagesavealpha($destImg, true);

        imagecopyresampled($destImg, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);

        $tempFile = tempnam(sys_get_temp_dir(), 'img_') . '.webp';
        $saved = imagewebp($destImg, $tempFile, $quality);

        if (PHP_VERSION_ID < 80500) {
            @imagedestroy($srcImg);
            @imagedestroy($destImg);
        }

        if (!$saved || !file_exists($tempFile)) {
            throw new RuntimeException("Failed to encode image as WebP.");
        }

        return [
            'temp_path' => $tempFile,
            'width'     => $targetW,
            'height'    => $targetH,
            'mime_type' => 'image/webp',
            'size'      => (int)filesize($tempFile),
        ];
    }

    /**
     * Generate responsive size variant for an image.
     */
    public static function generateVariant(string $sourcePath, int $targetWidth, int $quality = 82): ?array
    {
        $info = self::inspect($sourcePath);
        if (!$info) {
            return null;
        }

        $srcImg = self::createImageFromFile($sourcePath, $info['mime_type']);
        if (!$srcImg) {
            return null;
        }

        $origW = imagesx($srcImg);
        $origH = imagesy($srcImg);

        $targetW = min($targetWidth, $origW);
        $targetH = (int)round(($origH / $origW) * $targetW);

        $destImg = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($destImg, false);
        imagesavealpha($destImg, true);

        imagecopyresampled($destImg, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);

        $tempFile = tempnam(sys_get_temp_dir(), 'variant_') . '.webp';
        $saved = imagewebp($destImg, $tempFile, $quality);

        if (PHP_VERSION_ID < 80500) {
            @imagedestroy($srcImg);
            @imagedestroy($destImg);
        }

        if (!$saved || !file_exists($tempFile)) {
            return null;
        }

        return [
            'temp_path' => $tempFile,
            'width'     => $targetW,
            'height'    => $targetH,
            'mime_type' => 'image/webp',
            'size'      => (int)filesize($tempFile),
        ];
    }
}
