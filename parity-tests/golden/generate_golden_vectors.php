<?php
/**
 * Golden Test Vector Generator
 * Runs the real PHP implementation to generate deterministic JSON fixtures
 * for testing and proving 100% parity against the Rust backend.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/crypto.php';
require_once __DIR__ . '/../../includes/shop_bootstrap.php';
require_once __DIR__ . '/../../includes/customer_auth.php';

$fixturesDir = __DIR__ . '/fixtures';
if (!is_dir($fixturesDir)) {
    mkdir($fixturesDir, 0777, true);
}

echo "=== Generating Golden Test Vectors ===\n";

// -----------------------------------------------------------------------------
// 1. Money Formatting & Rounding
// -----------------------------------------------------------------------------
echo "[1/10] Generating money formatting & round vectors...\n";
$moneyInputs = [
    null, 0.0, 0.00, 0.01, 0.004, 0.005, 0.006, 0.05, 0.99, 1.0, 1.235, 1.245,
    10.5, 99.99, 99.995, 100.0, 1234.56, 1234.567, 999999.99, 1000000.0, -0.01, -45.2, -1.235
];
$moneyVectors = [];
$symbol = setting('currency_symbol', '$');
foreach ($moneyInputs as $val) {
    $moneyVectors[] = [
        'input' => $val,
        'currency_symbol' => $symbol,
        'price_formatted' => price($val),
        'number_format_2' => $val !== null ? number_format((float)$val, 2, '.', '') : null,
        'round_2' => $val !== null ? round((float)$val, 2) : null,
        'round_half_up' => $val !== null ? round((float)$val, 2, PHP_ROUND_HALF_UP) : null,
    ];
}
file_put_contents($fixturesDir . '/money_formatting.json', json_encode($moneyVectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// -----------------------------------------------------------------------------
// 2. Rounding Vectors (Precision Edge Cases)
// -----------------------------------------------------------------------------
echo "[2/10] Generating rounding vectors...\n";
$roundInputs = [
    0.000, 0.004, 0.005, 0.006, 0.015, 0.025, 0.035, 0.045, 0.055, 0.065, 0.075, 0.085, 0.095,
    1.005, 1.015, 1.234, 1.235, 1.236, 2.675, 3.555, 4.005, 9.995, 12.345, 12.3456,
    -0.004, -0.005, -0.006, -1.235, -2.675
];
$roundVectors = [];
foreach ($roundInputs as $val) {
    $roundVectors[] = [
        'input' => $val,
        'round_0' => round($val, 0),
        'round_1' => round($val, 1),
        'round_2' => round($val, 2),
        'round_3' => round($val, 3),
        'php_half_up_2' => round($val, 2, PHP_ROUND_HALF_UP),
    ];
}
file_put_contents($fixturesDir . '/round.json', json_encode($roundVectors, JSON_PRETTY_PRINT));

// -----------------------------------------------------------------------------
// 3. Cart Totals & Coupon Validation (200 Varied Carts)
// -----------------------------------------------------------------------------
echo "[3/10] Generating 200 varied cart_totals & coupon vectors...\n";
$cartVectors = [];

// Seed coupons if database is available or simulate deterministic responses
$testCoupons = [
    'SAVE10'    => ['type' => 'percentage', 'value' => 10.0, 'min_order' => 500.0, 'status' => 'active'],
    'FLAT100'   => ['type' => 'fixed', 'value' => 100.0, 'min_order' => 1000.0, 'status' => 'active'],
    'BIG500'    => ['type' => 'fixed', 'value' => 500.0, 'min_order' => 5000.0, 'status' => 'active'],
    'PERCENT25' => ['type' => 'percentage', 'value' => 25.0, 'min_order' => 0.0, 'status' => 'active'],
    'EXPIRED'   => ['type' => 'percentage', 'value' => 20.0, 'min_order' => 0.0, 'status' => 'expired'],
    ''          => null,
    'INVALID'   => null,
];

// 200 cart combinations
for ($i = 1; $i <= 200; $i++) {
    $itemCount = ($i % 5) + 1;
    $items = [];
    $rawSubtotal = 0.0;
    for ($j = 1; $j <= $itemCount; $j++) {
        $price = round((($i * 37 + $j * 89) % 3500) + 15.50, 2);
        $qty = (($i + $j) % 4) + 1;
        $rawSubtotal += ($price * $qty);
        $items[] = [
            'product_id' => $j,
            'variant_id' => ($j % 2 === 0) ? ($j * 10) : 0,
            'price' => $price,
            'qty' => $qty,
            'line_total' => round($price * $qty, 2)
        ];
    }
    $subtotal = round($rawSubtotal, 2);
    $cart = ['items' => $items, 'subtotal' => $subtotal];

    $couponKeys = array_keys($testCoupons);
    $couponCode = $couponKeys[$i % count($couponKeys)];
    $shippingMethodId = ($i % 3 === 0) ? 1 : (($i % 3 === 1) ? 2 : null);
    $paymentMethod = ($i % 2 === 0) ? 'cod' : 'bank_transfer';

    $totals = cart_totals($cart, $shippingMethodId, $couponCode, $paymentMethod);

    $cartVectors[] = [
        'scenario_id' => $i,
        'cart' => $cart,
        'coupon_code' => $couponCode,
        'shipping_method_id' => $shippingMethodId,
        'payment_method' => $paymentMethod,
        'result' => [
            'subtotal' => $totals['subtotal'],
            'discount' => $totals['discount'],
            'discounted_subtotal' => $totals['discounted_subtotal'],
            'coupon_valid' => $totals['coupon'] !== null,
            'coupon_error' => $totals['coupon_error'],
            'shipping_fee' => $totals['shipping_fee'],
            'cod_fee' => $totals['cod_fee'],
            'total' => $totals['total'],
        ]
    ];
}
file_put_contents($fixturesDir . '/cart_totals.json', json_encode($cartVectors, JSON_PRETTY_PRINT));

// -----------------------------------------------------------------------------
// 4. Shipping Fee per all 64 Districts
// -----------------------------------------------------------------------------
echo "[4/10] Generating shipping fee per 64 districts vectors...\n";
$districtsFile = __DIR__ . '/../../includes/data/bd_districts.php';
$districts = file_exists($districtsFile) ? require $districtsFile : [];
$districtVectors = [];

foreach ($districts as $d) {
    $name = $d['name'];
    $zone = $d['zone'];

    // Method selection based on zone
    $methodId = ($zone === 'inside_dhaka') ? 1 : 2;
    $standardCost = ($zone === 'inside_dhaka') ? 60.00 : 120.00;

    // Test under threshold and over threshold (free shipping simulation)
    $totalsUnder = cart_totals(['subtotal' => 500.00], $methodId, null, 'cod');
    $totalsOver = cart_totals(['subtotal' => 50000.00], $methodId, null, 'cod');

    $districtVectors[] = [
        'district' => $name,
        'zone' => $zone,
        'method_id' => $methodId,
        'standard_fee' => $standardCost,
        'calculated_fee_normal' => $totalsUnder['shipping_fee'],
        'calculated_fee_free_threshold' => $totalsOver['shipping_fee'],
    ];
}
file_put_contents($fixturesDir . '/shipping_districts.json', json_encode($districtVectors, JSON_PRETTY_PRINT));

// -----------------------------------------------------------------------------
// 5. Order Number Format
// -----------------------------------------------------------------------------
echo "[5/10] Generating order number format vectors...\n";
$prefix = setting('order_number_prefix', 'ORD');
if ($prefix === '') $prefix = 'ORD';
$yearMonth = date('Ym');

$orderNumberVectors = [];
$sampleIds = [1, 2, 7, 42, 99, 100, 999, 1000, 9999, 10000];
foreach ($sampleIds as $seq) {
    $expected = sprintf('%s-%s-%04d', $prefix, $yearMonth, $seq);
    $orderNumberVectors[] = [
        'prefix' => $prefix,
        'year_month' => $yearMonth,
        'sequence' => $seq,
        'formatted' => $expected,
    ];
}
file_put_contents($fixturesDir . '/order_numbers.json', json_encode($orderNumberVectors, JSON_PRETTY_PRINT));

// -----------------------------------------------------------------------------
// 6. Customer & Admin Password Hash/Verify
// -----------------------------------------------------------------------------
echo "[6/10] Generating customer & admin password vectors...\n";
$passwords = [
    'password123',
    'Secret@2026!',
    'Dhaka-Bangladesh#1',
    '12345678',
    'very-long-password-exceeding-standard-lengths-to-test-sha256-pre-hashing-behavior-cleanly-without-truncation',
    'unicode-পাসওয়ার্ড-১০১'
];
$passwordVectors = [];
foreach ($passwords as $pw) {
    // Customer hash (pre-hashed with SHA256 base64 before bcrypt)
    $customerHash = customer_password_hash($pw);
    $customerVerifyCorrect = customer_password_verify($pw, $customerHash);
    $customerVerifyWrong = customer_password_verify($pw . '_wrong', $customerHash);

    // Pre-hash string representation
    $preHashedB64 = base64_encode(hash('sha256', $pw, true));

    // Admin hash (standard bcrypt)
    $adminHash = password_hash($pw, PASSWORD_BCRYPT);
    $adminVerifyCorrect = password_verify($pw, $adminHash);
    $adminVerifyWrong = password_verify($pw . '_wrong', $adminHash);

    $passwordVectors[] = [
        'password' => $pw,
        'sha256_prehash_base64' => $preHashedB64,
        'customer_hash' => $customerHash,
        'customer_verify_correct' => $customerVerifyCorrect,
        'customer_verify_wrong' => $customerVerifyWrong,
        'admin_hash' => $adminHash,
        'admin_verify_correct' => $adminVerifyCorrect,
        'admin_verify_wrong' => $adminVerifyWrong,
    ];
}
file_put_contents($fixturesDir . '/passwords.json', json_encode($passwordVectors, JSON_PRETTY_PRINT));

// -----------------------------------------------------------------------------
// 7. HMAC Confirmation Tokens
// -----------------------------------------------------------------------------
echo "[7/10] Generating HMAC confirmation token vectors...\n";
$appKey = defined('APP_KEY') ? APP_KEY : 'c84f1a29e46a78b901d3e5f72a6b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b';
$orderNumbers = [
    'ORD-202611-0001',
    'ORD-202611-0002',
    'ORD-202611-0042',
    'ORD-202611-0100',
    'ORD-202611-9999',
    'ORD-202612-0001',
    'TEST-ORD-999',
];
$hmacVectors = [];
foreach ($orderNumbers as $on) {
    $token = hash_hmac('sha256', $on, $appKey);
    $hmacVectors[] = [
        'order_number' => $on,
        'app_key' => $appKey,
        'hmac_sha256' => $token,
    ];
}
file_put_contents($fixturesDir . '/hmac_tokens.json', json_encode($hmacVectors, JSON_PRETTY_PRINT));

// -----------------------------------------------------------------------------
// 8. AES Encrypt / Decrypt Samples
// -----------------------------------------------------------------------------
echo "[8/10] Generating AES crypto vectors...\n";
$plaintexts = [
    'hello world',
    'smtp_secret_password_2026',
    '{"access_key":"AKIA123","secret":"xyz789"}',
    'Unicode: বাংলা টেক্সট টেস্ট',
    '',
    'A very long plaintext string to test padding and multiblock CBC cipher mode interoperability between PHP OpenSSL and Rust aes/cbc crates.'
];
$cryptoVectors = [];
$appCryptoKey = defined('APP_KEY') ? APP_KEY : 'CHANGE_ME_TO_64_RANDOM_CHARS';
foreach ($plaintexts as $pt) {
    if ($pt === '') {
        $cipher = app_encrypt($pt);
        $decrypted = app_decrypt($cipher);
    } else {
        $cipher = app_encrypt($pt);
        $decrypted = app_decrypt($cipher);
    }
    $cryptoVectors[] = [
        'plaintext' => $pt,
        'app_key' => $appCryptoKey,
        'ciphertext_base64' => $cipher,
        'decrypted' => $decrypted,
        'matches' => ($pt === $decrypted),
    ];
}
file_put_contents($fixturesDir . '/crypto_aes.json', json_encode($cryptoVectors, JSON_PRETTY_PRINT));

// -----------------------------------------------------------------------------
// 9. Slug Generation Vectors
// -----------------------------------------------------------------------------
echo "[9/10] Generating slug vectors...\n";
$slugInputs = [
    'Men Black T-Shirt',
    'Women\'s Premium Silk Saree - Red & Gold',
    'Exclusive 100% Cotton Polo Shirt (2026 Edition)',
    'Special Discount: 50% Off!',
    '  Extra   Spaces   Around   ',
    'Product with special characters @#$%^&*()_+',
    'Simple-Already-Slugged',
    '123 456 789 Numbers Test',
    'Multiple---Dashes---And___Underscores',
    'Leading and Trailing Hyphens -',
];
$slugVectors = [];
foreach ($slugInputs as $in) {
    $slugVectors[] = [
        'input' => $in,
        'slug' => slugify($in),
    ];
}
file_put_contents($fixturesDir . '/slugs.json', json_encode($slugVectors, JSON_PRETTY_PRINT));

// -----------------------------------------------------------------------------
// 10. Date Formatting & JSON Floats
// -----------------------------------------------------------------------------
echo "[10/10] Generating date formatting & JSON float vectors...\n";
$timestamps = [
    1700000000, // 2023-11-14 22:13:20 UTC
    1763870400, // 2025-11-23 04:00:00 UTC
    1795492800, // 2026-11-23 04:00:00 UTC
];
$dateVectors = [];
foreach ($timestamps as $ts) {
    $dateVectors[] = [
        'timestamp' => $ts,
        'iso_datetime' => date('Y-m-d H:i:s', $ts),
        'date_only' => date('Y-m-d', $ts),
        'display_date' => date('d M Y', $ts),
        'display_datetime' => date('M j, Y, g:i a', $ts),
        'year_month' => date('Ym', $ts),
    ];
}
file_put_contents($fixturesDir . '/date_formatting.json', json_encode($dateVectors, JSON_PRETTY_PRINT));

$floatValues = [
    0.0, 1.0, 1.5, 99.99, 120.00, 1000.5, 0.0001, 1234567.89, -45.5
];
$floatVectors = [];
foreach ($floatValues as $fv) {
    $floatVectors[] = [
        'float_val' => $fv,
        'json_encoded' => json_encode(['val' => $fv]),
        'number_format_2' => number_format($fv, 2, '.', ''),
    ];
}
file_put_contents($fixturesDir . '/json_float.json', json_encode($floatVectors, JSON_PRETTY_PRINT));

echo "\nAll golden test vectors generated successfully in {$fixturesDir}!\n";
