<?php
/**
 * AJAX handler for Product Gallery Actions:
 * - delete image
 * - reorder images
 * - set primary image
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('products.create', 'products.edit');

header('Content-Type: application/json; charset=utf-8');

$action    = $_POST['action'] ?? ($_GET['action'] ?? '');
$productId = (int)($_POST['product_id'] ?? ($_GET['product_id'] ?? 0));

if ($productId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid product ID']);
    exit;
}

try {
    $pdo = db();

    if ($action === 'delete') {
        $imgId = (int)($_POST['img_id'] ?? 0);
        if ($imgId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid image ID']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT filename, file_id, is_primary FROM product_images WHERE id = :img_id AND product_id = :pid");
        $stmt->execute([':img_id' => $imgId, ':pid' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Image not found']);
            exit;
        }

        $filename = $row['filename'];
        $fileId   = $row['file_id'];
        $wasPrimary = (int)$row['is_primary'] === 1;

        // Delete from DB
        $pdo->prepare("DELETE FROM product_images WHERE id = :img_id AND product_id = :pid")->execute([':img_id' => $imgId, ':pid' => $productId]);

        // Delete storage record if applicable
        if (!empty($fileId) && function_exists('storage')) {
            try {
                storage()->deleteFile((int)$fileId);
            } catch (Throwable $se) {}
        }

        // If this image was set as product's primary image, pick next available
        $prodStmt = $pdo->prepare("SELECT image FROM products WHERE id = :pid");
        $prodStmt->execute([':pid' => $productId]);
        $curProdImg = $prodStmt->fetchColumn();

        $newPrimaryFilename = null;
        if ($wasPrimary || $curProdImg === $filename) {
            $nextStmt = $pdo->prepare("SELECT filename, file_id FROM product_images WHERE product_id = :pid ORDER BY is_primary DESC, position ASC LIMIT 1");
            $nextStmt->execute([':pid' => $productId]);
            $nextRow = $nextStmt->fetch(PDO::FETCH_ASSOC);

            if ($nextRow) {
                $newPrimaryFilename = $nextRow['filename'];
                $newPrimaryFid = $nextRow['file_id'];
                $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE product_id = :pid AND filename = :fn")->execute([':pid' => $productId, ':fn' => $newPrimaryFilename]);
                $pdo->prepare("UPDATE products SET image = :img, file_id = :fid WHERE id = :pid")->execute([':img' => $newPrimaryFilename, ':fid' => $newPrimaryFid, ':pid' => $productId]);
            } else {
                $pdo->prepare("UPDATE products SET image = NULL, file_id = NULL WHERE id = :pid")->execute([':pid' => $productId]);
            }
        }

        // Clean local disk file if no other product uses it
        $refStmt = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE filename = :fn");
        $refStmt->execute([':fn' => $filename]);
        if ((int)$refStmt->fetchColumn() === 0 && defined('UPLOADS_PATH')) {
            $filePath = UPLOADS_PATH . '/products/' . $filename;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        if (class_exists('FileCache')) {
            FileCache::deletePattern('products:*');
        }

        echo json_encode([
            'ok' => true,
            'message' => 'Image deleted successfully',
            'new_primary' => $newPrimaryFilename
        ]);
        exit;
    }

    if ($action === 'reorder') {
        $order = $_POST['order'] ?? [];
        if (!is_array($order) || empty($order)) {
            echo json_encode(['ok' => false, 'error' => 'No order provided']);
            exit;
        }

        $stmtPos = $pdo->prepare("UPDATE product_images SET position = :pos WHERE id = :img_id AND product_id = :pid");
        $pos = 1;
        foreach ($order as $imgId) {
            $imgId = (int)$imgId;
            if ($imgId > 0) {
                $stmtPos->execute([':pos' => $pos++, ':img_id' => $imgId, ':pid' => $productId]);
            }
        }

        if (class_exists('FileCache')) {
            FileCache::deletePattern('products:*');
        }

        echo json_encode(['ok' => true, 'message' => 'Gallery order updated']);
        exit;
    }

    if ($action === 'set_primary') {
        $imgId = (int)($_POST['img_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT filename, file_id FROM product_images WHERE id = :img_id AND product_id = :pid");
        $stmt->execute([':img_id' => $imgId, ':pid' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Image not found']);
            exit;
        }

        $pdo->prepare("UPDATE product_images SET is_primary = IF(id = :img_id, 1, 0) WHERE product_id = :pid")->execute([':img_id' => $imgId, ':pid' => $productId]);
        $pdo->prepare("UPDATE products SET image = :img, file_id = :fid WHERE id = :pid")->execute([':img' => $row['filename'], ':fid' => $row['file_id'], ':pid' => $productId]);

        if (class_exists('FileCache')) {
            FileCache::deletePattern('products:*');
        }

        echo json_encode(['ok' => true, 'message' => 'Primary image set']);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
