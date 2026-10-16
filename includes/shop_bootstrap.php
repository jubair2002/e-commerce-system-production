<?php
/**
 * Storefront bootstrap.
 * Reuses the admin panel's config/DB and adds a few shop-specific helpers.
 */
require_once __DIR__ . '/traffic.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/mailer.php';

// Poor man's cron fallback for queue processing
if (PHP_SAPI !== 'cli') {
    register_shutdown_function(function () {
        $lastRun = (int)setting('mail_last_run', '0');
        if ((time() - $lastRun) > 120) {
            update_setting('mail_last_run', (string)time());
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (function_exists('mail_process_queue')) {
                mail_process_queue(5);
            }
        }
    });
}

/**
 * Check maintenance mode for storefront.
 * When maintenance mode is active, the storefront is closed and displays maintenance.php.
 */
function check_storefront_maintenance(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_contains($script, '/admin/') || basename($script) === 'maintenance.php') {
        return;
    }
    $isMaint = function_exists('setting') ? ((int)setting('maintenance_mode', '0') === 1) : false;
    $preview = !empty($_GET['preview_maintenance']);
    if (!$isMaint && !$preview) {
        return;
    }

    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/') || str_contains($script, '/api/')) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Store is currently under scheduled maintenance.']);
        exit;
    }

    require __DIR__ . '/../maintenance.php';
    exit;
}
check_storefront_maintenance();

/**
 * Build a URL relative to the storefront (project root).
 * The shop now lives at the root, so shop_url() and url() are equivalent.
 * Kept as a separate helper for readability in shop pages.
 */
function shop_url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/** Active category list for the navbar. */
function shop_active_categories(): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = db()->query(
                "SELECT id, name, slug FROM categories
                 WHERE status = 'active'
                 ORDER BY name ASC"
            )->fetchAll();
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

/** Effective price for a product (sale_price if set, otherwise price). */
function product_effective_price(array $p): float
{
    if (isset($p['sale_price']) && $p['sale_price'] !== null
        && (float)$p['sale_price'] > 0) {
        return (float)$p['sale_price'];
    }
    return (float)($p['price'] ?? 0);
}

/** True if the product is on sale. */
function product_is_on_sale(array $p): bool
{
    return isset($p['sale_price']) && $p['sale_price'] !== null
        && (float)$p['sale_price'] > 0
        && (float)$p['sale_price'] < (float)($p['price'] ?? 0);
}

/** % off for a sale product. */
function product_discount_percent(array $p): int
{
    if (!product_is_on_sale($p)) return 0;
    $price = (float)$p['price'];
    $sale  = (float)$p['sale_price'];
    return (int) round((($price - $sale) / $price) * 100);
}

// ----------------------------------------------------------------
// Shopping cart (session-based, variant-aware)
// Cart shape: $_SESSION['cart'] = ["productId:variantId" => quantity, ...]
// where variantId is 0 for simple products.
// ----------------------------------------------------------------

/**
 * Build a normalized cart key string: "productId:variantId" (0 for simple products).
 */
function cart_key(int $productId, ?int $variantId = null): string
{
    $vid = ($variantId !== null && $variantId > 0) ? $variantId : 0;
    return "{$productId}:{$vid}";
}

/**
 * Parse a cart key back into product_id and optional variant_id (null if 0).
 */
function cart_parse_key(string $key): array
{
    $parts = explode(':', (string)$key, 2);
    $pid   = (int)($parts[0] ?? 0);
    $vid   = isset($parts[1]) ? (int)$parts[1] : 0;
    return [
        'product_id' => $pid,
        'variant_id' => ($vid > 0 ? $vid : null),
    ];
}

/**
 * One-time migration for legacy session carts [pid => qty] to ["pid:0" => qty].
 */
function cart_migrate(): void
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return;
    }
    $newCart = [];
    foreach ($_SESSION['cart'] as $k => $qty) {
        $k = (string)$k;
        $q = max(1, (int)$qty);
        if (!str_contains($k, ':')) {
            $newCart[$k . ':0'] = $q;
        } else {
            $newCart[$k] = $q;
        }
    }
    $_SESSION['cart'] = $newCart;
}

/**
 * Add or increment an item in the cart.
 * Supports signatures:
 *   cart_add($productId, $variantId, $qty)
 *   cart_add($productId, $qty, $variantId)
 * Returns updated line quantity.
 */
