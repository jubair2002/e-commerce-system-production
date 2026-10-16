<?php
/**
 * Inventory ledger, atomic stock adjustments, low stock checks, and order cancellation restock.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/hooks.php';

/**
 * Adjust stock for a product or variant atomically.
 *
 * @param int $productId
 * @param int|null $variantId
 * @param int $delta Positive for restock, negative for deduction
 * @param string $reason enum: 'order','cancel_restock','manual_adjust','restock','return','correction'
 * @param int|null $orderId
 * @param string|null $note
 * @return bool True if adjusted, false if insufficient stock or invalid record
 */
function stock_adjust(
    int $productId,
    ?int $variantId,
    int $delta,
    string $reason,
    ?int $orderId = null,
    ?string $note = null
): bool {
    if ($delta === 0) {
        return true;
    }

    $allowedReasons = ['order', 'cancel_restock', 'manual_adjust', 'restock', 'return', 'correction'];
    $reasonMap = [
        'manual' => 'manual_adjust',
        'cancel' => 'cancel_restock',
        'adjust' => 'manual_adjust',
    ];
    $reason = $reasonMap[$reason] ?? $reason;
    if (!in_array($reason, $allowedReasons, true)) {
        $reason = 'manual_adjust';
    }

    $pdo = db();
    $inTx = $pdo->inTransaction();
    if (!$inTx) {
        $pdo->beginTransaction();
    }

    try {
        // Fetch product to inspect inventory rules
        $stmtP = $pdo->prepare(
            "SELECT id, product_type, stock, track_inventory, allow_backorder, low_stock_threshold
             FROM products WHERE id = :id"
        );
        $stmtP->execute([':id' => $productId]);
        $product = $stmtP->fetch();

        if (!$product) {
            if (!$inTx) $pdo->rollBack();
            return false;
        }

        $trackInventory = isset($product['track_inventory']) ? (int)$product['track_inventory'] : 1;
        $allowBackorder = !empty($product['allow_backorder']);

        $stockAfter = 0;

        if ($variantId !== null) {
            // Check variant existence
            $stmtV = $pdo->prepare(
                "SELECT id, stock FROM product_variants WHERE id = :id AND product_id = :pid"
            );
            $stmtV->execute([':id' => $variantId, ':pid' => $productId]);
            $variant = $stmtV->fetch();

            if (!$variant) {
                if (!$inTx) $pdo->rollBack();
                return false;
            }

            // Negative delta requires atomic conditional check when tracking is enabled and backorders are disallowed
            if ($delta < 0 && $trackInventory && !$allowBackorder) {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE product_variants
                     SET stock = stock + :delta
                     WHERE id = :id AND (stock + :min_check) >= 0"
                );
                $stmtUpdate->execute([':delta' => $delta, ':id' => $variantId, ':min_check' => $delta]);
                if ($stmtUpdate->rowCount() === 0) {
                    if (!$inTx) $pdo->rollBack();
                    return false;
                }
            } else {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE product_variants
                     SET stock = stock + :delta
                     WHERE id = :id"
                );
                $stmtUpdate->execute([':delta' => $delta, ':id' => $variantId]);
            }

            // Retrieve updated variant stock
            $stmtAfter = $pdo->prepare("SELECT stock FROM product_variants WHERE id = :id");
            $stmtAfter->execute([':id' => $variantId]);
            $stockAfter = (int)$stmtAfter->fetchColumn();
        } else {
            // Simple product
            if ($delta < 0 && $trackInventory && !$allowBackorder) {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE products
                     SET stock = stock + :delta
                     WHERE id = :id AND (stock + :min_check) >= 0"
                );
                $stmtUpdate->execute([':delta' => $delta, ':id' => $productId, ':min_check' => $delta]);
                if ($stmtUpdate->rowCount() === 0) {
                    if (!$inTx) $pdo->rollBack();
                    return false;
                }
            } else {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE products
                     SET stock = stock + :delta
                     WHERE id = :id"
                );
                $stmtUpdate->execute([':delta' => $delta, ':id' => $productId]);
            }

            // Retrieve updated product stock
            $stmtAfter = $pdo->prepare("SELECT stock FROM products WHERE id = :id");
            $stmtAfter->execute([':id' => $productId]);
            $stockAfter = (int)$stmtAfter->fetchColumn();
        }

        // Write inventory ledger movement
        $userId = $_SESSION['user']['id'] ?? null;
        $stmtMov = $pdo->prepare(
            "INSERT INTO inventory_movements (product_id, variant_id, change_qty, stock_after, reason, order_id, user_id, note)
             VALUES (:pid, :vid, :qty, :after, :reason, :oid, :uid, :note)"
        );
        $stmtMov->execute([
            ':pid'    => $productId,
            ':vid'    => $variantId,
            ':qty'    => $delta,
            ':after'  => $stockAfter,
            ':reason' => $reason,
            ':oid'    => $orderId,
            ':uid'    => $userId,
            ':note'   => $note,
        ]);

        if (!$inTx) {
            $pdo->commit();
        }

        // Post-adjustment: verify low stock and trigger hooks/notifications
        stock_check_low($productId, $variantId);

        return true;
    } catch (Throwable $e) {
        if (!$inTx) {
            $pdo->rollBack();
        }
        error_log("stock_adjust failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if product/variant stock is at or below threshold.
 * Fires 'stock.low' hook and queues low_stock_admin email if mailer exists.
 */
function stock_check_low(int $productId, ?int $variantId): void
{
    try {
        $product = product_get($productId);
        if (!$product || (isset($product['track_inventory']) && (int)$product['track_inventory'] === 0)) {
            return;
        }

        $threshold = isset($product['low_stock_threshold']) ? (int)$product['low_stock_threshold'] : 5;
        $currentStock = 0;
        $label = $product['name'];

        if ($variantId !== null) {
            $variant = variant_get($variantId);
            if (!$variant) {
                return;
            }
            $currentStock = (int)$variant['stock'];
            $label .= ' (' . $variant['label'] . ')';
        } else {
            $currentStock = (int)($product['stock'] ?? 0);
        }

        if ($currentStock <= $threshold) {
            hook_fire('stock.low', [
                'product_id'   => $productId,
                'variant_id'   => $variantId,
                'product_name' => $product['name'],
                'label'        => $label,
                'stock'        => $currentStock,
                'threshold'    => $threshold,
            ]);

            if (function_exists('mail_queue_template')) {
                $adminEmail = function_exists('setting') ? setting('site_email') : 'admin@example.com';
                if (!empty($adminEmail)) {
                    mail_queue_template(
                        'low_stock_admin',
                        $adminEmail,
                        'Admin',
                        [
                            'product_name'  => $label,
                            'current_stock' => (string)$currentStock,
                            'threshold'     => (string)$threshold,
                        ],
                        [
                            'related_type' => 'product',
                            'related_id'   => $productId,
                        ]
                    );
                }
            }
        }
    } catch (Throwable $e) {
        error_log("stock_check_low error: " . $e->getMessage());
    }
}

/**
 * Restore stock for a cancelled order exactly once using the orders.stock_restored flag.
 */
function stock_restore_for_order(int $orderId): bool
{
    $pdo = db();
    $inTx = $pdo->inTransaction();
    if (!$inTx) {
        $pdo->beginTransaction();
    }

    try {
        // Lock order row for update
        $stmt = $pdo->prepare("SELECT id, status, stock_restored FROM orders WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            if (!$inTx) $pdo->rollBack();
            return false;
        }

        if (!empty($order['stock_restored'])) {
            // Already restored: do not restore again
            if (!$inTx) $pdo->commit();
            return false;
        }

        // Fetch all items from order_items
        $stmtItems = $pdo->prepare("SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = :oid");
        $stmtItems->execute([':oid' => $orderId]);
        $items = $stmtItems->fetchAll();

        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $vid = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
            $qty = (int)$item['quantity'];

            if ($qty > 0) {
                stock_adjust(
                    $pid,
                    $vid,
                    $qty,
                    'cancel_restock',
                    $orderId,
                    "Order #{$orderId} cancelled - stock restored"
                );
            }
        }

        // Mark stock_restored = 1
        $pdo->prepare("UPDATE orders SET stock_restored = 1 WHERE id = :id")->execute([':id' => $orderId]);

        if (!$inTx) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if (!$inTx) {
            $pdo->rollBack();
        }
        error_log("Failed to restore stock for order {$orderId}: " . $e->getMessage());
        return false;
    }
}
