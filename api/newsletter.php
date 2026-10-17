<?php
/**
 * API: Newsletter Subscription & Double Opt-in (Phase D4).
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// 1. Verify CSRF
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid session token. Please refresh.']);
    exit;
}

// 2. Check Feature Setting
if ((int)setting('newsletter_enabled', '1') !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Newsletter subscriptions are currently closed.']);
    exit;
}

$email = strtolower(trim((string)($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

$doubleOptIn = (int)setting('newsletter_double_optin', '1') === 1;
$ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
$source = trim((string)($_POST['source'] ?? 'storefront_footer'));

try {
    $stmt = db()->prepare("SELECT id, status FROM newsletter_subscribers WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing && $existing['status'] === 'active') {
        echo json_encode([
            'success' => true,
            'message' => 'You are already subscribed to our newsletter! Thank you.'
        ]);
        exit;
    }

    $rawToken = bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $rawToken);

    if ($doubleOptIn) {
        $status = 'pending';
        $confirmUrl = route('newsletter', ['action' => 'confirm', 't' => $rawToken]);

        if ($existing) {
            $upd = db()->prepare("
                UPDATE newsletter_subscribers 
                SET status = 'pending', token_hash = ?, ip = ?, created_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$tokenHash, $ip, $existing['id']]);
        } else {
            $ins = db()->prepare("
                INSERT INTO newsletter_subscribers (email, status, token_hash, source, ip, created_at)
                VALUES (?, 'pending', ?, ?, ?, NOW())
            ");
            $ins->execute([$email, $tokenHash, $source, $ip]);
        }

        if (function_exists('mail_queue') && (int)setting('mail_enabled', '0') === 1) {
            $storeName = setting('site_name', APP_NAME);
            mail_queue(
                $email,
                "Confirm your subscription to {$storeName}",
                "Hello,\n\nPlease confirm your email subscription by clicking the link below:\n\n{$confirmUrl}\n\nIf you did not request this, please ignore this email.",
                false
            );
        }

        echo json_encode([
            'success' => true,
            'message' => 'Please check your email to confirm your subscription.'
        ]);
    } else {
        // Direct Active Subscription
        if ($existing) {
            $upd = db()->prepare("
                UPDATE newsletter_subscribers 
                SET status = 'active', confirmed_at = NOW(), ip = ?
                WHERE id = ?
            ");
            $upd->execute([$ip, $existing['id']]);
        } else {
            $ins = db()->prepare("
                INSERT INTO newsletter_subscribers (email, status, token_hash, source, ip, created_at, confirmed_at)
                VALUES (?, 'active', ?, ?, ?, NOW(), NOW())
            ");
            $ins->execute([$email, $tokenHash, $source, $ip]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Thank you for subscribing to our newsletter!'
        ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Subscription failed. Please try again.']);
}
