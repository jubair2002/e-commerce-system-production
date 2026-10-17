<?php
/**
 * API: Submit Product Review (Phase D2).
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// 1. Verify CSRF Token
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired session token. Please refresh.']);
    exit;
}

// 2. Check Feature Setting
if ((int)setting('reviews_enabled', '1') !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Customer reviews are currently disabled.']);
    exit;
}

// 3. Spam Honeypot Check
if (!empty($_POST['website_hp'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Spam detection triggered.']);
    exit;
}

// 4. Time-trap check (minimum 3 seconds to fill form)
$formTime = (int)($_POST['form_time'] ?? 0);
if ($formTime > 0 && (time() - $formTime) < 3) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Form submitted too quickly. Please try again.']);
    exit;
}

// 5. Customer Authentication Check
$requireLogin = (int)setting('reviews_require_login', '1') === 1;
$customer = function_exists('customer_current') ? customer_current() : null;
$customerId = $customer['id'] ?? null;

if ($requireLogin && !$customerId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in to submit a product review.']);
    exit;
}

// 6. Validate Input Fields
$productId = (int)($_POST['product_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$body = trim((string)($_POST['body'] ?? ''));
$authorName = trim((string)($_POST['author_name'] ?? ($customer['name'] ?? '')));

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid product specified.']);
    exit;
}

// Check product exists
$stProd = db()->prepare("SELECT id, name FROM products WHERE id = ? AND status = 'active' LIMIT 1");
$stProd->execute([$productId]);
$product = $stProd->fetch();
if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found.']);
    exit;
}

if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please select a rating between 1 and 5 stars.']);
    exit;
}

if ($authorName === '') {
    $authorName = 'Anonymous Buyer';
}
if (mb_strlen($authorName) > 100) {
    $authorName = mb_substr($authorName, 0, 100);
}

if ($title === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please provide a short headline for your review.']);
    exit;
}
if (mb_strlen($title) > 120) {
    $title = mb_substr($title, 0, 120);
}

if (mb_strlen($body) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Review description must be at least 10 characters long.']);
    exit;
}
if (mb_strlen($body) > 2000) {
    $body = mb_substr($body, 0, 2000);
}

// 7. Check for duplicate review from this customer
if ($customerId) {
    $stDup = db()->prepare("SELECT id FROM product_reviews WHERE product_id = ? AND customer_id = ? LIMIT 1");
    $stDup->execute([$productId, $customerId]);
    if ($stDup->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'You have already submitted a review for this product.']);
        exit;
    }
}

// 8. Verified Purchase Detection
$orderId = null;
if ($customerId) {
    try {
        $stOrder = db()->prepare("
            SELECT o.id 
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.customer_id = ? AND oi.product_id = ?
            ORDER BY o.id DESC LIMIT 1
        ");
        $stOrder->execute([$customerId, $productId]);
        $orderId = $stOrder->fetchColumn() ?: null;
    } catch (Throwable $e) {}
}

// 9. Auto-approve setting
$autoApprove = (int)setting('reviews_auto_approve', '0') === 1;
$status = $autoApprove ? 'approved' : 'pending';
$ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');

try {
    $stmt = db()->prepare("
        INSERT INTO product_reviews 
        (product_id, customer_id, order_id, author_name, rating, title, body, status, ip, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $productId,
        $customerId,
        $orderId,
        $authorName,
        $rating,
        $title,
        $body,
        $status,
        $ip
    ]);

    if ($autoApprove) {
        product_recalculate_rating($productId);
    }

    // Optional admin notification email
    if (function_exists('mail_queue') && (int)setting('mail_enabled', '0') === 1) {
        $adminEmail = setting('contact_email', setting('site_email'));
        if ($adminEmail) {
            mail_queue(
                $adminEmail,
                'New Product Review Submitted: ' . $product['name'],
                "A new product review ({$rating} stars) was submitted by {$authorName} for '{$product['name']}'.\n\nTitle: {$title}\nStatus: {$status}\n\nModerate in Admin: " . url('admin/reviews.php'),
                false
            );
        }
    }

    $msg = $autoApprove 
        ? 'Thank you! Your review has been published.' 
        : 'Thank you! Your review has been submitted and is pending moderation.';

    echo json_encode([
        'success' => true,
        'status' => $status,
        'message' => $msg
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save review. Please try again later.']);
}
