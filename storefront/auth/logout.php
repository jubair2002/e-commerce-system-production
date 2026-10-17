<?php
/**
 * Customer Logout Action (Prompt 12).
 * Strictly requires POST + CSRF token. Rejects GET requests.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    $pageTitle = 'Method Not Allowed';
    include __DIR__ . '/../../includes/partials/header.php';
    ?>
    <div class="auth-page-wrapper py-5">
      <div class="auth-card mx-auto p-4 bg-white rounded-3 shadow-sm text-center" style="max-width: 440px;">
        <h1 class="h4 fw-bold text-dark mb-3">Sign Out Request</h1>
        <div class="alert alert-warning small mb-4">
          For your security, sign out must be requested via a POST form. Direct links or GET requests are not permitted.
        </div>
        <form method="post" action="<?= e(route('logout')) ?>">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-primary w-100 mb-2">Confirm Sign Out</button>
          <a href="<?= e(route('home')) ?>" class="btn btn-outline-secondary w-100">Cancel &amp; Return</a>
        </form>
      </div>
    </div>
    <?php
    include __DIR__ . '/../../includes/partials/footer.php';
    exit;
}

// Check CSRF
if (!csrf_verify($_POST['_csrf'] ?? null)) {
    flash('danger', 'Invalid or expired session token. Please try again.');
    header('Location: ' . route('home'));
    exit;
}

// Perform logout
customer_logout();
flash('info', 'You have been successfully signed out.');

$next = customer_url_next($_POST['next'] ?? $_GET['next'] ?? '');
if ($next === route('account') || $next === 'account/' || empty($next)) {
    $next = route('home');
}

header('Location: ' . $next);
exit;
