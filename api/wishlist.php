<?php
/**
 * Wishlist AJAX Endpoint (Prompt 13).
 * Supports toggle and list_ids with optimistic UI.
 * Performance rule: session_write_close() immediately after reading customer session.
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Read customer session then immediately release session lock
$customer = function_exists('customer_current') ? customer_current() : null;
$customerId = $customer ? (int)$customer['id'] : 0;
$csrfToken = $_SESSION['_csrf'] ?? '';
session_write_close();

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'toggle'));

// Check if wishlist feature is enabled
if ((int)setting('customer_wishlist_enabled', '1') !== 1) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Wishlist is currently disabled.',
    ]);
    exit;
}

$pdo = db();

// 1. List IDs for the currently logged in customer (used for populating heart states on page load)
if ($action === 'list_ids') {
    if ($customerId <= 0) {
        echo json_encode([
            'ok'        => true,
            'logged_in' => false,
            'ids'       => [],
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT product_id FROM wishlists WHERE customer_id = :cid");
        $stmt->execute([':cid' => $customerId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        echo json_encode([
            'ok'        => true,
            'logged_in' => true,
            'ids'       => $ids,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Failed to load wishlist IDs.']);
    }
    exit;
}

// 2. Toggle item in wishlist
if ($action === 'toggle') {
    // If guest clicks heart, return login required response with target redirect
    if ($customerId <= 0) {
        $productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
        $next = shop_url('account/wishlist.php');
        if ($productId > 0) {
            $p = product_get($productId);
            if ($p && !empty($p['slug'])) {
                $next = shop_url('product.php?slug=' . urlencode($p['slug']));
            }
        }
        $loginUrl = shop_url('login.php?next=' . urlencode($next) . ($productId > 0 ? '&add_wishlist=' . $productId : ''));

        echo json_encode([
            'ok'         => false,
            'error'      => 'login_required',
            'login_url'  => $loginUrl,
            'message'    => 'Please sign in to save items to your wishlist.',
        ]);
        exit;
    }

    // CSRF verification
    $submittedCsrf = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($csrfToken) || !hash_equals($csrfToken, (string)$submittedCsrf)) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Invalid or expired session token.',
        ]);
        exit;
    }

    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid product ID.']);
        exit;
    }

    try {
        // Check if item exists in wishlist
        $stmtChk = $pdo->prepare("SELECT 1 FROM wishlists WHERE customer_id = :cid AND product_id = :pid LIMIT 1");
        $stmtChk->execute([':cid' => $customerId, ':pid' => $productId]);
        $exists = (bool)$stmtChk->fetchColumn();

        if ($exists) {
            $pdo->prepare("DELETE FROM wishlists WHERE customer_id = :cid AND product_id = :pid")
                ->execute([':cid' => $customerId, ':pid' => $productId]);
            $inWishlist = false;
        } else {
            $pdo->prepare("INSERT IGNORE INTO wishlists (customer_id, product_id, created_at) VALUES (:cid, :pid, NOW())")
                ->execute([':cid' => $customerId, ':pid' => $productId]);
            $inWishlist = true;
        }

        // Get updated total count
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM wishlists WHERE customer_id = :cid");
        $cntStmt->execute([':cid' => $customerId]);
        $newCount = (int)$cntStmt->fetchColumn();

        echo json_encode([
            'ok'          => true,
            'in_wishlist' => $inWishlist,
            'product_id'  => $productId,
            'count'       => $newCount,
            'message'     => $inWishlist ? 'Item added to your wishlist.' : 'Item removed from your wishlist.',
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Database error updating wishlist.']);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
