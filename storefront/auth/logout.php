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
    <div class="auth-page-wrapper py-5 d-flex align-items-center justify-content-center" style="min-height: calc(100vh - 200px); background-color: var(--bg-soft);">
      <div class="container d-flex justify-content-center">
        <div class="auth-card card shadow-sm p-4 p-sm-5 text-center" style="max-width: 440px; width: 100%; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--line);">
          <h1 class="h4 fw-bold mb-3">Sign Out Request</h1>
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