function cart_add(int $productId, $arg2 = 1, $arg3 = null): int
{
    cart_migrate();
    $_SESSION['cart'] = $_SESSION['cart'] ?? [];

    $product = product_get($productId);
    $isVariable = ($product && ($product['product_type'] ?? '') === 'variable');

    $variantId = null;
    $qty = 1;

    if ($isVariable) {
        // If either arg2 or arg3 corresponds to an existing variant of this product
        if ($arg2 !== null && variant_get((int)$arg2)) {
            $variantId = (int)$arg2;
            $qty = ($arg3 !== null) ? max(1, (int)$arg3) : 1;
        } elseif ($arg3 !== null && variant_get((int)$arg3)) {
            $variantId = (int)$arg3;
            $qty = ($arg2 !== null) ? max(1, (int)$arg2) : 1;
        } else {
            $variantId = ($arg2 !== null && $arg2 > 0) ? (int)$arg2 : null;
            $qty = ($arg3 !== null) ? max(1, (int)$arg3) : 1;
        }
    } else {
        if ($arg2 !== null && is_numeric($arg2) && $arg3 === null) {
            $qty = max(1, (int)$arg2);
            $variantId = null;
        } elseif ($arg3 !== null) {
            $qty = max(1, (int)($arg3 ?: $arg2 ?: 1));
            $variantId = null;
        } else {
            $qty = 1;
            $variantId = null;
        }
    }

    $key = cart_key($productId, $variantId);
    $current = (int)($_SESSION['cart'][$key] ?? 0);
    $newQty = $current + $qty;
    $_SESSION['cart'][$key] = $newQty;

    hook_fire('cart.added', [
        'product_id' => $productId,
        'variant_id' => $variantId,
        'qty'        => $qty,
        'cart_key'   => $key,
    ]);

    return $newQty;
}

/**
 * Set quantity for an item.
 * Supports:
 *   cart_set('12:55', 3)
 *   cart_set(12, 3, 55)
 */
function cart_set($keyOrPid, int $qty, ?int $variantId = null): void
{
    cart_migrate();
    $_SESSION['cart'] = $_SESSION['cart'] ?? [];

    if (is_string($keyOrPid) && (str_contains($keyOrPid, ':') || !is_numeric($keyOrPid))) {
        $key = $keyOrPid;
    } else {
        $key = cart_key((int)$keyOrPid, $variantId);
    }

    if ($qty <= 0) {
        unset($_SESSION['cart'][$key]);
    } else {
        $_SESSION['cart'][$key] = $qty;
    }
}

/** Alias for setting by key */
function cart_set_key(string $key, int $qty): void
{
    cart_set($key, $qty);
}

/**
 * Remove an item from the cart.
 * Supports:
 *   cart_remove('12:55')
 *   cart_remove(12, 55)
 */
function cart_remove($keyOrPid, ?int $variantId = null): void
{
    cart_migrate();
    if (is_string($keyOrPid) && (str_contains($keyOrPid, ':') || !is_numeric($keyOrPid))) {
        $key = $keyOrPid;
    } else {
        $key = cart_key((int)$keyOrPid, $variantId);
    }

    $parsed = cart_parse_key($key);
    unset($_SESSION['cart'][$key]);

    hook_fire('cart.removed', [
        'product_id' => $parsed['product_id'],
        'variant_id' => $parsed['variant_id'],
        'cart_key'   => $key,
    ]);
}

/** Alias for removing by key */
function cart_remove_key(string $key): void
{
    cart_remove($key);
}

/** Wipe the cart */
function cart_clear(): void
{
    unset($_SESSION['cart']);
}

/** Return raw cart array ["productId:variantId" => qty] */
function cart_raw(): array
{
    cart_migrate();
    return $_SESSION['cart'] ?? [];
}

/** Total number of items in the cart (sum of quantities) */
function cart_count(): int
{
    return array_sum(cart_raw());
}

/**
 * Hydrate the cart with fresh product and variant data directly from DB.
 * Revalidates stock and prices on every call to prevent overselling.
 *
 * Returns: [
 *   'items'    => [
 *      ['cart_key' => '12:5', 'product_id' => 12, 'variant_id' => 5,
 *       'product' => row, 'variant' => row|null,
 *       'qty' => n, 'unit_price' => x, 'line_total' => y, 'available_qty' => a,
 *       'product_name' => str, 'variant_label' => str, 'sku' => str, 'image' => str], ...
 *   ],
 *   'subtotal' => float,
 *   'alerts'   => string[],
 *   'warnings' => string[], // alias of alerts
 * ]
 */
