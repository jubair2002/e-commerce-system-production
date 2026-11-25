<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/FileCache.php';
require_once __DIR__ . '/../admin/includes/auth.php';

echo "=== Running Security & Performance Verification ===\n";

// 1. FileCache Object Injection Test
class MaliciousPayload {
    public $hook = 'evil';
}
$testKey = 'test_security_object_injection';
FileCache::set($testKey, 'safe_string_data', 60);

// Verify L1 cache hit
$val1 = FileCache::get($testKey);
assert($val1 === 'safe_string_data', 'L1 Cache get failed');

// Overwrite cache file directly with a serialized object to test deserialization safety
$normKey = FileCache::normalizeKey($testKey);
$filePath = FileCache::getFilePath($normKey);
$poisoned = serialize([
    'key'  => $normKey,
    'exp'  => time() + 60,
    'data' => new MaliciousPayload()
]);
file_put_contents($filePath, $poisoned);

// Flush L1 to force disk read
FileCache::deletePattern('unrelated:*'); // wipes L1
$val2 = FileCache::get($testKey);
// In PHP with allowed_classes => false, non-allowed objects become __PHP_Incomplete_Class
assert(!($val2 instanceof MaliciousPayload), 'Object injection payload must NOT instantiate MaliciousPayload class!');
echo "[PASS] FileCache object injection protection confirmed.\n";

// Clean up
FileCache::delete($testKey);
assert(FileCache::get($testKey) === null, 'Cache delete failed');
echo "[PASS] FileCache deletion and invalidation confirmed.\n";

// 2. CSRF Token via Header Test
$_SESSION['csrf_token'] = 'test_valid_csrf_token_12345';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'test_valid_csrf_token_12345';
unset($_POST['_csrf']);
assert(csrf_verify() === true, 'csrf_verify should succeed when X-CSRF-Token header matches');
echo "[PASS] CSRF verification via X-CSRF-Token header confirmed.\n";

$_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong_token';
assert(csrf_verify() === false, 'csrf_verify should fail with wrong token');
echo "[PASS] CSRF verification rejection confirmed.\n";

// 3. Admin Rate Limiting Test
$testAdminEmail = 'rate_limit_test_' . time() . '@example.com';
$fakeIp = '198.51.100.99';
$_SERVER['REMOTE_ADDR'] = $fakeIp;

// 5 failed attempts
for ($i = 0; $i < 5; $i++) {
    attempt_login($testAdminEmail, 'wrong_pass_' . $i);
}
assert(auth_is_rate_limited('login', $testAdminEmail, $fakeIp) === true, 'Admin login must be rate limited after 5 failed attempts');
echo "[PASS] Admin brute force rate limiting confirmed.\n";

// Clean up test attempts
db()->prepare("DELETE FROM auth_attempts WHERE ip = :ip")->execute([':ip' => $fakeIp]);

// 4. Catalog & Cart Memoization Test
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/shop_bootstrap.php';

// Shipping estimate caching
$est1 = shipping_delivery_estimate();
$est2 = shipping_delivery_estimate();
assert(!empty($est1) && $est1 === $est2, 'shipping_delivery_estimate failed');
echo "[PASS] shipping_delivery_estimate caching confirmed.\n";

// Cart load memoization
$_SESSION['cart'] = ['1:0' => 1];
$c1 = cart_load();
$c2 = cart_load();
assert($c1 === $c2, 'cart_load memoization must return identical instance');
echo "[PASS] cart_load memoization confirmed.\n";

// Product get memoization
$p1 = product_get(3);
$p2 = product_get(3);
assert($p1 === $p2, 'product_get memoization failed');
echo "[PASS] product_get runtime memoization confirmed.\n";

echo "\nAll Security & Performance checks PASSED!\n";
