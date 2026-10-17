<?php
/**
 * Customer Email Verification Endpoint (Prompt 12).
 * Consumes single-use verify_email token, activates account, claims guest orders.
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

$rawToken = trim((string)($_GET['t'] ?? ''));
$pageTitle = 'Verify Email';

$res = !empty($rawToken) ? customer_verify_email($rawToken) : ['ok' => false, 'error' => 'No verification token provided.'];

if ($res['ok']) {
    flash('success', $res['message'] ?? 'Your email address has been verified successfully!');
    header('Location: ' . shop_url('account/'));
    exit;
}

// Failed verification page
include __DIR__ . '/../includes/partials/header.php';
?>

<div class="auth-page-wrapper">
  <div class="auth-card text-center py-4">
    <div class="fs-1 text-danger mb-3">
      <i class="bi bi-x-circle"></i>
    </div>
    <h1 class="auth-title mb-2">Verification Failed</h1>
    <p class="text-muted small mb-4">
      <?= e($res['error'] ?? 'This email verification link is invalid, expired, or has already been used.') ?>
    </p>

    <div class="d-grid gap-2">
      <a href="<?= e(shop_url('resend_verification.php')) ?>" class="btn btn-editorial-primary">
        Resend Verification Link &rarr;
      </a>
      <a href="<?= e(shop_url('login.php')) ?>" class="btn btn-outline-secondary btn-sm">
        Go to Sign In
      </a>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../includes/partials/footer.php';