function cart_load(): array
{
    $rawCart = cart_raw();
    if (!$rawCart) {
        return ['items' => [], 'subtotal' => 0.0, 'alerts' => [], 'warnings' => []];
    }

    $items    = [];
    $subtotal = 0.0;
    $alerts   = [];

    foreach ($rawCart as $cartKey => $qty) {
        $parsed = cart_parse_key((string)$cartKey);
        $pid    = $parsed['product_id'];
        $vid    = $parsed['variant_id'];
        $qty    = (int)$qty;

        if ($qty < 1 || $pid <= 0) {
            unset($_SESSION['cart'][$cartKey]);
            continue;
        }

        $product = product_get($pid);
        if (!$product || $product['status'] !== 'active') {
            unset($_SESSION['cart'][$cartKey]);
            $alerts[] = 'An item in your cart is no longer available and was removed.';
            continue;
        }

        // Variable product validation: MUST have a valid, active variant belonging to this product
        if (($product['product_type'] ?? 'simple') === 'variable') {
            if ($vid === null || $vid <= 0) {
                unset($_SESSION['cart'][$cartKey]);
                $alerts[] = '"' . $product['name'] . '" requires an option selection and was removed.';
                continue;
            }
            $variant = variant_get($vid);
            if (!$variant || (int)$variant['product_id'] !== $pid || $variant['status'] !== 'active') {
                unset($_SESSION['cart'][$cartKey]);
                $alerts[] = 'The selected combination for "' . $product['name'] . '" is no longer available and was removed.';
                continue;
            }
        } else {
            $variant = null;
        }

        // Available quantity check
        $availQty = ($variant !== null)
            ? variant_available_qty($variant, $product)
            : product_available_qty($pid);

        if ($availQty <= 0) {
            unset($_SESSION['cart'][$cartKey]);
            $label = $product['name'] . ($variant ? ' (' . $variant['label'] . ')' : '');
            $alerts[] = '"' . $label . '" is currently out of stock and was removed from your cart.';
            continue;
        }

        if ($qty > $availQty) {
            $qty = $availQty;
            $_SESSION['cart'][$cartKey] = $qty;
            $label = $product['name'] . ($variant ? ' (' . $variant['label'] . ')' : '');
            $alerts[] = 'Only ' . $availQty . ' units of "' . $label . '" are available. Cart quantity updated.';
        }

        $unitPrice = ($variant !== null)
            ? variant_effective_price($variant, $product)
            : product_effective_price($product);

        $lineTotal = round($unitPrice * $qty, 2);
        $subtotal += $lineTotal;

        $items[] = [
            'cart_key'       => (string)$cartKey,
            'product_id'     => $pid,
            'variant_id'     => $vid,
            'product'        => $product,
            'variant'        => $variant,
            'qty'            => $qty,
            'unit_price'     => $unitPrice,
            'line_total'     => $lineTotal,
            'available_qty'  => $availQty,
            'product_name'   => $product['name'],
            'variant_label'  => $variant ? $variant['label'] : '',
            'sku'            => $variant ? ($variant['sku'] ?: $product['sku']) : $product['sku'],
            'image'          => ($variant && $variant['image']) ? $variant['image'] : $product['image'],
        ];
    }

    $subtotal = round($subtotal, 2);

    return [
        'items'    => $items,
        'subtotal' => $subtotal,
        'alerts'   => $alerts,
        'warnings' => $alerts,
    ];
}

/**
 * Validate coupon code against coupons table.
 *
 * @param string $code
 * @param float $subtotal
 * @return array ['valid' => bool, 'coupon' => ?array, 'discount' => float, 'error' => ?string]
 */
