<?php
/**
 * Real-time product availability and stock API.
 * High-performance, unblocked session, ETag 304 caching, rate limiting.
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

// Check admin role from session before closing session write lock
$isAdmin = !empty($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['admin', 'editor'], true);

// Release session lock immediately to prevent blocking parallel requests
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// -----------------------------------------------------------------
// Rate Limiter: max 60 requests per minute per IP
// -----------------------------------------------------------------
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rateLimitKey = sys_get_temp_dir() . '/rate_' . md5($clientIp) . '.json';
$now = time();

$rateData = ['count' => 0, 'reset' => $now + 60];
if (file_exists($rateLimitKey)) {
    $raw = @file_get_contents($rateLimitKey);
    if ($raw) {
        $parsed = json_decode($raw, true);
        if ($parsed && isset($parsed['reset']) && $parsed['reset'] > $now) {
            $rateData = $parsed;
        }
    }
}

if (!$isAdmin) {
    $rateData['count']++;
    @file_put_contents($rateLimitKey, json_encode($rateData), LOCK_EX);

    if ($rateData['count'] > 60) {
        $retryAfter = max(1, $rateData['reset'] - $now);
        header('HTTP/1.1 429 Too Many Requests');
        header('Retry-After: ' . $retryAfter);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Too many requests. Please slow down.', 'retry_after' => $retryAfter]);
        if (function_exists('app_finish_response')) app_finish_response();
        exit;
    }
}

// -----------------------------------------------------------------
// Route 1: Batch IDs query (for catalog grid badges)
// Example: ?ids=1,2,3 (max 24)
// -----------------------------------------------------------------
if (isset($_GET['ids'])) {
    $rawIds = explode(',', (string)$_GET['ids']);
    $maxLimit = $isAdmin ? 100 : 24;
    $ids = array_slice(array_filter(array_map('intval', $rawIds)), 0, $maxLimit);

    $results = [];
    if (!empty($ids)) {
        $inClause = implode(',', $ids);
        $statusFilter = $isAdmin ? '' : "AND p.status = 'active'";
        $stmt = db()->query(
            "SELECT p.id, p.product_type, p.track_inventory, p.allow_backorder, p.low_stock_threshold,
                    COALESCE(vps.total_stock, p.stock) AS total_stock,
                    COALESCE(vps.variant_count, 1) AS variant_count
             FROM products p
             LEFT JOIN v_product_stock vps ON vps.product_id = p.id
             WHERE p.id IN ($inClause) $statusFilter"
        );

        $stockDisplayMode = function_exists('setting') ? setting('stock_display_mode', 'count_when_low') : 'count_when_low';

        while ($row = $stmt->fetch()) {
            $pid = (int)$row['id'];
            $stock = (int)$row['total_stock'];
            $track = (int)($row['track_inventory'] ?? 1);
            $backorder = (int)($row['allow_backorder'] ?? 0);
            $threshold = (int)($row['low_stock_threshold'] ?? 5);
            $variantCount = (int)($row['variant_count'] ?? 1);

            $status = 'in_stock';
            if (!$track) {
                $status = 'in_stock';
            } elseif ($backorder && $stock <= 0) {
                $status = 'backorder';
            } elseif ($stock <= 0) {
                $status = 'out_of_stock';
            } elseif ($stock <= $threshold) {
                $status = 'low_stock';
            }

            $exposedStock = null;
            if ($isAdmin) {
                $exposedStock = $stock;
            } elseif ($stockDisplayMode === 'always') {
                $exposedStock = max(0, $stock);
            } elseif ($stockDisplayMode === 'count_when_low' && $status === 'low_stock') {
                $exposedStock = max(0, $stock);
            }

            $results[$pid] = [
                'type'          => $row['product_type'],
                'status'        => $status,
                'total_stock'   => $exposedStock,
                'threshold'     => $threshold,
                'variant_count' => $variantCount,
            ];
        }
    }

    $response = [
        'server_time' => $now,
        'products'    => $results,
    ];

    send_etag_response($response);
}

// -----------------------------------------------------------------
// Route 2: Single Product Availability Query
// Example: ?product_id=12
// -----------------------------------------------------------------
$productId = (int)($_GET['product_id'] ?? $_GET['id'] ?? 0);
if ($productId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Missing or invalid product_id parameter.']);
    exit;
}

$product = product_get($productId);
if (!$product || $product['status'] !== 'active') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Product not found or no longer available.']);
    exit;
}

$type             = $product['product_type'] ?? 'simple';
$trackInventory   = (int)($product['track_inventory'] ?? 1);
$allowBackorder   = (int)($product['allow_backorder'] ?? 0);
$lowStockThresh   = (int)($product['low_stock_threshold'] ?? 5);
$showStockCount   = (int)($product['show_stock_count'] ?? 1);
$stockDisplayMode = function_exists('setting') ? setting('stock_display_mode', 'count_when_low') : 'count_when_low';
$backorderText    = function_exists('setting') ? setting('backorder_text', 'Ships in 5-7 days') : 'Ships in 5-7 days';

$response = [
    'product_id'  => $productId,
    'type'        => $type,
    'server_time' => $now,
];

if ($type === 'variable') {
    $optionsTree = product_options_get($productId);
    $activeVariants = variants_get($productId, true);

    $formattedOptions = [];
    $optOrder = [];
    foreach ($optionsTree as $opt) {
        $optOrder[] = (int)$opt['id'];
        $formattedOptions[] = [
            'id'           => (int)$opt['id'],
            'name'         => $opt['name'],
            'display_type' => $opt['display_type'],
            'values'       => array_map(function ($val) {
                return [
                    'id'         => (int)$val['id'],
                    'value'      => $val['value'],
                    'swatch_hex' => $val['swatch_hex'] ?? null,
                ];
            }, $opt['values']),
        ];
    }
    $response['options'] = $formattedOptions;

    $variantsList = [];
    foreach ($activeVariants as $v) {
        $stock = (int)$v['stock'];

        // Build key: option value ids joined by '-' in option order
        $keyParts = [];
        foreach ($optOrder as $oId) {
            if (isset($v['option_value_ids'][$oId])) {
                $keyParts[] = $v['option_value_ids'][$oId];
            }
        }
        $key = implode('-', $keyParts);

        // Status & label determination
        $status = 'in_stock';
        $label  = 'In stock';

        if (!$trackInventory) {
            $status = 'in_stock';
            $label  = 'In stock';
        } elseif ($allowBackorder && $stock <= 0) {
            $status = 'backorder';
            $label  = $backorderText;
        } elseif ($stock <= 0) {
            $status = 'out_of_stock';
            $label  = 'Out of stock';
        } elseif ($stock <= $lowStockThresh) {
            $status = 'low_stock';
            $label  = ($stockDisplayMode === 'hidden' || $stockDisplayMode === 'in_out_only') ? 'In stock' : "Only {$stock} left";
        }

        // Determine stock count visibility to user
        $exposedStock = null;
        if ($isAdmin) {
            $exposedStock = $stock;
        } elseif ($showStockCount) {
            if ($stockDisplayMode === 'always') {
                $exposedStock = max(0, $stock);
            } elseif ($stockDisplayMode === 'count_when_low' && $status === 'low_stock') {
                $exposedStock = max(0, $stock);
            }
        }

        $effectivePrice = variant_effective_price($v, $product);
        $regularPrice = (float)($v['price'] ?? $product['price']);
        $salePrice = (isset($v['sale_price']) && $v['sale_price'] !== null && (float)$v['sale_price'] > 0)
            ? (float)$v['sale_price']
            : ((isset($product['sale_price']) && (float)$product['sale_price'] > 0) ? (float)$product['sale_price'] : null);

        $variantsList[] = [
            'id'         => (int)$v['id'],
            'key'        => $key,
            'sku'        => $v['sku'] ?: $product['sku'],
            'price'      => $regularPrice,
            'sale_price' => $salePrice,
            'stock'      => $exposedStock,
            'status'     => $status,
            'label_text' => $label,
            'image'      => $v['image'] ?: $product['image'],
        ];
    }

    $response['variants'] = $variantsList;
} else {
    // Simple product
    $stock = (int)($product['stock'] ?? 0);
    $status = 'in_stock';
    $label  = 'In stock';

    if (!$trackInventory) {
        $status = 'in_stock';
        $label  = 'In stock';
    } elseif ($allowBackorder && $stock <= 0) {
        $status = 'backorder';
        $label  = $backorderText;
    } elseif ($stock <= 0) {
        $status = 'out_of_stock';
        $label  = 'Out of stock';
    } elseif ($stock <= $lowStockThresh) {
        $status = 'low_stock';
        $label  = ($stockDisplayMode === 'hidden' || $stockDisplayMode === 'in_out_only') ? 'In stock' : "Only {$stock} left";
    }

    $exposedStock = null;
    if ($isAdmin) {
        $exposedStock = $stock;
    } elseif ($showStockCount) {
        if ($stockDisplayMode === 'always') {
            $exposedStock = max(0, $stock);
        } elseif ($stockDisplayMode === 'count_when_low' && $status === 'low_stock') {
            $exposedStock = max(0, $stock);
        }
    }

    $response['sku']        = $product['sku'];
    $response['price']      = (float)$product['price'];
    $response['sale_price'] = (float)($product['sale_price'] ?? 0) > 0 ? (float)$product['sale_price'] : null;
    $response['stock']      = $exposedStock;
    $response['status']     = $status;
    $response['label_text'] = $label;
    $response['image']      = $product['image'];
}

send_etag_response($response);

function send_etag_response(array $data): void
{
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $etag = '"' . md5($payload) . '"';

    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';

    if ($ifNoneMatch === $etag || $ifNoneMatch === str_replace('"', '', $etag)) {
        header('HTTP/1.1 304 Not Modified');
        header('ETag: ' . $etag);
        header('Cache-Control: no-store');
        if (function_exists('app_finish_response')) app_finish_response();
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('ETag: ' . $etag);
    header('Cache-Control: no-store');
    echo $payload;
    if (function_exists('app_finish_response')) app_finish_response();
    exit;
}
