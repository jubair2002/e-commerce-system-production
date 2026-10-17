<?php
/**
 * Customer Email Verification Endpoint (Prompt 12).
 * Consumes single-use verify_email token, activates account, claims guest orders.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

$rawToken = trim((string)($_GET['t'] ?? ''));
$pageTitle = 'Verify Email';

$res = !empty($rawToken) ? customer_verify_email($rawToken) : ['ok' => false, 'error' => 'No verification token provided.'];

if ($res['ok']) {
    flash('success', $res['message'] ?? 'Your email address has been verified successfully!');
    header('Location: ' . route('account'));
    exit;
}

// Failed verification page
include __DIR__ . '/../../includes/partials/header.php';
?>

<div class="auth-page-wrapper py-5 d-flex align-items-center justify-content-center" style="min-height: calc(100vh - 200px); background-color: var(--bg-soft);">
  <div class="container d-flex justify-content-center">
    <div class="auth-card card shadow-sm p-4 p-sm-5 text-center" style="max-width: 440px; width: 100%; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--line);">
      <div class="fs-1 text-danger mb-3">
        <?= icon('x', 'text-danger', 'Error') ?>
      </div>
      <h1 class="h4 fw-bold mb-2">Verification Failed</h1>
      <p class="text-muted small mb-4">
        <?= e($res['error'] ?? 'This email verification link is invalid, expired, or has already been used.') ?>
      </p>

      <div class="d-grid gap-2">
        <a href="<?= e(route('resend_verification')) ?>" class="btn btn-editorial-primary">
          Resend Verification Link &rarr;
        </a>
        <a href="<?= e(route('login')) ?>" class="btn btn-editorial-secondary btn-sm">
          Go to Sign In
        </a>
      </div>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/partials/footer.php';