function coupon_validate(string $code, float $subtotal): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'Please enter a coupon code.'];
    }

    try {
        $stmt = db()->prepare("SELECT * FROM coupons WHERE UPPER(code) = :c LIMIT 1");
        $stmt->execute([':c' => $code]);
        $coupon = $stmt->fetch();

        if (!$coupon || $coupon['status'] !== 'active') {
            return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'Invalid or inactive coupon code.'];
        }

        $now = time();
        if (!empty($coupon['starts_at']) && strtotime($coupon['starts_at']) > $now) {
            return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'This coupon is not active yet.'];
        }

        if (!empty($coupon['expires_at']) && strtotime($coupon['expires_at']) < $now) {
            return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'This coupon has expired.'];
        }

        if ($coupon['usage_limit'] !== null && (int)$coupon['usage_limit'] > 0 && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
            return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'This coupon has reached its maximum usage limit.'];
        }

        $minSubtotal = (float)($coupon['min_subtotal'] ?? 0);
        if ($subtotal < $minSubtotal) {
            return [
                'valid'    => false,
                'coupon'   => null,
                'discount' => 0.0,
                'error'    => 'Minimum order amount for this coupon is ' . format_price($minSubtotal) . '.',
            ];
        }

        $val = (float)$coupon['value'];
        $discount = 0.0;
        if ($coupon['type'] === 'percent') {
            $discount = round($subtotal * ($val / 100), 2);
            if ($coupon['max_discount'] !== null && (float)$coupon['max_discount'] > 0) {
                $discount = min($discount, (float)$coupon['max_discount']);
            }
        } else {
            // Fixed discount
            $discount = min($val, $subtotal);
        }

        $discount = round($discount, 2);

        return [
            'valid'    => true,
            'coupon'   => $coupon,
            'discount' => $discount,
            'error'    => null,
        ];
    } catch (Throwable $e) {
        return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'Coupon lookup error.'];
    }
}

/**
 * Calculate cart totals: subtotal, coupon discount, shipping fee, COD fee, and grand total.
 */
function cart_totals(
    array $cart,
    ?int $shippingMethodId = null,
    ?string $couponCode = null,
    string $paymentMethod = 'cod'
): array {
    $subtotal = (float)($cart['subtotal'] ?? 0.0);
    $discount = 0.0;
    $coupon = null;
    $couponError = null;

    if (!empty($couponCode)) {
        $cRes = coupon_validate($couponCode, $subtotal);
        if ($cRes['valid']) {
            $coupon = $cRes['coupon'];
            $discount = $cRes['discount'];
        } else {
            $couponError = $cRes['error'];
        }
    }

    $discountedSubtotal = max(0.0, round($subtotal - $discount, 2));

    // Shipping methods from DB
    $shippingMethod = null;
    $shippingFee = 0.0;

    try {
        $methods = db()->query(
            "SELECT * FROM shipping_methods WHERE status = 'active' ORDER BY position ASC, id ASC"
        )->fetchAll();

        if ($methods) {
            if ($shippingMethodId !== null) {
                foreach ($methods as $m) {
                    if ((int)$m['id'] === $shippingMethodId) {
                        $shippingMethod = $m;
                        break;
                    }
                }
            }
            if (!$shippingMethod) {
                $shippingMethod = $methods[0];
            }

            $shippingFee = (float)$shippingMethod['cost'];
            // Free shipping threshold crossing computed on subtotal AFTER discount
            if ($shippingMethod['free_over'] !== null && (float)$shippingMethod['free_over'] > 0) {
                if ($discountedSubtotal >= (float)$shippingMethod['free_over']) {
                    $shippingFee = 0.0;
                }
            }
        }
    } catch (Throwable $e) {
        $shippingFee = (float)setting('shipping_fee', '0');
    }

    // COD fee
    $codFee = 0.0;
    if ($paymentMethod === 'cod') {
        $codEnabled = (int)setting('cod_enabled', '1') === 1;
        if ($codEnabled) {
            $codFee = (float)setting('cod_fee', '0.00');
        }
    }

    $total = round($discountedSubtotal + $shippingFee + $codFee, 2);

    return [
        'subtotal'            => $subtotal,
        'discount'            => $discount,
        'discounted_subtotal' => $discountedSubtotal,
        'coupon'              => $coupon,
        'coupon_error'        => $couponError,
        'shipping_method'     => $shippingMethod,
        'shipping_fee'        => $shippingFee,
        'cod_fee'             => $codFee,
        'total'               => $total,
    ];
}

/** Generate a unique order number with prefix setting e.g. ORD-202605-0007 */
function generate_order_number(): string
{
    $prefix = setting('order_number_prefix', 'ORD');
    if ($prefix === '') $prefix = 'ORD';
    $monthPrefix = $prefix . '-' . date('Ym') . '-';

    try {
        $stmt = db()->prepare(
            "SELECT order_number FROM orders
             WHERE order_number LIKE :p
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':p' => $monthPrefix . '%']);
        $last = $stmt->fetchColumn();
        $nextNum = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $nextNum = (int)$m[1] + 1;
        }
        return $monthPrefix . str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return $monthPrefix . strtoupper(bin2hex(random_bytes(2)));
    }
}

