<?php
/**
 * Customer Logout Action (Prompt 12).
 * Strictly requires POST + CSRF token. Rejects GET requests.
 */
require_once __DIR__ . '/includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    // If request was a GET, inform the user or redirect safely
    $pageTitle = 'Method Not Allowed';
    include __DIR__ . '/includes/shop_header.php';
    ?>
    <div class="auth-page-wrapper">
      <div class="auth-card text-center">
        <h1 class="auth-title mb-3">Sign Out Request</h1>
        <div class="alert alert-warning small mb-4">
          For your security, sign out must be requested via a POST form. Direct links or GET requests are not permitted.
        </div>
        <form method="post" action="logout.php">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-editorial-primary w-100 mb-2">Confirm Sign Out</button>
          <a href="<?= e(shop_url()) ?>" class="btn btn-outline-secondary w-100">Cancel & Return</a>
        </form>
      </div>
    </div>
    <?php
    include __DIR__ . '/includes/shop_footer.php';
    exit;
}

// Check CSRF
if (!csrf_verify($_POST['_csrf'] ?? null)) {
    flash('danger', 'Invalid or expired session token. Please try again.');
    header('Location: ' . shop_url());
    exit;
}

// Perform logout
customer_logout();
flash('info', 'You have been successfully signed out.');

$next = customer_url_next($_POST['next'] ?? $_GET['next'] ?? '');
if ($next === shop_url('account/')) {
    $next = shop_url();
}

header('Location: ' . $next);
exit;
