<?php
/**
 * AJAX handler for Quick Stock adjustments from admin products list.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'editor');

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';

try {
    if ($action === 'get_stock') {
        $productId = (int)($_GET['product_id'] ?? 0);
        $product = product_get($productId);
        if (!$product) {
            echo json_encode(['ok' => false, 'error' => 'Product not found.']);
            exit;
        }

        $type = $product['product_type'] ?? 'simple';
        $variants = [];
        if ($type === 'variable') {
            $variants = variants_get($productId, false); // include inactive for admin
        }

        $totalStock = product_available_qty($productId);

        echo json_encode([
            'ok'           => true,
            'product_id'   => $productId,
            'product_name' => $product['name'],
            'product_type' => $type,
            'stock'        => (int)($product['stock'] ?? 0),
            'total_stock'  => $totalStock,
            'variants'     => array_map(function ($v) {
                return [
                    'id'     => (int)$v['id'],
                    'label'  => $v['label'],
                    'sku'    => $v['sku'] ?: '',
                    'stock'  => (int)$v['stock'],
                    'status' => $v['status'],
                ];
            }, $variants),
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();

        $productId = (int)($_POST['product_id'] ?? 0);
        $variantId = !empty($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
        $note      = trim((string)($_POST['note'] ?? 'Quick stock update via admin modal'));

        $product = product_get($productId);
        if (!$product) {
            echo json_encode(['ok' => false, 'error' => 'Product not found.']);
            exit;
        }

        $delta = 0;
        if (isset($_POST['delta'])) {
            $delta = (int)$_POST['delta'];
        } elseif (isset($_POST['new_stock'])) {
            $newStock = max(0, (int)$_POST['new_stock']);
            $currentStock = 0;
            if ($variantId !== null) {
                $v = variant_get($variantId);
                $currentStock = $v ? (int)$v['stock'] : 0;
            } else {
                $currentStock = (int)($product['stock'] ?? 0);
            }
            $delta = $newStock - $currentStock;
        }

        if ($delta === 0) {
            $updatedTotal = product_available_qty($productId);
            echo json_encode([
                'ok'          => true,
                'delta'       => 0,
                'total_stock' => $updatedTotal,
                'message'     => 'No change in stock.',
            ]);
            exit;
        }

        $adjusted = stock_adjust($productId, $variantId, $delta, 'manual_adjust', null, $note);
        if (!$adjusted) {
            echo json_encode(['ok' => false, 'error' => 'Failed to adjust stock. Insufficient stock or invalid item.']);
            exit;
        }

        log_activity('stock.adjust', "Adjusted stock for product #{$productId} (variant " . ($variantId ? "#{$variantId}" : 'none') . ") delta: {$delta}");

        $newStock = 0;
        if ($variantId !== null) {
            $v = variant_get($variantId);
            $newStock = $v ? (int)$v['stock'] : 0;
        } else {
            $p = product_get($productId);
            $newStock = $p ? (int)$p['stock'] : 0;
        }
        $updatedTotal = product_available_qty($productId);

        echo json_encode([
            'ok'          => true,
            'delta'       => $delta,
            'new_stock'   => $newStock,
            'total_stock' => $updatedTotal,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Invalid action or request method.']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
