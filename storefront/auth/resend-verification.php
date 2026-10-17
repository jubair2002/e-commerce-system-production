<?php
/**
 * Resend Email Verification Page (Prompt 12).
 * Rate-limited endpoint for requesting a new email verification token.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

$customer = customer_current();
$pageTitle = 'Resend Verification Email';
$error = null;
$successMessage = null;

if ($customer && !empty($customer['verified'])) {
    flash('info', 'Your email address is already verified.');
    header('Location: ' . shop_url('account/'));
    exit;
}

$email = $customer['email'] ?? trim((string)($_GET['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $error = 'Invalid or expired session. Please try again.';
    } else {
        $emailInput = $customer['email'] ?? trim((string)($_POST['email'] ?? ''));
        $res = customer_resend_verification($emailInput);

        if ($res['ok']) {
            $successMessage = $res['message'];
        } else {
            $error = $res['error'] ?? 'Unable to send verification email. Please try again later.';
        }
    }
}

include __DIR__ . '/../../includes/partials/header.php';
?>

<div class="auth-page-wrapper">
  <div class="auth-card">
    <div class="auth-header text-center">
      <h1 class="auth-title">Verify Your Email</h1>
      <p class="auth-subtitle">Need another verification link? We can send a new one to your inbox.</p>
    </div>

    <?php if ($successMessage): ?>
      <div class="alert alert-success text-center mb-4">
        <div class="fs-4 mb-2"><i class="bi bi-envelope-check"></i></div>
        <h6 class="fw-bold mb-1">Link Sent</h6>
        <p class="small mb-0"><?= e($successMessage) ?></p>
      </div>
      <div class="d-grid gap-2">
        <a href="<?= e(shop_url('login.php')) ?>" class="btn btn-editorial-primary">
          Back to Sign In &rarr;
        </a>
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="alert alert-danger small py-2 mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="resend_verification.php" class="auth-form" novalidate>
        <?= csrf_field() ?>

        <?php if ($customer): ?>
          <div class="alert alert-info small mb-3">
            Currently logged in as <strong><?= e($customer['email']) ?></strong>.
          </div>
        <?php else: ?>
          <div class="form-group mb-3">
            <label for="resendEmail" class="form-label small fw-semibold">Email Address</label>
            <input type="email"
                   name="email"
                   id="resendEmail"
                   class="form-control"
                   value="<?= e($email) ?>"
                   autocomplete="email"
                   inputmode="email"
                   required
                   placeholder="you@example.com">
          </div>
        <?php endif; ?>

        <div class="d-grid gap-2 mb-3">
          <button type="submit" class="btn btn-editorial-primary">
            Send New Verification Link
          </button>
        </div>

        <div class="text-center pt-2">
          <a href="<?= e(shop_url('login.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Sign In
          </a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/partials/footer.php';
