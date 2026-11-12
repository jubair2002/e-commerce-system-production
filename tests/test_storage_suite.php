<?php
/**
 * Comprehensive Automated Test Suite for Storage Layer
 *
 * Covers:
 * - Driver operations (LocalStorage)
 * - Key generation scheme
 * - Strict MIME & getimagesize validation
 * - Negative security tests (fake extensions, path traversal, oversize, SVG XSS)
 * - Storage modes: Local only, S3 only (mock/driver), Dual mode with S3 failure fallback
 * - End-to-end StorageManager lifecycle (store, getUrl, delete)
 * - Backwards compatibility URL helpers
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Storage\LocalStorage;
use App\Storage\S3Storage;
use App\Storage\StorageConfig;
use App\Storage\StorageManager;
use App\Storage\ImageProcessor;

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  \033[32m[PASS]\033[0m {$name}" . PHP_EOL;
    } else {
        $failed++;
        echo "  \033[31m[FAIL]\033[0m {$name}" . ($details ? " - {$details}" : '') . PHP_EOL;
    }
}

echo "==========================================================" . PHP_EOL;
echo "Starting Storage Layer Comprehensive Test Suite" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$tmpDir = sys_get_temp_dir() . '/storage_test_' . time();
@mkdir($tmpDir, 0775, true);

// -------------------------------------------------------------
// TEST SUITE 1: LocalStorage Driver Operations
// -------------------------------------------------------------
echo PHP_EOL . "--- 1. LocalStorage Driver Operations ---" . PHP_EOL;
$local = new LocalStorage($tmpDir, 'http://localhost/uploads/', 'test_app_key_1234567890');

// Put string
$key1 = 'test/doc.txt';
$res1 = $local->put($key1, 'Hello Storage Layer');
assert_test("LocalStorage::put with string content", $res1 && file_exists($tmpDir . '/' . $key1));

// Exists
assert_test("LocalStorage::exists returns true for stored object", $local->exists($key1));
assert_test("LocalStorage::exists returns false for non-existent object", !$local->exists('non_existent.txt'));

// GetStream
$stream = $local->getStream($key1);
$content = $stream ? stream_get_contents($stream) : '';
if (is_resource($stream)) fclose($stream);
assert_test("LocalStorage::getStream reads content accurately", $content === 'Hello Storage Layer');

// Copy
$keyCopy = 'test/doc_copy.txt';
$resCopy = $local->copy($key1, $keyCopy);
assert_test("LocalStorage::copy copies object to destination", $resCopy && $local->exists($keyCopy));

// Public URL
$url = $local->publicUrl($key1);
assert_test("LocalStorage::publicUrl builds valid base URL", $url === 'http://localhost/uploads/test/doc.txt');

// Signed URL
$signed = $local->signedUrl($key1, 3600);
assert_test("LocalStorage::signedUrl includes expires and signature params", str_contains($signed, 'expires=') && str_contains($signed, 'signature='));

// Delete
$del = $local->delete($key1);
assert_test("LocalStorage::delete removes file from disk", $del && !$local->exists($key1));

// Path traversal protection
$traversalKey = '../../etc/passwd';
$local->put($traversalKey, 'traversal test');
assert_test("LocalStorage prevents path traversal outside base directory",
    str_starts_with($local->resolvePath($traversalKey), $tmpDir) && !str_contains($local->resolvePath($traversalKey), '..') && $local->exists('etc/passwd'));

// -------------------------------------------------------------
// TEST SUITE 2: Storage Key Generation Scheme
// -------------------------------------------------------------
echo PHP_EOL . "--- 2. Storage Key Generation Scheme ---" . PHP_EOL;
$manager = StorageManager::getInstance();

$keyGen1 = $manager->generateStorageKey('avatar', 'webp');
$keyGen2 = $manager->generateStorageKey('product', 'jpg');
$keyGen3 = $manager->generateStorageKey('../bad/purpose..', '.png');

$pattern = '#^[a-z0-9_-]+/\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]+$#';
assert_test("Key conforms to {purpose}/{yyyy}/{mm}/{32hex}.{ext}", (bool)preg_match($pattern, $keyGen1), "Key: {$keyGen1}");
assert_test("Key uses current year and month", str_contains($keyGen1, '/' . date('Y/m') . '/'));
assert_test("Key generation sanitizes purpose and extension", !str_contains($keyGen3, '..') && !str_starts_with($keyGen3, '/'));
assert_test("Two generated keys are cryptographically unique", $keyGen1 !== $manager->generateStorageKey('avatar', 'webp'));

// -------------------------------------------------------------
// TEST SUITE 3: Image Processing & EXIF Sanitization
// -------------------------------------------------------------
echo PHP_EOL . "--- 3. Image Processing & Sanitization ---" . PHP_EOL;

// Create a valid 200x100 test JPEG using GD
$validImgPath = $tmpDir . '/valid_test.jpg';
$im = imagecreatetruecolor(200, 100);
$bg = imagecolorallocate($im, 200, 50, 50);
imagefill($im, 0, 0, $bg);
imagejpeg($im, $validImgPath, 90);
if (PHP_VERSION_ID < 80500) {
    @imagedestroy($im);
}

$info = ImageProcessor::inspect($validImgPath);
assert_test("ImageProcessor::inspect detects dimensions and MIME", $info && $info['width'] === 200 && $info['height'] === 100 && $info['mime_type'] === 'image/jpeg');

// Process avatar: square crop to 400x400 WebP
$avatarProc = ImageProcessor::processAvatar($validImgPath, 400, 85);
assert_test("ImageProcessor::processAvatar crops and resizes to 400x400 WebP", $avatarProc['width'] === 400 && $avatarProc['height'] === 400 && $avatarProc['mime_type'] === 'image/webp' && file_exists($avatarProc['temp_path']));
@unlink($avatarProc['temp_path']);

// -------------------------------------------------------------
// TEST SUITE 4: Negative Security Tests
// -------------------------------------------------------------
echo PHP_EOL . "--- 4. Negative Security Tests ---" . PHP_EOL;

// 1. Fake extension: PHP script masquerading as .jpg
$fakeJpgPath = $tmpDir . '/malicious.jpg';
file_put_contents($fakeJpgPath, '<?php echo "malicious code"; ?>');
$fakeFileObj = [
    'name'     => 'malicious.jpg',
    'tmp_name' => $fakeJpgPath,
    'size'     => filesize($fakeJpgPath),
    'error'    => UPLOAD_ERR_OK,
];

$caughtFake = false;
try {
    $manager->storeUploadedFile('product', $fakeFileObj, ['is_test' => true]);
} catch (RuntimeException $e) {
    $caughtFake = true;
}
assert_test("Fake extension (PHP payload renamed to .jpg) is rejected by MIME/image inspection", $caughtFake);

// 2. Oversize file check
$oversizePath = $tmpDir . '/huge.jpg';
file_put_contents($oversizePath, 'dummy data');
$oversizeObj = [
    'name'     => 'huge.jpg',
    'tmp_name' => $oversizePath,
    'size'     => 15 * 1024 * 1024, // 15MB > 10MB limit
    'error'    => UPLOAD_ERR_OK,
];
$caughtOversize = false;
try {
    $manager->storeUploadedFile('product', $oversizeObj, ['is_test' => true]);
} catch (RuntimeException $e) {
    $caughtOversize = true;
}
assert_test("Oversize file exceeding purpose limit is strictly rejected", $caughtOversize);

// 3. SVG XSS injection check
$xssSvgPath = $tmpDir . '/logo.svg';
file_put_contents($xssSvgPath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie);</script></svg>');
$xssSvgObj = [
    'name'     => 'logo.svg',
    'tmp_name' => $xssSvgPath,
    'size'     => filesize($xssSvgPath),
    'error'    => UPLOAD_ERR_OK,
];
$caughtSvgXss = false;
try {
    $manager->storeUploadedFile('branding', $xssSvgObj, ['is_test' => true]);
} catch (RuntimeException $e) {
    $caughtSvgXss = true;
}
assert_test("SVG with active JavaScript/XSS payload is detected and rejected", $caughtSvgXss);

// -------------------------------------------------------------
// TEST SUITE 5: Storage Modes & Primary/Fallback Failover Logic
// -------------------------------------------------------------
echo PHP_EOL . "--- 5. Storage Modes & Primary/Fallback Logic ---" . PHP_EOL;

// Mode 1: Local only
$cfgLocal = new StorageConfig([
    'primary'  => 'local',
    'fallback' => 'none',
]);
$mgrLocal = new StorageManager($cfgLocal, db());
assert_test("Mode 1 (Local only): Primary driver is LocalStorage, fallback is null",
    $mgrLocal->getPrimaryDriver()->getProviderName() === 'local' && $mgrLocal->getFallbackDriver() === null);

// Store valid image in Mode 1
$validFileObj = [
    'name'     => 'product_sample.jpg',
    'tmp_name' => $validImgPath,
    'size'     => filesize($validImgPath),
    'error'    => UPLOAD_ERR_OK,
];
$stored1 = $mgrLocal->storeUploadedFile('product', $validFileObj, ['is_test' => true]);
assert_test("Mode 1: Stores file locally with provider='local' and sync_status='synced'",
    $stored1['provider'] === 'local' && $stored1['sync_status'] === 'synced' && $stored1['id'] > 0);

// Verify database record
$dbRow = db()->query("SELECT * FROM files WHERE id = {$stored1['id']}")->fetch(PDO::FETCH_ASSOC);
assert_test("Database record created in files table with SHA-256 and dimensions",
    $dbRow && !empty($dbRow['checksum_sha256']) && $dbRow['status'] === 'ready');

// Test URL resolution via StorageManager
$urlResolved = $mgrLocal->getUrl($stored1['id']);
assert_test("StorageManager::getUrl resolves valid URL from file ID", !empty($urlResolved) && str_contains($urlResolved, $stored1['storage_key']));

// Test Soft Deletion
$mgrLocal->deleteFile($stored1['id'], true);
$dbRowDel = db()->query("SELECT status, deleted_at FROM files WHERE id = {$stored1['id']}")->fetch(PDO::FETCH_ASSOC);
assert_test("StorageManager::deleteFile soft-deletes row (status='deleted')",
    $dbRowDel && $dbRowDel['status'] === 'deleted' && !empty($dbRowDel['deleted_at']));

// Mode 2: S3 only configuration verification
$cfgS3 = new StorageConfig([
    'primary'  => 's3',
    'fallback' => 'none',
    's3'       => [
        'bucket' => 'test-ecommerce-bucket',
        'region' => 'us-east-1',
    ],
]);
$mgrS3 = new StorageManager($cfgS3, db());
assert_test("Mode 2 (S3 only): Primary driver is S3Storage",
    $mgrS3->getPrimaryDriver()->getProviderName() === 's3' && $mgrS3->getFallbackDriver() === null);

// Mode 3: Dual Mode with Primary S3 and Fallback Local (Simulate S3 unreachable)
$cfgDual = new StorageConfig([
    'primary'  => 's3',
    'fallback' => 'local',
    's3'       => [
        'bucket'   => 'unreachable-bucket-simulation-12345',
        'region'   => 'us-east-1',
        'key'      => 'dummy-key',
        'secret'   => 'dummy-secret',
        'endpoint' => 'http://127.0.0.1:9', // Connection refused instantly
    ],
]);
$mgrDual = new StorageManager($cfgDual, db());

$storedFallback = $mgrDual->storeUploadedFile('product', $validFileObj, ['is_test' => true]);
assert_test("Mode 3: When Primary S3 fails, write automatically falls back to Local storage",
    $storedFallback['provider'] === 'local');
assert_test("Mode 3: Fallback upload sets sync_status='pending' for asynchronous sync worker",
    $storedFallback['sync_status'] === 'pending');

// Verify sync worker discovers the pending file
$pendingCheck = db()->query("SELECT COUNT(*) FROM files WHERE id = {$storedFallback['id']} AND sync_status = 'pending'")->fetchColumn();
assert_test("Pending file is tracked in files table ready for bin/sync-storage.php", (int)$pendingCheck === 1);

// Clean up test file
$mgrDual->deleteFile($storedFallback['id'], true);

// -------------------------------------------------------------
// TEST SUITE 6: Backwards Compatibility URL Helpers
// -------------------------------------------------------------
echo PHP_EOL . "--- 6. Backwards Compatibility URL Helpers ---" . PHP_EOL;

// 1. avatar_url with null -> returns ui-avatars fallback
$avatarFallback = avatar_url(null, 'Alice Smith');
assert_test("avatar_url(null) returns UI-Avatars placeholder", str_contains($avatarFallback, 'ui-avatars.com'));

// 2. avatar_url with full URL
$extUrl = 'https://s3.amazonaws.com/bucket/avatar.webp';
assert_test("avatar_url() preserves external/S3 URLs directly", avatar_url($extUrl) === $extUrl);

// 3. product_image_url with null -> returns SVG data URI
$svgPlaceholder = product_image_url(null);
assert_test("product_image_url(null) returns SVG placeholder", str_starts_with($svgPlaceholder, 'data:image/svg+xml'));

// 4. product_image_url with full S3 URL
$pS3Url = 'https://d123.cloudfront.net/products/2026/11/abc.webp';
assert_test("product_image_url() preserves full CloudFront/S3 URLs", product_image_url($pS3Url) === $pS3Url);

// Clean up temporary test files
@unlink($validImgPath);
@unlink($fakeJpgPath);
@unlink($oversizePath);
@unlink($xssSvgPath);
@rmdir($tmpDir . '/test');
@rmdir($tmpDir . '/etc');
@rmdir($tmpDir);

echo PHP_EOL . "==========================================================" . PHP_EOL;
echo "Test Suite Results: \033[32m{$passed} Passed\033[0m, " . ($failed > 0 ? "\033[31m{$failed} Failed\033[0m" : "0 Failed") . PHP_EOL;
echo "==========================================================" . PHP_EOL;

exit($failed > 0 ? 1 : 0);
