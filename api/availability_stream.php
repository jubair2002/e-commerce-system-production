<?php
/**
 * Optional Server-Sent Events (SSE) Stream for real-time availability updates.
 * Enabled when setting 'realtime_mode' === 'sse'.
 *
 * NOTE: Long-running SSE connections consume Apache/PHP-FPM worker threads.
 * For most shared hosting and standard PHP deployments, short polling with
 * ETag (api/availability.php) is the recommended and default transport.
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

// Release session lock immediately
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$productId = (int)($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    http_response_code(400);
    exit;
}

// Disable output buffering
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$startTime = time();
$maxDuration = 25; // 25 seconds max before closing so worker thread is freed
$lastChecksum = '';

while (time() - $startTime < $maxDuration) {
    // Check if client disconnected
    if (connection_aborted()) {
        break;
    }

    try {
        $stmt = db()->prepare(
            "SELECT CONCAT_WS(':', COALESCE(MAX(updated_at), ''), COALESCE(SUM(stock), 0), COUNT(*))
             FROM product_variants
             WHERE product_id = :pid AND status = 'active'"
        );
        $stmt->execute([':pid' => $productId]);
        $checksum = (string)$stmt->fetchColumn();

        if ($checksum !== $lastChecksum) {
            $lastChecksum = $checksum;

            // Fetch current availability payload
            $product = product_get($productId);
            if ($product && $product['status'] === 'active') {
                $variants = variants_get($productId, true);
                $options  = product_options_get($productId);

                $optOrder = array_map(function ($o) { return (int)$o['id']; }, $options);
                $variantData = [];

                foreach ($variants as $v) {
                    $keyParts = [];
                    foreach ($optOrder as $oId) {
                        if (isset($v['option_value_ids'][$oId])) {
                            $keyParts[] = $v['option_value_ids'][$oId];
                        }
                    }
                    $key = implode('-', $keyParts);
                    $stock = (int)$v['stock'];

                    $variantData[] = [
                        'id'         => (int)$v['id'],
                        'key'        => $key,
                        'sku'        => $v['sku'] ?: $product['sku'],
                        'price'      => (float)($v['price'] ?? $product['price']),
                        'sale_price' => (float)($v['sale_price'] ?? 0) > 0 ? (float)$v['sale_price'] : null,
                        'stock'      => max(0, $stock),
                        'status'     => $stock <= 0 ? 'out_of_stock' : 'in_stock',
                        'label_text' => $stock <= 0 ? 'Out of stock' : 'In stock',
                    ];
                }

                $payload = json_encode([
                    'product_id' => $productId,
                    'variants'   => $variantData,
                    'timestamp'  => time(),
                ]);

                echo "event: stock\n";
                echo "data: {$payload}\n\n";
                flush();
            }
        }
    } catch (Throwable $e) {
        // Silent error in stream loop
    }

    // Ping comment to keep connection alive
    echo ": ping\n\n";
    flush();

    sleep(2);
}

// Instruct client to reconnect
echo "event: reconnect\n";
echo "data: {}\n\n";
flush();
exit;
